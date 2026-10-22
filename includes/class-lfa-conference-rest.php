<?php
/**
 * Love Family Alumni — Conference REST API Controller
 *
 * Implements authenticated endpoints for conference registration,
 * status query, PayPal order creation, server capture, and receipt generation.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Conference_REST {
    public const NAMESPACE = 'lfa/v1';

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        // Public/Member Conference Information & Active Ticket Tiers
        register_rest_route(self::NAMESPACE, '/conference/config', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_get_config'],
            'permission_callback' => '__return_true',
        ]);

        // Member Conference Registration
        register_rest_route(self::NAMESPACE, '/conference/register', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_register'],
            'permission_callback' => [__CLASS__, 'check_register_permission'],
        ]);

        // Authenticated Member's Conference Registrations
        register_rest_route(self::NAMESPACE, '/conference/my-registrations', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_my_registrations'],
            'permission_callback' => [__CLASS__, 'check_auth_permission'],
        ]);

        // Authenticated Printable Conference Receipt
        register_rest_route(self::NAMESPACE, '/conference/receipt/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_get_receipt'],
            'permission_callback' => [__CLASS__, 'check_auth_permission'],
        ]);

        // Create PayPal Order for Conference Registration Invoice
        register_rest_route(self::NAMESPACE, '/conference/create-order', [
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

        // Capture PayPal Order for Conference Registration Invoice
        register_rest_route(self::NAMESPACE, '/conference/capture-order', [
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
    }

    public static function check_auth_permission(WP_REST_Request $request): bool {
        return is_user_logged_in();
    }

    public static function check_register_permission(WP_REST_Request $request): bool {
        // Logged-in users can register; guests only if guest registration is enabled
        if (is_user_logged_in()) {
            return true;
        }
        $conf_id = (int)$request->get_param('conference_id');
        $conf = $conf_id > 0 ? LFA_Conference::get_conference_by_id($conf_id) : LFA_Conference::get_conference();
        return $conf && (int)$conf->guest_registration_enabled === 1;
    }

    /**
     * Public Conference Configuration and Active Ticket Tiers
     */
    public static function handle_get_config(WP_REST_Request $request): WP_REST_Response {
        $conf_id = (int)$request->get_param('conference_id');
        $slug = sanitize_title((string)$request->get_param('slug'));
        if ($conf_id > 0) {
            $conf = LFA_Conference::get_conference_by_id($conf_id);
        } elseif (!empty($slug)) {
            $conf = LFA_Conference::get_conference_by_slug($slug);
        } else {
            $conf = LFA_Conference::get_conference();
        }

        if (!$conf) {
            return new WP_REST_Response(['error' => 'Conference record not found.'], 404);
        }

        $tickets = LFA_Conference::get_ticket_types((int)$conf->id, true);
        $ticket_data = [];
        foreach ($tickets as $t) {
            $ticket_data[] = [
                'id'             => (int)$t->id,
                'name'           => $t->name,
                'slug'           => $t->slug,
                'description'    => $t->description,
                'amount_cents'   => (int)$t->amount_cents,
                'amount_dollars' => number_format($t->amount_cents / 100, 2),
                'currency'       => $t->currency,
                'eligibility'    => $t->eligibility,
                'capacity'       => $t->capacity,
            ];
        }

        return new WP_REST_Response([
            'success'                    => true,
            'title'                      => $conf->title,
            'theme'                      => $conf->theme,
            'verse'                      => $conf->verse,
            'starts_at'                  => $conf->starts_at,
            'ends_at'                    => $conf->ends_at,
            'timezone'                   => $conf->timezone,
            'location_summary'           => $conf->location_summary,
            'registration_status'        => $conf->registration_status,
            'guest_registration_enabled' => (bool)$conf->guest_registration_enabled,
            'tickets'                    => $ticket_data,
        ], 200);
    }

    /**
     * Member / Guest Conference Registration Handler
     */
    public static function handle_register(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $res = LFA_Conference::create_registration($params, $user_id);
        if (!$res['success']) {
            $code = $res['code'] ?? 'REGISTRATION_FAILED';
            $status = ($code === 'ACCOUNT_NOT_APPROVED' || $code === 'GUEST_REGISTRATION_DISABLED') ? 403 : 400;
            return new WP_REST_Response([
                'success' => false,
                'code'    => $code,
                'message' => $res['error'],
            ], $status);
        }

        return new WP_REST_Response($res, 201);
    }

    /**
     * Authenticated Member's Own Conference Registrations
     */
    public static function handle_my_registrations(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        global $wpdb;

        $t_reg = LFA_Conference::table_registrations();
        $t_inv = LFA_Conference::table_invoices();
        $t_att = LFA_Conference::table_attendees();

        $registrations = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, i.id as invoice_id, i.invoice_number, i.status as invoice_status, i.amount_cents as invoice_amount_cents, i.paid_at as invoice_paid_at
             FROM {$t_reg} r
             LEFT JOIN {$t_inv} i ON r.id = i.registration_id
             WHERE r.owner_user_id = %d
             ORDER BY r.id DESC",
            $user_id
        ));

        $output = [];
        foreach ($registrations as $r) {
            $attendees = $wpdb->get_results($wpdb->prepare(
                "SELECT id, attendee_type, full_name, email, chapter, status FROM {$t_att} WHERE registration_id = %d ORDER BY id ASC",
                $r->id
            ));

            $output[] = [
                'id'                   => (int)$r->id,
                'registration_number'  => $r->registration_number,
                'ticket_name'          => $r->ticket_name_snapshot,
                'amount_cents'         => (int)$r->amount_cents_snapshot,
                'amount_dollars'       => number_format($r->amount_cents_snapshot / 100, 2),
                'currency'             => $r->currency,
                'status'               => $r->status,
                'created_at'           => $r->created_at,
                'invoice_id'           => $r->invoice_id ? (int)$r->invoice_id : null,
                'invoice_number'       => $r->invoice_number,
                'invoice_status'       => $r->invoice_status,
                'attendees'            => $attendees,
                'receipt_url'          => ($r->status === LFA_Conference::STATUS_CONFIRMED) ? rest_url(self::NAMESPACE . '/conference/receipt/' . $r->id) : null,
            ];
        }

        return new WP_REST_Response(['success' => true, 'registrations' => $output], 200);
    }

    /**
     * Authenticated Printable Conference Receipt
     */
    public static function handle_get_receipt(WP_REST_Request $request): WP_REST_Response {
        $reg_id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        $receipt = LFA_Conference::get_receipt_data($reg_id, $user_id);
        if (!$receipt) {
            return new WP_REST_Response(['error' => 'Receipt not found or unauthorized.'], 403);
        }

        if (!empty($receipt['error'])) {
            return new WP_REST_Response(['error' => $receipt['message']], 400);
        }

        $format = $request->get_param('format') ?: 'html';
        if ($format === 'json') {
            return new WP_REST_Response(['success' => true, 'receipt' => $receipt], 200);
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo LFA_Conference::render_receipt_html($receipt);
        exit;
    }

    /**
     * Create PayPal Order for Conference Invoice
     */
    public static function handle_create_order(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $invoice_id = (int) $request->get_param('invoice_id');

        $res = LFA_Payment_Service::create_order($invoice_id, $user_id, '', 'conference');
        if (!$res['success']) {
            $code = $res['code'] ?? 'ORDER_CREATION_FAILED';
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
     * Capture PayPal Order for Conference Invoice
     */
    public static function handle_capture_order(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $order_id = sanitize_text_field((string)$request->get_param('order_id'));

        $res = LFA_Payment_Service::capture_and_settle($order_id, $user_id, '', 'conference');
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
            'message'        => 'Conference registration confirmed and payment settled.',
        ], 200);
    }
}
