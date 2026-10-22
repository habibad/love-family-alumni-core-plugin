<?php
if (!defined('ABSPATH')) { exit; }

final class LFA_Auth {
    public static function init(): void {
        add_shortcode('lfa_member_registration', [__CLASS__, 'registration_shortcode']);
        add_shortcode('lfa_member_login', [__CLASS__, 'login_shortcode']);
        add_shortcode('lfa_member_password_reset', [__CLASS__, 'password_shortcode']);

        add_action('admin_post_nopriv_lfa_register_member', [__CLASS__, 'handle_registration']);
        add_action('admin_post_lfa_register_member', [__CLASS__, 'handle_registration']);

        add_action('admin_post_nopriv_lfa_member_login', [__CLASS__, 'handle_login']);
        add_action('admin_post_lfa_member_login', [__CLASS__, 'handle_login']);

        add_action('admin_post_nopriv_lfa_forgot_password', [__CLASS__, 'handle_forgot_password']);
        add_action('admin_post_lfa_forgot_password', [__CLASS__, 'handle_forgot_password']);

        add_action('init', [__CLASS__, 'verify_email']);
        add_filter('authenticate', [__CLASS__, 'prevent_pending_login'], 30, 3);
        add_action('template_redirect', [__CLASS__, 'redirect_authenticated_users']);
    }

    public static function registration_shortcode(): string {
        if (is_user_logged_in()) { return '<p>You are already signed in.</p>'; }
        ob_start(); ?>
        <section class="lfa-real-registration" aria-label="Love Family Alumni registration">
          <?php echo LFA_Core::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="on">
            <input type="hidden" name="action" value="lfa_register_member">
            <?php wp_nonce_field('lfa_register_member', 'lfa_registration_nonce'); ?>
            <div style="position:absolute;left:-10000px" aria-hidden="true"><label>Leave this field empty<input type="text" name="lfa_website" tabindex="-1" autocomplete="off"></label></div>
            <fieldset><legend>Personal information</legend>
              <p><label>First name * <input name="first_name" autocomplete="given-name" required maxlength="100"></label></p>
              <p><label>Last name * <input name="last_name" autocomplete="family-name" required maxlength="100"></label></p>
              <p><label>Email * <input type="email" name="email" autocomplete="email" required maxlength="190"></label></p>
              <p><label>Phone <input name="phone" autocomplete="tel" maxlength="35"></label></p>
              <p><label>City <input name="city" maxlength="100"></label></p>
              <p><label>State <input name="state" maxlength="100"></label></p>
              <p><label>Country <input name="country" maxlength="100"></label></p>
              <p><label>Password * <input type="password" name="password" minlength="12" autocomplete="new-password" required></label></p>
              <p><label>Confirm password * <input type="password" name="confirm_password" minlength="12" autocomplete="new-password" required></label></p>
            </fieldset>
            <fieldset><legend>Love Family background</legend>
              <p><label>Love Family connection * <input name="love_family_connection" required maxlength="250"></label></p>
              <p><label>UNIBEN information (if applicable) <input name="uniben_information" maxlength="250"></label></p>
              <p><label>Profession <input name="profession" maxlength="150"></label></p>
              <p><label>Employer/business <input name="employer_business" maxlength="150"></label></p>
              <p><label>Areas of expertise <input name="expertise" maxlength="350"></label></p>
              <p><label>Volunteer interests <input name="volunteer_interests" maxlength="350"></label></p>
              <p><label>Biography <textarea name="biography" maxlength="2500"></textarea></label></p>
            </fieldset>
            <fieldset><legend>Account Information</legend>
              <p><label>Account Type <input type="text" readonly value="Free General User Account" style="background:#f1f5f9;"></label></p>
              <input type="hidden" name="membership_type" value="general">
              <p><label>Profile visibility <select name="profile_visibility"><option value="private">Private (recommended)</option><option value="members">Members Only</option><option value="public">Public</option></select></label></p>
              <p><label><input type="checkbox" name="accept_privacy" value="1" required> I agree to submit my details and acknowledge the site's published privacy notice.</label></p>
              <p><button type="submit">Create General User Account</button></p>
            </fieldset>
            <p>After email verification, you can log in to your General User Dashboard. Annual membership ($100/yr) can be upgraded from the Membership page.</p>
          </form>
        </section>
        <?php return (string) ob_get_clean();
    }

