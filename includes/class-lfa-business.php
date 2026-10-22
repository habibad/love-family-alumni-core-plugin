<?php
/**
 * Love Family Alumni — Business Directory Service
 *
 * Implements authoritative business listing management, schema migrations,
 * submission lifecycle, moderation workflows, logo sanitization, and audit logging.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Business {

    public const SCHEMA_VERSION = '0.6.0';

    public const STATUS_DRAFT          = 'draft';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_PENDING        = 'pending_review';
    public const STATUS_APPROVED       = 'approved';
    public const STATUS_REJECTED       = 'rejected';
    public const STATUS_ARCHIVED       = 'archived';

    public const CAP_MANAGE = 'lfa_manage_business_directory';
    public const CAP_EXPORT = 'lfa_export_business_directory';

    public static function init(): void {
        self::ensure_schema();
        self::ensure_storage_dir();
        self::register_capabilities();
    }

    public static function table_businesses(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_businesses';
    }

    public static function table_audit(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_business_audit';
    }

    public static function get_storage_dir(): string {
        return WP_CONTENT_DIR . '/lfa-private-storage/business_logos';
    }

    public static function ensure_storage_dir(): string {
        $dir = self::get_storage_dir();
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $htaccess = "# Deny direct access to business logos\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        $index    = "<?php\nhttp_response_code(403);\nexit('Direct access forbidden.');\n";

        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', $htaccess);
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', $index);
        }

        return $dir;
    }

    /**
     * Map capabilities to Administrator and custom moderator roles
     */
    public static function register_capabilities(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::CAP_MANAGE);
            $admin->add_cap(self::CAP_EXPORT);
        }

        // Add lfa_business_moderator role if not present
        if (!get_role('lfa_business_moderator')) {
            add_role('lfa_business_moderator', 'Alumni Business Moderator', [
                'read'             => true,
                self::CAP_MANAGE   => true,
                self::CAP_EXPORT   => true,
            ]);
        }
    }

    /**
     * Idempotent schema installation using dbDelta
     */
    public static function ensure_schema(): void {
        $installed = get_option('lfa_business_schema_version', '');
        if ($installed === self::SCHEMA_VERSION) {
            return;
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Businesses Table
        $t_biz = self::table_businesses();
        $sql_biz = "CREATE TABLE {$t_biz} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            business_ref varchar(64) NOT NULL,
            owner_user_id bigint(20) unsigned NOT NULL,
            name varchar(150) NOT NULL,
            slug varchar(150) NOT NULL,
            category varchar(100) NOT NULL,
            description text NOT NULL,
            country varchar(100) NOT NULL,
            state varchar(100) NOT NULL,
            city varchar(100) NOT NULL,
            website_url varchar(255) NULL,
            public_email varchar(150) NULL,
            public_phone varchar(50) NULL,
            logo_file varchar(255) NULL,
            social_links_json text NULL,
            contact_consent tinyint(1) NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'draft',
            moderation_notes text NULL,
            revision_version int(11) NOT NULL DEFAULT 1,
            pending_changes_json longtext NULL,
            submitted_at datetime NULL,
            approved_at datetime NULL,
            approved_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY business_ref_unique (business_ref),
            UNIQUE KEY slug_unique (slug),
            KEY owner_user_id (owner_user_id),
            KEY category (category),
            KEY status (status)
        ) {$charset_collate};";
        dbDelta($sql_biz);

        // 2. Business Audit Table
        $t_audit = self::table_audit();
        $sql_audit = "CREATE TABLE {$t_audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            business_id bigint(20) unsigned NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            actor_role varchar(50) NOT NULL,
            action varchar(50) NOT NULL,
            old_status varchar(30) NULL,
            new_status varchar(30) NULL,
            note text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY business_id (business_id),
            KEY actor_id (actor_id),
            KEY action (action)
        ) {$charset_collate};";
        dbDelta($sql_audit);

        update_option('lfa_business_schema_version', self::SCHEMA_VERSION);
    }

    /**
     * Standard categories
     */
    public static function get_standard_categories(): array {
        return [
            'Technology & IT',
            'Healthcare & Wellness',
            'Education & Training',
            'Real Estate & Construction',
            'Finance & Investment',
            'Consulting & Professional',
            'Retail & E-commerce',
            'Travel & Hospitality',
        ];
    }

    /**
     * Generate unique business reference: BIZ-2027-XXXXXXXX
     */
    public static function generate_business_ref(): string {
        global $wpdb;
        $t = self::table_businesses();
        do {
            $ref = 'BIZ-2027-' . strtoupper(wp_generate_password(8, false, false));
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE business_ref = %s", $ref));
        } while ($exists > 0);
        return $ref;
    }

    /**
     * Generate unique slug
     */
    public static function generate_unique_slug(string $name, int $exclude_id = 0): string {
        global $wpdb;
        $t = self::table_businesses();
        $base = sanitize_title($name);
        if (empty($base)) {
            $base = 'business-' . time();
        }
        $slug = $base;
        $counter = 1;
        while (true) {
            $sql = "SELECT id FROM {$t} WHERE slug = %s";
            $params = [$slug];
            if ($exclude_id > 0) {
                $sql .= " AND id != %d";
                $params[] = $exclude_id;
            }
            $exists = $wpdb->get_var($wpdb->prepare($sql, ...$params));
            if (!$exists) {
                return $slug;
            }
            $counter++;
            $slug = $base . '-' . $counter;
        }
    }

    /**
     * Create a business listing draft
     */
    public static function create_business(array $data, int $owner_user_id): array {
        global $wpdb;

        // Gate: Owner must be an approved alumni member with verified paid annual dues
        if (!user_can($owner_user_id, 'manage_options')) {
            if (!LFA_Core::is_approved($owner_user_id)) {
                return [
                    'success' => false,
                    'code'    => 'ACCOUNT_NOT_APPROVED',
                    'error'   => 'Only administrator-approved alumni members are eligible to register businesses.',
                ];
            }
            if (class_exists('LFA_Payment_Entitlements') && !LFA_Payment_Entitlements::is_full_member($owner_user_id)) {
                return [
                    'success' => false,
                    'code'    => 'MEMBERSHIP_DUES_REQUIRED',
                    'error'   => 'Business Directory registration requires an active paid alumni membership ($100.00 annual dues). Please settle your annual dues to unlock this benefit.',
                ];
            }
        }

        $name = sanitize_text_field($data['name'] ?? '');
        if (empty($name)) {
            return [
                'success' => false,
                'code'    => 'MISSING_NAME',
                'error'   => 'Business name is required.',
            ];
        }

        $category = sanitize_text_field($data['category'] ?? '');
        if (empty($category) || !in_array($category, self::get_standard_categories(), true)) {
            return [
                'success' => false,
                'code'    => 'INVALID_CATEGORY',
                'error'   => 'Please select a valid business category.',
            ];
        }

        $description = sanitize_textarea_field($data['description'] ?? '');
        if (empty($description)) {
            return [
                'success' => false,
                'code'    => 'MISSING_DESCRIPTION',
                'error'   => 'Business description is required.',
            ];
        }

        $country = sanitize_text_field($data['country'] ?? '');
        $state   = sanitize_text_field($data['state'] ?? '');
        $city    = sanitize_text_field($data['city'] ?? '');
        if (empty($country) || empty($city)) {
            return [
                'success' => false,
                'code'    => 'MISSING_LOCATION',
                'error'   => 'Country and City are required.',
            ];
        }

        $website = esc_url_raw($data['website_url'] ?? '');
        if (!empty($website) && !filter_var($website, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'code'    => 'INVALID_WEBSITE',
                'error'   => 'Invalid website URL format.',
            ];
        }

        $contact_consent = !empty($data['contact_consent']) ? 1 : 0;
        $public_email    = sanitize_email($data['public_email'] ?? '');
        $public_phone    = sanitize_text_field($data['public_phone'] ?? '');

        // If contact details supplied without consent, contact_consent is 0 and will be hidden from public display

        $social_links = [
            'linkedin'  => esc_url_raw($data['linkedin'] ?? ''),
            'twitter'   => esc_url_raw($data['twitter'] ?? ''),
            'facebook'  => esc_url_raw($data['facebook'] ?? ''),
            'instagram' => esc_url_raw($data['instagram'] ?? ''),
        ];

        $ref  = self::generate_business_ref();
        $slug = self::generate_unique_slug($name);
        $now  = current_time('mysql', true);

        $inserted = $wpdb->insert(self::table_businesses(), [
            'business_ref'       => $ref,
            'owner_user_id'      => $owner_user_id,
            'name'               => $name,
            'slug'               => $slug,
            'category'           => $category,
            'description'        => $description,
            'country'            => $country,
            'state'              => $state,
            'city'               => $city,
            'website_url'        => $website,
            'public_email'       => $public_email,
            'public_phone'       => $public_phone,
            'logo_file'          => null,
            'social_links_json'  => wp_json_encode($social_links),
            'contact_consent'    => $contact_consent,
            'status'             => self::STATUS_DRAFT,
            'moderation_notes'   => null,
            'revision_version'   => 1,
            'created_at'         => $now,
            'updated_at'         => $now,
        ], [
            '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s'
        ]);

        if (!$inserted) {
            return [
                'success' => false,
                'code'    => 'DB_ERROR',
                'error'   => 'Failed to save business listing: ' . $wpdb->last_error,
            ];
        }

        $business_id = (int)$wpdb->insert_id;
        self::log_audit($business_id, $owner_user_id, 'created', null, self::STATUS_DRAFT, 'Created draft listing');

        return [
            'success'      => true,
            'business_id'  => $business_id,
            'business_ref' => $ref,
            'slug'         => $slug,
            'status'       => self::STATUS_DRAFT,
        ];
    }

    /**
     * Submit draft for moderation review (alias)
     */
    public static function submit_business(int $business_id, int $user_id): array {
        return self::submit_for_review($business_id, $user_id);
    }

    /**
     * Submit draft for moderation review
     */
    public static function submit_for_review(int $business_id, int $user_id): array {
        global $wpdb;
        $biz = self::get_business_by_id($business_id);
        if (!$biz) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Business listing not found.'];
        }

        // Ownership check
        if ((int)$biz->owner_user_id !== $user_id && !user_can($user_id, self::CAP_MANAGE)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'You do not own this business listing.'];
        }

        // Gate: Owner must have active paid membership to submit listing for moderation
        if (!user_can($user_id, self::CAP_MANAGE)) {
            if (class_exists('LFA_Payment_Entitlements') && !LFA_Payment_Entitlements::is_full_member($user_id)) {
                return [
                    'success' => false,
                    'code'    => 'MEMBERSHIP_DUES_REQUIRED',
                    'error'   => 'Submitting a business listing for moderation requires an active paid alumni membership ($100.00 annual dues). Please settle your annual dues in your dashboard.',
                ];
            }
        }

        if (!in_array($biz->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true)) {
            return ['success' => false, 'code' => 'INVALID_STATE', 'error' => 'Only draft or rejected listings can be submitted. Current status: ' . $biz->status];
        }

        $now = current_time('mysql', true);
        $wpdb->update(self::table_businesses(), [
            'status'       => self::STATUS_PENDING_REVIEW,
            'submitted_at' => $now,
            'updated_at'   => $now,
        ], ['id' => $business_id], ['%s', '%s', '%s'], ['%d']);

        self::log_audit($business_id, $user_id, 'submitted', $biz->status, self::STATUS_PENDING_REVIEW, 'Submitted listing for review');

        // Trigger notification to admin
        self::send_moderation_email('submitted', $biz);

        return ['success' => true, 'status' => self::STATUS_PENDING_REVIEW];
    }

    /**
     * Update a business listing
     */
    public static function update_business(int $business_id, array $data, int $user_id): array {
        global $wpdb;
        $biz = self::get_business_by_id($business_id);
        if (!$biz) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Business listing not found.'];
        }

        // Ownership & capability check
        $is_admin = user_can($user_id, self::CAP_MANAGE);
        if ((int)$biz->owner_user_id !== $user_id && !$is_admin) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Unauthorized: You do not own this listing.'];
        }

        // Prevent self-assigning approved status
        if (isset($data['status']) && $data['status'] === self::STATUS_APPROVED && !$is_admin) {
            return ['success' => false, 'code' => 'FORBIDDEN_STATUS', 'error' => 'Members cannot self-approve listings.'];
        }

        $name = isset($data['name']) ? sanitize_text_field($data['name']) : $biz->name;
        $category = isset($data['category']) ? sanitize_text_field($data['category']) : $biz->category;
        $description = isset($data['description']) ? sanitize_textarea_field($data['description']) : $biz->description;
        $country = isset($data['country']) ? sanitize_text_field($data['country']) : $biz->country;
        $state = isset($data['state']) ? sanitize_text_field($data['state']) : $biz->state;
        $city = isset($data['city']) ? sanitize_text_field($data['city']) : $biz->city;
        $website = isset($data['website_url']) ? esc_url_raw($data['website_url']) : $biz->website_url;
        $public_email = isset($data['public_email']) ? sanitize_email($data['public_email']) : $biz->public_email;
        $public_phone = isset($data['public_phone']) ? sanitize_text_field($data['public_phone']) : $biz->public_phone;
        $contact_consent = isset($data['contact_consent']) ? (!empty($data['contact_consent']) ? 1 : 0) : (int)$biz->contact_consent;



        $social_links = [
            'linkedin'  => isset($data['linkedin']) ? esc_url_raw($data['linkedin']) : '',
            'twitter'   => isset($data['twitter']) ? esc_url_raw($data['twitter']) : '',
            'facebook'  => isset($data['facebook']) ? esc_url_raw($data['facebook']) : '',
            'instagram' => isset($data['instagram']) ? esc_url_raw($data['instagram']) : '',
        ];

        $now = current_time('mysql', true);

        // If currently APPROVED and edited by member: store in pending_changes_json so live version stays intact!
        if ($biz->status === self::STATUS_APPROVED && !$is_admin) {
            $pending_payload = [
                'name'              => $name,
                'category'          => $category,
                'description'       => $description,
                'country'           => $country,
                'state'             => $state,
                'city'              => $city,
                'website_url'       => $website,
                'public_email'      => $public_email,
                'public_phone'      => $public_phone,
                'social_links_json' => wp_json_encode($social_links),
                'contact_consent'   => $contact_consent,
                'submitted_at'      => $now,
            ];

            $wpdb->update(self::table_businesses(), [
                'pending_changes_json' => wp_json_encode($pending_payload),
                'updated_at'           => $now,
            ], ['id' => $business_id], ['%s', '%s'], ['%d']);

            self::log_audit($business_id, $user_id, 'updated_pending_revision', $biz->status, $biz->status, 'Submitted revision for approval while keeping active listing public');
            return ['success' => true, 'status' => $biz->status, 'pending_review' => true];
        }

        // Standard update for draft, rejected, or admin editing
        $update_data = [
            'name'              => $name,
            'category'          => $category,
            'description'       => $description,
            'country'           => $country,
            'state'             => $state,
            'city'              => $city,
            'website_url'       => $website,
            'public_email'      => $public_email,
            'public_phone'      => $public_phone,
            'social_links_json' => wp_json_encode($social_links),
            'contact_consent'   => $contact_consent,
            'updated_at'        => $now,
        ];

        $wpdb->update(self::table_businesses(), $update_data, ['id' => $business_id]);
        self::log_audit($business_id, $user_id, 'updated', $biz->status, $biz->status, 'Updated listing details');

        return ['success' => true, 'status' => $biz->status];
    }

    /**
     * Admin review action: Approve or Reject
     */
    public static function review_business(int $business_id, string $action, string $notes, int $admin_user_id): array {
        global $wpdb;

        if (!user_can($admin_user_id, self::CAP_MANAGE)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'You do not have permission to moderate businesses.'];
        }

        $biz = self::get_business_by_id($business_id);
        if (!$biz) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Business listing not found.'];
        }

        $now = current_time('mysql', true);
        $clean_notes = sanitize_textarea_field($notes);

        if ($action === 'approve' || $action === 'approved') {
            // If there were pending changes on an approved listing, apply them!
            $apply_changes = [];
            if (!empty($biz->pending_changes_json)) {
                $pending = json_decode($biz->pending_changes_json, true);
                if (is_array($pending)) {
                    $apply_changes = $pending;
                }
            }

            $update = array_merge($apply_changes, [
                'status'               => self::STATUS_APPROVED,
                'approved_at'          => $now,
                'approved_by'          => $admin_user_id,
                'moderation_notes'     => $clean_notes,
                'pending_changes_json' => null,
                'revision_version'     => (int)$biz->revision_version + 1,
                'updated_at'           => $now,
            ]);

            $wpdb->update(self::table_businesses(), $update, ['id' => $business_id]);
            self::log_audit($business_id, $admin_user_id, 'approved', $biz->status, self::STATUS_APPROVED, $clean_notes);

            self::send_moderation_email('approved', $biz, $clean_notes);
            return ['success' => true, 'status' => self::STATUS_APPROVED];

        } elseif ($action === 'reject' || $action === 'rejected') {
            if (empty($clean_notes)) {
                return ['success' => false, 'code' => 'NOTE_REQUIRED', 'error' => 'A clear rejection reason is required.'];
            }

            $wpdb->update(self::table_businesses(), [
                'status'               => self::STATUS_REJECTED,
                'moderation_notes'     => $clean_notes,
                'pending_changes_json' => null,
                'updated_at'           => $now,
            ], ['id' => $business_id], ['%s', '%s', '%s', '%s'], ['%d']);

            self::log_audit($business_id, $admin_user_id, 'rejected', $biz->status, self::STATUS_REJECTED, $clean_notes);
            self::send_moderation_email('rejected', $biz, $clean_notes);
            return ['success' => true, 'status' => self::STATUS_REJECTED];

        } elseif ($action === 'archive' || $action === 'archived') {
            $wpdb->update(self::table_businesses(), [
                'status'     => self::STATUS_ARCHIVED,
                'updated_at' => $now,
            ], ['id' => $business_id], ['%s', '%s'], ['%d']);

            self::log_audit($business_id, $admin_user_id, 'archived', $biz->status, self::STATUS_ARCHIVED, $clean_notes);
            return ['success' => true, 'status' => self::STATUS_ARCHIVED];
        }

        return ['success' => false, 'code' => 'INVALID_ACTION', 'error' => 'Unknown moderation action.'];
    }

    /**
     * Withdraw business by owner
     */
    public static function withdraw_business(int $business_id, int $user_id): array {
        global $wpdb;
        $biz = self::get_business_by_id($business_id);
        if (!$biz) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Listing not found.'];
        }

        if ((int)$biz->owner_user_id !== $user_id && !user_can($user_id, self::CAP_MANAGE)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Unauthorized'];
        }

        $now = current_time('mysql', true);
        $wpdb->update(self::table_businesses(), [
            'status'     => self::STATUS_ARCHIVED,
            'updated_at' => $now,
        ], ['id' => $business_id], ['%s', '%s'], ['%d']);

        self::log_audit($business_id, $user_id, 'withdrawn', $biz->status, self::STATUS_ARCHIVED, 'Withdrawn by owner');
        return ['success' => true, 'status' => self::STATUS_ARCHIVED];
    }

    /**
     * Fetch single business by ID
     */
    public static function get_business_by_id(int $id): ?stdClass {
        global $wpdb;
        $t = self::table_businesses();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id));
        return $row ?: null;
    }

    /**
     * Fetch single business by slug
     */
    public static function get_business_by_slug(string $slug): ?stdClass {
        global $wpdb;
        $t = self::table_businesses();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE slug = %s", $slug));
        return $row ?: null;
    }

    /**
     * Fetch businesses owned by user
     */
    public static function get_user_businesses(int $user_id): array {
        global $wpdb;
        $t = self::table_businesses();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE owner_user_id = %d ORDER BY created_at DESC",
            $user_id
        ));
    }

    /**
     * Search & query approved public businesses (alias)
     */
    public static function get_approved_businesses(array $args = []): array {
        return self::query_public_businesses($args);
    }

    /**
     * Search & query approved public businesses
     */
    public static function query_public_businesses(array $args = []): array {
        global $wpdb;
        $t = self::table_businesses();

        $viewer_id = isset($args['viewer_id']) ? (int)$args['viewer_id'] : get_current_user_id();

        $page     = max(1, (int)($args['page'] ?? 1));
        $per_page = min(50, max(1, (int)($args['per_page'] ?? 12)));
        $offset   = ($page - 1) * $per_page;

        // Strict Server-Side Access Gate: Block unauthorized visitors before querying any business records
        if (class_exists('LFA_Payment_Entitlements') && !LFA_Payment_Entitlements::can_access_directory($viewer_id)) {
            return [
                'total'        => 0,
                'page'         => $page,
                'per_page'     => $per_page,
                'total_pages'  => 0,
                'businesses'   => [],
                'locked'       => true,
            ];
        }

        $where = ["b.status = 'approved'"];
        $params = [];

        // Inactive / unapproved owner check: Owner must be an approved active member and NOT rejected
        $where[] = "EXISTS (
            SELECT 1 FROM {$wpdb->usermeta} um 
            WHERE um.user_id = b.owner_user_id 
              AND (
                  (um.meta_key = 'lfa_account_status' AND um.meta_value = 'approved')
                  OR (um.meta_key = 'lfa_account_approved' AND um.meta_value = '1')
              )
        ) AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->usermeta} um2
            WHERE um2.user_id = b.owner_user_id
              AND um2.meta_key = 'lfa_account_status'
              AND um2.meta_value IN ('rejected', 'suspended')
        )";

        // Keyword search (Name, Description, City, State)
        if (!empty($args['keyword'])) {
            $kw = '%' . $wpdb->esc_like(sanitize_text_field($args['keyword'])) . '%';
            $where[] = "(b.name LIKE %s OR b.description LIKE %s OR b.city LIKE %s OR b.state LIKE %s OR b.category LIKE %s)";
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }

        // Category filter
        if (!empty($args['category'])) {
            $cat = sanitize_text_field($args['category']);
            $where[] = "b.category = %s";
            $params[] = $cat;
        }

        // Country filter
        if (!empty($args['country'])) {
            $country = sanitize_text_field($args['country']);
            $where[] = "b.country = %s";
            $params[] = $country;
        }

        // City / State filter
        if (!empty($args['city'])) {
            $city = sanitize_text_field($args['city']);
            $where[] = "(b.city = %s OR b.state = %s)";
            $params[] = $city;
            $params[] = $city;
        }

        $where_sql = implode(' AND ', $where);

        // Sort allowlist
        $sort = $args['sort'] ?? 'recent';
        $order_sql = match ($sort) {
            'name_asc'  => 'b.name ASC',
            'name_desc' => 'b.name DESC',
            'oldest'    => 'b.created_at ASC',
            default     => 'b.created_at DESC',
        };

        // Total count
        $count_sql = "SELECT COUNT(*) FROM {$t} b WHERE {$where_sql}";
        $total = (int) $wpdb->get_var(!empty($params) ? $wpdb->prepare($count_sql, ...$params) : $count_sql);

        // Paged rows
        $data_sql = "SELECT b.* FROM {$t} b WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
        $paged_params = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($data_sql, ...$paged_params));

        return [
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $per_page,
            'total_pages'  => ceil($total / $per_page),
            'businesses'   => $rows,
        ];
    }

    /**
     * Upload and sanitize business logo (alias)
     */
    public static function upload_logo(int $business_id, array $file, int $user_id): array {
        return self::handle_logo_upload($business_id, $file, $user_id);
    }

    /**
     * Upload and sanitize business logo
     */
    public static function handle_logo_upload(int $business_id, array $file, int $user_id): array {
        global $wpdb;
        $biz = self::get_business_by_id($business_id);
        if (!$biz) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Business listing not found.'];
        }

        if ((int)$biz->owner_user_id !== $user_id && !user_can($user_id, self::CAP_MANAGE)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Unauthorized'];
        }

        if (empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            return ['success' => false, 'code' => 'NO_FILE', 'error' => 'No file uploaded.'];
        }

        // Size check: Max 3MB
        if ($file['size'] > 3145728) {
            return ['success' => false, 'code' => 'FILE_TOO_LARGE', 'error' => 'File size exceeds 3MB limit.'];
        }

        // Strict MIME check
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed_mimes, true)) {
            return ['success' => false, 'code' => 'INVALID_MIME', 'error' => 'Only JPG, PNG, and WebP logos are permitted. SVG and executable files are rejected.'];
        }

        // Polyglot and extension check
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (in_array($ext, ['php', 'phtml', 'svg', 'html', 'js', 'exe'], true)) {
            return ['success' => false, 'code' => 'MALICIOUS_EXTENSION', 'error' => 'Prohibited file extension.'];
        }

        // Image decode via GD
        $img_info = @getimagesize($file['tmp_name']);
        if (!$img_info) {
            return ['success' => false, 'code' => 'CORRUPT_IMAGE', 'error' => 'File is not a valid image.'];
        }

        $w = (int)$img_info[0];
        $h = (int)$img_info[1];
        if ($w < 50 || $h < 50 || $w > 3000 || $h > 3000) {
            return ['success' => false, 'code' => 'INVALID_DIMENSIONS', 'error' => 'Image dimensions must be between 50x50 and 3000x3000.'];
        }

        $src_img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => @imagecreatefrompng($file['tmp_name']),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : null,
            default      => null,
        };

        if (!$src_img) {
            return ['success' => false, 'code' => 'DECODE_FAILED', 'error' => 'Could not safely process image.'];
        }

        // Resize / normalize max 500x500 box
        $max_box = 500;
        $ratio = min($max_box / $w, $max_box / $h, 1.0);
        $new_w = max(1, (int)round($w * $ratio));
        $new_h = max(1, (int)round($h * $ratio));

        $dest_img = imagecreatetruecolor($new_w, $new_h);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($dest_img, false);
            imagesavealpha($dest_img, true);
            $transparent = imagecolorallocatealpha($dest_img, 255, 255, 255, 127);
            imagefilledrectangle($dest_img, 0, 0, $new_w, $new_h, $transparent);
        }

        imagecopyresampled($dest_img, $src_img, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
        imagedestroy($src_img);

        $storage_dir = self::ensure_storage_dir();
        $safe_filename = 'biz_logo_' . $business_id . '_' . wp_generate_password(8, false, false) . '.png';
        $dest_path = $storage_dir . '/' . $safe_filename;

        // Save sanitized PNG (EXIF and comments stripped)
        imagesavealpha($dest_img, true);
        imagepng($dest_img, $dest_path, 8);
        imagedestroy($dest_img);

        // Delete previous logo file if exists
        if (!empty($biz->logo_file)) {
            $old_path = $storage_dir . '/' . basename($biz->logo_file);
            if (file_exists($old_path)) {
                @unlink($old_path);
            }
        }

        $wpdb->update(self::table_businesses(), [
            'logo_file'  => $safe_filename,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $business_id], ['%s', '%s'], ['%d']);

        self::log_audit($business_id, $user_id, 'logo_updated', null, null, 'Uploaded and sanitized logo: ' . $safe_filename);

        return [
            'success'   => true,
            'logo_file' => $safe_filename,
            'logo_url'  => self::get_logo_url($business_id),
        ];
    }

    /**
     * Get logo URL
     */
    public static function get_logo_url(int $business_id): string {
        return admin_url('admin-post.php?action=lfa_business_logo&bid=' . $business_id);
    }

    /**
     * Serve business logo with privacy & approval enforcement
     */
    public static function serve_logo(): void {
        $bid = isset($_GET['bid']) ? absint($_GET['bid']) : 0;
        if ($bid <= 0) {
            status_header(404);
            exit('Logo not found.');
        }

        $biz = self::get_business_by_id($bid);
        if (!$biz || empty($biz->logo_file)) {
            status_header(404);
            exit('No logo available.');
        }

        $viewer_id = get_current_user_id();
        $is_owner  = ($viewer_id > 0 && (int)$biz->owner_user_id === $viewer_id);
        $is_admin  = user_can($viewer_id, self::CAP_MANAGE);

        // Privacy Gate: If business is NOT approved, only owner or admin can view logo!
        if ($biz->status !== self::STATUS_APPROVED && !$is_owner && !$is_admin) {
            status_header(403);
            header('Cache-Control: private, no-store, no-cache');
            exit('Access Denied: Unapproved business logo.');
        }

        // Directory Access Gate: Block unauthorized users from scraping business logos
        if (!$is_owner && !$is_admin) {
            if (class_exists('LFA_Payment_Entitlements') && !LFA_Payment_Entitlements::can_access_directory($viewer_id)) {
                status_header(403);
                header('Cache-Control: private, no-store, no-cache');
                exit('Access Denied: Directory membership required.');
            }
        }

        $filepath = self::get_storage_dir() . '/' . basename($biz->logo_file);
        if (!file_exists($filepath)) {
            status_header(404);
            exit('Logo file missing.');
        }

        header('Content-Type: image/png');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        readfile($filepath);
        exit;
    }

    /**
     * Immutable audit logging
     */
    public static function log_audit(int $business_id, int $actor_id, string $action, ?string $old_status, ?string $new_status, ?string $note = ''): void {
        global $wpdb;
        $actor_role = 'guest';
        if ($actor_id > 0) {
            $user = get_userdata($actor_id);
            if ($user && !empty($user->roles)) {
                $actor_role = $user->roles[0];
            }
        }

        // Redact any PII or sensitive patterns from note
        $clean_note = $note ? preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $note) : '';

        $wpdb->insert(self::table_audit(), [
            'business_id' => $business_id,
            'actor_id'    => $actor_id,
            'actor_role'  => $actor_role,
            'action'      => sanitize_key($action),
            'old_status'  => $old_status ? sanitize_key($old_status) : null,
            'new_status'  => $new_status ? sanitize_key($new_status) : null,
            'note'        => $clean_note,
            'created_at'  => current_time('mysql', true),
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']);
    }

    /**
     * Export businesses to CSV with formula injection neutralization
     */
    public static function export_csv(): void {
        if (!current_user_can(self::CAP_EXPORT)) {
            wp_die('Unauthorized', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_export_businesses_action', '_lfa_export_nonce');

        global $wpdb;
        $t = self::table_businesses();
        $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id ASC", ARRAY_A);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="lfa_businesses_export_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM

        fputcsv($out, [
            'ID', 'Business Ref', 'Name', 'Category', 'Country', 'State', 'City',
            'Website', 'Company Email', 'Company Phone', 'Contact Consent', 'Status',
            'Revision', 'Created At', 'Approved At'
        ]);

        foreach ($rows as $r) {
            $row_data = [
                $r['id'],
                $r['business_ref'],
                self::neutralize_csv_formula($r['name']),
                self::neutralize_csv_formula($r['category']),
                self::neutralize_csv_formula($r['country']),
                self::neutralize_csv_formula($r['state']),
                self::neutralize_csv_formula($r['city']),
                self::neutralize_csv_formula($r['website_url'] ?? ''),
                // Only export company email/phone if consent was given
                $r['contact_consent'] ? self::neutralize_csv_formula($r['public_email'] ?? '') : '[NOT_OPTED_IN]',
                $r['contact_consent'] ? self::neutralize_csv_formula($r['public_phone'] ?? '') : '[NOT_OPTED_IN]',
                $r['contact_consent'] ? 'YES' : 'NO',
                $r['status'],
                $r['revision_version'],
                $r['created_at'],
                $r['approved_at'] ?? 'N/A',
            ];
            fputcsv($out, $row_data);
        }

        fclose($out);
        exit;
    }

    /**
     * Neutralize spreadsheet formula injection (=, +, -, @)
     */
    public static function escape_csv_field(string $val): string {
        return self::neutralize_csv_formula($val);
    }

    public static function neutralize_csv_formula(string $val): string {
        if (isset($val[0]) && in_array($val[0], ['=', '+', '-', '@'], true)) {
            return "'" . $val;
        }
        return $val;
    }

    /**
     * Moderation email notification via wp_mail()
     */
    public static function send_moderation_email(string $event, stdClass $biz, string $feedback = ''): bool {
        global $wpdb;

        // Deduplication check: Do not re-send identical notification for the same business/status
        $t_audit = self::table_audit();
        $dedup_key = "email_notif_{$event}_{$biz->id}_v{$biz->revision_version}";
        $already_sent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_audit} WHERE action = %s AND business_id = %d",
            $dedup_key,
            $biz->id
        ));
        if ($already_sent > 0) {
            return true;
        }

        $owner = get_userdata((int)$biz->owner_user_id);
        $owner_email = $owner ? $owner->user_email : '';
        $admin_email = get_option('admin_email');

        $subject = '';
        $body    = '';
        $to      = '';

        switch ($event) {
            case 'submitted':
                $to = $admin_email;
                $subject = '[Love Family Alumni] New Business Listing Submitted for Moderation';
                $body = "A new business listing has been submitted for review:\n\n" .
                        "Business Name: {$biz->name}\n" .
                        "Reference: {$biz->business_ref}\n" .
                        "Category: {$biz->category}\n" .
                        "Location: {$biz->city}, {$biz->country}\n\n" .
                        "Please sign in to the WP Admin to review and moderate this listing:\n" .
                        admin_url('users.php?page=lfa-business-pending');
                break;

            case 'approved':
                if (empty($owner_email)) return false;
                $to = $owner_email;
                $subject = '[Love Family Alumni] Your Business Listing has been Approved!';
                $body = "Dear " . ($owner->display_name ?? 'Alumnus') . ",\n\n" .
                        "Congratulations! Your business listing '{$biz->name}' (#{$biz->business_ref}) has been approved by the moderator.\n\n" .
                        "It is now live in the official Love Family Alumni Business Directory:\n" .
                        home_url('/business-directory/') . "\n\n" .
                        ($feedback ? "Moderator Note: {$feedback}\n\n" : "") .
                        "Thank you for being part of our alumni community!\nLove Family Alumni — North America Zone";
                break;

            case 'rejected':
                if (empty($owner_email)) return false;
                $to = $owner_email;
                $subject = '[Love Family Alumni] Business Listing Update: Revisions Requested';
                $body = "Dear " . ($owner->display_name ?? 'Alumnus') . ",\n\n" .
                        "Your business listing submission '{$biz->name}' (#{$biz->business_ref}) requires revisions before publication.\n\n" .
                        "Moderator Feedback:\n{$feedback}\n\n" .
                        "You can update and resubmit your listing from your Member Dashboard:\n" .
                        home_url('/member-dashboard/#businesses') . "\n\n" .
                        "Love Family Alumni — North America Zone";
                break;
        }

        if (empty($to) || empty($subject)) {
            return false;
        }

        $sent = wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);

        // Log deduplication key and event (without saving sensitive email body)
        self::log_audit($biz->id, (int)$biz->owner_user_id, $dedup_key, null, null, "Sent {$event} notification to [REDACTED]");

        return (bool)$sent;
    }
}
