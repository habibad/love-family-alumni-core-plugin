<?php
/**
 * Love Family Alumni — Centralized Privacy & Visibility Policy
 *
 * Enforces Public, Members-Only, and Private visibility server-side across
 * all PHP templates, REST routes, AJAX endpoints, and media streams.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Privacy {
    public const PUBLIC  = 'public';
    public const MEMBERS = 'members';
    public const PRIVATE = 'private';

    public static function init(): void {
        // Enforce REST API privacy
        add_filter('rest_prepare_user', [__CLASS__, 'filter_rest_user'], 10, 3);
    }

    /**
     * Normalize visibility value against allowlist. Default is strictly 'private'.
     */
    public static function sanitize_visibility(string $value, string $default = self::PRIVATE): string {
        $allowed = [self::PUBLIC, self::MEMBERS, self::PRIVATE];
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Determines whether $viewer_id is authorized to view a specific visibility level belonging to $subject_id.
     */
    public static function can_view(int $subject_id, string $visibility_level, int $viewer_id = 0): bool {
        if ($subject_id <= 0) {
            return false;
        }

        // 1. Account owner can always view their own data
        if ($viewer_id > 0 && $viewer_id === $subject_id) {
            return true;
        }

        // 2. Site administrators / officers with edit_users capability can view
        if ($viewer_id > 0 && user_can($viewer_id, 'edit_users')) {
            return true;
        }

        // 3. Strictly Private data: inaccessible to any other user
        if ($visibility_level === self::PRIVATE || empty($visibility_level)) {
            return false;
        }

        // 4. Members Only: accessible only by verified & approved alumni members in good financial standing (paid dues), or administrators
        if ($visibility_level === self::MEMBERS) {
            if ($viewer_id <= 0) {
                return false;
            }
            if (user_can($viewer_id, 'manage_options') || user_can($viewer_id, 'edit_users')) {
                return true;
            }
            return class_exists('LFA_Payment_Entitlements')
                ? LFA_Payment_Entitlements::is_full_member($viewer_id)
                : LFA_Core::is_approved($viewer_id);
        }

        // 5. Public: accessible by anyone (including unauthenticated guests)
        if ($visibility_level === self::PUBLIC) {
            return true;
        }

        return false;
    }

    /**
     * Check if viewer can view subject's personal contact information (email, phone).
     * Contact details are PRIVATE by default.
     */
    public static function can_view_contact(int $subject_id, int $viewer_id = 0): bool {
        $contact_visibility = (string) get_user_meta($subject_id, 'lfa_contact_visibility', true);
        if (empty($contact_visibility)) {
            $contact_visibility = self::PRIVATE;
        }
        return self::can_view($subject_id, $contact_visibility, $viewer_id);
    }

    /**
     * Check if viewer can view subject's general profile information.
     */
    public static function can_view_profile(int $subject_id, int $viewer_id = 0): bool {
        $profile_visibility = (string) get_user_meta($subject_id, LFA_Core::META_PRIVACY, true);
        if (empty($profile_visibility)) {
            $profile_visibility = self::PRIVATE;
        }
        return self::can_view($subject_id, $profile_visibility, $viewer_id);
    }

    /**
     * Check if viewer can view subject's profile photo.
     */
    public static function can_view_avatar(int $subject_id, int $viewer_id = 0): bool {
        $avatar_visibility = (string) get_user_meta($subject_id, 'lfa_avatar_visibility', true);
        if (empty($avatar_visibility)) {
            $avatar_visibility = self::PRIVATE;
        }
        return self::can_view($subject_id, $avatar_visibility, $viewer_id);
    }

    /**
     * Returns an allowlisted, privacy-filtered representation of a member's profile for a specific viewer.
     */
    public static function get_filtered_profile(int $subject_id, int $viewer_id = 0): array {
        $user = get_user_by('id', $subject_id);
        if (!$user) {
            return [];
        }

        $can_see_general = self::can_view_profile($subject_id, $viewer_id);
        $can_see_contact = self::can_view_contact($subject_id, $viewer_id);
        $is_owner_or_admin = ($viewer_id > 0 && ($viewer_id === $subject_id || user_can($viewer_id, 'edit_users')));

        $profile = [
            'id'           => $subject_id,
            'display_name' => $can_see_general ? $user->display_name : 'Love Family Alumnus',
            'alumni_id'    => $is_owner_or_admin || $can_see_general ? (string) get_user_meta($subject_id, LFA_Core::META_ID, true) : '',
        ];

        if ($can_see_general) {
            $profile['city']                   = (string) get_user_meta($subject_id, 'lfa_city', true);
            $profile['state']                  = (string) get_user_meta($subject_id, 'lfa_state', true);
            $profile['country']                = (string) get_user_meta($subject_id, 'lfa_country', true);
            $profile['profession']             = (string) get_user_meta($subject_id, 'lfa_profession', true);
            $profile['employer_business']      = (string) get_user_meta($subject_id, 'lfa_employer_business', true);
            $profile['love_family_connection'] = (string) get_user_meta($subject_id, 'lfa_love_family_connection', true);
            $profile['uniben_information']     = (string) get_user_meta($subject_id, 'lfa_uniben_information', true);
            $profile['expertise']              = (string) get_user_meta($subject_id, 'lfa_expertise', true);
            $profile['volunteer_interests']    = (string) get_user_meta($subject_id, 'lfa_volunteer_interests', true);
            $profile['biography']              = (string) get_user_meta($subject_id, 'lfa_biography', true);
            $profile['website']                = (string) get_user_meta($subject_id, 'lfa_website_url', true);
            $profile['social_link']            = (string) get_user_meta($subject_id, 'lfa_social_link', true);
        }

        if ($can_see_contact) {
            $profile['email'] = $user->user_email;
            $profile['phone'] = (string) get_user_meta($subject_id, 'lfa_phone', true);
        }

        // Couple partner info: strictly owner and admin only
        if ($is_owner_or_admin) {
            $profile['membership_type'] = (string) get_user_meta($subject_id, 'lfa_membership_type', true);
            $profile['partner_name']    = (string) get_user_meta($subject_id, 'lfa_partner_name', true);
            $profile['partner_email']   = (string) get_user_meta($subject_id, 'lfa_partner_email', true);
            $profile['account_status']  = (string) get_user_meta($subject_id, LFA_Core::META_STATUS, true);
            $profile['dues_status']     = (string) get_user_meta($subject_id, 'lfa_dues_status', true);
        }

        return $profile;
    }

    /**
     * Filter REST API user responses to prevent data leaks.
     */
    public static function filter_rest_user($response, $user, $request) {
        $viewer_id = get_current_user_id();
        $subject_id = (int) $user->ID;

        // If not admin and not owner, scrub any sensitive user meta
        if (!user_can($viewer_id, 'edit_users') && $viewer_id !== $subject_id) {
            $data = $response->get_data();
            unset(
                $data['email'],
                $data['roles'],
                $data['capabilities'],
                $data['extra_capabilities']
            );
            $response->set_data($data);
        }

        return $response;
    }
}
