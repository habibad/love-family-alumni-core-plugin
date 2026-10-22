<?php
/**
 * Love Family Alumni — Central Payment Gateway Orchestrator
 *
 * Coordinates gateway selection, provider routing (PayPal vs Stripe Test),
 * environment isolation, kill switch enforcement, and checkout session dispatching.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Payment_Orchestrator {
    private static array $gateways = [];
    private static bool $initialized = false;

    /**
     * Initialize registered payment gateway providers
     */
    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        self::register_gateway(new LFA_PayPal_Gateway());
        self::register_gateway(new LFA_Stripe_Gateway());

        self::$initialized = true;
    }

    /**
     * Register a gateway implementation
     */
    public static function register_gateway(LFA_Payment_Gateway_Interface $gateway): void {
        self::$gateways[$gateway->get_id()] = $gateway;
    }

    /**
     * Retrieve gateway instance by ID
     */
    public static function get_gateway(string $id): ?LFA_Payment_Gateway_Interface {
        self::init();
        return self::$gateways[$id] ?? null;
    }

    /**
     * Get all registered gateway instances
     */
    public static function get_all_gateways(): array {
        self::init();
        return self::$gateways;
    }

    /**
     * Retrieve array of currently enabled gateways
     */
    public static function get_enabled_gateways(): array {
        self::init();
        if (LFA_Gateway_Settings::is_global_kill_switch_active()) {
            return [];
        }

        $active = [];
        foreach (self::$gateways as $id => $gateway) {
            if ($gateway->is_enabled()) {
                $active[$id] = $gateway;
            }
        }
        return $active;
    }

    /**
     * Resolve active gateway for a checkout attempt
     */
    public static function resolve_gateway(?string $requested_gateway = null): ?LFA_Payment_Gateway_Interface {
        self::init();
        if (LFA_Gateway_Settings::is_global_kill_switch_active()) {
            return null;
        }

        $enabled = self::get_enabled_gateways();
        if (empty($enabled)) {
            return null;
        }

        if (!empty($requested_gateway) && isset($enabled[$requested_gateway])) {
            return $enabled[$requested_gateway];
        }

        $default_id = LFA_Gateway_Settings::get_default_testing_gateway();
        if (isset($enabled[$default_id])) {
            return $enabled[$default_id];
        }

        // Return first enabled gateway
        return reset($enabled) ?: null;
    }

    /**
     * Authoritative Gateway-Agnostic Checkout Session Creation
     */
    public static function create_session(int $invoice_id, int $user_id, string $payable_type = 'membership_due', ?string $gateway_id = null): array {
        if (LFA_Gateway_Settings::is_global_kill_switch_active()) {
            return [
                'success' => false,
                'code'    => 'KILL_SWITCH_ACTIVE',
                'error'   => 'Payment processing is temporarily disabled across all gateways by administrator policy.',
            ];
        }

        $gateway = self::resolve_gateway($gateway_id);
        if (!$gateway) {
            return [
                'success' => false,
                'code'    => 'NO_GATEWAY_AVAILABLE',
                'error'   => 'No payment gateway is currently enabled or ready to process transactions.',
            ];
        }

        // Validate payable
        $check = $gateway->validate_payable($invoice_id, $user_id, $payable_type);
        if (!$check['valid']) {
            return [
                'success' => false,
                'code'    => $check['code'] ?? 'VALIDATION_FAILED',
                'error'   => $check['error'] ?? 'Invoice validation failed.',
            ];
        }

        $payable = $check['invoice'];
        $res = $gateway->create_payment_session($payable, $user_id, $payable_type, [
            'request_key' => wp_generate_uuid4(),
        ]);

        if (!empty($res['success'])) {
            $res['gateway'] = $gateway->get_id();
            $res['environment'] = $gateway->get_environment();
        }

        return $res;
    }

    /**
     * High-level Gateway Overview Status for Dashboards & Admin
     */
    public static function get_status_overview(): array {
        $s = LFA_Gateway_Settings::get_settings();
        $paypal_enabled = LFA_Gateway_Settings::is_paypal_enabled();
        $stripe_enabled = LFA_Gateway_Settings::is_stripe_test_enabled();
        $kill_switch    = LFA_Gateway_Settings::is_global_kill_switch_active();

        return [
            'kill_switch_active'      => $kill_switch,
            'default_testing_gateway' => $s['default_testing_gateway'],
            'paypal'                  => [
                'enabled'      => (int)$s['paypal_enabled'] === 1,
                'ready'        => $paypal_enabled,
                'mode'         => $s['paypal_mode'],
                'client_id'    => LFA_Gateway_Settings::mask_key($s['paypal_sandbox_client_id'] ?: $s['paypal_live_client_id']),
                'last_test'    => ($s['paypal_mode'] === 'live' ? $s['paypal_live_last_test'] : $s['paypal_sandbox_last_test']),
            ],
            'stripe'                  => [
                'enabled'      => (int)$s['stripe_enabled'] === 1,
                'ready'        => $stripe_enabled,
                'mode'         => 'test',
                'pubkey'       => LFA_Gateway_Settings::mask_key(LFA_Gateway_Settings::get_stripe_publishable_key(), 12, 4),
                'last_test'    => $s['stripe_test_last_test'],
                'last_webhook' => $s['stripe_test_last_webhook'],
            ],
        ];
    }
}
