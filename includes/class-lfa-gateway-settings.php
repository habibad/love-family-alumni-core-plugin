<?php
/**
 * Love Family Alumni — Unified Payment Gateway Settings & Secure Credential Storage
 *
 * Implements centralized configuration, AES-256-GCM authenticated encryption at rest,
 * provider-level enable/disable toggles, global payment kill switch, default testing gateway
 * selection, and fail-closed secret handling for both PayPal and Stripe.
 *
 * Strictly enforces Stripe Test Mode ONLY in development. Rejects sk_live_ credentials.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Gateway_Settings {
    public const OPTION_NAME        = 'lfa_gateway_settings';
    public const LEGACY_PP_OPTION   = 'lfa_paypal_settings';
    public const CAPABILITY         = 'lfa_manage_paypal_settings'; // Dedicated gateway management capability
    public const CIPHER             = 'aes-256-gcm';
    public const STRIPE_API_VERSION = '2024-06-20';

    /**
     * Retrieve the master encryption key.
     * Derived from external constants or WordPress security salts.
     * Fails closed (returns null) if no valid secret key is present.
     */
    public static function get_master_key(): ?string {
        if (defined('LFA_GATEWAY_ENCRYPTION_KEY') && !empty(constant('LFA_GATEWAY_ENCRYPTION_KEY'))) {
            return hash('sha256', (string) constant('LFA_GATEWAY_ENCRYPTION_KEY'), true);
        }
        if (defined('LFA_PAYPAL_ENCRYPTION_KEY') && !empty(constant('LFA_PAYPAL_ENCRYPTION_KEY'))) {
            return hash('sha256', (string) constant('LFA_PAYPAL_ENCRYPTION_KEY'), true);
        }
        if (defined('AUTH_KEY') && defined('SECURE_AUTH_KEY') && !empty(AUTH_KEY) && !empty(SECURE_AUTH_KEY)) {
            return hash_hmac('sha256', (string) AUTH_KEY, (string) SECURE_AUTH_KEY, true);
        }
        return null;
    }

    /**
     * Authenticated encryption using AES-256-GCM.
     * Returns false if master key is missing or encryption fails (fail-closed).
     */
    public static function encrypt_secret(string $plaintext): string|false {
        if ($plaintext === '') {
            return '';
        }

        $key = self::get_master_key();
        if (!$key || !extension_loaded('openssl')) {
            return false; // Fail closed: never persist plaintext
        }

        $ivlen = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivlen);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // aad
            16  // tag length
        );

        if ($ciphertext === false || empty($tag)) {
            return false;
        }

        $payload = [
            'v'    => 1,
            'c'    => self::CIPHER,
            'iv'   => base64_encode($iv),
            'tag'  => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ];

        return 'enc:gcm:v1:' . base64_encode((string) json_encode($payload));
    }

    /**
     * Authenticated decryption using AES-256-GCM.
     * Returns false gracefully if key has rotated or tag is invalid.
     */
    public static function decrypt_secret(string $ciphertext): string|false {
        if ($ciphertext === '') {
            return '';
        }

        if (!str_starts_with($ciphertext, 'enc:gcm:v1:')) {
            return false;
        }

        $key = self::get_master_key();
        if (!$key || !extension_loaded('openssl')) {
            return false;
        }

        $raw_json = base64_decode(substr($ciphertext, 11), true);
        if ($raw_json === false) {
            return false;
        }

        $payload = json_decode($raw_json, true);
        if (!is_array($payload) || empty($payload['iv']) || empty($payload['tag']) || empty($payload['data'])) {
            return false;
        }

        $iv   = base64_decode($payload['iv'], true);
        $tag  = base64_decode($payload['tag'], true);
        $data = base64_decode($payload['data'], true);

        if ($iv === false || $tag === false || $data === false) {
            return false;
        }

        $decrypted = openssl_decrypt(
            $data,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $decrypted;
    }

    /**
     * Default settings structure
     */
    public static function get_default_settings(): array {
        return [
            // Global Controls
            'global_payments_kill_switch' => 0,          // 1 = ALL checkouts disabled
            'global_live_payments'        => 0,          // Strictly OFF in development
            'default_testing_gateway'     => 'stripe',   // 'stripe' or 'paypal'

            // PayPal Provider Configuration
            'paypal_enabled'              => 0,          // Provider toggle
            'paypal_mode'                 => 'sandbox',  // 'sandbox' | 'live'
            'paypal_live_confirmed'       => 0,
            'paypal_sandbox_client_id'    => '',
            'paypal_sandbox_secret_enc'   => '',
            'paypal_sandbox_webhook_id'   => '',
            'paypal_sandbox_merchant_id'  => '',
            'paypal_sandbox_last_test'    => null,
            'paypal_live_client_id'       => '',
            'paypal_live_secret_enc'      => '',
            'paypal_live_webhook_id'      => '',
            'paypal_live_merchant_id'     => '',
            'paypal_live_last_test'       => null,

            // Stripe Provider Configuration (Test Mode Only)
            'stripe_enabled'              => 1,          // Provider toggle
            'stripe_mode'                 => 'test',     // Fixed to test in this assignment
            'stripe_test_publishable_key' => '',
            'stripe_test_secret_key_enc'  => '',
            'stripe_test_webhook_secret_enc' => '',
            'stripe_test_last_test'       => null,
            'stripe_test_last_webhook'    => null,
            'stripe_test_last_transaction'=> null,

            // Metadata
            'updated_at'                  => null,
            'updated_by'                  => null,
        ];
    }

    /**
     * Retrieve stored settings merged with defaults and legacy PayPal settings
     */
    public static function get_settings(): array {
        $saved = get_option(self::OPTION_NAME, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        // Migrate or sync from legacy PayPal settings if available
        $legacy = get_option(self::LEGACY_PP_OPTION, []);
        if (is_array($legacy) && !empty($legacy)) {
            if (!isset($saved['paypal_enabled']) && isset($legacy['payments_enabled'])) {
                $saved['paypal_enabled'] = (int) $legacy['payments_enabled'];
            }
            if (!isset($saved['paypal_mode']) && isset($legacy['mode'])) {
                $saved['paypal_mode'] = $legacy['mode'];
            }
            if (!isset($saved['paypal_sandbox_client_id']) && isset($legacy['sandbox_client_id'])) {
                $saved['paypal_sandbox_client_id'] = $legacy['sandbox_client_id'];
            }
            if (!isset($saved['paypal_sandbox_secret_enc']) && isset($legacy['sandbox_client_secret_enc'])) {
                $saved['paypal_sandbox_secret_enc'] = $legacy['sandbox_client_secret_enc'];
            }
            if (!isset($saved['paypal_sandbox_webhook_id']) && isset($legacy['sandbox_webhook_id'])) {
                $saved['paypal_sandbox_webhook_id'] = $legacy['sandbox_webhook_id'];
            }
            if (!isset($saved['paypal_sandbox_merchant_id']) && isset($legacy['sandbox_merchant_id'])) {
                $saved['paypal_sandbox_merchant_id'] = $legacy['sandbox_merchant_id'];
            }
            if (!isset($saved['paypal_sandbox_last_test']) && isset($legacy['sandbox_last_test'])) {
                $saved['paypal_sandbox_last_test'] = $legacy['sandbox_last_test'];
            }
            if (!isset($saved['paypal_live_client_id']) && isset($legacy['live_client_id'])) {
                $saved['paypal_live_client_id'] = $legacy['live_client_id'];
            }
            if (!isset($saved['paypal_live_secret_enc']) && isset($legacy['live_client_secret_enc'])) {
                $saved['paypal_live_secret_enc'] = $legacy['live_client_secret_enc'];
            }
            if (!isset($saved['paypal_live_webhook_id']) && isset($legacy['live_webhook_id'])) {
                $saved['paypal_live_webhook_id'] = $legacy['live_webhook_id'];
            }
            if (!isset($saved['paypal_live_merchant_id']) && isset($legacy['live_merchant_id'])) {
                $saved['paypal_live_merchant_id'] = $legacy['live_merchant_id'];
            }
        }

        return array_merge(self::get_default_settings(), $saved);
    }

    /**
     * Check if global kill switch is engaged
     */
    public static function is_global_kill_switch_active(): bool {
        $s = self::get_settings();
        return (int) $s['global_payments_kill_switch'] === 1;
    }

    /**
     * Check if PayPal is enabled and ready
     */
    public static function is_paypal_enabled(): bool {
        if (self::is_global_kill_switch_active()) {
            return false;
        }
        $s = self::get_settings();
        if ((int)$s['paypal_enabled'] !== 1) {
            return false;
        }
        $mode = $s['paypal_mode'] === 'live' ? 'live' : 'sandbox';
        $cid  = ($mode === 'live') ? $s['paypal_live_client_id'] : $s['paypal_sandbox_client_id'];
        $sec  = self::get_paypal_secret($mode);

        if (empty($cid) || empty($sec)) {
            return false;
        }

        if ($mode === 'live' && (int)$s['paypal_live_confirmed'] !== 1) {
            return false;
        }

        return true;
    }

    /**
     * Check if Stripe Test gateway is enabled and ready
     */
    public static function is_stripe_test_enabled(): bool {
        if (self::is_global_kill_switch_active()) {
            return false;
        }
        $s = self::get_settings();
        if ((int)$s['stripe_enabled'] !== 1) {
            return false;
        }

        $pk  = self::get_stripe_publishable_key();
        $sec = self::get_stripe_secret_key();

        if (empty($pk) || empty($sec)) {
            return false;
        }

        // Must be a test key
        if (!str_starts_with($pk, 'pk_test_') || !str_starts_with($sec, 'sk_test_')) {
            return false;
        }

        return true;
    }

    /**
     * Get default testing gateway ('stripe' or 'paypal')
     */
    public static function get_default_testing_gateway(): string {
        $s = self::get_settings();
        $pref = $s['default_testing_gateway'] ?? 'stripe';
        if ($pref === 'stripe' && self::is_stripe_test_enabled()) {
            return 'stripe';
        }
        if ($pref === 'paypal' && self::is_paypal_enabled()) {
            return 'paypal';
        }
        if (self::is_stripe_test_enabled()) {
            return 'stripe';
        }
        if (self::is_paypal_enabled()) {
            return 'paypal';
        }
        return 'none';
    }

    /**
     * Get decrypted PayPal secret for server-side API only
     */
    public static function get_paypal_secret(?string $mode = null): ?string {
        $s = self::get_settings();
        $m = $mode ?: $s['paypal_mode'];
        $enc = ($m === 'live') ? $s['paypal_live_secret_enc'] : $s['paypal_sandbox_secret_enc'];

        if (empty($enc)) {
            // Check legacy setting
            $leg = get_option(self::LEGACY_PP_OPTION, []);
            $enc = ($m === 'live') ? ($leg['live_client_secret_enc'] ?? '') : ($leg['sandbox_client_secret_enc'] ?? '');
        }

        if (empty($enc)) {
            return null;
        }

        $dec = self::decrypt_secret((string)$enc);
        return ($dec !== false) ? $dec : null;
    }

    /**
     * Get Stripe Test Publishable Key (safe for frontend rendering)
     */
    public static function get_stripe_publishable_key(): string {
        if (defined('LFA_STRIPE_TEST_PUBLISHABLE_KEY') && !empty(constant('LFA_STRIPE_TEST_PUBLISHABLE_KEY'))) {
            return (string) constant('LFA_STRIPE_TEST_PUBLISHABLE_KEY');
        }
        $s = self::get_settings();
        return (string) $s['stripe_test_publishable_key'];
    }

    /**
     * Get decrypted Stripe Test Secret Key for server-side API only
     */
    public static function get_stripe_secret_key(): ?string {
        if (defined('LFA_STRIPE_TEST_SECRET_KEY') && !empty(constant('LFA_STRIPE_TEST_SECRET_KEY'))) {
            $key = (string) constant('LFA_STRIPE_TEST_SECRET_KEY');
            if (str_starts_with($key, 'sk_test_')) {
                return $key;
            }
        }
        $s = self::get_settings();
        $enc = $s['stripe_test_secret_key_enc'] ?? '';
        if (empty($enc)) {
            return null;
        }
        $dec = self::decrypt_secret((string)$enc);
        return ($dec !== false && str_starts_with($dec, 'sk_test_')) ? $dec : null;
    }

    /**
     * Get decrypted Stripe Test Webhook Signing Secret
     */
    public static function get_stripe_webhook_secret(): ?string {
        if (defined('LFA_STRIPE_TEST_WEBHOOK_SECRET') && !empty(constant('LFA_STRIPE_TEST_WEBHOOK_SECRET'))) {
            return (string) constant('LFA_STRIPE_TEST_WEBHOOK_SECRET');
        }
        $s = self::get_settings();
        $enc = $s['stripe_test_webhook_secret_enc'] ?? '';
        if (empty($enc)) {
            return null;
        }
        $dec = self::decrypt_secret((string)$enc);
        return ($dec !== false) ? $dec : null;
    }

    /**
     * Generate official public Stripe Webhook listener URL
     */
    public static function get_stripe_webhook_url(): string {
        return rest_url('lfa/v1/stripe/webhook');
    }

    /**
     * Generate official public PayPal Webhook listener URL
     */
    public static function get_paypal_webhook_url(): string {
        return rest_url('lfa/v1/paypal/webhook');
    }

    /**
     * Save admin settings with rigorous validation, encryption, and audit logging
     */
    public static function save_admin_settings(array $input, int $actor_user_id = 0): array {
        if (!current_user_can(self::CAPABILITY) && !current_user_can('manage_options')) {
            return ['success' => false, 'error' => 'Unauthorized: Insufficient permissions to manage payment gateways.'];
        }

        $current = self::get_settings();
        $updated = $current;

        // 1. Global Controls
        $updated['global_payments_kill_switch'] = !empty($input['global_payments_kill_switch']) ? 1 : 0;
        $updated['global_live_payments']        = 0; // Strictly enforce 0 in this development scope
        if (isset($input['default_testing_gateway'])) {
            $updated['default_testing_gateway'] = in_array($input['default_testing_gateway'], ['stripe', 'paypal'], true)
                ? $input['default_testing_gateway'] : 'stripe';
        }

        // 2. PayPal Settings
        $new_pp_mode = (isset($input['paypal_mode']) && $input['paypal_mode'] === 'live') ? 'live' : 'sandbox';
        $pp_mode_changed = ($new_pp_mode !== $current['paypal_mode']);
        $updated['paypal_mode'] = $new_pp_mode;

        if ($pp_mode_changed) {
            $updated['paypal_enabled'] = 0;
            $updated['paypal_live_confirmed'] = 0;
        } else {
            $updated['paypal_enabled'] = !empty($input['paypal_enabled']) ? 1 : 0;
            $updated['paypal_live_confirmed'] = !empty($input['paypal_live_confirmed']) ? 1 : 0;
        }

        if ($new_pp_mode === 'live' && empty($updated['paypal_live_confirmed'])) {
            $updated['paypal_enabled'] = 0;
        }

        if (isset($input['paypal_sandbox_client_id'])) {
            $updated['paypal_sandbox_client_id'] = sanitize_text_field(trim($input['paypal_sandbox_client_id']));
        }
        if (isset($input['paypal_sandbox_webhook_id'])) {
            $updated['paypal_sandbox_webhook_id'] = sanitize_text_field(trim($input['paypal_sandbox_webhook_id']));
        }
        if (isset($input['paypal_sandbox_merchant_id'])) {
            $updated['paypal_sandbox_merchant_id'] = sanitize_text_field(trim($input['paypal_sandbox_merchant_id']));
        }

        if (!empty($input['paypal_sandbox_secret'])) {
            $enc = self::encrypt_secret(trim($input['paypal_sandbox_secret']));
            if ($enc === false) {
                return ['success' => false, 'error' => 'Master encryption key missing or cipher error. PayPal secret could not be encrypted.'];
            }
            $updated['paypal_sandbox_secret_enc'] = $enc;
        } elseif (isset($input['paypal_sandbox_clear_secret']) && $input['paypal_sandbox_clear_secret'] === '1') {
            $updated['paypal_sandbox_secret_enc'] = '';
        }

        if (isset($input['paypal_live_client_id'])) {
            $updated['paypal_live_client_id'] = sanitize_text_field(trim($input['paypal_live_client_id']));
        }
        if (isset($input['paypal_live_webhook_id'])) {
            $updated['paypal_live_webhook_id'] = sanitize_text_field(trim($input['paypal_live_webhook_id']));
        }
        if (isset($input['paypal_live_merchant_id'])) {
            $updated['paypal_live_merchant_id'] = sanitize_text_field(trim($input['paypal_live_merchant_id']));
        }

        if (!empty($input['paypal_live_secret'])) {
            $enc = self::encrypt_secret(trim($input['paypal_live_secret']));
            if ($enc === false) {
                return ['success' => false, 'error' => 'Master encryption key missing or cipher error. PayPal live secret could not be encrypted.'];
            }
            $updated['paypal_live_secret_enc'] = $enc;
        } elseif (isset($input['paypal_live_clear_secret']) && $input['paypal_live_clear_secret'] === '1') {
            $updated['paypal_live_secret_enc'] = '';
        }

        // 3. Stripe Settings (Test Mode Only)
        $updated['stripe_enabled'] = !empty($input['stripe_enabled']) ? 1 : 0;
        $updated['stripe_mode']    = 'test'; // Strictly test mode

        if (isset($input['stripe_test_publishable_key'])) {
            $raw_pk = sanitize_text_field(trim($input['stripe_test_publishable_key']));
            if (!empty($raw_pk)) {
                if (str_starts_with($raw_pk, 'pk_live_')) {
                    return ['success' => false, 'error' => 'Security Violation: Live Stripe credentials (pk_live_...) are strictly disallowed in this development scope.'];
                }
                if (!str_starts_with($raw_pk, 'pk_test_')) {
                    return ['success' => false, 'error' => 'Invalid Stripe Test Publishable Key format. Must begin with pk_test_.'];
                }
            }
            $updated['stripe_test_publishable_key'] = $raw_pk;
        }

        if (!empty($input['stripe_test_secret_key'])) {
            $raw_sk = sanitize_text_field(trim($input['stripe_test_secret_key']));
            if (str_starts_with($raw_sk, 'sk_live_')) {
                return ['success' => false, 'error' => 'Security Violation: Live Stripe secret key (sk_live_...) is strictly disallowed in this development scope.'];
            }
            if (!str_starts_with($raw_sk, 'sk_test_')) {
                return ['success' => false, 'error' => 'Invalid Stripe Test Secret Key format. Must begin with sk_test_.'];
            }
            $enc_sk = self::encrypt_secret($raw_sk);
            if ($enc_sk === false) {
                return ['success' => false, 'error' => 'Master encryption key missing or cipher error. Stripe secret key could not be encrypted.'];
            }
            $updated['stripe_test_secret_key_enc'] = $enc_sk;
        } elseif (isset($input['stripe_test_clear_secret']) && $input['stripe_test_clear_secret'] === '1') {
            $updated['stripe_test_secret_key_enc'] = '';
        }

        if (!empty($input['stripe_test_webhook_secret'])) {
            $raw_wh = sanitize_text_field(trim($input['stripe_test_webhook_secret']));
            if (!str_starts_with($raw_wh, 'whsec_')) {
                return ['success' => false, 'error' => 'Invalid Stripe Webhook Signing Secret format. Must begin with whsec_.'];
            }
            $enc_wh = self::encrypt_secret($raw_wh);
            if ($enc_wh === false) {
                return ['success' => false, 'error' => 'Master encryption key missing or cipher error. Stripe webhook signing secret could not be encrypted.'];
            }
            $updated['stripe_test_webhook_secret_enc'] = $enc_wh;
        } elseif (isset($input['stripe_test_clear_webhook']) && $input['stripe_test_clear_webhook'] === '1') {
            $updated['stripe_test_webhook_secret_enc'] = '';
        }

        $actor_id = $actor_user_id ?: get_current_user_id();
        $now = current_time('mysql', true);
        $updated['updated_at'] = $now;
        $updated['updated_by'] = $actor_id;

        update_option(self::OPTION_NAME, $updated, false);

        // Also sync legacy PayPal options table so legacy callers remain compatible
        $legacy_sync = [
            'mode'                      => $updated['paypal_mode'],
            'payments_enabled'          => $updated['paypal_enabled'],
            'live_activation_confirmed' => $updated['paypal_live_confirmed'],
            'sandbox_client_id'         => $updated['paypal_sandbox_client_id'],
            'sandbox_client_secret_enc' => $updated['paypal_sandbox_secret_enc'],
            'sandbox_webhook_id'        => $updated['paypal_sandbox_webhook_id'],
            'sandbox_merchant_id'       => $updated['paypal_sandbox_merchant_id'],
            'sandbox_last_test'         => $updated['paypal_sandbox_last_test'],
            'live_client_id'            => $updated['paypal_live_client_id'],
            'live_client_secret_enc'    => $updated['paypal_live_secret_enc'],
            'live_webhook_id'           => $updated['paypal_live_webhook_id'],
            'live_merchant_id'          => $updated['paypal_live_merchant_id'],
            'live_last_test'            => $updated['paypal_live_last_test'],
            'updated_at'                => $now,
            'updated_by'                => $actor_id,
        ];
        update_option(self::LEGACY_PP_OPTION, $legacy_sync, false);

        // Audit log
        if (class_exists('LFA_Payment_Service')) {
            LFA_Payment_Service::log_audit('admin', $actor_id, 'gateway_settings_updated', 'dual_gateway_settings', [
                'paypal_enabled'          => $updated['paypal_enabled'],
                'paypal_mode'             => $updated['paypal_mode'],
                'stripe_enabled'          => $updated['stripe_enabled'],
                'default_testing_gateway' => $updated['default_testing_gateway'],
                'kill_switch'             => $updated['global_payments_kill_switch'],
            ]);
        }

        return ['success' => true, 'pp_mode_changed' => $pp_mode_changed];
    }

    /**
     * Record connection test result
     */
    public static function record_test_result(string $provider, string $mode, array $result): void {
        $s = self::get_settings();
        if ($provider === 'stripe') {
            $s['stripe_test_last_test'] = $result;
        } elseif ($provider === 'paypal') {
            if ($mode === 'live') {
                $s['paypal_live_last_test'] = $result;
            } else {
                $s['paypal_sandbox_last_test'] = $result;
            }
        }
        update_option(self::OPTION_NAME, $s, false);
    }

    /**
     * Record last verified webhook / transaction
     */
    public static function record_webhook_event(string $provider, array $info): void {
        $s = self::get_settings();
        if ($provider === 'stripe') {
            $s['stripe_test_last_webhook'] = $info;
        }
        update_option(self::OPTION_NAME, $s, false);
    }

    public static function record_last_transaction(string $provider, array $info): void {
        $s = self::get_settings();
        if ($provider === 'stripe') {
            $s['stripe_test_last_transaction'] = $info;
        }
        update_option(self::OPTION_NAME, $s, false);
    }

    /**
     * Mask keys for safe admin display (e.g. pk_test_••••••••1234)
     */
    public static function mask_key(string $key, int $prefix = 7, int $suffix = 4): string {
        $len = strlen($key);
        if ($len <= ($prefix + $suffix)) {
            return $key === '' ? 'Not Configured' : '••••••••';
        }
        return substr($key, 0, $prefix) . '••••••••' . substr($key, -$suffix);
    }
}
