<?php
/**
 * Love Family Alumni — PayPal Settings & Secure Credential Storage
 *
 * Implements protected administrator settings, AES-256-GCM authenticated
 * encryption at rest, mode isolation (Sandbox vs Live), connection testing,
 * and fail-closed secret handling.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_PayPal_Settings {
    public const OPTION_NAME = 'lfa_paypal_settings';
    public const CAPABILITY  = 'lfa_manage_paypal_settings';
    public const CIPHER      = 'aes-256-gcm';

    /**
     * Retrieve the master encryption key from wp-config or derived salts.
     * Fails closed (returns null) if no valid secret key is present.
     */
    public static function get_master_key(): ?string {
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
     * Returns null/false gracefully if key has rotated or tag is invalid.
     */
    public static function decrypt_secret(string $ciphertext): string|false {
        if ($ciphertext === '') {
            return '';
        }

        if (!str_starts_with($ciphertext, 'enc:gcm:v1:')) {
            // Unrecognized or plaintext string - reject
            return false;
        }

        $key = self::get_master_key();
        if (!$key || !extension_loaded('openssl')) {
            return false; // Fail closed
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

        return $decrypted; // false if tag verification fails (e.g. key rotated)
    }

    /**
     * Default settings structure
     */
    public static function get_default_settings(): array {
        return [
            'mode'                      => 'sandbox', // 'sandbox' | 'live'
            'payments_enabled'          => 0,         // OFF by default
            'live_activation_confirmed' => 0,
            // Sandbox environment
            'sandbox_client_id'         => '',
            'sandbox_client_secret_enc' => '',
            'sandbox_webhook_id'        => '',
            'sandbox_merchant_id'       => '',
            'sandbox_last_test'         => null,
            'sandbox_last_webhook'      => null,
            // Live environment
            'live_client_id'            => '',
            'live_client_secret_enc'    => '',
            'live_webhook_id'           => '',
            'live_merchant_id'          => '',
            'live_last_test'            => null,
            'live_last_webhook'         => null,
            // Meta
            'updated_at'                => null,
            'updated_by'                => null,
        ];
    }

    /**
     * Retrieve stored settings merged with defaults
     */
    public static function get_settings(): array {
        $saved = get_option(self::OPTION_NAME, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return array_merge(self::get_default_settings(), $saved);
    }

    /**
     * Check if payments are currently enabled and healthy
     */
    public static function is_payments_enabled(): bool {
        $s = self::get_settings();
        if ((int)$s['payments_enabled'] !== 1) {
            return false;
        }
        $mode = $s['mode'];
        $client_id = $mode === 'live' ? $s['live_client_id'] : $s['sandbox_client_id'];
        $secret = self::get_secret($mode);

        if (empty($client_id) || empty($secret)) {
            return false;
        }

        // Live mode requires explicit confirmation
        if ($mode === 'live' && (int)$s['live_activation_confirmed'] !== 1) {
            return false;
        }

        return true;
    }

    /**
     * Get active mode ('sandbox' or 'live')
     */
    public static function get_mode(): string {
        $s = self::get_settings();
        return ($s['mode'] === 'live') ? 'live' : 'sandbox';
    }

    /**
     * Get active client ID (safe for frontend rendering in JS SDK)
     */
    public static function get_client_id(?string $mode = null): string {
        $s = self::get_settings();
        $m = $mode ?: $s['mode'];
        return ($m === 'live') ? (string)$s['live_client_id'] : (string)$s['sandbox_client_id'];
    }

    /**
     * Get decrypted secret for server-side API requests ONLY
     */
    public static function get_secret(?string $mode = null): ?string {
        $s = self::get_settings();
        $m = $mode ?: $s['mode'];
        $enc = ($m === 'live') ? $s['live_client_secret_enc'] : $s['sandbox_client_secret_enc'];

        if (empty($enc)) {
            return null;
        }

        $dec = self::decrypt_secret((string)$enc);
        return ($dec !== false) ? $dec : null;
    }

    /**
     * Get webhook ID for mode
     */
    public static function get_webhook_id(?string $mode = null): string {
        $s = self::get_settings();
        $m = $mode ?: $s['mode'];
        return ($m === 'live') ? (string)$s['live_webhook_id'] : (string)$s['sandbox_webhook_id'];
    }

    /**
     * Get configured merchant ID/identity if verified
     */
    public static function get_merchant_id(?string $mode = null): string {
        $s = self::get_settings();
        $m = $mode ?: $s['mode'];
        return ($m === 'live') ? (string)$s['live_merchant_id'] : (string)$s['sandbox_merchant_id'];
    }

    /**
     * Generate the official, public REST webhook listener URL
     */
    public static function get_webhook_url(): string {
        return rest_url('lfa/v1/paypal/webhook');
    }

    /**
     * Save settings from admin form with security validations
     */
    public static function save_admin_settings(array $input, int $actor_user_id = 0): array {
        if (!current_user_can(self::CAPABILITY)) {
            return ['success' => false, 'error' => 'Unauthorized: Insufficient permissions.'];
        }

        $current = self::get_settings();
        $new_mode = (isset($input['mode']) && $input['mode'] === 'live') ? 'live' : 'sandbox';
        $mode_changed = ($new_mode !== $current['mode']);

        $updated = $current;
        $updated['mode'] = $new_mode;

        // If mode changed, force payments disabled pending verification (P4-019)
        if ($mode_changed) {
            $updated['payments_enabled'] = 0;
            $updated['live_activation_confirmed'] = 0;
        } else {
            $updated['payments_enabled'] = !empty($input['payments_enabled']) ? 1 : 0;
            $updated['live_activation_confirmed'] = !empty($input['live_activation_confirmed']) ? 1 : 0;
        }

        // Live cannot be enabled without explicit confirmation
        if ($new_mode === 'live' && empty($updated['live_activation_confirmed'])) {
            $updated['payments_enabled'] = 0;
        }

        // Sandbox credentials
        if (isset($input['sandbox_client_id'])) {
            $updated['sandbox_client_id'] = sanitize_text_field(trim($input['sandbox_client_id']));
        }
        if (isset($input['sandbox_webhook_id'])) {
            $updated['sandbox_webhook_id'] = sanitize_text_field(trim($input['sandbox_webhook_id']));
        }
        if (isset($input['sandbox_merchant_id'])) {
            $updated['sandbox_merchant_id'] = sanitize_text_field(trim($input['sandbox_merchant_id']));
        }

        // Sandbox secret: if provided, encrypt; if blank, preserve existing (P4-024)
        if (!empty($input['sandbox_client_secret'])) {
            $enc = self::encrypt_secret(trim($input['sandbox_client_secret']));
            if ($enc === false) {
                return ['success' => false, 'error' => 'Master encryption key missing or invalid cipher. Failed closed.'];
            }
            $updated['sandbox_client_secret_enc'] = $enc;
        } elseif (isset($input['sandbox_clear_secret']) && $input['sandbox_clear_secret'] === '1') {
            $updated['sandbox_client_secret_enc'] = '';
        }

        // Live credentials
        if (isset($input['live_client_id'])) {
            $updated['live_client_id'] = sanitize_text_field(trim($input['live_client_id']));
        }
        if (isset($input['live_webhook_id'])) {
            $updated['live_webhook_id'] = sanitize_text_field(trim($input['live_webhook_id']));
        }
        if (isset($input['live_merchant_id'])) {
            $updated['live_merchant_id'] = sanitize_text_field(trim($input['live_merchant_id']));
        }

        // Live secret
        if (!empty($input['live_client_secret'])) {
            $enc = self::encrypt_secret(trim($input['live_client_secret']));
            if ($enc === false) {
                return ['success' => false, 'error' => 'Master encryption key missing or invalid cipher. Failed closed.'];
            }
            $updated['live_client_secret_enc'] = $enc;
        } elseif (isset($input['live_clear_secret']) && $input['live_clear_secret'] === '1') {
            $updated['live_client_secret_enc'] = '';
        }

        $updated['updated_at'] = current_time('mysql', true);
        $updated['updated_by'] = $actor_user_id ?: get_current_user_id();

        // Autoload disabled for security
        update_option(self::OPTION_NAME, $updated, false);

        // Audit log
        if (class_exists('LFA_Payment_Service')) {
            LFA_Payment_Service::log_audit('admin', $updated['updated_by'], 'settings_updated', 'paypal_settings', [
                'mode'             => $new_mode,
                'payments_enabled' => $updated['payments_enabled'],
                'mode_changed'     => $mode_changed,
            ]);
        }

        return ['success' => true, 'mode_changed' => $mode_changed];
    }

    /**
     * Record connection test result
     */
    public static function record_test_result(string $mode, array $result): void {
        $s = self::get_settings();
        if ($mode === 'live') {
            $s['live_last_test'] = $result;
        } else {
            $s['sandbox_last_test'] = $result;
        }
        update_option(self::OPTION_NAME, $s, false);
    }

    /**
     * Mask client ID for safe admin display (e.g. AaBb...1234)
     */
    public static function mask_client_id(string $id): string {
        $len = strlen($id);
        if ($len <= 8) {
            return $id === '' ? 'Not Configured' : '••••••••';
        }
        return substr($id, 0, 4) . '••••••••' . substr($id, -4);
    }
}
