<?php
/**
 * Love Family Alumni — Unified Admin Portal Controller
 *
 * Connects all backend modules (Membership, Dues, Conferences, Scholarships,
 * Businesses, Donations, Events/News, Archives, Volunteers, Leadership, Notifications,
 * Analytics & Audit) to the responsive standalone Admin Portal interface.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Admin_Portal {

    public static function init(): void {
        add_action('template_redirect', [__CLASS__, 'guard_portal_access']);
        add_action('admin_post_lfa_portal_member_action', [__CLASS__, 'handle_member_action']);
        add_action('admin_post_lfa_portal_business_action', [__CLASS__, 'handle_business_action']);
    }

    /**
     * Server-side route guard for /admin-portal/ (ADM-006, ROL-016)
     */
    public static function guard_portal_access(): void {
        if (!is_page('admin-portal') && !is_page_template('templates/pages/page-admin-portal.php') && !is_page_template('page-admin-portal.php')) {
            return;
        }

        // 1. Guest check
        if (!is_user_logged_in()) {
            wp_safe_redirect(home_url('/member-login/?redirect_to=' . urlencode(home_url('/admin-portal/'))));
            exit;
        }

        $user_id = get_current_user_id();

        // 2. Pending or rejected check (ROL-016)
        $account_status = (string)get_user_meta($user_id, 'lfa_account_status', true);
        if ($account_status === 'rejected') {
            wp_die('Your account has been rejected and does not have access to administrative facilities.', 'Access Denied', ['response' => 403]);
        }

        // 3. Capability check: User must have lfa_view_admin_portal or administrator
        if (!user_can($user_id, LFA_Roles::CAP_VIEW_ADMIN_PORTAL) && !user_can($user_id, 'administrator')) {
            // Check if any specific staff capability exists
            $staff_caps = [
                LFA_Roles::CAP_MANAGE_MEMBERSHIPS,
                LFA_Roles::CAP_VIEW_FINANCIALS,
                LFA_Roles::CAP_MANAGE_CONFERENCES,
                LFA_Roles::CAP_REVIEW_SCHOLARSHIPS,
                LFA_Roles::CAP_MANAGE_BUSINESS_DIRECTORY,
                LFA_Roles::CAP_EDIT_NEWS,
                LFA_Roles::CAP_EDIT_EVENTS,
                LFA_Roles::CAP_MANAGE_ARCHIVES,
                LFA_Roles::CAP_MANAGE_VOLUNTEERS,
                LFA_Roles::CAP_MANAGE_LEADERSHIP,
            ];

            $has_staff_cap = false;
            foreach ($staff_caps as $c) {
                if (user_can($user_id, $c)) {
                    $has_staff_cap = true;
                    break;
                }
            }

            if (!$has_staff_cap) {
                // Deny regular members and redirect to Member Dashboard
                wp_safe_redirect(home_url('/member-dashboard/'));
                exit;
            }
        }
    }

    /**
     * Determines whether the current user can access a specific portal module
     */
    public static function can_access_module(string $module, int $user_id): bool {
        if (user_can($user_id, 'administrator')) {
            return true;
        }

        switch ($module) {
            case 'overview':
                return user_can($user_id, LFA_Roles::CAP_VIEW_ADMIN_PORTAL);

            case 'members':
                return user_can($user_id, LFA_Roles::CAP_MANAGE_MEMBERSHIPS) || user_can($user_id, LFA_Roles::CAP_VIEW_MEMBERSHIP_REPORTS);

            case 'dues':
                return user_can($user_id, LFA_Roles::CAP_VIEW_FINANCIALS) || user_can($user_id, LFA_Roles::CAP_MANAGE_DUES_POLICY);

            case 'donations':
                return user_can($user_id, LFA_Roles::CAP_VIEW_FINANCIALS);

            case 'conference':
                return user_can($user_id, LFA_Roles::CAP_MANAGE_CONFERENCES) || user_can($user_id, LFA_Roles::CAP_VIEW_CONFERENCE_ATTENDEES);

            case 'events-news':
                return user_can($user_id, LFA_Roles::CAP_EDIT_EVENTS) || user_can($user_id, LFA_Roles::CAP_EDIT_NEWS);

            case 'scholarships':
                return user_can($user_id, LFA_Roles::CAP_REVIEW_SCHOLARSHIPS) || user_can($user_id, LFA_Roles::CAP_MANAGE_SCHOLARSHIPS);

            case 'directories':
                return user_can($user_id, LFA_Roles::CAP_MANAGE_BUSINESS_DIRECTORY);

            case 'archives-media':
                return user_can($user_id, LFA_Roles::CAP_MANAGE_ARCHIVES) || user_can($user_id, LFA_Roles::CAP_VIEW_RESTRICTED_ARCHIVES);

            case 'volunteers':
                return user_can($user_id, LFA_Roles::CAP_MANAGE_VOLUNTEERS) || user_can($user_id, LFA_Roles::CAP_VIEW_VOLUNTEERS);

            case 'leadership':
                return user_can($user_id, LFA_Roles::CAP_MANAGE_LEADERSHIP) || user_can($user_id, LFA_Roles::CAP_VIEW_LEADERSHIP);

            case 'notifications':
                return user_can($user_id, LFA_Roles::CAP_MANAGE_NOTIFICATIONS) || user_can($user_id, LFA_Roles::CAP_BROADCAST_NOTIFICATIONS);

            case 'reports':
                return user_can($user_id, LFA_Roles::CAP_VIEW_REPORTS) || user_can($user_id, LFA_Roles::CAP_VIEW_FINANCIALS);

            case 'roles':
                return user_can($user_id, LFA_Roles::CAP_MANAGE_ROLES);

            case 'settings':
                return user_can($user_id, 'administrator');

            default:
                return false;
        }
    }

    /**
     * Assembles complete live portal data from authoritative database tables
     */
    public static function get_portal_data(): array {
        global $wpdb;

        // 1. Members
        $users = get_users(['number' => 100, 'orderby' => 'registered', 'order' => 'DESC']);
        $members_data = [];
        foreach ($users as $u) {
            $members_data[] = [
                'id'             => $u->ID,
                'name'           => $u->display_name ?: $u->user_login,
                'email'          => $u->user_email,
                'alumni_id'      => (string)get_user_meta($u->ID, 'lfa_alumni_id', true) ?: 'Pending',
                'account_status' => (string)get_user_meta($u->ID, 'lfa_account_status', true) ?: 'approved',
                'dues_status'    => (string)get_user_meta($u->ID, 'lfa_dues_status', true) ?: 'unpaid',
                'type'           => ucfirst((string)get_user_meta($u->ID, 'lfa_membership_type', true) ?: 'Individual'),
                'chapter'        => (string)get_user_meta($u->ID, 'lfa_city', true) ?: 'North America',
                'roles'          => $u->roles,
            ];
        }

        // 2. Dues
        $dues_data = class_exists('LFA_Reports') ? LFA_Reports::get_dues_analytics() : [];

        // 3. Donations
        $donation_data = class_exists('LFA_Reports') ? LFA_Reports::get_donation_analytics() : [];

        // 4. Conferences
        $conf_data = class_exists('LFA_Reports') ? LFA_Reports::get_conference_analytics() : [];
        $conf_list = class_exists('LFA_Conference') ? LFA_Conference::get_all_conferences() : [];

        // 5. Scholarships
        $scholar_data = class_exists('LFA_Reports') ? LFA_Reports::get_scholarship_analytics() : [];

        // 6. Volunteers
        $vol_data = class_exists('LFA_Reports') ? LFA_Reports::get_volunteer_analytics() : [];
        $vol_opps = class_exists('LFA_Volunteers') ? LFA_Volunteers::get_opportunities() : [];

        // 7. Leadership
        $officers = class_exists('LFA_Leadership') ? LFA_Leadership::get_officers(null, false) : [];

        // 8. Timeline Milestones
        $timeline = class_exists('LFA_Archives') ? LFA_Archives::get_timeline_milestones() : [];

        // 9. Businesses
        $businesses_table = $wpdb->prefix . 'lfa_businesses';
        $businesses = $wpdb->get_results("SELECT * FROM `{$businesses_table}` ORDER BY `id` DESC LIMIT 50", ARRAY_A);

        // 10. Audit Logs
        $audit_table = class_exists('LFA_Reports') ? LFA_Reports::table_admin_audit() : '';
        $audit_logs = !empty($audit_table) ? $wpdb->get_results("SELECT * FROM `{$audit_table}` ORDER BY `id` DESC LIMIT 30", ARRAY_A) : [];

        // 11. Role Change Audit Logs
        $role_audit_table = class_exists('LFA_Roles') ? LFA_Roles::table_audit() : '';
        $role_audit_logs = !empty($role_audit_table) ? $wpdb->get_results("SELECT * FROM `{$role_audit_table}` ORDER BY `id` DESC LIMIT 30", ARRAY_A) : [];

        // 12. Events & News CPT
        $events = get_posts(['post_type' => 'lfa_event', 'post_status' => ['publish', 'draft', 'pending'], 'numberposts' => 20]);
        $news = get_posts(['post_type' => 'lfa_news', 'post_status' => ['publish', 'draft', 'pending'], 'numberposts' => 20]);

        return [
            'members'         => $members_data,
            'dues'            => $dues_data,
            'donations'       => $donation_data,
            'conference'      => $conf_data,
            'conferences_list'=> $conf_list,
            'scholarships'    => $scholar_data,
            'volunteers'      => $vol_data,
            'volunteer_opps'  => $vol_opps,
            'leadership'      => $officers,
            'timeline'        => $timeline,
            'businesses'      => $businesses,
            'audit_logs'      => $audit_logs,
            'role_audit_logs' => $role_audit_logs,
            'events'          => $events,
            'news'            => $news,
        ];
    }

    /**
     * Handle member approval / rejection / alumni ID assignment post
     */
    public static function handle_member_action(): void {
        check_admin_referer('lfa_portal_member_action', 'lfa_nonce');
        $actor_id = get_current_user_id();

        if (!user_can($actor_id, LFA_Roles::CAP_MANAGE_MEMBERSHIPS) && !user_can($actor_id, 'administrator')) {
            wp_die('Unauthorized', 'Access Denied', ['response' => 403]);
        }

        $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $decision = isset($_POST['decision']) ? sanitize_key($_POST['decision']) : '';
        $alumni_id = isset($_POST['alumni_id']) ? sanitize_text_field($_POST['alumni_id']) : '';

        if ($decision === 'approve') {
            update_user_meta($user_id, 'lfa_account_status', 'approved');
            update_user_meta($user_id, 'lfa_account_approved', '1');
            update_user_meta($user_id, 'lfa_approved_at', current_time('mysql', true));
            update_user_meta($user_id, 'lfa_approved_by', $actor_id);

            if (empty($alumni_id)) {
                $alumni_id = get_user_meta($user_id, 'lfa_alumni_id', true);
                if (empty($alumni_id)) {
                    $alumni_id = 'LFA-NA-' . str_pad((string)$user_id, 6, '0', STR_PAD_LEFT);
                }
            }
            update_user_meta($user_id, 'lfa_alumni_id', $alumni_id);

            // Sync with authoritative normalized membership record and issue initial dues invoice
            if (class_exists('LFA_Membership')) {
                LFA_Membership::get_or_create_membership($user_id);
            }

            if (class_exists('LFA_Reports')) {
                LFA_Reports::log_audit($actor_id, 'member_approved', 'user', $user_id, "Approved member account (Alumni ID: {$alumni_id})");
            }
            if (class_exists('LFA_Notifications')) {
                LFA_Notifications::create_notification(
                    $user_id,
                    'account_approved',
                    'Membership Approved',
                    'Your Love Family Alumni membership account has been verified and approved!',
                    home_url('/member-dashboard/'),
                    "acct_appr_{$user_id}",
                    'user',
                    $user_id
                );
            }
        } elseif ($decision === 'reject') {
            update_user_meta($user_id, 'lfa_account_status', 'rejected');
            update_user_meta($user_id, 'lfa_rejected_at', current_time('mysql', true));
            update_user_meta($user_id, 'lfa_rejected_by', $actor_id);
            delete_user_meta($user_id, 'lfa_account_approved');
            if (class_exists('LFA_Reports')) {
                LFA_Reports::log_audit($actor_id, 'member_rejected', 'user', $user_id, "Rejected member registration");
            }
        }

        $redirect = add_query_arg([
            'lfa_notice' => 'member_updated',
            'msg' => urlencode('Member status updated successfully.'),
        ], wp_get_referer() ?: home_url('/admin-portal/#members'));

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Handle business listing approval / rejection from Admin Portal (BUS-004)
     */
    public static function handle_business_action(): void {
        check_admin_referer('lfa_portal_business_action', 'lfa_nonce');
        $actor_id = get_current_user_id();

        if (!user_can($actor_id, LFA_Roles::CAP_MANAGE_BUSINESS_DIRECTORY) && !user_can($actor_id, 'administrator')) {
            wp_die('Unauthorized', 'Access Denied', ['response' => 403]);
        }

        $business_id = isset($_POST['business_id']) ? (int)$_POST['business_id'] : 0;
        $decision = isset($_POST['decision']) ? sanitize_key($_POST['decision']) : '';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : 'Admin review from portal.';

        if ($business_id > 0 && class_exists('LFA_Business')) {
            if ($decision === 'approve') {
                LFA_Business::review_business($business_id, 'approve', $notes, $actor_id);
            } elseif ($decision === 'reject') {
                LFA_Business::review_business($business_id, 'reject', $notes, $actor_id);
            }
        }

        $redirect = add_query_arg([
            'lfa_notice' => 'business_updated',
            'msg' => urlencode('Business status updated successfully.'),
        ], wp_get_referer() ?: home_url('/admin-portal/#business-moderation'));

        wp_safe_redirect($redirect);
        exit;
    }
}
