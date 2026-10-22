<?php
/**
 * Love Family Alumni — Member Profile Handler
 *
 * Connects all profile fields to wp_users and wp_usermeta with strict server-side
 * input validation, authorization checks, privacy metadata, and completeness calculation.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Profile {
    public const EDITABLE_META_FIELDS = [
        'phone'                  => 'Phone',
        'city'                   => 'City',
        'state'                  => 'State',
        'country'                => 'Country',
        'love_family_connection' => 'Love Family connection',
        'uniben_information'     => 'UNIBEN information',
        'profession'             => 'Profession',
        'employer_business'      => 'Employer / Business',
        'expertise'              => 'Areas of expertise',
        'volunteer_interests'    => 'Volunteer interests',
        'website_url'            => 'Website URL',
        'social_link'            => 'Social media link',
    ];

    public static function init(): void {
        add_shortcode('lfa_member_profile', [__CLASS__, 'profile_shortcode']);
        add_action('admin_post_lfa_update_member_profile', [__CLASS__, 'save_profile']);
        add_action('wp_ajax_lfa_update_member_profile', [__CLASS__, 'save_profile_ajax']);
    }

    /**
     * Calculate profile completeness percentage from actual stored fields.
     */
    public static function calculate_completeness(int $uid): int {
        if ($uid <= 0) {
            return 0;
        }

        $user = get_user_by('id', $uid);
        if (!$user) {
            return 0;
        }

        $checks = [
            'name'       => !empty($user->first_name) && !empty($user->last_name),
            'phone'      => !empty(get_user_meta($uid, 'lfa_phone', true)),
            'location'   => !empty(get_user_meta($uid, 'lfa_city', true)) || !empty(get_user_meta($uid, 'lfa_country', true)),
            'connection' => !empty(get_user_meta($uid, 'lfa_love_family_connection', true)),
            'uniben'     => !empty(get_user_meta($uid, 'lfa_uniben_information', true)),
            'profession' => !empty(get_user_meta($uid, 'lfa_profession', true)),
            'employer'   => !empty(get_user_meta($uid, 'lfa_employer_business', true)),
            'expertise'  => !empty(get_user_meta($uid, 'lfa_expertise', true)),
            'volunteer'  => !empty(get_user_meta($uid, 'lfa_volunteer_interests', true)),
            'biography'  => !empty(get_user_meta($uid, 'lfa_biography', true)),
            'photo'      => LFA_Media::has_avatar($uid),
        ];

        $completed = count(array_filter($checks));
        $total = count($checks);

        return (int) round(($completed / $total) * 100);
    }

    public static function profile_shortcode(): string {
        if (!LFA_Core::can_use_portal()) {
            return '<p>Only approved members can access this profile. <a href="' . esc_url(LFA_Core::account_url('member-login')) . '">Sign in</a>.</p>';
        }
        $uid = get_current_user_id();
        $user = get_user_by('id', $uid);
        if (!$user) { return '<p>Member profile unavailable.</p>'; }
        $type = (string) get_user_meta($uid, 'lfa_membership_type', true);
        $status = (string) get_user_meta($uid, 'lfa_dues_status', true);
        $completeness = self::calculate_completeness($uid);
        ob_start(); ?>
        <section class="lfa-real-profile">
          <?php echo LFA_Core::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          <h2>My Alumni Profile (<?php echo esc_html($completeness); ?>% complete)</h2>
          <p>Alumni ID: <strong><?php echo esc_html(get_user_meta($uid, LFA_Core::META_ID, true) ?: 'Pending assignment'); ?></strong></p>
          <p>Account Status: <span class="badge badge-approved">Approved</span> · Membership Dues: <span class="badge badge-unpaid"><?php echo esc_html($status ? ucfirst($status) : 'Unpaid'); ?></span></p>
          
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="lfa_update_member_profile">
            <?php wp_nonce_field('lfa_update_profile_' . $uid, 'lfa_profile_nonce'); ?>
            <p><label>First name <input name="first_name" maxlength="100" required value="<?php echo esc_attr($user->first_name); ?>"></label></p>
            <p><label>Last name <input name="last_name" maxlength="100" required value="<?php echo esc_attr($user->last_name); ?>"></label></p>
            <p>Email: <strong><?php echo esc_html($user->user_email); ?></strong> (Contact an administrator to change registered email)</p>
            
            <?php foreach (self::EDITABLE_META_FIELDS as $key => $label):
                $k = ($key === 'website_url') ? 'lfa_website_url' : 'lfa_' . $key;
                $v = (string) get_user_meta($uid, $k, true);
                if (empty($v) && $key === 'website_url') {
                    $v = (string) get_user_meta($uid, 'lfa_website', true);
                } ?>
              <p><label><?php echo esc_html($label); ?> <input name="<?php echo esc_attr($key); ?>" maxlength="350" value="<?php echo esc_attr($v); ?>"></label></p>
            <?php endforeach; ?>
            
            <p><label>Biography <textarea name="biography" maxlength="2500" rows="4"><?php echo esc_textarea((string)get_user_meta($uid, 'lfa_biography', true)); ?></textarea></label></p>
            
            <h3>Privacy & Visibility Preferences</h3>
            <p><label>General Profile Visibility
              <select name="profile_visibility">
                <?php foreach (['private' => 'Private (Only you & admins)', 'members' => 'Members Only (Approved alumni)', 'public' => 'Public (Consented directory view)'] as $value => $label): ?>
                  <option value="<?php echo esc_attr($value); ?>" <?php selected(get_user_meta($uid, LFA_Core::META_PRIVACY, true) ?: 'private', $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select></label></p>
            <p><label>Contact Details Visibility (Kept private by default)
              <select name="contact_visibility">
                <?php foreach (['private' => 'Private (Only you & admins)', 'members' => 'Members Only (Approved alumni)', 'public' => 'Public'] as $value => $label): ?>
                  <option value="<?php echo esc_attr($value); ?>" <?php selected(get_user_meta($uid, 'lfa_contact_visibility', true) ?: 'private', $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select></label></p>
            <p><label>Profile Photo Visibility
              <select name="avatar_visibility">
                <?php foreach (['private' => 'Private (Only you & admins)', 'members' => 'Members Only (Approved alumni)', 'public' => 'Public'] as $value => $label): ?>
                  <option value="<?php echo esc_attr($value); ?>" <?php selected(get_user_meta($uid, 'lfa_avatar_visibility', true) ?: 'private', $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select></label></p>
            
            <p><button type="submit" class="button button-primary">Save Profile Changes</button></p>
          </form>
        </section>
        <?php return (string) ob_get_clean();
    }

    public static function process_profile_data(int $uid): array {
        $first = LFA_Core::input('first_name');
        $last  = LFA_Core::input('last_name');
        if (!$first || !$last) {
            return ['success' => false, 'error' => 'incomplete'];
        }

        // 1. Update basic user fields
        $response = wp_update_user([
            'ID'           => $uid,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim($first . ' ' . $last),
        ]);
        if (is_wp_error($response)) {
            return ['success' => false, 'error' => 'register_failed'];
        }

        // 2. Update allowed profile meta fields
        foreach (self::EDITABLE_META_FIELDS as $field => $label) {
            $value = LFA_Core::input($field);
            if (in_array($field, ['website_url', 'website', 'social_link'], true)) {
                $value = $value ? esc_url_raw($value, ['https', 'http']) : '';
            }
            if ($field === 'website' || $field === 'website_url') {
                update_user_meta($uid, 'lfa_website_url', $value);
                update_user_meta($uid, 'lfa_website', $value);
            } else {
                update_user_meta($uid, 'lfa_' . $field, $value);
            }
        }

        // 3. Biography (length limited to 2500 chars, sanitized)
        if (isset($_POST['biography']) && is_string($_POST['biography'])) {
            $bio = sanitize_textarea_field(wp_unslash($_POST['biography']));
            if (mb_strlen($bio) > 2500) {
                $bio = mb_substr($bio, 0, 2500);
            }
            update_user_meta($uid, 'lfa_biography', $bio);
        }

        // 4. Privacy settings (server-enforced allowlist, defaults to private)
        if (isset($_POST['profile_visibility'])) {
            update_user_meta($uid, LFA_Core::META_PRIVACY,
                LFA_Privacy::sanitize_visibility(LFA_Core::input('profile_visibility')));
        }
        if (isset($_POST['contact_visibility'])) {
            update_user_meta($uid, 'lfa_contact_visibility',
                LFA_Privacy::sanitize_visibility(LFA_Core::input('contact_visibility')));
        }
        if (isset($_POST['avatar_visibility'])) {
            update_user_meta($uid, 'lfa_avatar_visibility',
                LFA_Privacy::sanitize_visibility(LFA_Core::input('avatar_visibility')));
        }

        // STRICT FORBIDDEN FIELDS PROTECTION:
        // Do NOT allow writes to: role, lfa_account_status, lfa_alumni_id, lfa_dues_status
        // (Even if sent in $_POST, they are ignored because we only read explicit allowlisted keys)

        return ['success' => true];
    }

    public static function save_profile(): void {
        if (!is_user_logged_in() || !LFA_Core::can_use_portal()) {
            wp_die('Access denied: You must be an approved member to edit your profile.', 'Unauthorized', ['response' => 403]);
        }
        $uid = get_current_user_id();
        if (empty($_POST['lfa_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lfa_profile_nonce'])), 'lfa_update_profile_' . $uid)) {
            wp_die('Invalid security token. Please refresh and try again.', 'Unauthorized', ['response' => 403]);
        }

        $res = self::process_profile_data($uid);
        if (!$res['success']) {
            LFA_Core::redirect_notice('member-dashboard', $res['error']);
        }

        LFA_Core::redirect_notice('member-dashboard', 'profile_saved');
    }

    public static function save_profile_ajax(): void {
        if (!is_user_logged_in() || !LFA_Core::can_use_portal()) {
            wp_send_json_error(['message' => 'Access denied.'], 403);
        }
        $uid = get_current_user_id();
        if (empty($_POST['lfa_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lfa_profile_nonce'])), 'lfa_update_profile_' . $uid)) {
            wp_send_json_error(['message' => 'Security token expired.'], 403);
        }

        $res = self::process_profile_data($uid);
        if (!$res['success']) {
            wp_send_json_error(['message' => 'Incomplete or invalid fields.'], 400);
        }

        wp_send_json_success([
            'message'      => 'Your profile changes were saved successfully.',
            'completeness' => self::calculate_completeness($uid),
        ]);
    }
}
