<?php
/**
 * Love Family Alumni — Volunteer Management Service
 *
 * Implements real volunteer opportunities, approved member opt-in, deduplication,
 * staff assignment and review workflows, and transactional status notifications.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Volunteers {

    public const SCHEMA_VERSION = '1.0.0';

    public const STATUS_DRAFT    = 'draft';
    public const STATUS_OPEN     = 'open';
    public const STATUS_CLOSED   = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    public const SIGNUP_PENDING     = 'pending';
    public const SIGNUP_SHORTLISTED = 'shortlisted';
    public const SIGNUP_APPROVED    = 'approved';
    public const SIGNUP_DECLINED    = 'declined';
    public const SIGNUP_COMPLETED   = 'completed';

    public static function init(): void {
        self::install_schema();
        add_action('admin_post_lfa_save_volunteer_opp', [__CLASS__, 'handle_save_opp']);
        add_action('admin_post_lfa_apply_volunteer', [__CLASS__, 'handle_apply_post']);
        add_action('admin_post_lfa_review_volunteer', [__CLASS__, 'handle_review_post']);
    }

    public static function table_opportunities(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_volunteer_opportunities';
    }

    public static function table_signups(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_volunteer_signups';
    }

    public static function install_schema(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $opp_table = self::table_opportunities();
        $signup_table = self::table_signups();

        $sql1 = "CREATE TABLE IF NOT EXISTS `{$opp_table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(255) NOT NULL,
            `purpose` TEXT NOT NULL,
            `timeframe` VARCHAR(255) NOT NULL,
            `location_type` VARCHAR(50) NOT NULL DEFAULT 'remote',
            `location_details` VARCHAR(255) NULL,
            `openings` INT NULL DEFAULT NULL,
            `committee` VARCHAR(255) NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'draft',
            `created_by` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `status_idx` (`status`)
        ) {$charset_collate};";

        $sql2 = "CREATE TABLE IF NOT EXISTS `{$signup_table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `opportunity_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `availability` VARCHAR(255) NOT NULL,
            `interests` TEXT NULL,
            `consent_version` VARCHAR(50) NOT NULL DEFAULT 'v1.0',
            `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
            `reviewed_by` BIGINT UNSIGNED NULL,
            `reviewed_at` DATETIME NULL,
            `staff_notes` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `opp_user_uniq` (`opportunity_id`, `user_id`),
            KEY `opp_idx` (`opportunity_id`),
            KEY `user_idx` (`user_id`),
            KEY `status_idx` (`status`)
        ) {$charset_collate};";

        dbDelta($sql1);
        dbDelta($sql2);

        self::seed_default_opportunities();
    }

    public static function seed_default_opportunities(): void {
        global $wpdb;
        $table = self::table_opportunities();
        $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($count > 0) {
            return;
        }

        $admin_id = 1;
        $seeds = [
            [
                'title'            => '2027 Biennial Conference Hospitality & Protocol Team',
                'purpose'          => 'Coordinate VIP reception, attendee registration desks, and delegate accommodations for the 2027 Biennial Conference.',
                'timeframe'        => 'September 2027 (Pre-conference preparation May–August 2027)',
                'location_type'    => 'in_person',
                'location_details' => 'Conference Venue, North America Zone',
                'openings'         => 12,
                'committee'        => 'Conference Steering Committee',
                'status'           => self::STATUS_OPEN,
                'created_by'       => $admin_id,
            ],
            [
                'title'            => 'Historical Archives & Media Digitization Coordinator',
                'purpose'          => 'Assist the Secretariat in collecting, archiving, and cataloging photographic and historical records from early alumni chapters.',
                'timeframe'        => 'Flexible / Ongoing 2026–2027',
                'location_type'    => 'remote',
                'location_details' => 'Online Archive Repository',
                'openings'         => 4,
                'committee'        => 'Archives & Documentation Committee',
                'status'           => self::STATUS_OPEN,
                'created_by'       => $admin_id,
            ],
            [
                'title'            => 'Alumni Mentorship & Professional Development Liaison',
                'purpose'          => 'Support graduating students and young professionals with resume reviews, career coaching, and industry networking.',
                'timeframe'        => 'Quarterly cohort sessions',
                'location_type'    => 'remote',
                'location_details' => 'Virtual / Zoom',
                'openings'         => 8,
                'committee'        => 'Welfare & Empowerment Committee',
                'status'           => self::STATUS_OPEN,
                'created_by'       => $admin_id,
            ],
        ];

        foreach ($seeds as $s) {
            $wpdb->insert($table, array_merge($s, ['created_at' => current_time('mysql')]));
        }
    }

    public static function get_opportunities(?string $status = null): array {
        global $wpdb;
        $table = self::table_opportunities();
        if ($status !== null) {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` WHERE `status` = %s ORDER BY `id` DESC", $status), ARRAY_A);
        }
        return $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY `id` DESC", ARRAY_A);
    }

    public static function get_opportunity(int $id): ?array {
        global $wpdb;
        $table = self::table_opportunities();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE `id` = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public static function save_opportunity(array $data, int $actor_id): array {
        if (!user_can($actor_id, LFA_Roles::CAP_MANAGE_VOLUNTEERS) && !user_can($actor_id, 'administrator')) {
            return ['success' => false, 'error' => 'Unauthorized'];
        }

        global $wpdb;
        $table = self::table_opportunities();

        $title = sanitize_text_field($data['title'] ?? '');
        $purpose = sanitize_textarea_field($data['purpose'] ?? '');
        $timeframe = sanitize_text_field($data['timeframe'] ?? '');
        $location_type = sanitize_key($data['location_type'] ?? 'remote');
        $location_details = sanitize_text_field($data['location_details'] ?? '');
        $openings = !empty($data['openings']) ? (int)$data['openings'] : null;
        $committee = sanitize_text_field($data['committee'] ?? '');
        $status = sanitize_key($data['status'] ?? self::STATUS_DRAFT);

        if (empty($title) || empty($purpose)) {
            return ['success' => false, 'error' => 'Title and purpose are required.'];
        }

        $id = !empty($data['id']) ? (int)$data['id'] : 0;
        if ($id > 0) {
            $wpdb->update($table, [
                'title'            => $title,
                'purpose'          => $purpose,
                'timeframe'        => $timeframe,
                'location_type'    => $location_type,
                'location_details' => $location_details,
                'openings'         => $openings,
                'committee'        => $committee,
                'status'           => $status,
                'updated_at'       => current_time('mysql'),
            ], ['id' => $id]);
            return ['success' => true, 'id' => $id, 'action' => 'updated'];
        } else {
            $wpdb->insert($table, [
                'title'            => $title,
                'purpose'          => $purpose,
                'timeframe'        => $timeframe,
                'location_type'    => $location_type,
                'location_details' => $location_details,
                'openings'         => $openings,
                'committee'        => $committee,
                'status'           => $status,
                'created_by'       => $actor_id,
                'created_at'       => current_time('mysql'),
                'updated_at'       => current_time('mysql'),
            ]);
            return ['success' => true, 'id' => $wpdb->insert_id, 'action' => 'created'];
        }
    }

    /**
     * Member application submission (VOL-004, VOL-005, VOL-006)
     */
    public static function apply_for_opportunity(int $opp_id, int $user_id, array $data): array {
        // VOL-006: Pending / rejected / guest users cannot apply
        if ($user_id <= 0) {
            return ['success' => false, 'error' => 'You must be logged in to apply.'];
        }

        $account_status = (string)get_user_meta($user_id, 'lfa_account_status', true);
        if ($account_status !== 'approved' && !user_can($user_id, 'administrator')) {
            return ['success' => false, 'error' => 'Only verified and approved alumni members can apply for volunteer opportunities (VOL-006).'];
        }

        // VOL-003: Opportunity must be open
        $opp = self::get_opportunity($opp_id);
        if (!$opp || $opp['status'] !== self::STATUS_OPEN) {
            return ['success' => false, 'error' => 'This volunteer opportunity is closed or archived and no longer accepting applications (VOL-003).'];
        }

        // VOL-005: Prevent duplicate signups
        global $wpdb;
        $signup_table = self::table_signups();
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$signup_table}` WHERE `opportunity_id` = %d AND `user_id` = %d",
            $opp_id,
            $user_id
        ));
        if ($existing) {
            return ['success' => false, 'error' => 'You have already submitted an application for this opportunity (VOL-005).'];
        }

        $availability = sanitize_text_field($data['availability'] ?? 'Available on request');
        $interests    = sanitize_textarea_field($data['interests'] ?? '');
        $consent_ver  = sanitize_text_field($data['consent_version'] ?? 'v1.0');

        $inserted = $wpdb->insert($signup_table, [
            'opportunity_id'  => $opp_id,
            'user_id'         => $user_id,
            'availability'    => $availability,
            'interests'       => $interests,
            'consent_version' => $consent_ver,
            'status'          => self::SIGNUP_PENDING,
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ]);

        if (!$inserted) {
            return ['success' => false, 'error' => 'Failed to record volunteer application.'];
        }

        $signup_id = $wpdb->insert_id;

        // Transactional notification to member
        if (class_exists('LFA_Notifications')) {
            LFA_Notifications::create_notification(
                $user_id,
                'volunteer_application_submitted',
                'Volunteer Application Submitted',
                "Your application for '{$opp['title']}' has been received and is pending committee review.",
                home_url('/member-dashboard/#volunteers'),
                "vol_apply_{$opp_id}_{$user_id}",
                'volunteer_signup',
                $signup_id
            );
        }

        // Notification email
        $user = get_userdata($user_id);
        if ($user && !empty($user->user_email)) {
            $subject = "Love Family Alumni — Volunteer Application Received: {$opp['title']}";
            $msg = "Dear {$user->display_name},\n\nThank you for opting into the '{$opp['title']}' volunteer team. Your application has been logged and the committee will review your availability shortly.\n\nWarm regards,\nLove Family Alumni Secretariat";
            wp_mail($user->user_email, $subject, $msg);
        }

        return ['success' => true, 'signup_id' => $signup_id];
    }

    /**
     * Get signups for an opportunity (authorized staff only)
     */
    public static function get_signups_for_opportunity(int $opp_id, int $actor_id): array {
        if (!user_can($actor_id, LFA_Roles::CAP_MANAGE_VOLUNTEERS) && !user_can($actor_id, 'administrator')) {
            return [];
        }

        global $wpdb;
        $signup_table = self::table_signups();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email, 
                    (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = s.user_id AND meta_key = 'lfa_alumni_id' LIMIT 1) as alumni_id,
                    (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = s.user_id AND meta_key = 'lfa_phone' LIMIT 1) as phone
             FROM `{$signup_table}` s
             JOIN `{$wpdb->users}` u ON s.user_id = u.ID
             WHERE s.opportunity_id = %d
             ORDER BY s.id DESC",
            $opp_id
        ), ARRAY_A);
    }

    /**
     * Get user's own signups (VOL-007)
     */
    public static function get_user_signups(int $user_id): array {
        global $wpdb;
        $signup_table = self::table_signups();
        $opp_table = self::table_opportunities();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, o.title as opp_title, o.purpose as opp_purpose, o.timeframe as opp_timeframe, o.committee as opp_committee, o.status as opp_status
             FROM `{$signup_table}` s
             JOIN `{$opp_table}` o ON s.opportunity_id = o.id
             WHERE s.user_id = %d
             ORDER BY s.id DESC",
            $user_id
        ), ARRAY_A);
    }

    /**
     * Staff review/status update (VOL-008)
     */
    public static function review_signup(int $signup_id, string $new_status, int $actor_id, string $notes = ''): array {
        if (!user_can($actor_id, LFA_Roles::CAP_MANAGE_VOLUNTEERS) && !user_can($actor_id, 'administrator')) {
            return ['success' => false, 'error' => 'Unauthorized'];
        }

        global $wpdb;
        $signup_table = self::table_signups();
        $opp_table = self::table_opportunities();

        $signup = $wpdb->get_row($wpdb->prepare("SELECT s.*, o.title as opp_title FROM `{$signup_table}` s JOIN `{$opp_table}` o ON s.opportunity_id = o.id WHERE s.id = %d", $signup_id), ARRAY_A);
        if (!$signup) {
            return ['success' => false, 'error' => 'Signup record not found.'];
        }

        $valid_statuses = [self::SIGNUP_PENDING, self::SIGNUP_SHORTLISTED, self::SIGNUP_APPROVED, self::SIGNUP_DECLINED, self::SIGNUP_COMPLETED];
        if (!in_array($new_status, $valid_statuses, true)) {
            return ['success' => false, 'error' => 'Invalid status.'];
        }

        $wpdb->update($signup_table, [
            'status'      => $new_status,
            'reviewed_by' => $actor_id,
            'reviewed_at' => current_time('mysql'),
            'staff_notes' => sanitize_textarea_field($notes),
            'updated_at'  => current_time('mysql'),
        ], ['id' => $signup_id]);

        // Send status change notification once per real state transition
        $member_id = (int)$signup['user_id'];
        $member = get_userdata($member_id);
        $status_label = ucfirst($new_status);

        if (class_exists('LFA_Notifications')) {
            LFA_Notifications::create_notification(
                $member_id,
                'volunteer_status_updated',
                "Volunteer Update: {$status_label}",
                "Your application for '{$signup['opp_title']}' has been updated to: {$status_label}.",
                home_url('/member-dashboard/#volunteers'),
                "vol_status_{$signup_id}_{$new_status}",
                'volunteer_signup',
                $signup_id
            );
        }

        if ($member && !empty($member->user_email)) {
            $subject = "Love Family Alumni — Volunteer Status Update: {$signup['opp_title']}";
            $body = "Dear {$member->display_name},\n\nYour application status for '{$signup['opp_title']}' has been updated to: {$status_label}.\n\n";
            if (!empty($notes)) {
                $body .= "Coordinator Note:\n{$notes}\n\n";
            }
            $body .= "View details on your Member Dashboard: " . home_url('/member-dashboard/#volunteers') . "\n\nWarm regards,\nLove Family Alumni Volunteer Coordination Team";
            wp_mail($member->user_email, $subject, $body);
        }

        return ['success' => true, 'new_status' => $new_status];
    }

    public static function handle_save_opp(): void {
        check_admin_referer('lfa_save_volunteer_opp_action', 'lfa_nonce');
        $actor_id = get_current_user_id();
        $res = self::save_opportunity($_POST, $actor_id);
        $redirect = add_query_arg([
            'lfa_notice' => $res['success'] ? 'opp_saved' : 'opp_error',
            'msg' => $res['success'] ? urlencode('Volunteer opportunity saved successfully') : urlencode($res['error']),
        ], wp_get_referer() ?: home_url('/admin-portal/#volunteers'));
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_apply_post(): void {
        check_admin_referer('lfa_apply_volunteer_action', 'lfa_nonce');
        $user_id = get_current_user_id();
        $opp_id  = isset($_POST['opportunity_id']) ? (int)$_POST['opportunity_id'] : 0;
        $res = self::apply_for_opportunity($opp_id, $user_id, $_POST);
        $redirect = add_query_arg([
            'lfa_notice' => $res['success'] ? 'applied_ok' : 'applied_error',
            'msg' => $res['success'] ? urlencode('Your volunteer interest has been submitted successfully!') : urlencode($res['error']),
        ], wp_get_referer() ?: home_url('/member-dashboard/#volunteers'));
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_review_post(): void {
        check_admin_referer('lfa_review_volunteer_action', 'lfa_nonce');
        $actor_id  = get_current_user_id();
        $signup_id = isset($_POST['signup_id']) ? (int)$_POST['signup_id'] : 0;
        $status    = isset($_POST['new_status']) ? sanitize_key($_POST['new_status']) : '';
        $notes     = isset($_POST['staff_notes']) ? sanitize_textarea_field($_POST['staff_notes']) : '';

        $res = self::review_signup($signup_id, $status, $actor_id, $notes);
        $redirect = add_query_arg([
            'lfa_notice' => $res['success'] ? 'reviewed_ok' : 'reviewed_error',
            'msg' => $res['success'] ? urlencode('Volunteer review updated.') : urlencode($res['error']),
        ], wp_get_referer() ?: home_url('/admin-portal/#volunteers'));
        wp_safe_redirect($redirect);
        exit;
    }
}
