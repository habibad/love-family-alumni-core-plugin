<?php
/**
 * Love Family Alumni — PayPal Settings (compatibility facade)
 *
 * Historically PayPal configuration lived in its own option (`lfa_paypal_settings`)
 * while the admin UI saved to `lfa_gateway_settings`. Two stores drifted apart and the
 * runtime (API client / payment service) read the wrong one.
 *
 * This class is now a thin, read-through facade over LFA_Gateway_Settings — the single
 * source of truth for mode, credentials, webhook IDs and enablement. The public API is
 * unchanged so existing callers keep working.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_PayPal_Settings {
    public const OPTION_NAME = 'lfa_paypal_settings';
    public const CAPABILITY  = 'lfa_manage_paypal_settings';
    public const CIPHER      = 'aes-256-gcm';

    /* ---------- Encryption (unified key store) ---------- */

    public static function get_master_key(): ?string {
        return LFA_Gateway_Settings::get_master_key();
    }

    public static function encrypt_secret(string $plaintext): string|false {
        return LFA_Gateway_Settings::encrypt_secret($plaintext);
    }

    public static function decrypt_secret(string $ciphertext): string|false {
        return LFA_Gateway_Settings::decrypt_secret($ciphertext);
    }

    /* ---------- Read API ---------- */

    /**
     * Legacy-shaped view of the current PayPal configuration.
     */
    public static function get_settings(): array {
        $g = LFA_Gateway_Settings::get_settings();
        return [
            'mode'                      => LFA_Gateway_Settings::get_paypal_mode(),
            'payments_enabled'          => (int) $g['paypal_enabled'],
            'live_activation_confirmed' => (int) $g['paypal_live_confirmed'],
            'sandbox_client_id'         => (string) $g['paypal_sandbox_client_id'],
            'sandbox_client_secret_enc' => (string) $g['paypal_sandbox_secret_enc'],
            'sandbox_webhook_id'        => (string) $g['paypal_sandbox_webhook_id'],
            'sandbox_merchant_id'       => (string) $g['paypal_sandbox_merchant_id'],
            'sandbox_last_test'         => $g['paypal_sandbox_last_test'] ?? null,
            'live_client_id'            => (string) $g['paypal_live_client_id'],
            'live_client_secret_enc'    => (string) $g['paypal_live_secret_enc'],
            'live_webhook_id'           => (string) $g['paypal_live_webhook_id'],
            'live_merchant_id'          => (string) $g['paypal_live_merchant_id'],
            'live_last_test'            => $g['paypal_live_last_test'] ?? null,
            'updated_at'                => $g['updated_at'] ?? null,
            'updated_by'                => $g['updated_by'] ?? null,
        ];
    }

    public static function get_default_settings(): array {
        return self::get_settings();
    }

    /**
     * PayPal is usable: enabled, credentials decrypt, live gated by confirmations, kill switch off.
     */
    public static function is_payments_enabled(): bool {
        return LFA_Gateway_Settings::is_paypal_enabled();
    }

    public static function get_mode(): string {
        return LFA_Gateway_Settings::get_paypal_mode();
    }

    public static function is_live(): bool {
        return self::get_mode() === 'live' && self::is_payments_enabled();
    }

    public static function get_client_id(?string $mode = null): string {
        return LFA_Gateway_Settings::get_paypal_client_id($mode ?: self::get_mode());
    }

    public static function get_secret(?string $mode = null): ?string {
        return LFA_Gateway_Settings::get_paypal_secret($mode ?: self::get_mode());
    }

    public static function get_webhook_id(?string $mode = null): string {
        return LFA_Gateway_Settings::get_paypal_webhook_id($mode ?: self::get_mode());
    }

    public static function get_merchant_id(?string $mode = null): string {
        return LFA_Gateway_Settings::get_paypal_merchant_id($mode ?: self::get_mode());
    }

    public static function get_webhook_url(): string {
        return LFA_Gateway_Settings::get_paypal_webhook_url();
    }

    /* ---------- Write API (routes into the unified store) ---------- */

    /**
     * Legacy save entry point — writes PayPal fields into lfa_gateway_settings.
     */
    public static function save_admin_settings(array $input, int $actor_user_id = 0): array {
        if (!current_user_can(self::CAPABILITY) && !current_user_can('manage_options')) {
            return ['success' => false, 'error' => 'Unauthorized: Insufficient permissions.'];
        }

        $g        = LFA_Gateway_Settings::get_settings();
        $new_mode = (isset($input['mode']) && $input['mode'] === 'live') ? 'live' : 'sandbox';
        $changed  = $new_mode !== LFA_Gateway_Settings::get_paypal_mode();

        $g['paypal_mode'] = $new_mode;
        if ($changed) {
            $g['paypal_enabled']        = 0;
            $g['paypal_live_confirmed'] = 0;
        } else {
            $g['paypal_enabled']        = !empty($input['payments_enabled']) ? 1 : 0;
            $g['paypal_live_confirmed'] = !empty($input['live_activation_confirmed']) ? 1 : 0;
        }
        if ($new_mode === 'live' && empty($g['paypal_live_confirmed'])) {
            $g['paypal_enabled'] = 0;
        }

        foreach (['sandbox', 'live'] as $m) {
            foreach (['client_id', 'webhook_id', 'merchant_id'] as $f) {
                if (isset($input["{$m}_{$f}"])) {
                    $g["paypal_{$m}_{$f}"] = sanitize_text_field(trim((string) $input["{$m}_{$f}"]));
                }
            }
            if (!empty($input["{$m}_client_secret"])) {
                $enc = self::encrypt_secret(trim((string) $input["{$m}_client_secret"]));
                if ($enc === false) {
                    return ['success' => false, 'error' => 'Master encryption key missing or invalid cipher. Failed closed.'];
                }
                $g["paypal_{$m}_secret_enc"] = $enc;
            } elseif (($input["{$m}_clear_secret"] ?? '') === '1') {
                $g["paypal_{$m}_secret_enc"] = '';
            }
        }

        $g['updated_at'] = current_time('mysql', true);
        $g['updated_by'] = $actor_user_id ?: get_current_user_id();
        update_option(LFA_Gateway_Settings::OPTION_NAME, $g, false);
        update_option(self::OPTION_NAME, self::get_settings(), false);
        LFA_Gateway_Settings::flush_paypal_tokens();

        if (class_exists('LFA_Payment_Service')) {
            LFA_Payment_Service::log_audit('admin', (int) $g['updated_by'], 'settings_updated', 'paypal_settings', [
                'mode'             => $new_mode,
                'payments_enabled' => $g['paypal_enabled'],
                'mode_changed'     => $changed,
            ]);
        }

        return ['success' => true, 'mode_changed' => $changed];
    }

    public static function record_test_result(string $mode, array $result): void {
        LFA_Gateway_Settings::record_test_result('paypal', $mode, $result);
    }

    public static function mask_client_id(string $id): string {
        $len = strlen($id);
        if ($len <= 8) {
            return $id === '' ? 'Not Configured' : '••••••••';
        }
        return substr($id, 0, 4) . '••••••••' . substr($id, -4);
    }
}