    public static function login_shortcode(): string {
        if (is_user_logged_in()) { return '<p>You are signed in. <a href="' . esc_url(LFA_Core::account_url('member-dashboard')) . '">Open dashboard</a></p>'; }
        ob_start();
        echo LFA_Core::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        wp_login_form(['redirect' => LFA_Core::account_url('member-dashboard'), 'remember' => false]);
        echo '<p><a href="' . esc_url(wp_lostpassword_url()) . '">Forgot your password?</a></p>';
        echo '<p><a href="' . esc_url(LFA_Core::account_url('member-register')) . '">Register a member account</a></p>';
        return (string) ob_get_clean();
    }

    public static function password_shortcode(): string {
        return '<p>Reset your password using WordPress’s secure password recovery flow. '
            . '<a href="' . esc_url(wp_lostpassword_url()) . '">Request a password reset</a></p>';
    }

    public static function prevent_pending_login($user, $username, $password) {
        if (is_wp_error($user) || !($user instanceof WP_User)) { return $user; }
        if (!in_array(LFA_Core::ROLE, (array) $user->roles, true)) { return $user; }
        $status = LFA_Core::member_status((int) $user->ID);
        if ($status === 'pending_email') {
            return new WP_Error('lfa_pending_email', __('Your email address has not been verified yet. Please check your inbox for the verification link.', 'love-family-core'));
        }
        if ($status === 'rejected') {
            return new WP_Error('lfa_rejected', __('Your account registration was not approved. Please contact the membership team for details.', 'love-family-core'));
        }
        // General Users with verified email can log in to their limited General User Dashboard
        return $user;
    }

    private static function throttle(): bool {
        $raw_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $key = 'lfa_reg_' . substr(hash_hmac('sha256', $raw_ip, wp_salt('auth')), 0, 28);
        $attempts = (int) get_transient($key);
        if ($attempts >= 5) { return false; }
        set_transient($key, $attempts + 1, HOUR_IN_SECONDS);
        return true;
    }

