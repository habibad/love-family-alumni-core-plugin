<?php
/**
 * Love Family Alumni — Member Notification Center Service
 *
 * Implements member inboxes, read/unread status tracking, deduplication,
 * transactional vs marketing preference separation, and authorized broadcast workflows.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Notifications {

    public const SCHEMA_VERSION = '1.0.0';

    public static function init(): void {
        self::install_schema();
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('admin_post_lfa_mark_notification_read', [__CLASS__, 'handle_mark_read_post']);
        add_action('admin_post_lfa_mark_all_notifications_read', [__CLASS__, 'handle_mark_all_read_post']);
        add_action('admin_post_lfa_broadcast_notification', [__CLASS__, 'handle_broadcast_post']);
    }

    public static function table_notifications(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_member_notifications';
    }

    public static function install_schema(): void {
        global $wpdb;
        $table = self::table_notifications();
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `event_type` VARCHAR(100) NOT NULL,
            `subject_type` VARCHAR(100) NULL,
            `subject_id` BIGINT UNSIGNED NULL,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `action_url` VARCHAR(500) NULL,
            `dedupe_key` VARCHAR(150) NOT NULL DEFAULT '',
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `read_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `user_read_idx` (`user_id`, `is_read`),
            KEY `created_idx` (`created_at`),
            UNIQUE KEY `user_dedupe_uniq` (`user_id`, `dedupe_key`)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Create notification with deduplication (NOT-004, NOT-010)
     */
    public static function create_notification(
        int $user_id,
        string $event_type,
        string $title,
        string $message,
        string $action_url = '',
        string $dedupe_key = '',
        string $subject_type = '',
        int $subject_id = 0
    ): array {
        if ($user_id <= 0) {
            return ['success' => false, 'error' => 'Invalid user ID'];
        }

        global $wpdb;
        $table = self::table_notifications();

        // Fallback dedupe key if not provided
        if (empty($dedupe_key)) {
            $dedupe_key = md5("{$user_id}_{$event_type}_{$subject_type}_{$subject_id}_" . date('Ymd_H'));
        }

        // Check if duplicate exists (NOT-010)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE `user_id` = %d AND `dedupe_key` = %s",
            $user_id,
            $dedupe_key
        ));

        if ($existing) {
            return ['success' => true, 'id' => (int)$existing, 'deduplicated' => true];
        }

        $inserted = $wpdb->insert($table, [
            'user_id'      => $user_id,
            'event_type'   => sanitize_key($event_type),
            'subject_type' => sanitize_key($subject_type),
            'subject_id'   => $subject_id,
            'title'        => sanitize_text_field($title),
            'message'      => wp_kses_post($message),
            'action_url'   => esc_url_raw($action_url),
            'dedupe_key'   => sanitize_text_field($dedupe_key),
            'is_read'      => 0,
            'created_at'   => current_time('mysql'),
        ]);

        if (!$inserted) {
            return ['success' => false, 'error' => 'Failed to persist notification.'];
        }

        return ['success' => true, 'id' => $wpdb->insert_id, 'deduplicated' => false];
    }

    /**
     * Retrieve notifications for a specific user (NOT-001)
     */
    public static function get_user_notifications(int $user_id, int $limit = 50, int $offset = 0): array {
        global $wpdb;
        $table = self::table_notifications();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE `user_id` = %d ORDER BY `created_at` DESC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ), ARRAY_A);
    }

    /**
     * Get unread notification count for user (NOT-004)
     */
    public static function get_unread_count(int $user_id): int {
        global $wpdb;
        $table = self::table_notifications();
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE `user_id` = %d AND `is_read` = 0",
            $user_id
        ));
    }

    /**
     * Mark single notification as read with strict ownership check (NOT-003, NOT-005)
     */
    public static function mark_as_read(int $notification_id, int $user_id): array {
        global $wpdb;
        $table = self::table_notifications();

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE `id` = %d", $notification_id), ARRAY_A);
        if (!$row) {
            return ['success' => false, 'error' => 'Notification not found'];
        }

        // NOT-003: Member B cannot mark Member A notification as read
        if ((int)$row['user_id'] !== $user_id && !user_can($user_id, 'administrator')) {
            return ['success' => false, 'error' => 'Unauthorized: Cannot modify another member’s notification (NOT-003)'];
        }

        $wpdb->update($table, [
            'is_read' => 1,
            'read_at' => current_time('mysql'),
        ], ['id' => $notification_id]);

        return ['success' => true, 'id' => $notification_id];
    }

    /**
     * Mark all notifications as read for current user (NOT-005)
     */
    public static function mark_all_read(int $user_id): array {
        global $wpdb;
        $table = self::table_notifications();

        $wpdb->update($table, [
            'is_read' => 1,
            'read_at' => current_time('mysql'),
        ], ['user_id' => $user_id, 'is_read' => 0]);

        return ['success' => true, 'user_id' => $user_id];
    }

    /**
     * Get communication preferences for user
     */
    public static function get_preferences(int $user_id): array {
        $prefs = get_user_meta($user_id, 'lfa_notification_prefs', true);
        if (!is_array($prefs)) {
            $prefs = [
                'transactional' => true, // Always true (NOT-006)
                'news'          => true, // Optional opt-in (NOT-007)
                'events'        => true, // Optional opt-in (NOT-007)
                'sms'           => false,
            ];
        }
        $prefs['transactional'] = true; // Hard force transactional alerts (NOT-006)
        return $prefs;
    }

    /**
     * Update communication preferences for user
     */
    public static function save_preferences(int $user_id, array $new_prefs): array {
        $prefs = self::get_preferences($user_id);
        $prefs['news']   = !empty($new_prefs['news']);
        $prefs['events'] = !empty($new_prefs['events']);
        $prefs['sms']    = !empty($new_prefs['sms']);
        $prefs['transactional'] = true; // Always remains ON (NOT-006)

        update_user_meta($user_id, 'lfa_notification_prefs', $prefs);
        return ['success' => true, 'preferences' => $prefs];
    }

    /**
     * Broadcast notification to all approved members (NOT-009)
     */
    public static function send_broadcast(string $title, string $message, string $action_url, int $actor_id, bool $preview_only = false): array {
        if (!user_can($actor_id, LFA_Roles::CAP_BROADCAST_NOTIFICATIONS) && !user_can($actor_id, 'administrator')) {
            return ['success' => false, 'error' => 'Unauthorized: Broadcast requires lfa_broadcast_notifications capability (NOT-009)'];
        }

        // Query eligible approved members
        $users = get_users([
            'role'   => 'lfa_member',
            'fields' => ['ID', 'user_email', 'display_name'],
        ]);

        $recipient_count = 0;
        $opted_in_users = [];

        foreach ($users as $u) {
            $account_status = (string)get_user_meta($u->ID, 'lfa_account_status', true);
            if ($account_status !== 'approved') {
                continue;
            }
            $prefs = self::get_preferences((int)$u->ID);
            // Check opt-in for community announcements (NOT-007, NOT-008)
            if (!empty($prefs['news'])) {
                $opted_in_users[] = $u;
                $recipient_count++;
            }
        }

        if ($preview_only) {
            return [
                'success'         => true,
                'preview'         => true,
                'recipient_count' => $recipient_count,
                'total_members'   => count($users),
            ];
        }

        $sent_count = 0;
        $batch_id = 'broadcast_' . time();

        foreach ($opted_in_users as $u) {
            $uid = (int)$u->ID;
            $dedupe = "{$batch_id}_{$uid}";
            self::create_notification(
                $uid,
                'broadcast_announcement',
                $title,
                $message,
                $action_url,
                $dedupe,
                'broadcast',
                0
            );

            // Send email
            if (!empty($u->user_email)) {
                $email_subject = "Love Family Alumni Announcement: {$title}";
                $email_body = "Dear {$u->display_name},\n\n{$message}\n\n";
                if (!empty($action_url)) {
                    $email_body .= "Read more: {$action_url}\n\n";
                }
                $email_body .= "To manage your communication preferences, visit: " . home_url('/member-dashboard/#settings') . "\n\nWarm regards,\nLove Family Alumni Communications Team";
                wp_mail($u->user_email, $email_subject, $email_body);
                $sent_count++;
            }
        }

        return [
            'success'         => true,
            'preview'         => false,
            'recipient_count' => $sent_count,
        ];
    }

    /**
     * REST API Routes for Member Inbox
     */
    public static function register_rest_routes(): void {
        $namespace = 'lfa/v1';

        // 1. Get notifications (NOT-001, NOT-002)
        register_rest_route($namespace, '/notifications', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_get_notifications'],
            'permission_callback' => static function() {
                return is_user_logged_in(); // NOT-002: Unauthenticated denied
            },
        ]);

        // 2. Mark single as read (NOT-003, NOT-005)
        register_rest_route($namespace, '/notifications/(?P<id>\d+)/read', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_mark_read'],
            'permission_callback' => static function() {
                return is_user_logged_in();
            },
        ]);

        // 3. Mark all as read (NOT-005)
        register_rest_route($namespace, '/notifications/read-all', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_mark_all_read'],
            'permission_callback' => static function() {
                return is_user_logged_in();
            },
        ]);

        // 4. Preferences (NOT-006, NOT-007, NOT-008)
        register_rest_route($namespace, '/notifications/preferences', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [__CLASS__, 'rest_preferences'],
            'permission_callback' => static function() {
                return is_user_logged_in();
            },
        ]);
    }

    public static function rest_get_notifications(WP_REST_Request $req): WP_REST_Response {
        $uid = get_current_user_id();
        $notifications = self::get_user_notifications($uid);
        $unread_count = self::get_unread_count($uid);

        return new WP_REST_Response([
            'success'       => true,
            'unread_count'  => $unread_count,
            'notifications' => $notifications,
        ], 200);
    }

    public static function rest_mark_read(WP_REST_Request $req): WP_REST_Response {
        $uid = get_current_user_id();
        $id = (int)$req->get_param('id');
        $res = self::mark_as_read($id, $uid);

        if (!$res['success']) {
            return new WP_REST_Response(['success' => false, 'error' => $res['error']], 403);
        }

        return new WP_REST_Response(['success' => true, 'unread_count' => self::get_unread_count($uid)], 200);
    }

    public static function rest_mark_all_read(WP_REST_Request $req): WP_REST_Response {
        $uid = get_current_user_id();
        $res = self::mark_all_read($uid);
        return new WP_REST_Response(['success' => true, 'unread_count' => 0], 200);
    }

    public static function rest_preferences(WP_REST_Request $req): WP_REST_Response {
        $uid = get_current_user_id();
        if ($req->get_method() === 'POST') {
            $params = $req->get_json_params() ?: $req->get_body_params();
            $res = self::save_preferences($uid, $params);
            return new WP_REST_Response($res, 200);
        }

        return new WP_REST_Response([
            'success'     => true,
            'preferences' => self::get_preferences($uid),
        ], 200);
    }

    public static function handle_mark_read_post(): void {
        check_admin_referer('lfa_notification_action', 'lfa_nonce');
        $uid = get_current_user_id();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        self::mark_as_read($id, $uid);
        wp_safe_redirect(wp_get_referer() ?: home_url('/member-dashboard/#notifications'));
        exit;
    }

    public static function handle_mark_all_read_post(): void {
        check_admin_referer('lfa_notification_action', 'lfa_nonce');
        $uid = get_current_user_id();
        self::mark_all_read($uid);
        wp_safe_redirect(wp_get_referer() ?: home_url('/member-dashboard/#notifications'));
        exit;
    }

    public static function handle_broadcast_post(): void {
        check_admin_referer('lfa_broadcast_action', 'lfa_nonce');
        $actor_id = get_current_user_id();
        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $msg = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        $url = isset($_POST['action_url']) ? esc_url_raw($_POST['action_url']) : '';
        $is_preview = !empty($_POST['is_preview']);

        $res = self::send_broadcast($title, $msg, $url, $actor_id, $is_preview);
        $redirect = add_query_arg([
            'lfa_notice' => $res['success'] ? 'broadcast_ok' : 'broadcast_error',
            'msg' => $res['success'] ? urlencode($is_preview ? "Preview: {$res['recipient_count']} eligible opted-in members will receive this broadcast" : "Broadcast sent to {$res['recipient_count']} members") : urlencode($res['error']),
        ], wp_get_referer() ?: home_url('/admin-portal/#notifications'));

        wp_safe_redirect($redirect);
        exit;
    }
}
