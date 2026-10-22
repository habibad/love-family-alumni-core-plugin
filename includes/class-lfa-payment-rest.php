<?php
/**
 * Love Family Alumni — Payment REST API Endpoints
 *
 * Implements protected routes for PayPal order creation, client capture confirmation,
 * authenticated receipt downloads, and public verified webhook ingestion.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Payment_REST {
    public const NAMESPACE = 'lfa/v1';

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        // Create PayPal Order
        register_rest_route(self::NAMESPACE, '/payments/create-order', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_create_order'],
            'permission_callback' => [__CLASS__, 'check_auth_permission'],
            'args'                => [
                'invoice_id' => [
                    'required'          => true,
                    'validate_callback' => static fn($val) => is_numeric($val) && (int)$val > 0,
                ],
            ],
        ]);

        // Capture PayPal Order
        register_rest_route(self::NAMESPACE, '/payments/capture-order', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_capture_order'],
            'permission_callback' => [__CLASS__, 'check_auth_permission'],
            'args'                => [
                'order_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Unified Create Checkout Session (Stripe or PayPal)
        register_rest_route(self::NAMESPACE, '/payments/create-session', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_create_session'],
            'permission_callback' => [__CLASS__, 'check_create_session_permission'],
            'args'                => [
                'invoice_id' => [
                    'required'          => true,
                    'validate_callback' => static fn($val) => is_numeric($val) && (int)$val > 0,
                ],
            ],
        ]);

        // Reconcile Session upon Return from Stripe Hosted Checkout
        register_rest_route(self::NAMESPACE, '/payments/reconcile-session', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_reconcile_session'],
            'permission_callback' => [__CLASS__, 'check_reconcile_session_permission'],
            'args'                => [
                'session_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Public Stripe Webhook Receiver
        register_rest_route(self::NAMESPACE, '/stripe/webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_stripe_webhook'],
            'permission_callback' => '__return_true', // Signature is authorization
        ]);

        // Active Gateways Discovery (Public/Member)
        register_rest_route(self::NAMESPACE, '/payments/gateways', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_get_gateways'],
            'permission_callback' => '__return_true',
        ]);

        // Public PayPal Webhook Receiver
        register_rest_route(self::NAMESPACE, '/paypal/webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_webhook'],
            'permission_callback' => '__return_true', // Signature is authorization
        ]);

        // Authenticated Receipt View
        register_rest_route(self::NAMESPACE, '/payments/receipt/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_get_receipt'],
            'permission_callback' => [__CLASS__, 'check_auth_permission'],
        ]);
    }

    public static function check_auth_permission(WP_REST_Request $request): bool {
        return is_user_logged_in();
    }

    /**
     * Authoritative permission check for checkout session creation.
     * Supports both authenticated members and guest donors for pending donation intents.
     */
    public static function check_create_session_permission(WP_REST_Request $request): bool {
        $payable_type = sanitize_key((string)($request->get_param('payable_type') ?: 'membership_due'));

        if ($payable_type === 'donation') {
            $invoice_id = (int) $request->get_param('invoice_id');
            if ($invoice_id <= 0) {
                return false;
            }
            if (!class_exists('LFA_Donation')) {
                return false;
            }
            $donation = LFA_Donation::get_donation_by_id($invoice_id);
            if (!$donation || $donation->status !== LFA_Donation::STATUS_PENDING_PAYMENT) {
                return false;
            }

            // Authenticated donation: only the owner can initiate checkout
            if (!empty($donation->donor_user_id) && (int)$donation->donor_user_id > 0) {
                return get_current_user_id() === (int)$donation->donor_user_id;
            }

            // Guest donation: allowed for anyone with the valid pending donation ID
            return true;
        }

        // Membership dues and conference ticket purchases require authentication
        return is_user_logged_in();
    }

    /**
     * Permission check for post-checkout reconciliation.
     * Stripe session verification is performed cryptographically against the Stripe API.
     */
    public static function check_reconcile_session_permission(WP_REST_Request $request): bool {
        $payable_type = sanitize_key((string)($request->get_param('payable_type') ?: ''));
        if ($payable_type === 'donation' || empty($payable_type)) {
            // Guest donors returning from Stripe need to reconcile without being logged in
            return true;
        }
        return is_user_logged_in();
    }

    /**
     * Unified Checkout Session Creation
     */
    public static function handle_create_session(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $invoice_id = (int) $request->get_param('invoice_id');
        $payable_type = sanitize_key((string)($request->get_param('payable_type') ?: 'membership_due'));
        $gateway_id = sanitize_key((string)$request->get_param('gateway'));

        $res = LFA_Payment_Orchestrator::create_session($invoice_id, $user_id, $payable_type, $gateway_id ?: null);
        if (!$res['success']) {
            $code = $res['code'] ?? 'session_creation_failed';
            $status = ($code === 'FORBIDDEN' || $code === 'ACCOUNT_NOT_APPROVED') ? 403 : 400;
            return new WP_REST_Response([
                'success' => false,
                'code'    => $code,
                'message' => $res['error'],
            ], $status);
        }

        // Guarantee both session_url and checkout_url are present for contract consistency
        $checkout_url = $res['checkout_url'] ?? ($res['session_url'] ?? '');
        $res['checkout_url'] = $checkout_url;
        $res['session_url']  = $checkout_url;

        return new WP_REST_Response($res, 200);
    }

    /**
     * Reconcile Session upon Return from Stripe Hosted Checkout
     */
    public static function handle_reconcile_session(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $session_id = sanitize_text_field((string)$request->get_param('session_id'));
        $payable_type = sanitize_key((string)($request->get_param('payable_type') ?: 'membership_due'));

        $res = LFA_Payment_Reconciliation::settle_stripe_session($session_id, $user_id, $payable_type);
        if (!$res['success']) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $res['error'],
            ], 400);
        }

        return new WP_REST_Response($res, 200);
    }

    /**
     * Ingest Stripe Webhook
     */
    public static function handle_stripe_webhook(WP_REST_Request $request): WP_REST_Response {
        $headers = $request->get_headers();
        $raw_body = $request->get_body();

        $gateway = LFA_Payment_Orchestrator::get_gateway('stripe');
        if (!$gateway instanceof LFA_Stripe_Gateway) {
            return new WP_REST_Response(['status' => 500, 'error' => 'Stripe gateway not initialized.'], 500);
        }

        $res = $gateway->handle_webhook_event([], $headers, $raw_body);
        $status = $res['status'] ?? 200;

        return new WP_REST_Response([
            'status'  => $status,
            'message' => $res['message'] ?? ($res['error'] ?? 'Handled'),
        ], $status);
    }

    /**
     * Active Gateways Discovery
     */
    public static function handle_get_gateways(WP_REST_Request $request): WP_REST_Response {
        $overview = LFA_Payment_Orchestrator::get_status_overview();
        return new WP_REST_Response([
            'success'  => true,
            'overview' => $overview,
        ], 200);
    }

    /**
     * Handle Create Order Request from Dashboard (PayPal Legacy Route)
     */
    public static function handle_create_order(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $invoice_id = (int) $request->get_param('invoice_id');

        $res = LFA_Payment_Service::create_order($invoice_id, $user_id);
        if (!$res['success']) {
            $code = $res['code'] ?? 'order_creation_failed';
            $status = ($code === 'FORBIDDEN' || $code === 'ACCOUNT_NOT_APPROVED') ? 403 : 400;
            return new WP_REST_Response([
                'success' => false,
                'code'    => $code,
                'message' => $res['error'],
            ], $status);
        }

        return new WP_REST_Response([
            'success'  => true,
            'order_id' => $res['order_id'],
        ], 200);
    }

    /**
     * Handle Server Capture Order Request from Dashboard (PayPal Legacy Route)
     */
    public static function handle_capture_order(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $order_id = sanitize_text_field((string)$request->get_param('order_id'));

        $res = LFA_Payment_Service::capture_and_settle($order_id, $user_id);
        if (!$res['success']) {
            $status = !empty($res['pending']) ? 202 : 400;
            return new WP_REST_Response([
                'success' => false,
                'message' => $res['error'],
                'pending' => !empty($res['pending']),
            ], $status);
        }

        return new WP_REST_Response([
            'success'        => true,
            'status'         => 'COMPLETED',
            'invoice_number' => $res['invoice_number'],
            'capture_id'     => $res['capture_id'],
            'amount_dollars' => $res['amount_dollars'],
            'receipt_url'    => rest_url(self::NAMESPACE . '/payments/receipt/' . (int)($request->get_param('invoice_id') ?: 0)),
        ], 200);
    }

    /**
     * Ingest PayPal Webhook
     */
    public static function handle_webhook(WP_REST_Request $request): WP_REST_Response {
        $headers = $request->get_headers();
        $raw_body = $request->get_body();

        $res = LFA_Payment_Service::handle_webhook($headers, $raw_body);
        $status = $res['status'] ?? 200;

        return new WP_REST_Response([
            'status'  => $status,
            'message' => $res['message'] ?? ($res['error'] ?? 'Handled'),
        ], $status);
    }

    /**
     * View or Download Payment Receipt
     */
    public static function handle_get_receipt(WP_REST_Request $request): WP_REST_Response {
        $invoice_id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        $receipt = LFA_Payment_Service::get_receipt_data($invoice_id, $user_id);
        if (!$receipt) {
            return new WP_REST_Response(['error' => 'Receipt not found or unauthorized.'], 403);
        }

        if (!empty($receipt['error'])) {
            return new WP_REST_Response(['error' => $receipt['message']], 400);
        }

        // Return styled receipt HTML if requested via browser or JSON for API
        $format = $request->get_param('format') ?: 'html';
        if ($format === 'json') {
            return new WP_REST_Response(['success' => true, 'receipt' => $receipt], 200);
        }

        // Output clean printable HTML
        header('Content-Type: text/html; charset=UTF-8');
        echo self::render_receipt_html($receipt);
        exit;
    }

    public static function render_receipt_html(array $r): string {
        $num     = esc_html($r['invoice_number']);
        $name    = esc_html($r['member_name']);
        $email   = esc_html($r['member_email']);
        $type    = esc_html($r['membership_type']);
        $amount  = esc_html($r['amount_dollars']);
        $date    = esc_html($r['payment_date']);
        $ref     = esc_html($r['capture_id']);
        $gateway = esc_html($r['gateway'] ?? 'PayPal');
        $is_test = !empty($r['is_test']);

        $test_banner = $is_test ? <<<HTML
        <div style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:10px 16px;border-radius:6px;font-weight:700;font-size:12px;margin-bottom:20px;text-align:center;text-transform:uppercase;letter-spacing:0.5px;">
            ⚠️ STRIPE TEST MODE — NO REAL MONEY CHARGED • STAGING VERIFICATION RECEIPT
        </div>
HTML : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Payment Receipt - {$num}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; padding: 40px 20px; margin: 0; }
        .receipt-card { max-width: 640px; margin: 0 auto; background: #ffffff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); padding: 40px; border: 1px solid #e2e8f0; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #f1f5f9; padding-bottom: 24px; margin-bottom: 24px; }
        .logo { font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; }
        .logo span { color: #2563eb; }
        .pill-paid { background: #dcfce7; color: #15803d; font-size: 13px; font-weight: 700; padding: 4px 12px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.5px; }
        .section-title { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        .value { font-size: 15px; font-weight: 600; color: #0f172a; }
        .sub { font-size: 13px; color: #64748b; }
        .table-wrap { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
        .table-wrap th { text-align: left; padding: 12px; font-size: 12px; color: #64748b; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .table-wrap td { padding: 16px 12px; font-size: 14px; border-bottom: 1px solid #f1f5f9; }
        .total-row td { font-size: 18px; font-weight: 800; color: #0f172a; border-top: 2px solid #e2e8f0; border-bottom: none; }
        .footer { text-align: center; color: #94a3b8; font-size: 12px; border-top: 1px solid #f1f5f9; padding-top: 24px; }
        .btn-print { display: inline-block; background: #0f172a; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 600; margin-top: 20px; cursor: pointer; border: none; }
        @media print { .btn-print { display: none; } body { background: #fff; padding: 0; } .receipt-card { box-shadow: none; border: none; padding: 0; } }
    </style>
</head>
<body>
    <div class="receipt-card">
        {$test_banner}
        <div class="header">
            <div>
                <div class="logo">LOVE FAMILY <span>ALUMNI</span></div>
                <div class="sub">North America Zone — Official Receipt</div>
            </div>
            <div class="pill-paid">PAID</div>
        </div>

        <div class="grid">
            <div>
                <div class="section-title">Billed To</div>
                <div class="value">{$name}</div>
                <div class="sub">{$email}</div>
                <div class="sub">Membership: {$type}</div>
            </div>
            <div>
                <div class="section-title">Invoice & Payment Info</div>
                <div class="value">Invoice #{$num}</div>
                <div class="sub">Payment Date: {$date}</div>
                <div class="sub">Payment Gateway: {$gateway}</div>
                <div class="sub">Reference: {$ref}</div>
            </div>
        </div>

        <table class="table-wrap">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align: right;">Amount (USD)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Annual Membership Dues (Official Assessment)</td>
                    <td style="text-align: right; font-weight: 600;">\${$amount}</td>
                </tr>
                <tr class="total-row">
                    <td>Total Paid</td>
                    <td style="text-align: right;">\${$amount} USD</td>
                </tr>
            </tbody>
        </table>

        <div class="footer">
            <p>Love Family Alumni (North America Zone) • Official Electronic Ledger Record</p>
            <p>This document serves as an authentic receipt of payment for annual alumni dues.</p>
            <button class="btn-print" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
