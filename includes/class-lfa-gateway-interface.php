<?php
/**
 * Love Family Alumni — Common Payment Gateway Interface
 *
 * Defines the authoritative contract for payment gateways (PayPal, Stripe, etc.).
 * Guarantees provider-agnostic validation, session/order creation, status retrieval,
 * webhook verification, and settlement.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

interface LFA_Payment_Gateway_Interface {
    /**
     * Get unique provider identifier (e.g. 'paypal', 'stripe')
     */
    public function get_id(): string;

    /**
     * Get human-readable gateway title
     */
    public function get_title(): string;

    /**
     * Check if gateway is currently enabled
     */
    public function is_enabled(): bool;

    /**
     * Get current environment ('test', 'sandbox', or 'live')
     */
    public function get_environment(): string;

    /**
     * Test connection to provider API
     */
    public function test_connection(): array;

    /**
     * Validate payable object before initiating checkout
     */
    public function validate_payable(int $invoice_id, int $user_id, string $payable_type = 'membership_due'): array;

    /**
     * Create payment session or order server-side
     */
    public function create_payment_session(object $payable, int $user_id, string $payable_type, array $context = []): array;

    /**
     * Retrieve current payment status from provider
     */
    public function retrieve_payment_status(string $provider_payment_id): array;

    /**
     * Verify incoming webhook request signature
     */
    public function verify_webhook(array $headers, string $raw_body): array;

    /**
     * Process verified webhook event
     */
    public function handle_webhook_event(array $event_data, array $headers, string $raw_body): array;

    /**
     * Authoritative capture / reconcile of a payment
     */
    public function capture_or_reconcile(string $provider_payment_id, int $user_id, string $request_key = '', string $payable_type = 'membership_due'): array;
}
