<?php
/**
 * Love Family Alumni — Centralized Dynamic Alumni Directory Service
 *
 * Implements approved-member directory queries, server-side field-level
 * privacy filtering via LFA_Privacy, and anti-leak protections.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Alumni_Directory {

    /**
     * Query alumni profiles with strict server-side LFA_Privacy enforcement
     */
    public static function get_profiles(array $args = [], int $viewer_id = 0): array {
        global $wpdb;

        if ($viewer_id === 0 && is_user_logged_in()) {
            $viewer_id = get_current_user_id();
        }

        $page     = max(1, (int)($args['page'] ?? 1));
        $per_page = min(50, max(1, (int)($args['per_page'] ?? 12)));

        // Strict Server-Side Access Gate: Block unauthorized visitors before executing any user queries
        if (class_exists('LFA_Payment_Entitlements') && !LFA_Payment_Entitlements::can_access_directory($viewer_id)) {
            return [
                'total'       => 0,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => 0,
                'profiles'    => [],
                'locked'      => true,
            ];
        }

        // 1. Candidate Set: Only approved active members with role lfa_member or administrator
        // Must have lfa_account_approved = 1 and membership status != rejected
        $user_query_args = [
            'meta_query' => [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    [
                        'key'     => 'lfa_account_status',
                        'value'   => 'approved',
                        'compare' => '=',
                    ],
                    [
                        'key'     => 'lfa_account_approved',
                        'value'   => '1',
                        'compare' => '=',
                    ],
                ],
                [
                    'key'     => 'lfa_account_status',
                    'value'   => ['rejected', 'pending_approval'],
                    'compare' => 'NOT IN',
                ],
            ],
            'number'  => -1, // Fetch candidates to filter through privacy
            'fields'  => 'all',
        ];

        $user_query = new WP_User_Query($user_query_args);
        $candidates = $user_query->get_results();

        $visible_profiles = [];

        foreach ($candidates as $user) {
            $subject_id = (int)$user->ID;

            // 2. Gate 1: Check overall profile visibility for current viewer
            if (!LFA_Privacy::can_view_profile($subject_id, $viewer_id)) {
                continue;
            }

            // Extract metadata
            $meta = get_user_meta($subject_id);
            $profession  = $meta['lfa_profession'][0] ?? '';
            $company     = $meta['lfa_employer_business'][0] ?? ($meta['lfa_employer'][0] ?? ($meta['lfa_company'][0] ?? ''));
            $industry    = $meta['lfa_industry'][0] ?? '';
            $expertise   = $meta['lfa_expertise'][0] ?? '';
            $country     = $meta['lfa_country'][0] ?? '';
            $state       = $meta['lfa_state'][0] ?? '';
            $city        = $meta['lfa_city'][0] ?? '';
            $class_year  = $meta['lfa_class_year'][0] ?? ($meta['lfa_grad_year'][0] ?? ($meta['lfa_uniben_information'][0] ?? ''));
            $department  = $meta['lfa_department'][0] ?? ($meta['lfa_uniben_dept'][0] ?? '');
            $bio         = $meta['lfa_biography'][0] ?? ($meta['lfa_bio'][0] ?? ($user->description ?? ''));
            $linkedin    = $meta['lfa_linkedin'][0] ?? '';
            $alumni_id   = $meta['lfa_alumni_id'][0] ?? '';

            $name = $user->display_name ?: trim(($meta['first_name'][0] ?? '') . ' ' . ($meta['last_name'][0] ?? ''));
            if (empty($name)) {
                $name = $user->user_login;
            }

            // Location display
            $loc_parts = array_filter([$city, $state, $country]);
            $location_str = implode(', ', $loc_parts);

            // 3. Apply Filter & Search Conditions
            $search_kw = $args['keyword'] ?? ($args['search'] ?? ($args['q'] ?? ''));
            if (!empty($search_kw)) {
                $kw = strtolower(trim($search_kw));
                $haystack = strtolower("{$name} {$profession} {$company} {$industry} {$expertise} {$city} {$state} {$country} {$department} {$class_year} {$bio}");
                if (!str_contains($haystack, $kw)) {
                    continue;
                }
            }

            if (!empty($args['profession'])) {
                $req_prof = strtolower(trim($args['profession']));
                if (!str_contains(strtolower($profession), $req_prof) && !str_contains(strtolower($industry), $req_prof)) {
                    continue;
                }
            }

            if (!empty($args['industry'])) {
                $req_ind = strtolower(trim($args['industry']));
                if (!str_contains(strtolower($industry), $req_ind)) {
                    continue;
                }
            }

            if (!empty($args['country'])) {
                if (strcasecmp(trim($country), trim($args['country'])) !== 0) {
                    continue;
                }
            }

            if (!empty($args['location'])) {
                $req_loc = strtolower(trim($args['location']));
                if (!str_contains(strtolower($location_str), $req_loc)) {
                    continue;
                }
            }

            if (!empty($args['class_year'])) {
                if (trim($class_year) !== trim($args['class_year'])) {
                    continue;
                }
            }

            // 4. Field-Level Privacy Scrubbing
            $can_contact = LFA_Privacy::can_view_contact($subject_id, $viewer_id);
            $can_avatar  = LFA_Privacy::can_view_avatar($subject_id, $viewer_id);

            $avatar_url = '';
            if ($can_avatar && class_exists('LFA_Media')) {
                $avatar_url = LFA_Media::get_avatar_url($subject_id);
            }

            $email = $can_contact ? $user->user_email : '';
            $phone = $can_contact ? ($meta['lfa_phone'][0] ?? '') : '';

            $visible_profiles[] = [
                'id'          => $subject_id,
                'name'        => $name,
                'title'       => $profession ?: 'Alumnus',
                'company'     => $company,
                'industry'    => $industry,
                'expertise'   => $expertise,
                'location'    => $location_str ?: 'North America Zone',
                'country'     => $country,
                'state'       => $state,
                'city'        => $city,
                'batch'       => $class_year ?: 'Alumni',
                'department'  => $department,
                'bio'         => $bio,
                'avatar'      => $avatar_url,
                'email'       => $email,
                'phone'       => $phone,
                'linkedin'    => $linkedin,
                'alumni_id'   => ($can_contact || $viewer_id === $subject_id || user_can($viewer_id, 'edit_users')) ? $alumni_id : '',
            ];
        }

        // Sorting
        $sort = $args['sort'] ?? 'name_asc';
        usort($visible_profiles, function($a, $b) use ($sort) {
            return match ($sort) {
                'name_desc' => strcasecmp($b['name'], $a['name']),
                'year_desc' => strcmp($b['batch'], $a['batch']),
                'recent'    => ($b['id'] <=> $a['id']),
                default     => strcasecmp($a['name'], $b['name']),
            };
        });

        $total = count($visible_profiles);
        $offset = ($page - 1) * $per_page;
        $paged_profiles = array_slice($visible_profiles, $offset, $per_page);

        return [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int)ceil($total / $per_page),
            'profiles'    => $paged_profiles,
        ];
    }

    /**
     * Get single member profile with privacy validation
     */
    public static function get_profile_by_id(int $subject_id, int $viewer_id = 0): ?array {
        if ($subject_id <= 0) {
            return null;
        }

        if ($viewer_id === 0 && is_user_logged_in()) {
            $viewer_id = get_current_user_id();
        }

        // Strict Server-Side Gate: Only authorized viewers, account owner, or staff can view profile details
        $is_self  = ($viewer_id > 0 && $viewer_id === $subject_id);
        $is_admin = user_can($viewer_id, 'edit_users') || user_can($viewer_id, 'manage_options');
        if (!$is_self && !$is_admin) {
            if (class_exists('LFA_Payment_Entitlements') && !LFA_Payment_Entitlements::can_access_directory($viewer_id)) {
                return null;
            }
        }

        // Must be an approved member
        if (!LFA_Core::is_approved($subject_id) && !user_can($subject_id, 'manage_options')) {
            return null;
        }

        // Must be permitted by privacy policy
        if (!LFA_Privacy::can_view_profile($subject_id, $viewer_id)) {
            return null;
        }

        $user = get_userdata($subject_id);
        if (!$user) {
            return null;
        }

        $meta = get_user_meta($subject_id);
        $can_contact = LFA_Privacy::can_view_contact($subject_id, $viewer_id);
        $can_avatar  = LFA_Privacy::can_view_avatar($subject_id, $viewer_id);

        $name = $user->display_name ?: trim(($meta['first_name'][0] ?? '') . ' ' . ($meta['last_name'][0] ?? ''));
        $city = $meta['lfa_city'][0] ?? '';
        $state = $meta['lfa_state'][0] ?? '';
        $country = $meta['lfa_country'][0] ?? '';
        $loc_parts = array_filter([$city, $state, $country]);

        return [
            'id'          => $subject_id,
            'name'        => $name,
            'title'       => $meta['lfa_profession'][0] ?? 'Alumnus',
            'company'     => $meta['lfa_employer'][0] ?? ($meta['lfa_company'][0] ?? ''),
            'industry'    => $meta['lfa_industry'][0] ?? '',
            'expertise'   => $meta['lfa_expertise'][0] ?? '',
            'location'    => implode(', ', $loc_parts) ?: 'North America Zone',
            'country'     => $country,
            'state'       => $state,
            'city'        => $city,
            'batch'       => $meta['lfa_class_year'][0] ?? ($meta['lfa_grad_year'][0] ?? 'Alumni'),
            'department'  => $meta['lfa_department'][0] ?? ($meta['lfa_uniben_dept'][0] ?? ''),
            'bio'         => $meta['lfa_bio'][0] ?? ($user->description ?? ''),
            'avatar'      => ($can_avatar && class_exists('LFA_Media')) ? LFA_Media::get_avatar_url($subject_id) : '',
            'email'       => $can_contact ? $user->user_email : '',
            'phone'       => $can_contact ? ($meta['lfa_phone'][0] ?? '') : '',
            'linkedin'    => $meta['lfa_linkedin'][0] ?? '',
            'alumni_id'   => ($can_contact || $viewer_id === $subject_id || user_can($viewer_id, 'edit_users')) ? ($meta['lfa_alumni_id'][0] ?? '') : '',
        ];
    }
}
