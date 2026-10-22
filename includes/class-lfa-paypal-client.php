<?php
/**
 * Love Family Alumni — PayPal API Client (Orders v2 & Webhook Verification)
 *
 * Implements server-side OAuth2 token management, Orders v2 create/capture,
 * webhook signature verification postbacks, and strict redaction of secrets.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_PayPal_Client {
    public const SANDBOX_URL = 'https://api-m.sandbox.paypal.com';
    public const LIVE_URL    = 'https://api-m.paypal.com';
    public const TIMEOUT     = 20;

    /**
     * Resolve PayPal API base URL for specified or active mode
     */
    public static function get_base_url(?string $mode = null): string {
        $m = $mode ?: LFA_PayPal_Settings::get_mode();
        return ($m === 'live') ? self::LIVE_URL : self::SANDBOX_URL;
    }

    /**
     * Retrieve OAuth2 Access Token with caching
     */
    public static function get_access_token(?string $mode = null): array {
        $m = $mode ?: LFA_PayPal_Settings::get_mode();
        $transient_key = "lfa_pp_tok_{$m}";

        $cached = get_transient($transient_key);
        if (!empty($cached) && is_string($cached)) {
            return ['success' => true, 'token' => $cached];
        }

        $client_id = LFA_PayPal_Settings::get_client_id($m);
        $secret    = LFA_PayPal_Settings::get_secret($m);

        if (empty($client_id) || empty($secret)) {
            return ['success' => false, 'error' => 'PayPal credentials are not configured for ' . esc_html($m) . ' mode.'];
        }

        $base_url = self::get_base_url($m);
        $endpoint = "{$base_url}/v1/oauth2/token";

        $response = wp_remote_post($endpoint, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$client_id}:{$secret}"),
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => 'grant_type=client_credentials',
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => 'Network error connecting to PayPal API: ' . $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 || empty($data['access_token'])) {
            $err_msg = !empty($data['error_description']) ? $data['error_description'] : (!empty($data['error']) ? $data['error'] : "HTTP {$code}");
            return [
                'success' => false,
                'error'   => 'PayPal OAuth authentication failed: ' . sanitize_text_field($err_msg),
            ];
        }

        $token = $data['access_token'];
        $expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        // Cache token with 120s buffer
        set_transient($transient_key, $token, max(60, $expires_in - 120));

        return ['success' => true, 'token' => $token];
    }

    /**
     * Test OAuth connection to PayPal API
     */
    public static function test_connection(?string $mode = null): array {
        $m = $mode ?: LFA_PayPal_Settings::get_mode();
        $token_res = self::get_access_token($m);

        if (!$token_res['success']) {
            $result = [
                'success'    => false,
                'message'    => $token_res['error'],
                'tested_at'  => current_time('mysql', true),
                'merchant_id'=> null,
            ];
            LFA_PayPal_Settings::record_test_result($m, $result);
            return $result;
        }

        $base_url = self::get_base_url($m);
        // Ping PayPal userinfo/account check
        $token = $token_res['token'];
        $response = wp_remote_get("{$base_url}/v1/identity/oauth2/userinfo?schema=paypalv4.0", [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
        ]);

        $merchant_id = null;
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['payer_id'])) {
                $merchant_id = sanitize_text_field($body['payer_id']);
            }
        }

        $result = [
            'success'     => true,
            'message'     => 'Successfully authenticated with PayPal ' . ucfirst($m) . ' API.',
            'tested_at'   => current_time('mysql', true),
            'merchant_id' => $merchant_id,
        ];
        LFA_PayPal_Settings::record_test_result($m, $result);
        return $result;
    }

    /**
     * Create PayPal Order (Orders v2) from authoritative local invoice
     */
    public static function create_order(object $invoice, string $request_id, ?string $mode = null, string $custom_id = '', string $description = ''): array {
        $m = $mode ?: LFA_PayPal_Settings::get_mode();
        $token_res = self::get_access_token($m);
        if (!$token_res['success']) {
            return $token_res;
        }

        $base_url = self::get_base_url($m);
        $amount_dollars = number_format($invoice->amount_cents / 100, 2, '.', '');

        $cid  = !empty($custom_id) ? $custom_id : "lfa_inv_{$invoice->id}_u_{$invoice->user_id}";
        $desc = !empty($description) ? $description : "Love Family Alumni Annual Dues — Invoice {$invoice->invoice_number}";

        $payload = [
            'intent'         => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => (string) $invoice->invoice_number,
                    'custom_id'    => $cid,
                    'description'  => $desc,
                    'amount'       => [
                        'currency_code' => 'USD',
                        'value'         => $amount_dollars,
                    ],
                ],
            ],
            'application_context' => [
                'brand_name'          => 'Love Family Alumni',
                'landing_page'        => 'NO_PREFERENCE',
                'user_action'         => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
            ],
        ];

        $response = wp_remote_post("{$base_url}/v2/checkout/orders", [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization'     => "Bearer {$token_res['token']}",
                'Content-Type'      => 'application/json',
                'PayPal-Request-Id' => $request_id,
                'Prefer'            => 'return=representation',
            ],
            'body'    => (string) json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => 'PayPal create order network error: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 201 && $code !== 200) {
            $msg = !empty($body['message']) ? $body['message'] : "HTTP {$code}";
            return ['success' => false, 'error' => 'PayPal create order failed: ' . sanitize_text_field($msg)];
        }

        return [
            'success'  => true,
            'order_id' => (string) $body['id'],
            'status'   => (string) $body['status'],
            'raw'      => $body,
        ];
    }

    /**
     * Capture PayPal Order (Orders v2)
     */
    public static function capture_order(string $order_id, string $request_id, ?string $mode = null): array {
        $m = $mode ?: LFA_PayPal_Settings::get_mode();
        $token_res = self::get_access_token($m);
        if (!$token_res['success']) {
            return $token_res;
        }

        $base_url = self::get_base_url($m);
        $endpoint = "{$base_url}/v2/checkout/orders/{$order_id}/capture";

        $response = wp_remote_post($endpoint, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization'     => "Bearer {$token_res['token']}",
                'Content-Type'      => 'application/json',
                'PayPal-Request-Id' => $request_id,
                'Prefer'            => 'return=representation',
            ],
            'body'    => '{}',
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => 'PayPal capture network error: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 201 && $code !== 200) {
            $msg = !empty($body['message']) ? $body['message'] : "HTTP {$code}";
            return ['success' => false, 'error' => 'PayPal capture API error: ' . sanitize_text_field($msg), 'code' => $code];
        }

        // Extract capture details from purchase_units[0].payments.captures[0]
        $capture_data = null;
        if (!empty($body['purchase_units'][0]['payments']['captures'][0])) {
            $capture_data = $body['purchase_units'][0]['payments']['captures'][0];
        }

        if (!$capture_data || empty($capture_data['id'])) {
            return ['success' => false, 'error' => 'PayPal capture response did not contain capture details.'];
        }

        $captured_cents = isset($capture_data['amount']['value']) ? (int) round(((float)$capture_data['amount']['value']) * 100) : 0;
        $captured_curr  = isset($capture_data['amount']['currency_code']) ? (string)$capture_data['amount']['currency_code'] : 'USD';
        $payee_merchant = isset($capture_data['payee']['merchant_id']) ? (string)$capture_data['payee']['merchant_id'] : '';

        return [
            'success'           => true,
            'order_id'          => $order_id,
            'capture_id'        => (string) $capture_data['id'],
            'capture_status'    => (string) $capture_data['status'],
            'amount_cents'      => $captured_cents,
            'currency'          => $captured_curr,
            'payee_merchant_id' => $payee_merchant,
            'captured_at'       => !empty($capture_data['create_time']) ? gmdate('Y-m-d H:i:s', strtotime($capture_data['create_time'])) : current_time('mysql', true),
            'raw'               => $capture_data,
        ];
    }

    /**
     * Get Order details from PayPal API
     */
    public static function get_order(string $order_id, ?string $mode = null): array {
        $m = $mode ?: LFA_PayPal_Settings::get_mode();
        $token_res = self::get_access_token($m);
        if (!$token_res['success']) {
            return $token_res;
        }

        $base_url = self::get_base_url($m);
        $response = wp_remote_get("{$base_url}/v2/checkout/orders/{$order_id}", [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => "Bearer {$token_res['token']}",
                'Content-Type'  => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return ['success' => false, 'error' => !empty($body['message']) ? $body['message'] : "HTTP {$code}"];
        }

        return ['success' => true, 'order' => $body];
    }

    /**
     * Get Capture details from PayPal API
     */
    public static function get_capture(string $capture_id, ?string $mode = null): array {
        $m = $mode ?: LFA_PayPal_Settings::get_mode();
        $token_res = self::get_access_token($m);
        if (!$token_res['success']) {
            return $token_res;
        }

        $base_url = self::get_base_url($m);
        $response = wp_remote_get("{$base_url}/v2/payments/captures/{$capture_id}", [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => "Bearer {$token_res['token']}",
                'Content-Type'  => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return ['success' => false, 'error' => !empty($body['message']) ? $body['message'] : "HTTP {$code}"];
        }

        return ['success' => true, 'capture' => $body];
    }

    /**
     * Strict PayPal Webhook Signature Verification Postback API
     */
    public static function verify_webhook_signature(array $headers, string $raw_body, ?string $mode = null): array {
        $m = $mode ?: LFA_PayPal_Settings::get_mode();
        $webhook_id = LFA_PayPal_Settings::get_webhook_id($m);

        if (empty($webhook_id)) {
            return ['verified' => false, 'error' => 'No webhook ID configured for ' . esc_html($m) . ' mode.'];
        }

        // Normalize header keys
        $clean_headers = [];
        foreach ($headers as $k => $v) {
            $clean_headers[strtoupper(str_replace('-', '_', $k))] = is_array($v) ? $v[0] : $v;
        }

        $auth_algo         = $clean_headers['PAYPAL_AUTH_ALGO'] ?? '';
        $cert_url          = $clean_headers['PAYPAL_CERT_URL'] ?? '';
        $transmission_id   = $clean_headers['PAYPAL_TRANSMISSION_ID'] ?? '';
        $transmission_sig  = $clean_headers['PAYPAL_TRANSMISSION_SIG'] ?? '';
        $transmission_time = $clean_headers['PAYPAL_TRANSMISSION_TIME'] ?? '';

        if (empty($transmission_sig) || empty($cert_url) || empty($auth_algo) || empty($transmission_id)) {
            return ['verified' => false, 'error' => 'Missing required PayPal signature headers.'];
        }

        $event_json = json_decode($raw_body, true);
        if (!is_array($event_json)) {
            return ['verified' => false, 'error' => 'Invalid JSON in webhook body.'];
        }

        $token_res = self::get_access_token($m);
        if (!$token_res['success']) {
            return ['verified' => false, 'error' => 'Cannot authenticate to verify webhook: ' . $token_res['error']];
        }

        $base_url = self::get_base_url($m);
        $verify_payload = [
            'auth_algo'         => $auth_algo,
            'cert_url'          => $cert_url,
            'transmission_id'   => $transmission_id,
            'transmission_sig'  => $transmission_sig,
            'transmission_time' => $transmission_time,
            'webhook_id'        => $webhook_id,
            'webhook_event'     => $event_json,
        ];

        $response = wp_remote_post("{$base_url}/v1/notifications/verify-webhook-signature", [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => "Bearer {$token_res['token']}",
                'Content-Type'  => 'application/json',
            ],
            'body'    => (string) json_encode($verify_payload),
        ]);

        if (is_wp_error($response)) {
            return ['verified' => false, 'error' => 'Webhook verification request failed: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['verification_status']) && $body['verification_status'] === 'SUCCESS') {
            return ['verified' => true, 'status' => 'SUCCESS'];
        }

        $status = !empty($body['verification_status']) ? $body['verification_status'] : "HTTP {$code}";
        return ['verified' => false, 'status' => $status, 'error' => 'PayPal signature verification rejected: ' . sanitize_text_field($status)];
    }
}
