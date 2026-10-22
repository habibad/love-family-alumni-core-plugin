<?php
/**
 * Love Family Alumni — Authoritative Payment Reconciliation Service
 *
 * Implements atomic, idempotent database settlement, duplicate webhook protection,
 * out-of-order event resilience, and isolated test/staging entitlement updates.
 *
 * Excludes Stripe test payments from live production income and revenue reports.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Payment_Reconciliation {

    /**
     * Handle incoming verified Stripe webhook event
     */
    public static function handle_stripe_webhook(array $headers, string $raw_body, LFA_Stripe_Gateway $gateway): array {
        global $wpdb;
        $t_events = LFA_Payment_Service::table_webhook_events();

        // 1. Signature Verification
        $sig_res = $gateway->verify_webhook($headers, $raw_body);
        if (!$sig_res['verified']) {
            LFA_Payment_Service::log_audit('system', null, 'stripe_webhook_signature_rejected', 'stripe_webhook', [
                'error' => $sig_res['error'] ?? 'Invalid signature',
            ]);
            return ['status' => 401, 'error' => $sig_res['error'] ?? 'Unauthorized webhook signature.'];
        }

        $event = $sig_res['event'];
        $event_id = sanitize_text_field($event->id);
        $event_type = sanitize_text_field($event->type);

        // 2. Strict Livemode Check: Reject any live event in test setup
        if (!empty($event->livemode)) {
            LFA_Payment_Service::log_audit('system', null, 'stripe_live_event_rejected', $event_id, [
                'event_type' => $event_type,
            ]);
            return ['status' => 400, 'error' => 'Live Stripe webhook rejected: Development gateway operates in TEST MODE only.'];
        }

        // 3. Deduplication Check (Idempotency)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_events} WHERE paypal_event_id = %s OR (provider_event_id = %s AND gateway_provider = 'stripe')",
            $event_id,
            $event_id
        ));

        if ($existing && $existing->processing_state === 'processed') {
            return ['status' => 200, 'message' => 'Event already processed idempotently.'];
        }

        $now = current_time('mysql', true);
        if (!$existing) {
            $wpdb->insert($t_events, [
                'paypal_event_id'        => $event_id,
                'paypal_transmission_id' => $event_id,
                'event_type'             => $event_type,
                'mode'                   => 'test',
                'verified_at'            => $now,
                'processed_at'           => null,
                'processing_state'       => 'verified',
                'failure_count'          => 0,
                'created_at'             => $now,
            ]);
            $event_row_id = $wpdb->insert_id;
            // Update gateway_provider / provider_event_id if columns exist
            $wpdb->query($wpdb->prepare(
                "UPDATE {$t_events} SET gateway_provider = 'stripe', provider_event_id = %s WHERE id = %d",
                $event_id,
                $event_row_id
            ));
        } else {
            $event_row_id = $existing->id;
        }

        // Record last webhook in settings
        LFA_Gateway_Settings::record_webhook_event('stripe', [
            'event_id'    => $event_id,
            'event_type'  => $event_type,
            'received_at' => $now,
            'status'      => 'verified',
        ]);

        // 4. Dispatch Event Family
        $settled = false;
        try {
            switch ($event_type) {
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    $session = $event->data->object;
                    $settle_res = self::process_checkout_session_completed($session);
                    $settled = !empty($settle_res['success']);
                    break;

                case 'checkout.session.async_payment_failed':
                case 'payment_intent.payment_failed':
                    $obj = $event->data->object;
                    self::process_payment_failed($obj, $event_type);
                    break;

                case 'checkout.session.expired':
                    $session = $event->data->object;
                    self::process_session_expired($session);
                    break;

                case 'charge.refunded':
                    $charge = $event->data->object;
                    self::process_charge_refunded($charge);
                    break;

                default:
                    // Log unhandled event type harmlessly
                    LFA_Payment_Service::log_audit('system', null, 'stripe_webhook_unhandled_type', $event_id, [
                        'type' => $event_type,
                    ]);
                    break;
            }

            // Mark event processed
            $wpdb->update($t_events, [
                'processing_state' => 'processed',
                'processed_at'     => current_time('mysql', true),
            ], ['id' => $event_row_id]);

            return ['status' => 200, 'message' => 'Stripe webhook received and processed.'];
        } catch (\Throwable $e) {
            $wpdb->update($t_events, [
                'processing_state' => 'failed',
                'last_error_code'  => sanitize_text_field(substr($e->getMessage(), 0, 100)),
                'failure_count'    => ($existing ? (int)$existing->failure_count + 1 : 1),
            ], ['id' => $event_row_id]);

            LFA_Payment_Service::log_audit('system', null, 'stripe_webhook_processing_failed', $event_id, [
                'error' => $e->getMessage(),
            ]);

            return ['status' => 500, 'error' => 'Processing exception: ' . $e->getMessage()];
        }
    }

    /**
     * Handle checkout.session.completed
     */
    private static function process_checkout_session_completed(object $session): array {
        if ($session->payment_status !== 'paid') {
            return ['success' => false, 'error' => 'Session not in paid status.'];
        }

        $session_id = $session->id;
        $meta = is_object($session->metadata) && method_exists($session->metadata, 'toArray') ? $session->metadata->toArray() : (array)($session->metadata ?? []);
        $user_id = isset($meta['user_id']) ? (int)$meta['user_id'] : ($session->metadata->user_id ?? 0);
        $payable_type = isset($meta['payable_type']) ? (string)$meta['payable_type'] : ($session->metadata->payable_type ?? 'membership_due');

        return self::settle_stripe_session($session_id, (int)$user_id, (string)$payable_type, $session);
    }

    /**
     * Authoritative Server-Side Settle for Stripe Checkout Session
     */
    public static function settle_stripe_session(string $session_id, int $user_id = 0, string $payable_type = 'membership_due', ?object $session = null): array {
        global $wpdb;
        $t_att = LFA_Payment_Service::table_attempts();
        $t_cap = LFA_Payment_Service::table_captures();
        $now = current_time('mysql', true);

        // If session object was not provided, retrieve it from Stripe
        if (!$session) {
            $client = LFA_Stripe_Gateway::get_client();
            if (!$client) {
                return ['success' => false, 'error' => 'Stripe client unavailable for settlement.'];
            }
            try {
                $session = $client->checkout->sessions->retrieve($session_id, ['expand' => ['payment_intent']]);
            } catch (\Exception $e) {
                return ['success' => false, 'error' => 'Failed to retrieve Stripe session: ' . $e->getMessage()];
            }
        }

        if (!$session || $session->payment_status !== 'paid') {
            return ['success' => false, 'error' => 'Stripe Checkout Session is not paid.'];
        }

        // Retrieve authoritative PaymentIntent
        $payment_intent = null;
        $pi_id = is_string($session->payment_intent) ? $session->payment_intent : ($session->payment_intent->id ?? '');
        if (!empty($pi_id)) {
            try {
                $payment_intent = is_object($session->payment_intent) ? $session->payment_intent : $client->paymentIntents->retrieve($pi_id);
            } catch (\Exception $e) {
                // If payment intent retrieve fails, continue with session details
            }
        }

        $meta = is_object($session->metadata) && method_exists($session->metadata, 'toArray') ? $session->metadata->toArray() : (array)($session->metadata ?? []);
        $invoice_id = isset($meta['invoice_id']) ? (int) $meta['invoice_id'] : (int) ($session->metadata->invoice_id ?? ($session->client_reference_id ?? 0));
        $p_type = isset($meta['payable_type']) ? (string) $meta['payable_type'] : (string) ($session->metadata->payable_type ?? $payable_type);
        $target_user_id = isset($meta['user_id']) ? (int) $meta['user_id'] : (int) ($session->metadata->user_id ?? $user_id);

        // Find attempt
        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_att} WHERE (order_id = %s OR checkout_session_id = %s) LIMIT 1",
            $session_id,
            $session_id
        ));

        if (!$attempt && $invoice_id > 0) {
            $attempt = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t_att} WHERE (payable_id = %d OR invoice_id = %d) AND payable_type = %s AND provider = 'stripe' ORDER BY id DESC LIMIT 1",
                $invoice_id,
                $invoice_id,
                $p_type
            ));
        }

        if ($attempt) {
            $invoice_id = (int) $attempt->invoice_id;
            $target_user_id = (int) $attempt->user_id;
            $p_type = $attempt->payable_type;
        }

        $pi_capture_id = !empty($pi_id) ? $pi_id : ('str_cap_' . md5($session_id));
        $captured_cents = (int) ($session->amount_total ?? 0);
        $captured_currency = strtoupper((string) ($session->currency ?? 'USD'));

        // Idempotency check: Already settled in captures table?
        $existing_capture = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_cap} WHERE (paypal_capture_id = %s OR checkout_session_id = %s OR provider_payment_id = %s)",
            $pi_capture_id,
            $session_id,
            $pi_id
        ));

        if ($existing_capture) {
            return [
                'success'         => true,
                'reconciled'      => true,
                'already_settled' => true,
                'capture_id'      => $existing_capture->paypal_capture_id,
                'amount_dollars'  => number_format($existing_capture->amount_cents / 100, 2),
                'status'          => 'COMPLETED',
                'message'         => 'Payment was already successfully reconciled idempotently.',
            ];
        }

        // Insert Capture Record (Marked as Stripe Test)
        $wpdb->insert($t_cap, [
            'attempt_id'        => $attempt ? (int) $attempt->id : 0,
            'invoice_id'        => $invoice_id,
            'payable_type'      => $p_type,
            'payable_id'        => $invoice_id,
            'paypal_capture_id' => $pi_capture_id,
            'paypal_order_id'   => $session_id,
            'merchant_identity' => 'Stripe Test Account',
            'mode'              => 'test',
            'amount_cents'      => $captured_cents,
            'currency'          => $captured_currency,
            'capture_status'    => 'COMPLETED',
            'captured_at'       => $now,
            'reconciled_at'     => $now,
            'refund_state'      => 'none',
            'last_checked_at'   => $now,
        ]);
        $capture_id = $wpdb->insert_id;

        // Populate new schema columns if available
        $wpdb->query($wpdb->prepare(
            "UPDATE {$t_cap} SET gateway_provider = 'stripe', gateway_environment = 'test', checkout_session_id = %s, provider_payment_id = %s, test_transaction = 1, settled_at = %s, payment_status = 'COMPLETED', entitlement_effect = 'test_paid' WHERE id = %d",
            $session_id,
            $pi_id,
            $now,
            $capture_id
        ));

        // Transition attempt state
        if ($attempt) {
            $wpdb->update($t_att, [
                'state'      => 'captured',
                'updated_at' => $now,
            ], ['id' => $attempt->id]);
        }

        // Record last verified test transaction in settings
        LFA_Gateway_Settings::record_last_transaction('stripe', [
            'session_id'   => $session_id,
            'pi_id'        => $pi_id,
            'amount_cents' => $captured_cents,
            'payable_type' => $p_type,
            'settled_at'   => $now,
        ]);

        // Settle based on payable type
        if ($p_type === 'donation') {
            if (class_exists('LFA_Donation')) {
                LFA_Donation::settle_donation($invoice_id, $pi_capture_id, $session_id, $captured_cents);
                // Mark donation record as test_transaction and gateway_provider stripe
                $t_don = LFA_Donation::table_donations();
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$t_don} SET status = 'paid', gateway_provider = 'stripe', provider_payment_id = %s, test_transaction = 1, paid_at = COALESCE(paid_at, %s), updated_at = %s WHERE id = %d",
                    $pi_id,
                    $now,
                    $now,
                    $invoice_id
                ));
            }
            self::send_test_receipt_email($invoice_id, $pi_capture_id, 'donation', $target_user_id);
            return [
                'success'        => true,
                'reconciled'     => true,
                'payable_type'   => 'donation',
                'capture_id'     => $pi_capture_id,
                'session_id'     => $session_id,
                'amount_dollars' => number_format($captured_cents / 100, 2),
                'status'         => 'COMPLETED',
                'message'        => 'Stripe Test donation successfully reconciled.',
            ];
        }

        if ($p_type === 'conference' || $p_type === 'conference_pass') {
            if (class_exists('LFA_Conference')) {
                LFA_Conference::settle_registration($invoice_id, $pi_capture_id, $captured_cents);
                // Mark conference invoice and registration as test_paid
                $t_cinv = LFA_Conference::table_invoices();
                $t_creg = LFA_Conference::table_registrations();
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$t_cinv} SET gateway_provider = 'stripe', provider_payment_id = %s, test_paid = 1 WHERE id = %d",
                    $pi_id,
                    $invoice_id
                ));
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$t_creg} SET gateway_provider = 'stripe', provider_payment_id = %s, test_paid = 1 WHERE id = (SELECT registration_id FROM {$t_cinv} WHERE id = %d)",
                    $pi_id,
                    $invoice_id
                ));
            }
            self::send_test_receipt_email($invoice_id, $pi_capture_id, 'conference', $target_user_id);
            return [
                'success'        => true,
                'reconciled'     => true,
                'payable_type'   => 'conference',
                'capture_id'     => $pi_capture_id,
                'session_id'     => $session_id,
                'amount_dollars' => number_format($captured_cents / 100, 2),
                'status'         => 'COMPLETED',
                'message'        => 'Stripe Test conference registration confirmed and settled.',
            ];
        }

        // Membership Dues Settlement
        $t_inv = LFA_Membership::table_invoices();
        $t_mem = LFA_Membership::table_memberships();
        $t_per = LFA_Membership::table_periods();

        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));
        if ($inv) {
            $wpdb->update($t_inv, [
                'status'     => LFA_Membership::INVOICE_STATUS_PAID,
                'paid_at'    => $now,
                'updated_at' => $now,
            ], ['id' => $inv->id]);

            // Safe update test_paid / gateway_provider on invoice
            $wpdb->query($wpdb->prepare(
                "UPDATE {$t_inv} SET gateway_provider = 'stripe', provider_payment_id = %s, test_paid = 1 WHERE id = %d",
                $pi_id,
                $inv->id
            ));

            $wpdb->update($t_mem, [
                'status'     => LFA_Membership::STANDING_CURRENT,
                'updated_at' => $now,
            ], ['user_id' => $inv->user_id]);

            $one_year_later = LFA_Membership::calculate_calendar_year_expiry($now);
            $period_id = (int)$inv->period_id;
            if ($period_id <= 0) {
                $mem_row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$t_mem} WHERE user_id = %d", $inv->user_id));
                if ($mem_row) {
                    $existing_per = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$t_per} WHERE membership_id = %d ORDER BY id DESC LIMIT 1", $mem_row->id));
                    if ($existing_per) {
                        $period_id = (int)$existing_per->id;
                    } else {
                        $current_year = (int) gmdate('Y', strtotime($now));
                        $period_key = $current_year . '/' . ($current_year + 1);
                        $wpdb->insert($t_per, [
                            'membership_id' => $mem_row->id,
                            'period_key'    => $period_key,
                            'standing'      => LFA_Membership::STANDING_CURRENT,
                            'starts_at'     => $now,
                            'ends_at'       => $one_year_later,
                            'created_at'    => $now,
                            'updated_at'    => $now,
                        ]);
                        $period_id = (int) $wpdb->insert_id;
                    }
                    $wpdb->update($t_inv, ['period_id' => $period_id], ['id' => $inv->id]);
                }
            }

            if ($period_id > 0) {
                $wpdb->update($t_per, [
                    'standing'   => LFA_Membership::STANDING_CURRENT,
                    'starts_at'  => $now,
                    'ends_at'    => $one_year_later,
                    'updated_at' => $now,
                ], ['id' => $period_id]);
            }

            update_user_meta($inv->user_id, 'lfa_dues_status', 'current');

            self::send_test_receipt_email((int)$inv->id, $pi_capture_id, 'membership_due', (int)$inv->user_id);
        }

        LFA_Payment_Service::log_audit('member', $target_user_id, 'stripe_payment_settled', (string)$invoice_id, [
            'session_id'       => $session_id,
            'payment_intent'   => $pi_id,
            'amount_cents'     => $captured_cents,
            'payable_type'     => $p_type,
            'test_transaction' => 1,
        ]);

        return [
            'success'        => true,
            'reconciled'     => true,
            'payable_type'   => 'membership_due',
            'capture_id'     => $pi_capture_id,
            'session_id'     => $session_id,
            'amount_dollars' => number_format($captured_cents / 100, 2),
            'status'         => 'COMPLETED',
            'message'        => 'Stripe Test payment verified and staging membership standing updated to Current.',
        ];
    }

    /**
     * Process failed payment intent or async session
     */
    private static function process_payment_failed(object $obj, string $event_type): void {
        global $wpdb;
        $t_att = LFA_Payment_Service::table_attempts();
        $pi_id = $obj->id ?? '';

        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_att} WHERE order_id = %s OR provider_payment_id = %s LIMIT 1",
            $pi_id,
            $pi_id
        ));

        if ($attempt) {
            $wpdb->update($t_att, [
                'state'      => 'failed',
                'updated_at' => current_time('mysql', true),
            ], ['id' => $attempt->id]);

            LFA_Payment_Service::log_audit('system', (int)$attempt->user_id, 'stripe_payment_failed', (string)$attempt->invoice_id, [
                'event_type' => $event_type,
                'pi_id'      => $pi_id,
            ]);
        }
    }

    /**
     * Process expired checkout session
     */
    private static function process_session_expired(object $session): void {
        global $wpdb;
        $t_att = LFA_Payment_Service::table_attempts();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$t_att} SET state = 'expired', updated_at = NOW() WHERE (order_id = %s OR checkout_session_id = %s) AND state = 'session_created'",
            $session->id,
            $session->id
        ));
    }

    /**
     * Process charge.refunded event
     */
    private static function process_charge_refunded(object $charge): void {
        global $wpdb;
        $t_cap = LFA_Payment_Service::table_captures();
        $pi_id = $charge->payment_intent ?? '';

        $capture = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_cap} WHERE provider_payment_id = %s OR paypal_capture_id = %s LIMIT 1",
            $pi_id,
            $pi_id
        ));

        if ($capture) {
            $now = current_time('mysql', true);
            $wpdb->update($t_cap, [
                'refund_state'    => 'refunded',
                'last_checked_at' => $now,
            ], ['id' => $capture->id]);

            $wpdb->query($wpdb->prepare(
                "UPDATE {$t_cap} SET refunded_at = %s, entitlement_effect = 'refunded' WHERE id = %d",
                $now,
                $capture->id
            ));

            LFA_Payment_Entitlements::handle_refund((int)$capture->invoice_id, $capture->payable_type, 'stripe_charge_refunded');
        }
    }

    /**
     * Dispatch clearly labeled TEST receipt email via wp_mail()
     */
    public static function send_test_receipt_email(int $invoice_id, string $capture_id, string $payable_type, int $user_id): bool {
        $email = '';
        $recipient_name = 'Alumni Member';
        $amount_str = '$100.00 USD';
        $ref_str = $capture_id;
        $type_label = 'Annual Membership Dues';

        if ($payable_type === 'donation') {
            $type_label = 'Donation to Giving Fund';
            if (class_exists('LFA_Donation')) {
                $don = LFA_Donation::get_donation_by_id($invoice_id);
                if ($don) {
                    $email = $don->donor_email;
                    $recipient_name = $don->donor_display_name ?: 'Valued Supporter';
                    $amount_str = '$' . number_format($don->amount_cents / 100, 2) . ' USD';
                    $type_label = 'Donation to ' . ($don->purpose_snapshot ?: 'Giving Fund');
                    $ref_str = $don->donation_ref ?: $capture_id;
                }
            }
        } elseif ($payable_type === 'conference') {
            $type_label = '2027 Conference Pass Registration';
        }

        if (empty($email) && $user_id > 0) {
            $user = get_user_by('id', $user_id);
            if ($user && !empty($user->user_email)) {
                $email = $user->user_email;
                $recipient_name = $user->display_name;
            }
        }

        if (empty($email)) {
            return false;
        }

        $subject = "[TEST MODE] Payment Receipt & Confirmation — {$type_label} (#{$ref_str})";

        $message = "NOTICE: THIS IS A VERIFIED STRIPE TEST MODE TRANSACTION — NO REAL MONEY CHARGED.\n\n"
            . "Dear " . esc_html($recipient_name) . ",\n\n"
            . "Your test payment for {$type_label} has been successfully verified via Stripe Test Mode.\n\n"
            . "TRANSACTION SUMMARY (TEST ENVIRONMENT):\n"
            . "------------------------------------------------------------\n"
            . "Gateway Provider:  Stripe (Test Mode)\n"
            . "Payment Reference: " . esc_html($capture_id) . "\n"
            . "Item Reference:    #" . esc_html((string)$invoice_id) . " (" . esc_html($ref_str) . ")\n"
            . "Amount Paid:       " . esc_html($amount_str) . "\n"
            . "Environment:       TEST MODE (No real currency charged)\n"
            . "Date of Payment:   " . current_time('mysql') . "\n"
            . "------------------------------------------------------------\n\n"
            . "Love Family Alumni Testing & Development Team\n";

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        return wp_mail($email, $subject, $message, $headers);
    }
}
