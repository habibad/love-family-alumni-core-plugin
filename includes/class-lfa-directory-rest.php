<?php
/**
 * Love Family Alumni — Directory REST Controllers
 *
 * Implements secure REST API routes for Alumni and Business Directories
 * with strict permission callbacks and server-side privacy filtering.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Directory_REST {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        $namespace = 'lfa/v1';

        // 1. Alumni Directory: Protected Member query
        register_rest_route($namespace, '/alumni', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'handle_get_alumni'],
            'permission_callback' => [__CLASS__, 'check_directory_access'],
            'args'                => [
                'keyword'    => ['sanitize_callback' => 'sanitize_text_field'],
                'profession' => ['sanitize_callback' => 'sanitize_text_field'],
                'industry'   => ['sanitize_callback' => 'sanitize_text_field'],
                'country'    => ['sanitize_callback' => 'sanitize_text_field'],
                'location'   => ['sanitize_callback' => 'sanitize_text_field'],
                'class_year' => ['sanitize_callback' => 'sanitize_text_field'],
                'sort'       => ['sanitize_callback' => 'sanitize_key'],
                'page'       => ['sanitize_callback' => 'absint', 'default' => 1],
                'per_page'   => ['sanitize_callback' => 'absint', 'default' => 12],
            ],
        ]);

        // 2. Single Alumni Profile
        register_rest_route($namespace, '/alumni/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'handle_get_single_alumni'],
            'permission_callback' => [__CLASS__, 'check_directory_access'],
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => function($param) { return is_numeric($param); }],
            ],
        ]);

        // 3. Public Business Directory (Protected to Paid Members)
        register_rest_route($namespace, '/businesses', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'handle_get_businesses'],
            'permission_callback' => [__CLASS__, 'check_directory_access'],
            'args'                => [
                'keyword'  => ['sanitize_callback' => 'sanitize_text_field'],
                'category' => ['sanitize_callback' => 'sanitize_text_field'],
                'country'  => ['sanitize_callback' => 'sanitize_text_field'],
                'city'     => ['sanitize_callback' => 'sanitize_text_field'],
                'sort'     => ['sanitize_callback' => 'sanitize_key'],
                'page'     => ['sanitize_callback' => 'absint', 'default' => 1],
                'per_page' => ['sanitize_callback' => 'absint', 'default' => 12],
            ],
        ]);

        // 4. Single Business Details
        register_rest_route($namespace, '/businesses/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'handle_get_single_business'],
            'permission_callback' => [__CLASS__, 'check_single_business_access'],
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => function($param) { return is_numeric($param); }],
            ],
        ]);

        // 5. My Businesses (Logged in member only)
        register_rest_route($namespace, '/businesses/mine', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'handle_get_my_businesses'],
            'permission_callback' => [__CLASS__, 'check_logged_in_member'],
        ]);

        // 6. Create Business Draft (Paid member only)
        register_rest_route($namespace, '/businesses', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'handle_create_business'],
            'permission_callback' => [__CLASS__, 'check_paid_member'],
        ]);

        // 7. Update Business
        register_rest_route($namespace, '/businesses/(?P<id>\d+)', [
            'methods'             => ['POST', 'PATCH'],
            'callback'            => [__CLASS__, 'handle_update_business'],
            'permission_callback' => [__CLASS__, 'check_logged_in_member'],
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => function($param) { return is_numeric($param); }],
            ],
        ]);

        // 8. Submit Business for Review (Paid member only)
        register_rest_route($namespace, '/businesses/(?P<id>\d+)/submit', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'handle_submit_business'],
            'permission_callback' => [__CLASS__, 'check_paid_member'],
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => function($param) { return is_numeric($param); }],
            ],
        ]);

        // 9. Withdraw Business
        register_rest_route($namespace, '/businesses/(?P<id>\d+)/withdraw', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'handle_withdraw_business'],
            'permission_callback' => [__CLASS__, 'check_logged_in_member'],
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => function($param) { return is_numeric($param); }],
            ],
        ]);

        // 10. Moderator Review Action
        register_rest_route($namespace, '/businesses/(?P<id>\d+)/review', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'handle_review_business'],
            'permission_callback' => [__CLASS__, 'check_moderator_cap'],
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => function($param) { return is_numeric($param); }],
            ],
        ]);

        // 11. Upload Business Logo
        register_rest_route($namespace, '/businesses/(?P<id>\d+)/logo', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'handle_upload_logo'],
            'permission_callback' => [__CLASS__, 'check_logged_in_member'],
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => function($param) { return is_numeric($param); }],
            ],
        ]);
    }

    /**
     * Permission Callback: Any logged-in member
     */
    public static function check_logged_in_member(): bool {
        return is_user_logged_in() && LFA_Core::can_use_portal();
    }

    /**
     * Permission Callback: Approved member only
     */
    public static function check_approved_member(): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        $uid = get_current_user_id();
        return LFA_Core::is_approved($uid) || user_can($uid, 'manage_options');
    }

    /**
     * Permission Callback: Paid member only (Mandatory Client Policy)
     */
    public static function check_paid_member(): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        $uid = get_current_user_id();
        return class_exists('LFA_Payment_Entitlements')
            ? LFA_Payment_Entitlements::is_full_member($uid)
            : (LFA_Core::is_approved($uid) || user_can($uid, 'manage_options'));
    }

    /**
     * Permission Callback: Enforce Directory Access Control
     * Returns true for paid alumni members & administrators/moderators.
     * Returns WP_Error (401 for guests, 403 for unapproved/unpaid/expired).
     */
    public static function check_directory_access(WP_REST_Request $req = null): bool|WP_Error {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_unauthorized_directory',
                __('Directory access is restricted to verified, paid alumni members. Please log in.', 'love-family-core'),
                ['status' => 401]
            );
        }

        $uid = get_current_user_id();

        // Administrator and Moderator bypass
        if (user_can($uid, 'manage_options') || user_can($uid, 'edit_users') || current_user_can(LFA_Business::CAP_MANAGE)) {
            return true;
        }

        if (class_exists('LFA_Payment_Entitlements')) {
            $state = LFA_Payment_Entitlements::get_directory_access_state($uid);
            if (!$state['can_access']) {
                $message = match ($state['state']) {
                    'email_unverified' => __('Please verify your email address to access directory benefits.', 'love-family-core'),
                    'pending_approval' => __('Your alumni membership is pending administrator approval.', 'love-family-core'),
                    'expired'          => __('Your annual membership dues have expired. Please renew your membership to regain directory access.', 'love-family-core'),
                    default            => __('Directory access requires an active paid alumni membership ($100 annual dues).', 'love-family-core'),
                };

                return new WP_Error(
                    'rest_forbidden_directory',
                    $message,
                    ['status' => 403, 'access_state' => $state['state']]
                );
            }
        } elseif (!LFA_Core::is_approved($uid)) {
            return new WP_Error('rest_forbidden_directory', __('Membership approval required.', 'love-family-core'), ['status' => 403]);
        }

        return true;
    }

    /**
     * Permission Callback for single business detail route
     * Allows owner to see their own business, staff to manage, or paid members to view approved business
     */
    public static function check_single_business_access(WP_REST_Request $req): bool|WP_Error {
        $id = (int)$req->get_param('id');
        $uid = get_current_user_id();

        if ($uid > 0 && (user_can($uid, 'manage_options') || current_user_can(LFA_Business::CAP_MANAGE))) {
            return true;
        }

        if ($id > 0 && class_exists('LFA_Business')) {
            $biz = LFA_Business::get_business_by_id($id);
            if ($biz && $uid > 0 && (int)$biz->owner_user_id === $uid) {
                return true;
            }
        }

        return self::check_directory_access($req);
    }

    /**
     * Permission Callback: Business Moderator only
     */
    public static function check_moderator_cap(): bool {
        return current_user_can(LFA_Business::CAP_MANAGE);
    }

    /**
     * Handler: GET /alumni
     */
    public static function handle_get_alumni(WP_REST_Request $req): WP_REST_Response {
        $viewer_id = get_current_user_id();
        $params = $req->get_params();

        $result = LFA_Alumni_Directory::get_profiles($params, $viewer_id);

        $response = new WP_REST_Response($result, 200);
        $response->header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
        return $response;
    }

    /**
     * Handler: GET /alumni/{id}
     */
    public static function handle_get_single_alumni(WP_REST_Request $req): WP_REST_Response {
        $id = (int)$req->get_param('id');
        $viewer_id = get_current_user_id();

        $profile = LFA_Alumni_Directory::get_profile_by_id($id, $viewer_id);
        if (!$profile) {
            return new WP_REST_Response([
                'code'    => 'profile_not_found',
                'message' => 'Profile not found or inaccessible.',
            ], 404);
        }

        $response = new WP_REST_Response(['profile' => $profile], 200);
        $response->header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
        return $response;
    }

    /**
     * Handler: GET /businesses
     */
    public static function handle_get_businesses(WP_REST_Request $req): WP_REST_Response {
        $params = $req->get_params();
        $result = LFA_Business::query_public_businesses($params);

        // Sanitize contact info if not opted in
        $sanitized_rows = [];
        foreach ($result['businesses'] as $b) {
            $sanitized_rows[] = self::format_business_public($b);
        }
        $result['businesses'] = $sanitized_rows;

        $response = new WP_REST_Response($result, 200);
        $response->header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
        return $response;
    }

    /**
     * Handler: GET /businesses/{id}
     */
    public static function handle_get_single_business(WP_REST_Request $req): WP_REST_Response {
        $id = (int)$req->get_param('id');
        $biz = LFA_Business::get_business_by_id($id);
        if (!$biz) {
            return new WP_REST_Response(['code' => 'not_found', 'message' => 'Listing not found.'], 404);
        }

        $viewer_id = get_current_user_id();
        $is_owner = ($viewer_id > 0 && (int)$biz->owner_user_id === $viewer_id);
        $is_admin = user_can($viewer_id, LFA_Business::CAP_MANAGE);

        // Non-approved businesses can only be viewed by owner or admin
        if ($biz->status !== LFA_Business::STATUS_APPROVED && !$is_owner && !$is_admin) {
            return new WP_REST_Response(['code' => 'forbidden', 'message' => 'Listing is pending review or not published.'], 404);
        }

        $formatted = self::format_business_public($biz, $is_owner || $is_admin);
        return new WP_REST_Response(['business' => $formatted], 200);
    }

    /**
     * Handler: GET /businesses/mine
     */
    public static function handle_get_my_businesses(): WP_REST_Response {
        $user_id = get_current_user_id();
        $businesses = LFA_Business::get_user_businesses($user_id);

        $formatted = [];
        foreach ($businesses as $b) {
            $item = (array)$b;
            $item['logo_url'] = !empty($b->logo_file) ? LFA_Business::get_logo_url((int)$b->id) : '';
            $item['social_links'] = json_decode($b->social_links_json ?? '{}', true);
            $item['pending_changes'] = json_decode($b->pending_changes_json ?? '{}', true);
            $formatted[] = $item;
        }

        $response = new WP_REST_Response(['businesses' => $formatted], 200);
        $response->header('Cache-Control', 'private, no-cache, no-store');
        return $response;
    }

    /**
     * Handler: POST /businesses (Create draft)
     */
    public static function handle_create_business(WP_REST_Request $req): WP_REST_Response {
        $user_id = get_current_user_id();
        $params = $req->get_json_params() ?: $req->get_body_params();

        // Enforce server session owner: ignore any client-supplied owner_user_id
        $result = LFA_Business::create_business($params, $user_id);

        if (!$result['success']) {
            $status = ($result['code'] === 'FORBIDDEN' || $result['code'] === 'MEMBERSHIP_DUES_REQUIRED' || $result['code'] === 'ACCOUNT_NOT_APPROVED') ? 403 : 400;
            return new WP_REST_Response($result, $status);
        }

        return new WP_REST_Response($result, 201);
    }

    /**
     * Handler: POST /businesses/{id} (Update)
     */
    public static function handle_update_business(WP_REST_Request $req): WP_REST_Response {
        $id = (int)$req->get_param('id');
        $user_id = get_current_user_id();
        $params = $req->get_json_params() ?: $req->get_body_params();

        $result = LFA_Business::update_business($id, $params, $user_id);
        if (!$result['success']) {
            $status = ($result['code'] === 'FORBIDDEN' || $result['code'] === 'FORBIDDEN_STATUS') ? 403 : 400;
            return new WP_REST_Response($result, $status);
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Handler: POST /businesses/{id}/submit
     */
    public static function handle_submit_business(WP_REST_Request $req): WP_REST_Response {
        $id = (int)$req->get_param('id');
        $user_id = get_current_user_id();

        $result = LFA_Business::submit_for_review($id, $user_id);
        if (!$result['success']) {
            $status = ($result['code'] === 'FORBIDDEN' || $result['code'] === 'MEMBERSHIP_DUES_REQUIRED') ? 403 : 400;
            return new WP_REST_Response($result, $status);
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Handler: POST /businesses/{id}/withdraw
     */
    public static function handle_withdraw_business(WP_REST_Request $req): WP_REST_Response {
        $id = (int)$req->get_param('id');
        $user_id = get_current_user_id();

        $result = LFA_Business::withdraw_business($id, $user_id);
        if (!$result['success']) {
            $status = ($result['code'] === 'FORBIDDEN') ? 403 : 400;
            return new WP_REST_Response($result, $status);
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Handler: POST /businesses/{id}/review (Moderator)
     */
    public static function handle_review_business(WP_REST_Request $req): WP_REST_Response {
        $id = (int)$req->get_param('id');
        $params = $req->get_json_params() ?: $req->get_body_params();

        $action = sanitize_key($params['action'] ?? '');
        $notes  = sanitize_textarea_field($params['notes'] ?? '');

        $result = LFA_Business::review_business($id, $action, $notes, get_current_user_id());
        if (!$result['success']) {
            return new WP_REST_Response($result, 400);
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Handler: POST /businesses/{id}/logo
     */
    public static function handle_upload_logo(WP_REST_Request $req): WP_REST_Response {
        $id = (int)$req->get_param('id');
        $user_id = get_current_user_id();
        $files = $req->get_file_params();

        if (empty($files['logo'])) {
            return new WP_REST_Response(['code' => 'no_file', 'error' => 'No logo file provided.'], 400);
        }

        $result = LFA_Business::handle_logo_upload($id, $files['logo'], $user_id);
        if (!$result['success']) {
            return new WP_REST_Response($result, 400);
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Format a business record for public consumption
     */
    private static function format_business_public(stdClass|array $b, bool $privileged = false): array {
        $b = (object)$b;
        $owner = get_userdata((int)$b->owner_user_id);
        $owner_display = $owner ? $owner->display_name : 'Verified Alumnus';

        $has_consent = (bool)$b->contact_consent;

        return [
            'id'            => (int)$b->id,
            'business_ref'  => $b->business_ref,
            'name'          => $b->name,
            'slug'          => $b->slug,
            'category'      => $b->category,
            'description'   => $b->description,
            'country'       => $b->country,
            'state'         => $b->state,
            'city'          => $b->city,
            'website_url'   => $b->website_url,
            'public_email'  => ($has_consent || $privileged) ? $b->public_email : '',
            'public_phone'  => ($has_consent || $privileged) ? $b->public_phone : '',
            'owner_name'    => $owner_display,
            'logo_url'      => !empty($b->logo_file) ? LFA_Business::get_logo_url((int)$b->id) : '',
            'social_links'  => json_decode($b->social_links_json ?? '{}', true),
            'status'        => $b->status,
            'approved_at'   => $b->approved_at,
            'moderation_notes' => $privileged ? $b->moderation_notes : '',
        ];
    }
}