    public static function handle_registration(): void {
        if (is_user_logged_in()) { LFA_Core::redirect_notice('member-register', 'register_failed'); }

        if (empty($_POST['lfa_registration_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lfa_registration_nonce'])), 'lfa_register_member')) {
            wp_die('Registration security token expired. Refresh the page and try again.', 'Invalid request', ['response' => 403]);
        }

        if (!empty($_POST['lfa_website']) || !self::throttle()) {
            LFA_Core::redirect_notice('member-register', 'rate_limited');
        }

        // Support both canonical and theme field aliases
        $first = LFA_Core::input('first_name');
        $last  = LFA_Core::input('last_name');
        if (empty($first) && !empty($_POST['fullname'])) {
            $raw_name = sanitize_text_field(wp_unslash($_POST['fullname']));
            $parts = explode(' ', $raw_name, 2);
            $first = $parts[0] ?? '';
            $last  = $parts[1] ?? ($parts[0] ?? '');
        }

        $email = sanitize_email(LFA_Core::input('email'));
        $pass = isset($_POST['password']) && is_string($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        $confirm = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? (string) wp_unslash($_POST['confirm_password']) : '';
        if (empty($confirm) && isset($_POST['confirmpassword']) && is_string($_POST['confirmpassword'])) {
            $confirm = (string) wp_unslash($_POST['confirmpassword']);
        }

        $conn = LFA_Core::input('love_family_connection');
        if (empty($conn)) {
            $conn = LFA_Core::input('affiliation');
        }

        $accept = LFA_Core::input('accept_privacy');
        if (empty($accept) && LFA_Core::input('consent') === '1') {
            $accept = '1';
        }

        if (!$first || !$last || !$email || !$conn || $accept !== '1') {
            LFA_Core::redirect_notice('member-register', 'incomplete');
        }

        if (!is_email($email)) {
            LFA_Core::redirect_notice('member-register', 'email_invalid');
        }

        if (strlen($pass) < 12 || strlen($pass) > 256 || !hash_equals($pass, $confirm)) {
            LFA_Core::redirect_notice('member-register', 'password_invalid');
        }

        if (email_exists($email)) {
            LFA_Core::redirect_notice('member-register', 'already_exists');
        }

        try {
            $login = 'lfa_' . bin2hex(random_bytes(10));
        } catch (Exception $e) {
            LFA_Core::redirect_notice('member-register', 'register_failed');
        }

        $uid = wp_insert_user([
            'user_login'   => $login,
            'user_pass'    => $pass,
            'user_email'   => $email,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim($first . ' ' . $last),
            'role'         => LFA_Core::ROLE,
        ]);
        unset($pass, $confirm);

        if (is_wp_error($uid) || !$uid) {
            LFA_Core::redirect_notice('member-register', 'register_failed');
        }

        // City & State
        $city  = LFA_Core::input('city');
        $state = LFA_Core::input('state');
        if (empty($city) && !empty($_POST['citystate'])) {
            $parts = explode(',', sanitize_text_field(wp_unslash($_POST['citystate'])), 2);
            $city  = trim($parts[0] ?? '');
            $state = trim($parts[1] ?? '');
        }

        $employer = LFA_Core::input('employer_business');
        if (empty($employer)) {
            $employer = LFA_Core::input('employer');
        }

        $fields = [
            'phone'                  => LFA_Core::input('phone'),
            'city'                   => $city,
            'state'                  => $state,
            'country'                => LFA_Core::input('country'),
            'love_family_connection' => $conn,
            'uniben_information'     => LFA_Core::input('uniben_information', LFA_Core::input('batch')),
            'profession'             => LFA_Core::input('profession'),
            'employer_business'      => $employer,
            'expertise'              => LFA_Core::input('expertise'),
            'volunteer_interests'    => LFA_Core::input('volunteer_interests'),
        ];
        foreach ($fields as $key => $val) {
            update_user_meta((int) $uid, 'lfa_' . $key, $val);
        }

        $bio = LFA_Core::input('biography');
        if (empty($bio)) {
            $bio = LFA_Core::input('intro');
        }
        update_user_meta((int) $uid, 'lfa_biography', $bio);
        // Free General User account created; paid membership upgrade ($100/yr) is purchased separately
        update_user_meta((int) $uid, 'lfa_membership_type', 'general');

        $visibility = LFA_Core::input('profile_visibility', LFA_Core::input('directory_privacy'));
        if ($visibility === 'members_only') { $visibility = 'members'; }
        update_user_meta((int) $uid, LFA_Core::META_PRIVACY,
            LFA_Core::choices($visibility, ['private', 'members', 'public'], 'private'));

        // Sensitive contact and media information has a separate PRIVATE default, regardless of general profile choice.
        update_user_meta((int) $uid, 'lfa_contact_visibility', 'private');
        update_user_meta((int) $uid, 'lfa_avatar_visibility', 'private');
        update_user_meta((int) $uid, LFA_Core::META_STATUS, 'pending_email');
        update_user_meta((int) $uid, 'lfa_dues_status', 'unpaid');

        self::send_verification((int) $uid);
        LFA_Core::redirect_notice('member-register', 'registered');
    }

    /**
     * Resolves the authoritative role-aware dashboard URL for an authenticated user.
     */
    public static function get_user_dashboard_url(?WP_User $user = null): string {
        if (!$user) {
            if (!is_user_logged_in()) {
                return home_url('/member-login/');
            }
            $user = wp_get_current_user();
        }

        if (!$user || !$user->ID) {
            return home_url('/member-login/');
        }

        // 1. Full administrator
        if (user_can($user, 'manage_options')) {
            return home_url('/admin-portal/');
        }

        // 2. Staff roles with administrative responsibilities
        $staff_roles = [
            'lfa_president',
            'lfa_general_secretary',
            'lfa_treasurer',
            'lfa_financial_secretary',
            'lfa_communications_manager',
            'lfa_communications_editor',
            'lfa_conference_admin',
            'lfa_conference_publisher',
            'lfa_conference_pub',
            'lfa_scholarship_admin',
            'lfa_scholarship_reviewer',
            'lfa_scholarship_rev',
            'lfa_archive_manager',
            'lfa_business_moderator',
        ];

        foreach ($staff_roles as $role) {
            if (in_array($role, (array) $user->roles, true)) {
                return home_url('/admin-portal/');
            }
        }

        if (user_can($user, 'lfa_view_admin_portal')) {
            return home_url('/admin-portal/');
        }

        // 3. Approved alumni member
        if (LFA_Core::is_approved((int) $user->ID) || in_array(LFA_Core::ROLE, (array) $user->roles, true) || in_array('lfa_member', (array) $user->roles, true)) {
            return home_url('/member-dashboard/');
        }

        return home_url('/');
    }

    /**
     * Redirects authenticated, authorized users away from authentication-only screens.
     */
    public static function redirect_authenticated_users(): void {
        if (!is_user_logged_in()) {
            return;
        }

        if (!is_page(['member-login', 'login', 'member-register', 'join', 'forgot-password'])) {
            return;
        }

        $user = wp_get_current_user();
        $status = LFA_Core::member_status((int) $user->ID);

        // Only rejected accounts should be prevented from reaching the dashboard
        if (!user_can($user, 'manage_options') && !user_can($user, 'lfa_view_admin_portal')) {
            if ($status === 'rejected') {
                return;
            }
        }

        $redirect_to = isset($_REQUEST['redirect_to']) ? sanitize_text_field(wp_unslash($_REQUEST['redirect_to'])) : '';
        $default_url = self::get_user_dashboard_url($user);

        if (!empty($redirect_to)) {
            $target_url = wp_validate_redirect($redirect_to, $default_url);
        } else {
            $target_url = $default_url;
        }

        wp_safe_redirect($target_url);
        exit;
    }

    public static function handle_login(): void {
        if (is_user_logged_in()) {
            wp_safe_redirect(self::get_user_dashboard_url());
            exit;
        }

        if (empty($_POST['lfa_login_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lfa_login_nonce'])), 'lfa_member_login')) {
            wp_die('Login security token expired. Refresh the page and try again.', 'Invalid request', ['response' => 403]);
        }

        $email = sanitize_email(LFA_Core::input('email'));
        $pass  = isset($_POST['password']) && is_string($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        $remember = !empty($_POST['remember']);

        if (!$email || !$pass) {
            LFA_Core::redirect_notice('member-login', 'incomplete');
        }

        $creds = [
            'user_login'    => $email,
            'user_password' => $pass,
            'remember'      => $remember,
        ];
        $user = wp_signon($creds, is_ssl());

        if (is_wp_error($user)) {
            $code = $user->get_error_code();
            if ($code === 'lfa_pending_email') {
                LFA_Core::redirect_notice('member-login', 'login_pending_email');
            } elseif ($code === 'lfa_pending_approval') {
                LFA_Core::redirect_notice('member-login', 'login_pending_approval');
            } elseif ($code === 'lfa_rejected') {
                LFA_Core::redirect_notice('member-login', 'login_rejected');
            } else {
                LFA_Core::redirect_notice('member-login', 'login_invalid');
            }
        }

        $redirect_to = !empty($_POST['redirect_to']) ? sanitize_text_field(wp_unslash($_POST['redirect_to'])) : '';
        $default_url = self::get_user_dashboard_url($user);

        if (!empty($redirect_to)) {
            $target_url = wp_validate_redirect($redirect_to, $default_url);
        } else {
            $target_url = $default_url;
        }

        wp_safe_redirect($target_url);
        exit;
    }

    public static function handle_forgot_password(): void {
        if (empty($_POST['lfa_forgot_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lfa_forgot_nonce'])), 'lfa_forgot_password')) {
            wp_die('Password recovery security token expired. Refresh the page and try again.', 'Invalid request', ['response' => 403]);
        }

        $email = sanitize_email(LFA_Core::input('email'));
        if (!$email || !is_email($email)) {
            LFA_Core::redirect_notice('forgot-password', 'email_invalid');
        }

        // Trigger native core password recovery email
        retrieve_password($email);

        LFA_Core::redirect_notice('forgot-password', 'reset_sent');
    }

    public static function send_verification(int $uid): bool {
        $user = get_user_by('id', $uid);
        if (!$user || LFA_Core::member_status($uid) !== 'pending_email') { return false; }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            return false;
        }

        update_user_meta($uid, 'lfa_verify_hash', hash_hmac('sha256', $token, wp_salt('auth')));
        update_user_meta($uid, 'lfa_verify_expires', time() + DAY_IN_SECONDS);

        $link = add_query_arg(['lfa_verify' => '1', 'uid' => $uid, 'token' => $token], home_url('/'));
        $subject = 'Verify your Love Family Alumni account';
        $message = "Hello " . esc_html($user->first_name) . ",\n\n"
            . "Please verify your email address for your Love Family Alumni membership:\n\n"
            . $link . "\n\n"
            . "This link expires in 24 hours. Your membership is subject to administrator approval.\n\n"
            . "If you did not request this, you may safely ignore this email.\n\n"
            . "Love Family Alumni — North America Zone";

        LFA_Core::log_email_event('verification_email', $uid, $user->user_email, 'dispatched');
        return (bool) wp_mail($user->user_email, $subject, $message);
    }

    public static function verify_email(): void {
        if (!isset($_GET['lfa_verify']) || (string) $_GET['lfa_verify'] !== '1') { return; }
        $uid = isset($_GET['uid']) ? absint($_GET['uid']) : 0;
        $token = isset($_GET['token']) && is_string($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $hash = (string) get_user_meta($uid, 'lfa_verify_hash', true);
        $expiry = (int) get_user_meta($uid, 'lfa_verify_expires', true);

        if (!$uid || !preg_match('/^[a-f0-9]{64}$/', $token) || !$hash || time() > $expiry
            || LFA_Core::member_status($uid) !== 'pending_email'
            || !hash_equals($hash, hash_hmac('sha256', $token, wp_salt('auth')))) {
            wp_die('This verification link is invalid or expired. Contact the membership team to request another link.', 'Link expired', ['response' => 400]);
        }

        delete_user_meta($uid, 'lfa_verify_hash');
        delete_user_meta($uid, 'lfa_verify_expires');
        update_user_meta($uid, 'lfa_email_verified', '1');
        update_user_meta($uid, LFA_Core::META_STATUS, 'pending_approval');

        $user = get_user_by('id', $uid);
        $name = $user ? $user->display_name : 'Member';
        $admin_subject = 'Love Family Alumni membership awaiting review';
        $admin_message = "Member {$name} (User ID {$uid}) has verified their email address and awaits administrator approval.\n\n"
            . "Review applications: " . admin_url('users.php?page=lfa-members');

        $admin_email = (string) get_option('admin_email');
        LFA_Core::log_email_event('admin_review_notice', $uid, $admin_email, 'dispatched');
        wp_mail($admin_email, $admin_subject, $admin_message);

        LFA_Core::redirect_notice('member-login', 'verified');
    }
}
