<?php
/**
 * Love Family Alumni — Stripe Gateway Provider Implementation (Test Mode Only)
 *
 * Implements LFA_Payment_Gateway_Interface using official Stripe PHP SDK (v16.4.0).
 * Handles server-side Stripe Checkout Sessions for Membership Dues, Conference Registrations,
 * and Donations. Enforces strict test-mode isolation, webhook signature validation,
 * PaymentIntent verification, and idempotent reconciliation.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Stripe_Gateway implements LFA_Payment_Gateway_Interface {
    public const PROVIDER_ID = 'stripe';

    /**
     * Get unique provider identifier
     */
    public function get_id(): string {
        return self::PROVIDER_ID;
    }

    /**
     * Get human-readable title
     */
    public function get_title(): string {
        return 'Stripe (Test Mode)';
    }

    /**
     * Check if Stripe Test gateway is enabled
     */
    public function is_enabled(): bool {
        return LFA_Gateway_Settings::is_stripe_test_enabled();
    }

    /**
     * Environment is fixed to test in this development scope
     */
    public function get_environment(): string {
        return 'test';
    }

    /**
     * Get initialized StripeClient instance
     */
    public static function get_client(): ?\Stripe\StripeClient {
        $secret = LFA_Gateway_Settings::get_stripe_secret_key();
        if (empty($secret) || !str_starts_with($secret, 'sk_test_')) {
            return null;
        }

        if (!class_exists('\Stripe\StripeClient')) {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            }
        }

        if (!class_exists('\Stripe\StripeClient')) {
            return null;
        }

        return new \Stripe\StripeClient([
            'api_key'        => $secret,
            'stripe_version' => LFA_Gateway_Settings::STRIPE_API_VERSION,
        ]);
    }

    /**
     * Test connection to Stripe Test API
     */
    public function test_connection(): array {
        $secret = LFA_Gateway_Settings::get_stripe_secret_key();
        $pubkey = LFA_Gateway_Settings::get_stripe_publishable_key();

        if (empty($secret) || empty($pubkey)) {
            $result = [
                'success'   => false,
                'message'   => 'Stripe Test Publishable Key and Secret Key must both be configured.',
                'tested_at' => current_time('mysql', true),
                'account'   => null,
            ];
            LFA_Gateway_Settings::record_test_result('stripe', 'test', $result);
            return $result;
        }

        if (!str_starts_with($secret, 'sk_test_')) {
            $result = [
                'success'   => false,
                'message'   => 'Security Violation: Only Stripe Test credentials (sk_test_...) are permitted.',
                'tested_at' => current_time('mysql', true),
                'account'   => null,
            ];
            LFA_Gateway_Settings::record_test_result('stripe', 'test', $result);
            return $result;
        }

        $client = self::get_client();
        if (!$client) {
            $result = [
                'success'   => false,
                'message'   => 'Stripe PHP SDK could not be loaded or initialized.',
                'tested_at' => current_time('mysql', true),
                'account'   => null,
            ];
            LFA_Gateway_Settings::record_test_result('stripe', 'test', $result);
            return $result;
        }

        try {
            // Ping Stripe Balance / Account API
            $bal = $client->balance->retrieve();
            $account_id = null;
            $account_name = 'Stripe Test Account';

            try {
                $acc = $client->accounts->retrieve(null);
                if (!empty($acc->id)) {
                    $account_id = $acc->id;
                    $account_name = $acc->business_profile->name ?? ($acc->settings->dashboard->display_name ?? 'Stripe Test Account');
                }
            } catch (\Exception $e) {
                // Balance retrieve already confirmed API key validity
                $account_id = 'test_verified';
            }

            $result = [
                'success'      => true,
                'message'      => 'Successfully connected to Stripe Test API. Ready for test checkouts.',
                'tested_at'    => current_time('mysql', true),
                'account_id'   => $account_id,
                'account_name' => $account_name,
                'livemode'     => $bal->livemode ?? false,
            ];
            LFA_Gateway_Settings::record_test_result('stripe', 'test', $result);
            return $result;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $result = [
                'success'   => false,
                'message'   => 'Stripe API Authentication Failed: ' . $e->getMessage(),
                'tested_at' => current_time('mysql', true),
                'account'   => null,
            ];
            LFA_Gateway_Settings::record_test_result('stripe', 'test', $result);
            return $result;
        } catch (\Throwable $e) {
            $result = [
                'success'   => false,
                'message'   => 'Connection Error: ' . $e->getMessage(),
                'tested_at' => current_time('mysql', true),
                'account'   => null,
            ];
            LFA_Gateway_Settings::record_test_result('stripe', 'test', $result);
            return $result;
        }
    }

    /**
     * Authoritative payable validation
     */
    public function validate_payable(int $invoice_id, int $user_id, string $payable_type = 'membership_due'): array {
        return LFA_Payment_Service::validate_invoice_for_checkout($invoice_id, $user_id, $payable_type);
    }

    /**
     * Create Stripe Hosted Test Checkout Session
     */
    public function create_payment_session(object $payable, int $user_id, string $payable_type, array $context = []): array {
        $check = $this->validate_payable((int)$payable->id, $user_id, $payable_type);
        if (!$check['valid']) {
            return ['success' => false, 'error' => $check['error'], 'code' => $check['code']];
        }

        if (!$this->is_enabled()) {
            return [
                'success' => false,
                'code'    => 'STRIPE_DISABLED',
                'error'   => 'Stripe Test payment gateway is currently disabled or pending configuration.',
            ];
        }

        $client = self::get_client();
        if (!$client) {
            return [
                'success' => false,
                'code'    => 'STRIPE_CLIENT_ERROR',
                'error'   => 'Stripe SDK client could not be initialized.',
            ];
        }

        global $wpdb;
        $t_att = LFA_Payment_Service::table_attempts();
        $local_request_key = $context['request_key'] ?? wp_generate_uuid4();
        $invoice_id = (int) $payable->id;
        $amount_cents = (int) $payable->amount_cents;

        // Idempotency: Reuse recent valid unexpired checkout session if available
        $two_hours_ago = gmdate('Y-m-d H:i:s', time() - 7200);
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_att} WHERE (payable_id = %d OR invoice_id = %d) AND payable_type = %s AND user_id = %d AND provider = 'stripe' AND state = 'session_created' AND updated_at > %s ORDER BY id DESC LIMIT 1",
            $invoice_id,
            $invoice_id,
            $payable_type,
            $user_id,
            $two_hours_ago
        ));

        $existing_session_id = !empty($existing->checkout_session_id) ? $existing->checkout_session_id : ($existing->order_id ?? '');
        if ($existing && !empty($existing_session_id)) {
            try {
                $session = $client->checkout->sessions->retrieve($existing_session_id);
                if ($session && $session->status === 'open') {
                    return [
                        'success'             => true,
                        'session_id'          => $session->id,
                        'session_url'         => $session->url,
                        'checkout_url'        => $session->url,
                        'attempt_id'          => (int) $existing->id,
                        'invoice_id'          => $invoice_id,
                        'payable_type'        => $payable_type,
                        'is_reused'           => true,
                    ];
                }
            } catch (\Exception $e) {
                // Expired or invalid, proceed to create fresh session
            }
        }

        // Record initialized attempt in local database
        $now = current_time('mysql', true);
        $wpdb->insert($t_att, [
            'invoice_id'          => $invoice_id,
            'payable_type'        => $payable_type,
            'payable_id'          => $invoice_id,
            'user_id'             => $user_id,
            'provider'            => 'stripe',
            'mode'                => 'test',
            'merchant_identity'   => 'Stripe Test',
            'local_request_key'   => $local_request_key,
            'order_id'            => null,
            'amount_cents'        => $amount_cents,
            'currency'            => 'USD',
            'state'               => 'initialized',
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $attempt_id = $wpdb->insert_id;

        // Compose Line Item and Metadata based on Payable Type
        $user_obj = get_user_by('id', $user_id);
        $customer_email = $user_obj ? $user_obj->user_email : '';

        if ($payable_type === 'donation') {
            $item_title = "Donation: {$payable->purpose_snapshot}";
            $item_desc  = "Love Family Alumni Giving — Ref {$payable->donation_ref} [TEST MODE]";
            $ref_num    = $payable->donation_ref;
            if (!empty($payable->donor_email)) {
                $customer_email = $payable->donor_email;
            }
            $success_url = home_url("/give/?payment_session_id={CHECKOUT_SESSION_ID}&session_id={CHECKOUT_SESSION_ID}&gateway=stripe&context=donation&ref={$ref_num}");
            $cancel_url  = home_url("/give/?payment_cancelled=1&ref={$ref_num}");
        } elseif ($payable_type === 'conference' || $payable_type === 'conference_pass') {
            $reg = $check['registration'] ?? null;
            $ticket_name = $reg ? $reg->ticket_name_snapshot : 'Conference Pass';
            $item_title  = "2027 Conference Pass: {$ticket_name}";
            $item_desc   = "Love Family Alumni Biennial Conference 2027 — Invoice {$payable->invoice_number} [TEST MODE]";
            $ref_num     = $payable->invoice_number;
            $success_url = home_url("/member-dashboard/?payment_session_id={CHECKOUT_SESSION_ID}&session_id={CHECKOUT_SESSION_ID}&gateway=stripe&context=conference#conference");
            $cancel_url  = home_url("/member-dashboard/?payment_cancelled=1#conference");
        } else {
            $item_title  = "Annual Membership Dues (2026/2027)";
            $item_desc   = "Love Family Alumni North America — Invoice {$payable->invoice_number} [TEST MODE]";
            $ref_num     = $payable->invoice_number;
            $success_url = home_url("/member-dashboard/?payment_session_id={CHECKOUT_SESSION_ID}&session_id={CHECKOUT_SESSION_ID}&gateway=stripe&context=dues#dues");
            $cancel_url  = home_url("/member-dashboard/?payment_cancelled=1#dues");
        }

        $session_payload = [
            'mode'                 => 'payment',
            'payment_method_types' => ['card'],
            'line_items'           => [
                [
                    'price_data' => [
                        'currency'     => 'usd',
                        'unit_amount'  => $amount_cents,
                        'product_data' => [
                            'name'        => $item_title,
                            'description' => $item_desc,
                        ],
                    ],
                    'quantity'   => 1,
                ],
            ],
            'client_reference_id'  => "lfa_{$payable_type}_{$invoice_id}_{$user_id}",
            'metadata'             => [
                'gateway_provider' => 'stripe',
                'environment'      => 'test',
                'payable_type'     => $payable_type,
                'invoice_id'       => (string) $invoice_id,
                'payable_id'       => (string) $invoice_id,
                'user_id'          => (string) $user_id,
                'invoice_number'   => (string) $ref_num,
                'attempt_id'       => (string) $attempt_id,
                'test_transaction' => '1',
            ],
            'success_url'          => $success_url,
            'cancel_url'           => $cancel_url,
        ];

        if (!empty($customer_email)) {
            $session_payload['customer_email'] = $customer_email;
        }

        try {
            $session = $client->checkout->sessions->create($session_payload);

            // Update local attempt with session details
            $wpdb->update($t_att, [
                'order_id'            => $session->id, // store session ID in order_id column for backwards compat
                'state'               => 'session_created',
                'updated_at'          => current_time('mysql', true),
            ], ['id' => $attempt_id]);

            // Attempt to update checkout_session_id column if present
            $wpdb->query($wpdb->prepare(
                "UPDATE {$t_att} SET checkout_session_id = %s, provider_payment_id = %s WHERE id = %d",
                $session->id,
                $session->payment_intent ? (string)$session->payment_intent : null,
                $attempt_id
            ));

            LFA_Payment_Service::log_audit('member', $user_id, 'stripe_session_created', (string)$invoice_id, [
                'session_id'   => $session->id,
                'attempt_id'   => $attempt_id,
                'payable_type' => $payable_type,
                'amount_cents' => $amount_cents,
            ]);

            return [
                'success'      => true,
                'session_id'   => $session->id,
                'session_url'  => $session->url,
                'checkout_url' => $session->url,
                'attempt_id'   => $attempt_id,
                'invoice_id'   => $invoice_id,
                'payable_type' => $payable_type,
                'pubkey'       => LFA_Gateway_Settings::get_stripe_publishable_key(),
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $wpdb->update($t_att, [
                'state'      => 'failed',
                'updated_at' => current_time('mysql', true),
            ], ['id' => $attempt_id]);

            LFA_Payment_Service::log_audit('member', $user_id, 'stripe_session_create_failed', (string)$invoice_id, [
                'error'        => $e->getMessage(),
                'payable_type' => $payable_type,
            ]);

            return [
                'success' => false,
                'code'    => 'STRIPE_API_ERROR',
                'error'   => 'Stripe Checkout error: ' . $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'code'    => 'EXCEPTION',
                'error'   => 'Checkout initialization failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Retrieve payment session / payment intent status from Stripe
     */
    public function retrieve_payment_status(string $provider_payment_id): array {
        $client = self::get_client();
        if (!$client) {
            return ['success' => false, 'error' => 'Stripe client unavailable.'];
        }

        try {
            if (str_starts_with($provider_payment_id, 'cs_')) {
                $session = $client->checkout->sessions->retrieve($provider_payment_id, ['expand' => ['payment_intent']]);
                return ['success' => true, 'session' => $session, 'status' => $session->status];
            }
            if (str_starts_with($provider_payment_id, 'pi_')) {
                $pi = $client->paymentIntents->retrieve($provider_payment_id);
                return ['success' => true, 'payment_intent' => $pi, 'status' => $pi->status];
            }
            return ['success' => false, 'error' => 'Unrecognized Stripe identifier format.'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify Stripe webhook signature using official SDK
     */
    public function verify_webhook(array $headers, string $raw_body): array {
        $signing_secret = LFA_Gateway_Settings::get_stripe_webhook_secret();
        if (empty($signing_secret)) {
            return ['verified' => false, 'error' => 'Stripe Test Webhook Signing Secret is not configured.'];
        }

        // Clean headers: look for Stripe-Signature
        $sig_header = '';
        foreach ($headers as $k => $v) {
            if (strtolower(str_replace('_', '-', $k)) === 'stripe-signature') {
                $sig_header = is_array($v) ? $v[0] : (string)$v;
                break;
            }
        }

        if (empty($sig_header)) {
            return ['verified' => false, 'error' => 'Missing Stripe-Signature header.'];
        }

        try {
            // Use official Stripe Webhook signature verification
            $event = \Stripe\Webhook::constructEvent($raw_body, $sig_header, $signing_secret);
            return ['verified' => true, 'event' => $event];
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return ['verified' => false, 'error' => 'Stripe Webhook Signature Verification Failed: ' . $e->getMessage()];
        } catch (\UnexpectedValueException $e) {
            return ['verified' => false, 'error' => 'Invalid Webhook Payload JSON: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['verified' => false, 'error' => 'Webhook Verification Exception: ' . $e->getMessage()];
        }
    }

    /**
     * Handle verified webhook event
     */
    public function handle_webhook_event(array $event_data, array $headers, string $raw_body): array {
        // Delegate to LFA_Payment_Reconciliation for atomic processing
        return LFA_Payment_Reconciliation::handle_stripe_webhook($headers, $raw_body, $this);
    }

    /**
     * Settle payment authoritatively
     */
    public function capture_or_reconcile(string $provider_payment_id, int $user_id, string $request_key = '', string $payable_type = 'membership_due'): array {
        return LFA_Payment_Reconciliation::settle_stripe_session($provider_payment_id, $user_id, $payable_type);
    }
}
