<?php
/**
 * Love Family Alumni — PayPal Gateway Provider Implementation
 *
 * Implements LFA_Payment_Gateway_Interface wrapping existing PayPal Orders v2 client,
 * capture flows, and webhook verification postbacks.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_PayPal_Gateway implements LFA_Payment_Gateway_Interface {
    public function get_id(): string {
        return 'paypal';
    }

    public function get_title(): string {
        $env = strtoupper($this->get_environment());
        return "PayPal ({$env})";
    }

    public function is_enabled(): bool {
        return LFA_Gateway_Settings::is_paypal_enabled();
    }

    public function get_environment(): string {
        $s = LFA_Gateway_Settings::get_settings();
        return ($s['paypal_mode'] === 'live') ? 'live' : 'sandbox';
    }

    public function test_connection(): array {
        $mode = $this->get_environment();
        $res = LFA_PayPal_Client::test_connection($mode);
        LFA_Gateway_Settings::record_test_result('paypal', $mode, $res);
        return $res;
    }

    public function validate_payable(int $invoice_id, int $user_id, string $payable_type = 'membership_due'): array {
        return LFA_Payment_Service::validate_invoice_for_checkout($invoice_id, $user_id, $payable_type);
    }

    public function create_payment_session(object $payable, int $user_id, string $payable_type, array $context = []): array {
        $request_key = $context['request_key'] ?? '';
        $invoice_id = (int) $payable->id;
        return LFA_Payment_Service::create_order($invoice_id, $user_id, $request_key, $payable_type);
    }

    public function retrieve_payment_status(string $provider_payment_id): array {
        $mode = $this->get_environment();
        return LFA_PayPal_Client::get_order($provider_payment_id, $mode);
    }

    public function verify_webhook(array $headers, string $raw_body): array {
        $mode = $this->get_environment();
        return LFA_PayPal_Client::verify_webhook_signature($headers, $raw_body, $mode);
    }

    public function handle_webhook_event(array $event_data, array $headers, string $raw_body): array {
        return LFA_Payment_Service::handle_webhook($headers, $raw_body);
    }

    public function capture_or_reconcile(string $provider_payment_id, int $user_id, string $request_key = '', string $payable_type = 'membership_due'): array {
        return LFA_Payment_Service::capture_and_settle($provider_payment_id, $user_id, $request_key, $payable_type);
    }
}
