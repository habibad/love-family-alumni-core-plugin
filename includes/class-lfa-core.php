<?php
if (!defined('ABSPATH')) { exit; }

final class LFA_Core {
    const ROLE = 'lfa_member';
    const META_STATUS = 'lfa_account_status';
    const META_PRIVACY = 'lfa_profile_visibility';
    const META_ID = 'lfa_alumni_id';

    public static function activate(): void {
        self::register_roles();
    }

    public static function init(): void {
        self::register_roles();
        // Protect member portal / dashboard from non-approved or unauthenticated users
        add_action('template_redirect', [__CLASS__, 'protect_portal'], 1);

        // Prevent regular lfa_member users from accessing wp-admin backoffice screens
        add_action('admin_init', [__CLASS__, 'restrict_admin_backoffice']);
    }

    public static function register_roles(): void {
        if (!get_role(self::ROLE)) {
            add_role(self::ROLE, 'Love Family Member', ['read' => true]);
        }

        if (!get_role('lfa_communications_editor')) {
            add_role('lfa_communications_editor', 'Communications Editor', [
                'read'            => true,
                'upload_files'    => true,
                'lfa_edit_events' => true,
                'lfa_edit_news'   => true,
            ]);
        }

        if (!get_role('lfa_communications_manager')) {
            add_role('lfa_communications_manager', 'Communications Manager / PRO', [
                'read'               => true,
                'upload_files'       => true,
                'lfa_edit_events'    => true,
                'lfa_publish_events' => true,
                'lfa_delete_events'  => true,
                'lfa_edit_news'      => true,
                'lfa_publish_news'   => true,
                'lfa_delete_news'    => true,
            ]);
        }

        if (!get_role('lfa_archive_manager')) {
            add_role('lfa_archive_manager', 'Archive Manager', [
                'read'                         => true,
                'upload_files'                 => true,
                'lfa_manage_archives'          => true,
                'lfa_publish_archives'         => true,
                'lfa_delete_archives'          => true,
                'lfa_view_restricted_archives' => true,
            ]);
        }

        // Grant all content capabilities to Administrator role
        $admin = get_role('administrator');
        if ($admin) {
            $caps = [
                'lfa_edit_events', 'lfa_publish_events', 'lfa_delete_events',
                'lfa_edit_news', 'lfa_publish_news', 'lfa_delete_news',
                'lfa_manage_archives', 'lfa_publish_archives', 'lfa_delete_archives',
                'lfa_view_restricted_archives',
            ];
            foreach ($caps as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    public static function restrict_admin_backoffice(): void {
        if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }
        // Exempt admin-post.php so authorized member POST actions (profile save, avatar upload, etc.) execute
        if (isset($_SERVER['SCRIPT_NAME']) && str_contains($_SERVER['SCRIPT_NAME'], 'admin-post.php')) {
            return;
        }
        if (!is_user_logged_in()) {
            return;
        }
        // Allow administrators and authorized staff officers (scholarship, conference, business, membership, communications, archives)
        if (
            current_user_can('manage_options') ||
            current_user_can('edit_users') ||
            current_user_can('lfa_manage_memberships') ||
            current_user_can('lfa_manage_businesses') ||
            current_user_can('lfa_manage_conferences') ||
            current_user_can('lfa_review_scholarships') ||
            current_user_can('lfa_manage_scholarships') ||
            current_user_can('lfa_manage_donations') ||
            current_user_can('lfa_view_financials') ||
            current_user_can('lfa_edit_events') ||
            current_user_can('lfa_publish_events') ||
            current_user_can('lfa_edit_news') ||
            current_user_can('lfa_publish_news') ||
            current_user_can('lfa_manage_archives') ||
            current_user_can('lfa_publish_archives')
        ) {
            return;
        }
        $user = wp_get_current_user();
        if (in_array(self::ROLE, (array) $user->roles, true)) {
            wp_safe_redirect(home_url('/member-dashboard/'));
            exit;
        }

    }

    public static function member_status(int $uid): string {
        return (string) get_user_meta($uid, self::META_STATUS, true);
    }

    public static function is_approved(int $uid): bool {
        return self::member_status($uid) === 'approved' || get_user_meta($uid, 'lfa_account_approved', true) === '1';
    }

    public static function can_use_portal(): bool {
        if (!is_user_logged_in()) { return false; }
        if (current_user_can('manage_options')) { return true; }
        return self::is_approved(get_current_user_id());
    }

    public static function protect_portal(): void {
        // Adjust these exact slugs against the existing local route inventory.
        if (!is_page(['member-dashboard', 'member-portal'])) { return; }
        if (self::can_use_portal()) { return; }
        $current_url = home_url(add_query_arg([], $GLOBALS['wp']->request ?? 'member-dashboard'));
        $login_url = add_query_arg([
            'lfa_notice'  => 'login_required',
            'redirect_to' => rawurlencode($current_url),
        ], home_url('/member-login/'));
        wp_safe_redirect($login_url);
        exit;
    }

    public static function account_url(string $path): string {
        return home_url('/' . trim($path, '/') . '/');
    }

    public static function redirect_notice(string $page, string $code): void {
        wp_safe_redirect(add_query_arg('lfa_notice', sanitize_key($code), self::account_url($page)));
        exit;
    }

    public static function notice_html(): string {
        $key = isset($_GET['lfa_notice']) ? sanitize_key(wp_unslash($_GET['lfa_notice'])) : '';
        $messages = [
            'registered'             => 'Registration received. Please check your email to verify your address. Your account also requires administrator approval.',
            'verified'               => 'Email confirmed. Your account is awaiting administrator review.',
            'approved'               => 'Your membership account has been approved. You can sign in.',
            'profile_saved'          => 'Your profile changes were saved successfully.',
            'avatar_updated'         => 'Profile photo updated successfully.',
            'avatar_deleted'         => 'Profile photo removed successfully.',
            'password_invalid'       => 'Choose a password with at least 12 characters and matching confirmation.',
            'email_invalid'          => 'Please enter a valid email address.',
            'incomplete'             => 'Please fill all required fields and accept the privacy notice.',
            'already_exists'         => 'An account with that email may already exist. Try signing in or recovering your password.',
            'rate_limited'           => 'Too many attempts. Please try again later.',
            'register_failed'        => 'Registration could not be completed. Please try again or contact the team.',
            'upload_invalid'         => 'Photo must be a valid JPG, PNG or WebP image under 3 MB.',
            'login_required'         => 'Sign in with an approved membership account to access the portal.',
            'login_pending_email'    => 'Your email address has not been verified yet. Please check your inbox for the verification link.',
            'login_pending_approval' => 'Your alumni membership account is currently awaiting administrator review.',
            'login_rejected'         => 'Your alumni membership application was not approved. Please contact the membership team for details.',
            'login_invalid'          => 'Invalid email address or password. Please try again.',
            'reset_sent'             => 'If an account exists with that email address, password recovery instructions have been sent.',
            'scholarship_draft_saved' => 'Your scholarship application draft has been saved.',
            'scholarship_submitted'   => 'Your scholarship application has been successfully submitted! A confirmation email has been dispatched.',
            'scholarship_doc_uploaded' => 'Supporting document uploaded successfully to secure private storage.',
            'scholarship_info_submitted' => 'Additional information submitted successfully. The review committee has been notified.',
            'scholarship_closed'     => 'Applications are currently closed for this scholarship program.',
            'scholarship_error'      => 'An error occurred with your scholarship request. Please review the details and try again.',
        ];
        if (!empty($_GET['lfa_error'])) {
            $err = sanitize_text_field(wp_unslash($_GET['lfa_error']));
            return '<div class="lfa-account-notice alert-error" role="status">' . esc_html($err) . '</div>';
        }
        if (!isset($messages[$key])) { return ''; }
        $is_success = in_array($key, ['registered', 'verified', 'approved', 'profile_saved', 'avatar_updated', 'avatar_deleted', 'reset_sent', 'scholarship_draft_saved', 'scholarship_submitted', 'scholarship_doc_uploaded', 'scholarship_info_submitted'], true);
        $cls = $is_success ? 'alert-success' : 'alert-error';
        return '<div class="lfa-account-notice ' . esc_attr($cls) . '" role="status">' . esc_html($messages[$key]) . '</div>';
    }

    public static function input(string $name, string $fallback = ''): string {
        return isset($_POST[$name]) && is_scalar($_POST[$name])
            ? sanitize_text_field(wp_unslash((string) $_POST[$name])) : $fallback;
    }

    public static function choices(string $value, array $allowed, string $default): string {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Security-hardened email event logger.
     * Stores ONLY metadata: timestamp, event name, user ID, recipient domain, status.
     * NEVER stores message bodies, reset tokens, verification links, or sensitive data.
     */
    public static function log_email_event(string $event, int $user_id, string $recipient_email, string $status = 'dispatched'): void {
        $domain = '';
        if (str_contains($recipient_email, '@')) {
            $parts = explode('@', $recipient_email);
            $domain = sanitize_text_field(end($parts));
        }
        $log_entry = [
            'time'             => current_time('mysql'),
            'event'            => sanitize_key($event),
            'user_id'          => $user_id,
            'recipient_domain' => $domain,
            'status'           => in_array($status, ['dispatched', 'delivery_failed'], true) ? $status : 'dispatched',
        ];
        $recent = get_option('lfa_email_events', []);
        if (!is_array($recent)) { $recent = []; }
        array_unshift($recent, $log_entry);
        if (count($recent) > 50) { $recent = array_slice($recent, 0, 50); }
        update_option('lfa_email_events', $recent, false);
    }

    /**
     * Backward compatible shim for log_email - strips all content.
     */
    public static function log_email(string $to, string $subject, string $message): void {
        self::log_email_event('email_notification', 0, $to, 'dispatched');
    }
}
