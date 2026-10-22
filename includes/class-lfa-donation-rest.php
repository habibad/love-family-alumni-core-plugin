<?php
/**
 * Love Family Alumni — Donations & Giving REST API
 *
 * Implements public/member REST endpoints for giving funds, projects,
 * donation intents, my donations history, and verified receipts.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Donation_REST {

    public const REST_NAMESPACE = 'lfa/v1';

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        // 1. Get published giving funds & projects (Public)
        register_rest_route(self::REST_NAMESPACE, '/give/funds', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_get_funds'],
            'permission_callback' => '__return_true',
        ]);

        // 2. Create donation intent (Member or Guest if policy allows)
        register_rest_route(self::REST_NAMESPACE, '/give/intent', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'rest_create_intent'],
            'permission_callback' => [__CLASS__, 'check_intent_permissions'],
        ]);

        // 3. Current user's donation history (Authenticated Member)
        register_rest_route(self::REST_NAMESPACE, '/give/my-donations', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_get_my_donations'],
            'permission_callback' => static function () {
                return is_user_logged_in();
            },
        ]);

        // 4. Genuine receipt download / JSON (Owner or Financial Admin)
        register_rest_route(self::REST_NAMESPACE, '/give/receipt/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_get_receipt'],
            'permission_callback' => [__CLASS__, 'check_receipt_permissions'],
            'args'                => [
                'id' => [
                    'validate_callback' => static function ($param) {
                        return is_numeric($param) && (int)$param > 0;
                    },
                ],
            ],
        ]);

        // 5. Financial reports & totals (Authorized Financial Staff only)
        register_rest_route(self::REST_NAMESPACE, '/give/reports', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_get_reports'],
            'permission_callback' => static function () {
                return current_user_can(LFA_Donation::CAP_VIEW_FINANCIALS);
            },
        ]);
    }

    /**
     * Permission callback for intent creation: checks guest giving policy
     */
    public static function check_intent_permissions(WP_REST_Request $request): bool {
        // CSRF verification for logged in users
        if (is_user_logged_in()) {
            return true;
        }

        // Check if the targeted fund allows guest giving
        $fund_id = (int)$request->get_param('fund_id');
        $fund_slug = sanitize_title((string)$request->get_param('fund_slug'));

        $fund = $fund_id > 0 ? LFA_Donation::get_fund_by_id($fund_id) : LFA_Donation::get_fund_by_slug($fund_slug);
        if ($fund && (int)$fund->guest_giving_enabled === 1) {
            return true;
        }

        // If not logged in and guest giving is not explicitly enabled, require authentication
        return false;
    }

    /**
     * Permission callback for receipt retrieval
     */
    public static function check_receipt_permissions(WP_REST_Request $request): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        $id = (int)$request->get_param('id');
        $donation = LFA_Donation::get_donation_by_id($id);
        if (!$donation) {
            return false;
        }

        $current_user_id = get_current_user_id();
        if ((int)$donation->donor_user_id === $current_user_id) {
            return true;
        }

        return current_user_can(LFA_Donation::CAP_VIEW_FINANCIALS);
    }

    /**
     * Endpoint 1: GET /lfa/v1/give/funds
     */
    public static function rest_get_funds(WP_REST_Request $request): WP_REST_Response {
        $funds = LFA_Donation::get_published_funds();
        $response_data = [];

        foreach ($funds as $f) {
            $projects = LFA_Donation::get_published_projects((int)$f->id);
            $clean_projects = [];
            foreach ($projects as $p) {
                $clean_projects[] = [
                    'id'                => (int)$p->id,
                    'slug'              => $p->slug,
                    'title'             => $p->title,
                    'description'       => $p->description,
                    'project_type'      => $p->project_type,
                    'goal_amount_cents' => $p->goal_amount_cents ? (int)$p->goal_amount_cents : null,
                ];
            }

            $response_data[] = [
                'id'                   => (int)$f->id,
                'slug'                 => $f->slug,
                'title'                => $f->title,
                'description'          => $f->description,
                'icon'                 => $f->icon,
                'objective'            => $f->objective,
                'account_category'     => $f->account_category,
                'min_amount_cents'     => (int)$f->min_amount_cents,
                'max_amount_cents'     => (int)$f->max_amount_cents,
                'guest_giving_enabled' => (int)$f->guest_giving_enabled === 1,
                'projects'             => $clean_projects,
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'funds'   => $response_data,
        ], 200);
    }

    /**
     * Endpoint 2: POST /lfa/v1/give/intent
     */
    public static function rest_create_intent(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $params = $request->get_json_params() ?: $request->get_body_params();

        // Idempotency support
        $idempotency_key = sanitize_text_field($request->get_header('x-idempotency-key') ?: ($params['idempotency_key'] ?? ''));
        if (!empty($idempotency_key)) {
            $params['idempotency_key'] = $idempotency_key;
        }

        $res = LFA_Donation::create_donation_intent($params, $user_id);
        if (!$res['success']) {
            $status_code = match($res['code'] ?? '') {
                'GUEST_GIVING_DISABLED', 'FORBIDDEN' => 403,
                'FUND_NOT_FOUND', 'FUND_NOT_PUBLISHED' => 404,
                default => 400,
            };
            return new WP_REST_Response([
                'success' => false,
                'code'    => $res['code'] ?? 'ERROR',
                'message' => $res['error'] ?? 'Donation intent creation failed.',
            ], $status_code);
        }

        $res['payments_enabled']    = class_exists('LFA_Gateway_Settings') ? (LFA_Gateway_Settings::is_stripe_test_enabled() || LFA_Gateway_Settings::is_paypal_enabled()) : LFA_PayPal_Settings::is_payments_enabled();
        $res['stripe_test_enabled'] = class_exists('LFA_Gateway_Settings') && LFA_Gateway_Settings::is_stripe_test_enabled();
        $res['paypal_enabled']      = class_exists('LFA_Gateway_Settings') ? LFA_Gateway_Settings::is_paypal_enabled() : LFA_PayPal_Settings::is_payments_enabled();
        $res['default_gateway']     = class_exists('LFA_Gateway_Settings') ? LFA_Gateway_Settings::get_default_testing_gateway() : 'stripe';
        $res['paypal_mode']         = LFA_PayPal_Settings::get_mode();
        if (!$res['payments_enabled']) {
            $res['gateway_notice'] = 'Online payment processing is currently disabled or pending gateway credential configuration.';
        }

        return new WP_REST_Response($res, 201);
    }

    /**
     * Endpoint 3: GET /lfa/v1/give/my-donations
     */
    public static function rest_get_my_donations(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $donations = LFA_Donation::get_user_donations($user_id);
        $output = [];

        foreach ($donations as $d) {
            $output[] = [
                'id'                => (int)$d->id,
                'donation_ref'      => $d->donation_ref,
                'purpose'           => $d->purpose_snapshot,
                'amount_cents'      => (int)$d->amount_cents,
                'amount_dollars'    => number_format($d->amount_cents / 100, 2),
                'currency'          => $d->currency,
                'status'            => $d->status,
                'is_anonymous'      => (int)$d->is_anonymous === 1,
                'paypal_capture_id' => $d->paypal_capture_id ?: null,
                'paid_at'           => $d->paid_at ?: null,
                'created_at'        => $d->created_at,
            ];
        }

        return new WP_REST_Response([
            'success'   => true,
            'donations' => $output,
        ], 200);
    }

    /**
     * Endpoint 4: GET /lfa/v1/give/receipt/{id}
     */
    public static function rest_get_receipt(WP_REST_Request $request): WP_REST_Response {
        $id = (int)$request->get_param('id');
        $donation = LFA_Donation::get_donation_by_id($id);

        if (!$donation) {
            return new WP_REST_Response(['success' => false, 'error' => 'Donation record not found.'], 404);
        }

        if ($donation->status !== LFA_Donation::STATUS_PAID) {
            return new WP_REST_Response([
                'success' => false,
                'code'    => 'RECEIPT_UNAVAILABLE',
                'error'   => 'Receipt unavailable for unconfirmed or unpaid donation intent.',
            ], 400);
        }

        $receipt = [
            'success'             => true,
            'receipt_number'      => $donation->donation_ref,
            'date_paid'           => $donation->paid_at,
            'donor_display_name'  => $donation->is_anonymous ? 'Anonymous Donor' : $donation->donor_display_name,
            'designated_cause'    => $donation->purpose_snapshot,
            'amount_cents'        => (int)$donation->amount_cents,
            'amount_dollars'      => number_format($donation->amount_cents / 100, 2),
            'currency'            => $donation->currency,
            'payment_method'      => 'PayPal (Orders v2 Server Capture)',
            'paypal_capture_id'   => $donation->paypal_capture_id,
            'tax_deduction_note'  => 'Official 501(c)(3) tax deductibility statements are pending executive governance confirmation.',
            'issuing_entity'      => 'Love Family Alumni — North America Zone',
        ];

        return new WP_REST_Response($receipt, 200);
    }

    /**
     * Endpoint 5: GET /lfa/v1/give/reports
     */
    public static function rest_get_reports(WP_REST_Request $request): WP_REST_Response {
        $totals = LFA_Donation::get_donation_totals();
        return new WP_REST_Response([
            'success' => true,
            'totals'  => $totals,
        ], 200);
    }
}
