<?php
/**
 * Love Family Alumni — Role & Permissions Governance Service
 *
 * Implements strict role-based access control, granular capability boundaries,
 * role change audit logging, last-admin lockout protection, and capability aliasing.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Roles {

    public const SCHEMA_VERSION = '1.0.0';

    // Capabilities
    public const CAP_VIEW_ADMIN_PORTAL             = 'lfa_view_admin_portal';
    public const CAP_MANAGE_ROLES                   = 'lfa_manage_roles';
    public const CAP_VIEW_REPORTS                   = 'lfa_view_reports';
    public const CAP_VIEW_FINANCIALS                = 'lfa_view_financials';
    public const CAP_MANAGE_FINANCES                = 'lfa_manage_finances';
    public const CAP_EXPORT_FINANCIALS               = 'lfa_export_financials';
    public const CAP_MANAGE_PAYPAL_SETTINGS         = 'lfa_manage_paypal_settings';
    public const CAP_RECONCILE_PAYMENTS             = 'lfa_reconcile_payments';
    public const CAP_VIEW_PAYMENT_TRANSACTIONS      = 'lfa_view_payment_transactions';
    public const CAP_MANAGE_MEMBERSHIPS             = 'lfa_manage_memberships';
    public const CAP_VIEW_MEMBERSHIP_REPORTS        = 'lfa_view_membership_reports';
    public const CAP_MANAGE_DUES_POLICY             = 'lfa_manage_dues_policy';
    public const CAP_MANAGE_CONFERENCES             = 'lfa_manage_conferences';
    public const CAP_PUBLISH_CONFERENCES            = 'lfa_publish_conferences';
    public const CAP_VIEW_CONFERENCE_ATTENDEES      = 'lfa_view_conference_attendees';
    public const CAP_EXPORT_CONFERENCE_ATTENDEES     = 'lfa_export_conference_attendees';
    public const CAP_MANAGE_SCHOLARSHIPS            = 'lfa_manage_scholarships';
    public const CAP_REVIEW_SCHOLARSHIPS            = 'lfa_review_scholarships';
    public const CAP_PUBLISH_SCHOLARSHIP_RECIPIENTS = 'lfa_publish_scholarship_recipients';
    public const CAP_EXPORT_SCHOLARSHIPS            = 'lfa_export_scholarships';
    public const CAP_MANAGE_BUSINESS_DIRECTORY      = 'lfa_manage_business_directory';
    public const CAP_EXPORT_BUSINESS_DIRECTORY      = 'lfa_export_business_directory';
    public const CAP_EDIT_EVENTS                    = 'lfa_edit_events';
    public const CAP_PUBLISH_EVENTS                 = 'lfa_publish_events';
    public const CAP_DELETE_EVENTS                  = 'lfa_delete_events';
    public const CAP_EDIT_NEWS                      = 'lfa_edit_news';
    public const CAP_PUBLISH_NEWS                   = 'lfa_publish_news';
    public const CAP_DELETE_NEWS                    = 'lfa_delete_news';
    public const CAP_MANAGE_ARCHIVES                = 'lfa_manage_archives';
    public const CAP_PUBLISH_ARCHIVES               = 'lfa_publish_archives';
    public const CAP_DELETE_ARCHIVES                = 'lfa_delete_archives';
    public const CAP_VIEW_RESTRICTED_ARCHIVES       = 'lfa_view_restricted_archives';
    public const CAP_MANAGE_VOLUNTEERS              = 'lfa_manage_volunteers';
    public const CAP_VIEW_VOLUNTEERS                = 'lfa_view_volunteers';
    public const CAP_MANAGE_LEADERSHIP              = 'lfa_manage_leadership';
    public const CAP_VIEW_LEADERSHIP                = 'lfa_view_leadership';
    public const CAP_MANAGE_NOTIFICATIONS           = 'lfa_manage_notifications';
    public const CAP_BROADCAST_NOTIFICATIONS        = 'lfa_broadcast_notifications';
    public const CAP_MEMBER_ACCESS                  = 'lfa_member_access';

    // Role Slugs
    public const ROLE_PRESIDENT             = 'lfa_president';
    public const ROLE_GENERAL_SECRETARY     = 'lfa_general_secretary';
    public const ROLE_TREASURER             = 'lfa_treasurer';
    public const ROLE_FINANCIAL_SECRETARY   = 'lfa_financial_secretary';
    public const ROLE_COMMUNICATIONS_MGR    = 'lfa_communications_manager';
    public const ROLE_COMMUNICATIONS_EDT    = 'lfa_communications_editor';
    public const ROLE_CONFERENCE_ADMIN      = 'lfa_conference_admin';
    public const ROLE_CONFERENCE_PUB        = 'lfa_conference_publisher';
    public const ROLE_SCHOLARSHIP_ADMIN     = 'lfa_scholarship_admin';
    public const ROLE_SCHOLARSHIP_REV       = 'lfa_scholarship_reviewer';
    public const ROLE_ARCHIVE_MANAGER       = 'lfa_archive_manager';
    public const ROLE_BUSINESS_MODERATOR    = 'lfa_business_moderator';
    public const ROLE_MEMBER                = 'lfa_member';

    public static function init(): void {
        self::install_schema();
        self::register_roles();
        add_filter('map_meta_cap', [__CLASS__, 'filter_map_meta_cap'], 10, 4);
        add_action('set_user_role', [__CLASS__, 'on_set_user_role'], 10, 3);
        add_filter('user_has_cap', [__CLASS__, 'filter_user_has_cap'], 10, 4);
        add_action('admin_post_lfa_change_user_role', [__CLASS__, 'handle_role_change_post']);
    }

    public static function table_audit(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_role_change_audit';
    }

    public static function install_schema(): void {
        global $wpdb;
        $table = self::table_audit();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `target_user_id` BIGINT UNSIGNED NOT NULL,
            `actor_user_id` BIGINT UNSIGNED NOT NULL,
            `old_roles` VARCHAR(255) NOT NULL,
            `new_roles` VARCHAR(255) NOT NULL,
            `reason` TEXT NULL,
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `target_user_idx` (`target_user_id`),
            KEY `actor_user_idx` (`actor_user_id`),
            KEY `created_at_idx` (`created_at`)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Registers all LFA organizational roles with strictly segregated capabilities
     */
    public static function register_roles(): void {
        global $wp_roles;
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }

        // 1. Ensure Administrator has all LFA capabilities
        $admin_caps = [
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_MANAGE_ROLES                   => true,
            self::CAP_VIEW_REPORTS                   => true,
            self::CAP_VIEW_FINANCIALS                => true,
            self::CAP_MANAGE_FINANCES                => true,
            self::CAP_EXPORT_FINANCIALS               => true,
            self::CAP_MANAGE_PAYPAL_SETTINGS         => true,
            self::CAP_RECONCILE_PAYMENTS             => true,
            self::CAP_VIEW_PAYMENT_TRANSACTIONS      => true,
            self::CAP_MANAGE_MEMBERSHIPS             => true,
            self::CAP_VIEW_MEMBERSHIP_REPORTS        => true,
            self::CAP_MANAGE_DUES_POLICY             => true,
            self::CAP_MANAGE_CONFERENCES             => true,
            self::CAP_PUBLISH_CONFERENCES            => true,
            self::CAP_VIEW_CONFERENCE_ATTENDEES      => true,
            self::CAP_EXPORT_CONFERENCE_ATTENDEES     => true,
            self::CAP_MANAGE_SCHOLARSHIPS            => true,
            self::CAP_REVIEW_SCHOLARSHIPS            => true,
            self::CAP_PUBLISH_SCHOLARSHIP_RECIPIENTS => true,
            self::CAP_EXPORT_SCHOLARSHIPS            => true,
            self::CAP_MANAGE_BUSINESS_DIRECTORY      => true,
            self::CAP_EXPORT_BUSINESS_DIRECTORY      => true,
            self::CAP_EDIT_EVENTS                    => true,
            self::CAP_PUBLISH_EVENTS                 => true,
            self::CAP_DELETE_EVENTS                  => true,
            self::CAP_EDIT_NEWS                      => true,
            self::CAP_PUBLISH_NEWS                   => true,
            self::CAP_DELETE_NEWS                    => true,
            self::CAP_MANAGE_ARCHIVES                => true,
            self::CAP_PUBLISH_ARCHIVES               => true,
            self::CAP_DELETE_ARCHIVES                => true,
            self::CAP_VIEW_RESTRICTED_ARCHIVES       => true,
            self::CAP_MANAGE_VOLUNTEERS              => true,
            self::CAP_VIEW_VOLUNTEERS                => true,
            self::CAP_MANAGE_LEADERSHIP              => true,
            self::CAP_VIEW_LEADERSHIP                => true,
            self::CAP_MANAGE_NOTIFICATIONS           => true,
            self::CAP_BROADCAST_NOTIFICATIONS        => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ];
        foreach ($admin_caps as $cap => $grant) {
            $wp_roles->add_cap('administrator', $cap);
        }

        // 2. President / Zonal President (ROL-003)
        // Governance oversight, view reports, view members, view conferences, view archives
        // EXPLICITLY NO PayPal settings, NO financial mutation (manage_finances, reconcile_payments)
        self::ensure_role(self::ROLE_PRESIDENT, 'Love Family President / Zonal President', [
            'read'                                  => true,
            'upload_files'                          => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_VIEW_REPORTS                   => true,
            self::CAP_VIEW_MEMBERSHIP_REPORTS        => true,
            self::CAP_VIEW_FINANCIALS                => true,
            self::CAP_VIEW_CONFERENCE_ATTENDEES      => true,
            self::CAP_VIEW_RESTRICTED_ARCHIVES       => true,
            self::CAP_VIEW_VOLUNTEERS                => true,
            self::CAP_VIEW_LEADERSHIP                => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 3. General Secretary (ROL-004)
        // Membership approval support, leadership docs, volunteer administration, notices
        // EXPLICITLY NO finance mutation or finance view
        self::ensure_role(self::ROLE_GENERAL_SECRETARY, 'Love Family General Secretary', [
            'read'                                  => true,
            'upload_files'                          => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_MANAGE_MEMBERSHIPS             => true,
            self::CAP_VIEW_MEMBERSHIP_REPORTS        => true,
            self::CAP_MANAGE_LEADERSHIP              => true,
            self::CAP_VIEW_LEADERSHIP                => true,
            self::CAP_MANAGE_VOLUNTEERS              => true,
            self::CAP_VIEW_VOLUNTEERS                => true,
            self::CAP_VIEW_REPORTS                   => true,
            self::CAP_MANAGE_NOTIFICATIONS           => true,
            self::CAP_BROADCAST_NOTIFICATIONS        => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 4. Treasurer (ROL-005)
        // Verified financial reports, reconciliation, refund review, finance management
        // EXPLICITLY NO PayPal secrets (CAP_MANAGE_PAYPAL_SETTINGS)
        self::ensure_role(self::ROLE_TREASURER, 'Love Family Treasurer', [
            'read'                                  => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_VIEW_FINANCIALS                => true,
            self::CAP_EXPORT_FINANCIALS               => true,
            self::CAP_VIEW_PAYMENT_TRANSACTIONS      => true,
            self::CAP_RECONCILE_PAYMENTS             => true,
            self::CAP_MANAGE_FINANCES                => true,
            self::CAP_VIEW_REPORTS                   => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 5. Financial Secretary (ROL-006)
        // Dues policy, invoice review, financial reports, payment view, permitted exports
        // EXPLICITLY NO PayPal secrets (CAP_MANAGE_PAYPAL_SETTINGS)
        self::ensure_role(self::ROLE_FINANCIAL_SECRETARY, 'Love Family Financial Secretary', [
            'read'                                  => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_MANAGE_DUES_POLICY             => true,
            self::CAP_VIEW_PAYMENT_TRANSACTIONS      => true,
            self::CAP_VIEW_MEMBERSHIP_REPORTS        => true,
            self::CAP_VIEW_FINANCIALS                => true,
            self::CAP_EXPORT_FINANCIALS               => true,
            self::CAP_VIEW_REPORTS                   => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 6. Communications Manager / PRO (ROL-008)
        // Publishing news, events, notices, announcements
        self::ensure_role(self::ROLE_COMMUNICATIONS_MGR, 'Communications Manager / PRO', [
            'read'                                  => true,
            'upload_files'                          => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_EDIT_EVENTS                    => true,
            self::CAP_PUBLISH_EVENTS                 => true,
            self::CAP_DELETE_EVENTS                  => true,
            self::CAP_EDIT_NEWS                      => true,
            self::CAP_PUBLISH_NEWS                   => true,
            self::CAP_DELETE_NEWS                    => true,
            self::CAP_MANAGE_NOTIFICATIONS           => true,
            self::CAP_BROADCAST_NOTIFICATIONS        => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 7. Communications Editor (ROL-007)
        // Drafts only: cannot publish or delete news/events
        self::ensure_role(self::ROLE_COMMUNICATIONS_EDT, 'Communications Editor', [
            'read'                                  => true,
            'upload_files'                          => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_EDIT_EVENTS                    => true,
            self::CAP_EDIT_NEWS                      => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 8. Conference Administrator (ROL-009)
        // Edit conferences, view/export attendees; publishing separated
        self::ensure_role(self::ROLE_CONFERENCE_ADMIN, 'Conference Administrator', [
            'read'                                  => true,
            'upload_files'                          => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_MANAGE_CONFERENCES             => true,
            self::CAP_VIEW_CONFERENCE_ATTENDEES      => true,
            self::CAP_EXPORT_CONFERENCE_ATTENDEES     => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 9. Conference Publisher (ROL-010)
        // Conference Administrator + Publish capability
        self::ensure_role(self::ROLE_CONFERENCE_PUB, 'Conference Publisher', [
            'read'                                  => true,
            'upload_files'                          => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_MANAGE_CONFERENCES             => true,
            self::CAP_PUBLISH_CONFERENCES            => true,
            self::CAP_VIEW_CONFERENCE_ATTENDEES      => true,
            self::CAP_EXPORT_CONFERENCE_ATTENDEES     => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 10. Scholarship Administrator (ROL-012)
        // Manage scholarships, reviewer assignments, publish awards; NO PayPal settings
        self::ensure_role(self::ROLE_SCHOLARSHIP_ADMIN, 'Scholarship Administrator', [
            'read'                                  => true,
            'upload_files'                          => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_MANAGE_SCHOLARSHIPS            => true,
            self::CAP_REVIEW_SCHOLARSHIPS            => true,
            self::CAP_PUBLISH_SCHOLARSHIP_RECIPIENTS => true,
            self::CAP_EXPORT_SCHOLARSHIPS            => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 11. Scholarship Reviewer (ROL-011)
        // Scoped review only
        self::ensure_role(self::ROLE_SCHOLARSHIP_REV, 'Scholarship Reviewer', [
            'read'                                  => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_REVIEW_SCHOLARSHIPS            => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 12. Archive Manager (ROL-014)
        // Media archives, timeline, historical documents; NO member finances
        self::ensure_role(self::ROLE_ARCHIVE_MANAGER, 'Archive Manager', [
            'read'                                  => true,
            'upload_files'                          => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_MANAGE_ARCHIVES                => true,
            self::CAP_PUBLISH_ARCHIVES               => true,
            self::CAP_DELETE_ARCHIVES                => true,
            self::CAP_VIEW_RESTRICTED_ARCHIVES       => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 13. Business Moderator (ROL-013)
        // Business review only; NO finances or dues
        self::ensure_role(self::ROLE_BUSINESS_MODERATOR, 'Alumni Business Moderator', [
            'read'                                  => true,
            self::CAP_VIEW_ADMIN_PORTAL             => true,
            self::CAP_MANAGE_BUSINESS_DIRECTORY      => true,
            self::CAP_EXPORT_BUSINESS_DIRECTORY      => true,
            self::CAP_MEMBER_ACCESS                  => true,
        ]);

        // 14. Member (ROL-015)
        self::ensure_role(self::ROLE_MEMBER, 'Love Family Member', [
            'read'                  => true,
            self::CAP_MEMBER_ACCESS => true,
        ]);
    }

    private static function ensure_role(string $slug, string $display_name, array $caps): void {
        global $wp_roles;
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }

        if ($wp_roles->is_role($slug)) {
            // Update capabilities
            $current_role = $wp_roles->get_role($slug);
            foreach ($caps as $cap => $grant) {
                if ($grant) {
                    $current_role->add_cap($cap);
                } else {
                    $current_role->remove_cap($cap);
                }
            }
        } else {
            $wp_roles->add_role($slug, $display_name, $caps);
        }
    }

    /**
     * Map meta capabilities and normalize alias conflicts (e.g. lfa_manage_businesses vs lfa_moderate_businesses)
     */
    public static function filter_map_meta_cap(array $caps, string $cap, int $user_id, array $args): array {
        switch ($cap) {
            case 'lfa_manage_businesses':
            case 'lfa_moderate_businesses':
                return [self::CAP_MANAGE_BUSINESS_DIRECTORY];

            case 'lfa_publish_news_check':
                return [self::CAP_PUBLISH_NEWS];

            case 'lfa_publish_events_check':
                return [self::CAP_PUBLISH_EVENTS];
        }

        return $caps;
    }

    /**
     * Dynamic capability checks
     */
    public static function filter_user_has_cap(array $allcaps, array $caps, array $args, WP_User $user): array {
        // Alias support
        if (!empty($allcaps[self::CAP_MANAGE_BUSINESS_DIRECTORY])) {
            $allcaps['lfa_manage_businesses'] = true;
            $allcaps['lfa_moderate_businesses'] = true;
        }

        return $allcaps;
    }

    /**
     * Audit role change whenever a user role changes (ROL-020)
     */
    public static function on_set_user_role(int $user_id, string $new_role, array $old_roles): void {
        global $wpdb;
        $actor_id = get_current_user_id();

        $wpdb->insert(self::table_audit(), [
            'target_user_id' => $user_id,
            'actor_user_id'  => $actor_id ?: 0,
            'old_roles'      => implode(', ', $old_roles),
            'new_roles'      => $new_role,
            'reason'         => 'System role modification',
            'ip_address'     => !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
            'created_at'     => current_time('mysql'),
        ]);

        // Clean user cache immediately (ROL-019)
        clean_user_cache($user_id);
    }

    /**
     * Assign role with full authorization check, lockout protection, and reason audit
     */
    public static function assign_role_with_audit(int $target_user_id, string $new_role, int $actor_user_id, string $reason = ''): array {
        // 1. Authorization check: only Administrator can assign staff roles
        if (!user_can($actor_user_id, 'administrator') && !user_can($actor_user_id, self::CAP_MANAGE_ROLES)) {
            return ['success' => false, 'error' => 'Unauthorized: only Administrators can manage organizational roles'];
        }

        // 2. Self-assignment prevention check (ROL-017)
        if ($target_user_id === $actor_user_id && $new_role !== 'administrator') {
            // Cannot demote self if last admin
            $admins = get_users(['role' => 'administrator', 'fields' => 'ID']);
            if (count($admins) <= 1 && in_array($target_user_id, $admins, true)) {
                return ['success' => false, 'error' => 'Lockout prevention: Cannot remove the last Administrator on the site (ROL-002)'];
            }
        }

        // 3. Last admin lockout check (ROL-002)
        $target_user = get_userdata($target_user_id);
        if ($target_user && in_array('administrator', $target_user->roles, true) && $new_role !== 'administrator') {
            $admins = get_users(['role' => 'administrator', 'fields' => 'ID']);
            if (count($admins) <= 1) {
                return ['success' => false, 'error' => 'Lockout prevention: Cannot remove the last Administrator on the site (ROL-002)'];
            }
        }

        // 4. Validate target role exists
        global $wp_roles;
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        if (!$wp_roles->is_role($new_role)) {
            return ['success' => false, 'error' => "Invalid role: {$new_role}"];
        }

        $old_roles = $target_user ? $target_user->roles : [];

        // 5. Update user role
        $target_user->set_role($new_role);

        // 6. Log audit trail
        global $wpdb;
        $wpdb->insert(self::table_audit(), [
            'target_user_id' => $target_user_id,
            'actor_user_id'  => $actor_user_id,
            'old_roles'      => implode(', ', $old_roles),
            'new_roles'      => $new_role,
            'reason'         => sanitize_text_field($reason) ?: 'Administrative assignment via Admin Portal',
            'ip_address'     => !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
            'created_at'     => current_time('mysql'),
        ]);

        // Clean user cache immediately (ROL-019)
        clean_user_cache($target_user_id);

        return ['success' => true, 'target_user_id' => $target_user_id, 'new_role' => $new_role];
    }

    /**
     * Admin post handler for changing user roles
     */
    public static function handle_role_change_post(): void {
        check_admin_referer('lfa_change_user_role_action', 'lfa_nonce');

        $actor_id = get_current_user_id();
        $target_id = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
        $new_role  = isset($_POST['new_role']) ? sanitize_key($_POST['new_role']) : '';
        $reason    = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';

        $res = self::assign_role_with_audit($target_id, $new_role, $actor_id, $reason);

        $redirect = add_query_arg([
            'lfa_notice' => $res['success'] ? 'role_updated' : 'role_error',
            'msg' => $res['success'] ? urlencode("User role successfully updated to {$new_role}") : urlencode($res['error']),
        ], wp_get_referer() ?: home_url('/admin-portal/#roles'));

        wp_safe_redirect($redirect);
        exit;
    }
}
