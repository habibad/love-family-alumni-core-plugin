<?php
/**
 * Love Family Alumni — Donations, Giving, Projects & Welfare Subsystem
 *
 * Implements giving funds, projects, welfare activities, donor intent records,
 * immutable amount/cause snapshots, and ledger isolation from dues and conference.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Donation {
    public const SCHEMA_VERSION = '0.8.0';
    public const OPTION_SCHEMA_VER = 'lfa_donation_schema_version';

    // Donation lifecycle statuses
    public const STATUS_DRAFT               = 'draft';
    public const STATUS_PENDING_PAYMENT     = 'pending_payment';
    public const STATUS_PROCESSING          = 'processing';
    public const STATUS_PAID                = 'paid';
    public const STATUS_REFUNDED            = 'refunded';
    public const STATUS_REVERSED            = 'reversed';
    public const STATUS_CANCELLED           = 'cancelled';
    public const STATUS_NEEDS_REVIEW        = 'needs_review';

    // Capabilities
    public const CAP_MANAGE_DONATIONS       = 'lfa_manage_donations';
    public const CAP_VIEW_FINANCIALS        = 'lfa_view_financials';
    public const CAP_EXPORT_FINANCIALS      = 'lfa_export_financials';
    public const CAP_MANAGE_FINANCES        = 'lfa_manage_finances';

    public static function init(): void {
        if (get_option(self::OPTION_SCHEMA_VER) !== self::SCHEMA_VERSION) {
            self::install_schema();
        }
        add_action('init', [__CLASS__, 'register_capabilities']);
    }

    public static function register_capabilities(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $caps = [
                self::CAP_MANAGE_DONATIONS,
                self::CAP_VIEW_FINANCIALS,
                self::CAP_EXPORT_FINANCIALS,
                self::CAP_MANAGE_FINANCES,
            ];
            foreach ($caps as $cap) {
                if (!$admin->has_cap($cap)) {
                    $admin->add_cap($cap);
                }
            }
        }
    }

    /* Table Name Helpers */
    public static function table_funds(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_giving_funds';
    }

    public static function table_projects(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_giving_projects';
    }

    public static function table_donations(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_donations';
    }

    public static function table_audit(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_donation_audit';
    }

    /**
     * Idempotent schema installation
     */
    public static function install_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        // 1. Giving Funds Table
        $t_funds = self::table_funds();
        $sql_funds = "CREATE TABLE {$t_funds} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(64) NOT NULL,
            title varchar(255) NOT NULL,
            description text NULL,
            icon varchar(50) DEFAULT 'heart',
            published_status varchar(30) NOT NULL DEFAULT 'published',
            objective text NULL,
            account_category varchar(50) DEFAULT 'general',
            min_amount_cents int(11) DEFAULT 100,
            max_amount_cents int(11) DEFAULT 1000000,
            guest_giving_enabled tinyint(1) NOT NULL DEFAULT 0,
            policy_status varchar(50) NOT NULL DEFAULT 'unconfirmed',
            policy_approval_notes text NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY fund_slug_unique (slug),
            KEY published_status (published_status),
            KEY sort_order (sort_order)
        ) {$charset_collate};";

        // 2. Giving Projects & Welfare Table
        $t_projects = self::table_projects();
        $sql_projects = "CREATE TABLE {$t_projects} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            fund_id bigint(20) unsigned NOT NULL,
            slug varchar(64) NOT NULL,
            title varchar(255) NOT NULL,
            description text NULL,
            project_type varchar(50) NOT NULL DEFAULT 'project',
            published_status varchar(30) NOT NULL DEFAULT 'published',
            start_date date NULL,
            end_date date NULL,
            goal_amount_cents int(11) NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY project_slug_unique (slug),
            KEY fund_id (fund_id),
            KEY published_status (published_status)
        ) {$charset_collate};";

        // 3. Donations Ledger Table (Strictly isolated from dues & conference)
        $t_donations = self::table_donations();
        $sql_donations = "CREATE TABLE {$t_donations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            donation_ref varchar(64) NOT NULL,
            donor_user_id bigint(20) unsigned NULL,
            donor_display_name varchar(255) NOT NULL,
            donor_email varchar(150) NOT NULL,
            donor_phone varchar(50) NULL,
            is_anonymous tinyint(1) NOT NULL DEFAULT 0,
            consent_agreed tinyint(1) NOT NULL DEFAULT 1,
            fund_id bigint(20) unsigned NOT NULL,
            project_id bigint(20) unsigned NULL,
            amount_cents int(11) NOT NULL,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            donor_message text NULL,
            status varchar(30) NOT NULL DEFAULT 'pending_payment',
            payable_type varchar(30) NOT NULL DEFAULT 'donation',
            purpose_snapshot varchar(255) NOT NULL,
            idempotency_key varchar(64) NOT NULL,
            paypal_order_id varchar(100) NULL,
            paypal_capture_id varchar(100) NULL,
            paid_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY donation_ref_unique (donation_ref),
            UNIQUE KEY idempotency_unique (idempotency_key),
            KEY donor_user_id (donor_user_id),
            KEY fund_id (fund_id),
            KEY project_id (project_id),
            KEY status (status),
            KEY payable_type (payable_type)
        ) {$charset_collate};";

        // 4. Donation Audit Table
        $t_audit = self::table_audit();
        $sql_audit = "CREATE TABLE {$t_audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_type varchar(30) NOT NULL DEFAULT 'system',
            actor_id bigint(20) unsigned NULL,
            action varchar(100) NOT NULL,
            entity_type varchar(50) NOT NULL DEFAULT 'donation',
            entity_id varchar(100) NOT NULL,
            details_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY actor_id (actor_id),
            KEY action (action),
            KEY entity_id (entity_id)
        ) {$charset_collate};";

        dbDelta($sql_funds);
        dbDelta($sql_projects);
        dbDelta($sql_donations);
        dbDelta($sql_audit);

        // Seed original client-documented giving categories
        self::seed_giving_funds();

        update_option(self::OPTION_SCHEMA_VER, self::SCHEMA_VERSION);
    }

    /**
     * Seed authoritative client giving categories exactly once
     */
    public static function seed_giving_funds(): void {
        global $wpdb;
        $t_funds = self::table_funds();
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_funds}'") !== $t_funds) {
            return;
        }

        $count = (int)$wpdb->get_var("SELECT count(*) FROM {$t_funds}");
        if ($count > 0) {
            return;
        }

        $now = current_time('mysql', true);
        $funds = [
            [
                'slug'                 => 'general-fund',
                'title'                => 'General Love Family Alumni Fund',
                'description'          => 'Unrestricted operational and ministry support empowering alumni programs, member welfare initiatives, communications, and emergency fellowship needs across North America.',
                'icon'                 => 'heart',
                'published_status'     => 'published',
                'objective'            => 'General Operational & Ministry Support',
                'account_category'     => 'general',
                'min_amount_cents'     => 100,
                'max_amount_cents'     => 1000000,
                'guest_giving_enabled' => 0,
                'sort_order'           => 1,
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [
                'slug'                 => 'akpo-scholarship',
                'title'                => 'The Akpo Family Scholarship Fund',
                'description'          => 'Dedicated academic grant endowment supporting qualified descendants and students within the Love Family fellowship in their university education.',
                'icon'                 => 'scholarship',
                'published_status'     => 'published',
                'objective'            => 'Academic Grant Endowment',
                'account_category'     => 'scholarship',
                'min_amount_cents'     => 500,
                'max_amount_cents'     => 5000000,
                'guest_giving_enabled' => 0,
                'sort_order'           => 2,
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [
                'slug'                 => 'legacy-archives',
                'title'                => 'Love Family Legacy & Archives',
                'description'          => 'Preserving the historical records, founding memories, testimonies, publications, and milestone celebrations of our UNIBEN roots and global fellowship.',
                'icon'                 => 'book',
                'published_status'     => 'published',
                'objective'            => 'Heritage & Historical Preservation',
                'account_category'     => 'archives',
                'min_amount_cents'     => 100,
                'max_amount_cents'     => 1000000,
                'guest_giving_enabled' => 0,
                'sort_order'           => 3,
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [
                'slug'                 => 'conference-fellowship',
                'title'                => 'Conference & Fellowship Sponsorship',
                'description'          => 'Subsidizing travel grants, youth delegate passes, and accommodation sponsorships for biennial gatherings and regional alumni fellowship summits.',
                'icon'                 => 'users',
                'published_status'     => 'published',
                'objective'            => 'Fellowship Subsidies & Youth Passes',
                'account_category'     => 'conference',
                'min_amount_cents'     => 100,
                'max_amount_cents'     => 2000000,
                'guest_giving_enabled' => 0,
                'sort_order'           => 4,
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
        ];

        foreach ($funds as $f) {
            $wpdb->insert($t_funds, $f);
        }
    }

    /* Fund Retrieval Methods */
    public static function get_published_funds(): array {
        global $wpdb;
        $t_funds = self::table_funds();
        return $wpdb->get_results("SELECT * FROM {$t_funds} WHERE published_status = 'published' ORDER BY sort_order ASC, id ASC") ?: [];
    }

    public static function get_all_funds(): array {
        global $wpdb;
        $t_funds = self::table_funds();
        return $wpdb->get_results("SELECT * FROM {$t_funds} ORDER BY sort_order ASC, id ASC") ?: [];
    }

    public static function get_fund_by_id(int $id): ?object {
        global $wpdb;
        $t_funds = self::table_funds();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_funds} WHERE id = %d", $id));
    }

    public static function get_fund_by_slug(string $slug): ?object {
        global $wpdb;
        $t_funds = self::table_funds();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_funds} WHERE slug = %s", $slug));
    }

    /* Project Retrieval Methods */
    public static function get_published_projects(int $fund_id = 0): array {
        global $wpdb;
        $t_proj = self::table_projects();
        $sql = "SELECT * FROM {$t_proj} WHERE published_status = 'published'";
        if ($fund_id > 0) {
            $sql .= $wpdb->prepare(" AND fund_id = %d", $fund_id);
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        return $wpdb->get_results($sql) ?: [];
    }

    public static function get_all_projects(int $fund_id = 0): array {
        global $wpdb;
        $t_proj = self::table_projects();
        $sql = "SELECT * FROM {$t_proj}";
        if ($fund_id > 0) {
            $sql .= $wpdb->prepare(" WHERE fund_id = %d", $fund_id);
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        return $wpdb->get_results($sql) ?: [];
    }

    public static function get_project_by_id(int $id): ?object {
        global $wpdb;
        $t_proj = self::table_projects();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_proj} WHERE id = %d", $id));
    }

    /* Fund & Project Admin Actions */
    public static function save_fund(array $data, int $actor_id): array {
        if (!current_user_can(self::CAP_MANAGE_DONATIONS)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Insufficient permissions to manage funds.'];
        }

        global $wpdb;
        $t_funds = self::table_funds();

        $id = (int)($data['id'] ?? 0);
        $title = sanitize_text_field($data['title'] ?? '');
        $slug = sanitize_title($data['slug'] ?? $title);

        if (empty($title) || empty($slug)) {
            return ['success' => false, 'code' => 'REQUIRED_FIELDS', 'error' => 'Title and slug are required.'];
        }

        // Slug uniqueness check
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t_funds} WHERE slug = %s AND id != %d", $slug, $id));
        if ($existing) {
            return ['success' => false, 'code' => 'SLUG_EXISTS', 'error' => 'A fund with this slug already exists.'];
        }

        $min_cents = isset($data['min_amount_dollars']) ? (int)round((float)$data['min_amount_dollars'] * 100) : 100;
        $max_cents = isset($data['max_amount_dollars']) ? (int)round((float)$data['max_amount_dollars'] * 100) : 1000000;
        if ($min_cents < 100) $min_cents = 100;
        if ($max_cents < $min_cents) $max_cents = $min_cents;

        $fields = [
            'slug'                 => $slug,
            'title'                => $title,
            'description'          => sanitize_textarea_field($data['description'] ?? ''),
            'icon'                 => sanitize_key($data['icon'] ?? 'heart'),
            'published_status'     => sanitize_key($data['published_status'] ?? 'published'),
            'objective'            => sanitize_text_field($data['objective'] ?? ''),
            'account_category'     => sanitize_key($data['account_category'] ?? 'general'),
            'min_amount_cents'     => $min_cents,
            'max_amount_cents'     => $max_cents,
            'guest_giving_enabled' => !empty($data['guest_giving_enabled']) ? 1 : 0,
            'policy_status'        => sanitize_key($data['policy_status'] ?? 'unconfirmed'),
            'policy_approval_notes'=> sanitize_textarea_field($data['policy_approval_notes'] ?? ''),
            'sort_order'           => (int)($data['sort_order'] ?? 0),
            'updated_at'           => current_time('mysql', true),
        ];

        if ($id > 0) {
            $wpdb->update($t_funds, $fields, ['id' => $id]);
            $fund_id = $id;
            self::log_audit('admin', $actor_id, 'fund_updated', 'fund', (string)$id, ['title' => $title]);
        } else {
            $fields['created_at'] = current_time('mysql', true);
            $wpdb->insert($t_funds, $fields);
            $fund_id = $wpdb->insert_id;
            self::log_audit('admin', $actor_id, 'fund_created', 'fund', (string)$fund_id, ['title' => $title]);
        }

        return ['success' => true, 'fund_id' => $fund_id];
    }

    public static function save_project(array $data, int $actor_id): array {
        if (!current_user_can(self::CAP_MANAGE_DONATIONS)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Insufficient permissions to manage projects.'];
        }

        global $wpdb;
        $t_proj = self::table_projects();

        $id = (int)($data['id'] ?? 0);
        $fund_id = (int)($data['fund_id'] ?? 0);
        $title = sanitize_text_field($data['title'] ?? '');
        $slug = sanitize_title($data['slug'] ?? $title);

        if ($fund_id <= 0 || empty($title) || empty($slug)) {
            return ['success' => false, 'code' => 'REQUIRED_FIELDS', 'error' => 'Fund association, title and slug are required.'];
        }

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t_proj} WHERE slug = %s AND id != %d", $slug, $id));
        if ($existing) {
            return ['success' => false, 'code' => 'SLUG_EXISTS', 'error' => 'A project with this slug already exists.'];
        }

        $goal_cents = !empty($data['goal_amount_dollars']) ? (int)round((float)$data['goal_amount_dollars'] * 100) : null;

        $fields = [
            'fund_id'           => $fund_id,
            'slug'              => $slug,
            'title'             => $title,
            'description'       => sanitize_textarea_field($data['description'] ?? ''),
            'project_type'      => sanitize_key($data['project_type'] ?? 'project'),
            'published_status'  => sanitize_key($data['published_status'] ?? 'published'),
            'start_date'        => !empty($data['start_date']) ? sanitize_text_field($data['start_date']) : null,
            'end_date'          => !empty($data['end_date']) ? sanitize_text_field($data['end_date']) : null,
            'goal_amount_cents' => $goal_cents,
            'sort_order'        => (int)($data['sort_order'] ?? 0),
            'updated_at'        => current_time('mysql', true),
        ];

        if ($id > 0) {
            $wpdb->update($t_proj, $fields, ['id' => $id]);
            $proj_id = $id;
            self::log_audit('admin', $actor_id, 'project_updated', 'project', (string)$id, ['title' => $title]);
        } else {
            $fields['created_at'] = current_time('mysql', true);
            $wpdb->insert($t_proj, $fields);
            $proj_id = $wpdb->insert_id;
            self::log_audit('admin', $actor_id, 'project_created', 'project', (string)$proj_id, ['title' => $title]);
        }

        return ['success' => true, 'project_id' => $proj_id];
    }

    /* Authoritative Donation Validation & Intent Creation */
    public static function validate_donation_intent(array $input, int $user_id): array {
        $fund_id = (int)($input['fund_id'] ?? 0);
        $fund_slug = sanitize_title((string)($input['fund_slug'] ?? ''));

        $fund = $fund_id > 0 ? self::get_fund_by_id($fund_id) : self::get_fund_by_slug($fund_slug);
        if (!$fund) {
            return ['valid' => false, 'code' => 'FUND_NOT_FOUND', 'error' => 'Designated giving fund not found.'];
        }

        if ($fund->published_status !== 'published') {
            return ['valid' => false, 'code' => 'FUND_NOT_PUBLISHED', 'error' => 'This giving fund is not currently active for donations.'];
        }

        // Amount validation
        $amount_cents = 0;
        if (isset($input['amount_dollars'])) {
            $amount_cents = (int)round((float)$input['amount_dollars'] * 100);
        } elseif (isset($input['amount_cents'])) {
            $amount_cents = (int)$input['amount_cents'];
        }

        if ($amount_cents <= 0) {
            return ['valid' => false, 'code' => 'INVALID_AMOUNT', 'error' => 'Please enter a valid donation amount greater than $0.'];
        }

        if ($fund->min_amount_cents && $amount_cents < (int)$fund->min_amount_cents) {
            return ['valid' => false, 'code' => 'AMOUNT_BELOW_MIN', 'error' => 'Minimum donation for this fund is $' . number_format($fund->min_amount_cents / 100, 2) . ' USD.'];
        }

        // G0-C / GAT-007: Only enforce strict ceilings if policy has been explicitly approved
        $is_policy_approved = isset($fund->policy_status) && $fund->policy_status === 'approved';
        if ($is_policy_approved && $fund->max_amount_cents && $amount_cents > (int)$fund->max_amount_cents) {
            return ['valid' => false, 'code' => 'AMOUNT_ABOVE_MAX', 'error' => 'Maximum online donation for this fund is $' . number_format($fund->max_amount_cents / 100, 2) . ' USD.'];
        }

        // Guest vs Member check
        $is_guest = ($user_id === 0);
        if ($is_guest && (int)$fund->guest_giving_enabled !== 1) {
            return [
                'valid' => false,
                'code'  => 'GUEST_GIVING_DISABLED',
                'error' => 'Guest giving is currently unapproved by administrative policy. Please sign in with an approved alumni account to donate.',
            ];
        }

        // Donor Name & Email
        $donor_name = sanitize_text_field(trim($input['donor_display_name'] ?? ''));
        $donor_email = sanitize_email(trim($input['donor_email'] ?? ''));

        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if ($user) {
                if (empty($donor_name)) $donor_name = $user->display_name;
                if (empty($donor_email)) $donor_email = $user->user_email;
            }
        }

        if (empty($donor_name)) {
            return ['valid' => false, 'code' => 'NAME_REQUIRED', 'error' => 'Donor display name is required.'];
        }

        if (empty($donor_email) || !is_email($donor_email)) {
            return ['valid' => false, 'code' => 'INVALID_EMAIL', 'error' => 'A valid donor email address is required.'];
        }

        // Project check if specified
        $project_id = !empty($input['project_id']) ? (int)$input['project_id'] : null;
        $project_title = '';
        if ($project_id) {
            $proj = self::get_project_by_id($project_id);
            if ($proj && (int)$proj->fund_id === (int)$fund->id) {
                $project_title = $proj->title;
            } else {
                $project_id = null;
            }
        }

        $purpose_snapshot = $fund->title . ($project_title ? ' — ' . $project_title : '');

        return [
            'valid'            => true,
            'fund'             => $fund,
            'project_id'       => $project_id,
            'amount_cents'     => $amount_cents,
            'donor_name'       => $donor_name,
            'donor_email'      => $donor_email,
            'donor_phone'      => sanitize_text_field($input['donor_phone'] ?? ''),
            'is_anonymous'     => !empty($input['is_anonymous']) ? 1 : 0,
            'donor_message'    => sanitize_textarea_field($input['donor_message'] ?? ''),
            'purpose_snapshot' => $purpose_snapshot,
            'is_guest'         => $is_guest,
        ];
    }

    /**
     * Create Donation Intent record (Immutable snapshot)
     */
    public static function create_donation_intent(array $input, int $user_id): array {
        $validation = self::validate_donation_intent($input, $user_id);
        if (!$validation['valid']) {
            return ['success' => false, 'code' => $validation['code'], 'error' => $validation['error']];
        }

        global $wpdb;
        $t_donations = self::table_donations();

        // Idempotency check: reuse pending intent if submitted with same key or within current hour
        $donor_identifier = $user_id > 0 ? (string)$user_id : strtolower(trim($validation['donor_email']));
        $default_key = 'IDEM-' . md5($donor_identifier . '|' . $validation['fund']->id . '|' . $validation['amount_cents'] . '|' . date('YmdH'));
        $idempotency_key = sanitize_text_field($input['idempotency_key'] ?? $default_key);

        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_donations} WHERE idempotency_key = %s", $idempotency_key));
        if ($existing && $existing->status === self::STATUS_PENDING_PAYMENT) {
            return [
                'success'        => true,
                'idempotent'     => true,
                'donation_id'    => (int)$existing->id,
                'donation_ref'   => $existing->donation_ref,
                'amount_cents'   => (int)$existing->amount_cents,
                'amount_dollars' => number_format($existing->amount_cents / 100, 2),
                'status'         => $existing->status,
                'purpose'        => $existing->purpose_snapshot,
            ];
        }

        $now = current_time('mysql', true);
        $donation_ref = 'DON-' . date('Y') . '-' . strtoupper(wp_generate_password(8, false, false));

        $data = [
            'donation_ref'       => $donation_ref,
            'donor_user_id'      => $user_id > 0 ? $user_id : null,
            'donor_display_name' => $validation['donor_name'],
            'donor_email'        => $validation['donor_email'],
            'donor_phone'        => $validation['donor_phone'] ?: null,
            'is_anonymous'       => $validation['is_anonymous'],
            'consent_agreed'     => 1,
            'fund_id'            => (int)$validation['fund']->id,
            'project_id'         => $validation['project_id'],
            'amount_cents'       => $validation['amount_cents'],
            'currency'           => 'USD',
            'donor_message'      => $validation['donor_message'] ?: null,
            'status'             => self::STATUS_PENDING_PAYMENT,
            'payable_type'       => 'donation',
            'purpose_snapshot'   => $validation['purpose_snapshot'],
            'idempotency_key'    => $idempotency_key,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $inserted = $wpdb->insert($t_donations, $data);
        if (!$inserted) {
            return ['success' => false, 'code' => 'DB_ERROR', 'error' => 'Failed to record donation intent.'];
        }

        $donation_id = $wpdb->insert_id;
        self::log_audit($user_id > 0 ? 'member' : 'guest', $user_id ?: null, 'donation_intent_created', 'donation', (string)$donation_id, [
            'donation_ref' => $donation_ref,
            'fund_id'      => (int)$validation['fund']->id,
            'amount_cents' => $validation['amount_cents'],
            'is_anonymous' => $validation['is_anonymous'],
        ]);

        return [
            'success'        => true,
            'donation_id'    => $donation_id,
            'donation_ref'   => $donation_ref,
            'amount_cents'   => $validation['amount_cents'],
            'amount_dollars' => number_format($validation['amount_cents'] / 100, 2),
            'status'         => self::STATUS_PENDING_PAYMENT,
            'purpose'        => $validation['purpose_snapshot'],
        ];
    }

    public static function get_donation_by_id(int $id): ?object {
        global $wpdb;
        $t_donations = self::table_donations();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_donations} WHERE id = %d", $id));
    }

    public static function get_donation_by_ref(string $ref): ?object {
        global $wpdb;
        $t_donations = self::table_donations();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_donations} WHERE donation_ref = %s", $ref));
    }

    public static function get_user_donations(int $user_id): array {
        global $wpdb;
        $t_donations = self::table_donations();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t_donations} WHERE donor_user_id = %d ORDER BY id DESC",
            $user_id
        )) ?: [];
    }

    public static function get_all_donations(array $filters = []): array {
        global $wpdb;
        $t_donations = self::table_donations();
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = sanitize_key($filters['status']);
        }
        if (!empty($filters['fund_id'])) {
            $where[] = 'fund_id = %d';
            $params[] = (int)$filters['fund_id'];
        }

        $sql = "SELECT * FROM {$t_donations} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC";
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    public static function get_donation_totals(): array {
        global $wpdb;
        $t_donations = self::table_donations();
        $row = $wpdb->get_row(
            "SELECT count(*) as total_count, COALESCE(sum(amount_cents), 0) as total_cents 
             FROM {$t_donations} 
             WHERE status = 'paid'"
        );

        return [
            'total_count'   => (int)($row->total_count ?? 0),
            'total_cents'   => (int)($row->total_cents ?? 0),
            'total_dollars' => number_format(((int)($row->total_cents ?? 0)) / 100, 2),
        ];
    }

    /* Minimal, PII-scrubbed Audit Logger */
    public static function log_audit(string $actor_type, ?int $actor_id, string $action, string $entity_type, string $entity_id, array $data = []): void {
        global $wpdb;
        $t_audit = self::table_audit();

        unset($data['password'], $data['client_secret'], $data['token'], $data['auth_header']);
        if (isset($data['donor_email'])) {
            $data['donor_email'] = '[REDACTED]';
        }

        $wpdb->insert($t_audit, [
            'actor_type'   => sanitize_key($actor_type),
            'actor_id'     => $actor_id ?: null,
            'action'       => sanitize_key($action),
            'entity_type'  => sanitize_key($entity_type),
            'entity_id'    => sanitize_text_field($entity_id),
            'details_json' => wp_json_encode($data),
            'created_at'   => current_time('mysql', true),
        ]);
    }

    /**
     * Atomically settle a donation following verified PayPal capture
     */
    public static function settle_donation(int $donation_id, string $capture_id, string $order_id, int $amount_cents): array {
        global $wpdb;
        $t_donations = self::table_donations();
        $donation = self::get_donation_by_id($donation_id);

        if (!$donation) {
            return ['success' => false, 'error' => 'Donation record not found.'];
        }

        if ($donation->status === self::STATUS_PAID) {
            return [
                'success'        => true,
                'already_paid'   => true,
                'donation_ref'   => $donation->donation_ref,
                'capture_id'     => $donation->paypal_capture_id,
                'amount_dollars' => number_format($donation->amount_cents / 100, 2),
                'status'         => self::STATUS_PAID,
            ];
        }

        // Amount integrity
        if ((int)$donation->amount_cents !== $amount_cents) {
            self::log_audit('system', null, 'settle_amount_mismatch', 'donation', (string)$donation_id, [
                'expected' => $donation->amount_cents,
                'received' => $amount_cents,
            ]);
            return ['success' => false, 'error' => 'Captured amount mismatch.'];
        }

        $now = current_time('mysql', true);
        $wpdb->update($t_donations, [
            'status'            => self::STATUS_PAID,
            'paypal_capture_id' => sanitize_text_field(substr($capture_id, 0, 100)),
            'paypal_order_id'   => sanitize_text_field(substr($order_id, 0, 100)),
            'paid_at'           => $now,
            'updated_at'        => $now,
        ], ['id' => $donation_id]);

        self::log_audit('system', $donation->donor_user_id ?: null, 'donation_settled', 'donation', (string)$donation_id, [
            'donation_ref' => $donation->donation_ref,
            'capture_id'   => $capture_id,
            'amount_cents' => $amount_cents,
        ]);

        self::send_receipt_email($donation_id, $capture_id);

        return [
            'success'        => true,
            'donation_ref'   => $donation->donation_ref,
            'capture_id'     => $capture_id,
            'amount_dollars' => number_format($donation->amount_cents / 100, 2),
            'status'         => self::STATUS_PAID,
            'message'        => 'Donation payment verified and settled successfully.',
        ];
    }

    /**
     * Send genuine payment receipt email via wp_mail() / WP Mail SMTP
     */
    public static function send_receipt_email(int $donation_id, string $capture_id): bool {
        $donation = self::get_donation_by_id($donation_id);
        if (!$donation || $donation->status !== self::STATUS_PAID || empty($donation->donor_email)) {
            return false;
        }

        $amount_str = '$' . number_format($donation->amount_cents / 100, 2) . ' USD';
        $site_name = get_bloginfo('name') ?: 'Love Family Alumni';
        $to = $donation->donor_email;
        $subject = sprintf('[%s] Official Donation Receipt — %s', $site_name, $donation->donation_ref);

        $body = "Dear " . ($donation->is_anonymous ? 'Fellow Alumnus / Friend' : esc_html($donation->donor_display_name)) . ",\n\n";
        $body .= "Thank you for your generous contribution in support of Love Family Alumni — North America Zone.\n\n";
        $body .= "DONATION RECEIPT DETAILS:\n";
        $body .= "--------------------------------------------------\n";
        $body .= "Receipt Reference: " . $donation->donation_ref . "\n";
        $body .= "Date Paid:         " . ($donation->paid_at ?: current_time('mysql')) . "\n";
        $body .= "Designated Cause:  " . $donation->purpose_snapshot . "\n";
        $body .= "Amount Received:   " . $amount_str . "\n";
        $body .= "Payment Method:    PayPal (Verified Server-Side Capture)\n";
        $body .= "Transaction ID:    " . $capture_id . "\n";
        $body .= "Status:            COMPLETED\n";
        $body .= "--------------------------------------------------\n\n";
        $body .= "Please retain this official receipt for your personal records.\n";
        $body .= "Love Family Alumni North America Zone expresses deep gratitude for your commitment and partnership.\n\n";
        $body .= "Blessings,\n";
        $body .= "The Executive Committee & Finance Office\n";
        $body .= "Love Family Alumni — North America Zone\n";

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $sent = wp_mail($to, $subject, $body, $headers);

        if (class_exists('LFA_Core') && method_exists('LFA_Core', 'log_email_event')) {
            LFA_Core::log_email_event('donation_receipt', (int)($donation->donor_user_id ?: 0), $to, $sent ? 'dispatched' : 'failed');
        }

        return (bool)$sent;
    }

    /**
     * Neutralize CSV cells to prevent Formula Injection (CWE-1236 / GIV-013)
     */
    public static function neutralize_csv_cell(string $cell): string {
        $first_char = substr($cell, 0, 1);
        if (in_array($first_char, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $cell;
        }
        return $cell;
    }

    public static function set_fund_status(int $fund_id, string $status, int $actor_id): array {
        if (!current_user_can(self::CAP_MANAGE_DONATIONS)) {
            return ['success' => false, 'error' => 'Permission denied.'];
        }
        $allowed = ['published', 'draft', 'archived'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'error' => 'Invalid status.'];
        }
        global $wpdb;
        $t_funds = self::table_funds();
        $wpdb->update($t_funds, [
            'published_status' => $status,
            'updated_at'       => current_time('mysql', true),
        ], ['id' => $fund_id]);

        self::log_audit('admin', $actor_id, 'fund_status_changed', 'fund', (string)$fund_id, ['status' => $status]);
        return ['success' => true];
    }

    public static function set_project_status(int $project_id, string $status, int $actor_id): array {
        if (!current_user_can(self::CAP_MANAGE_DONATIONS)) {
            return ['success' => false, 'error' => 'Permission denied.'];
        }
        $allowed = ['published', 'draft', 'archived'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'error' => 'Invalid status.'];
        }
        global $wpdb;
        $t_proj = self::table_projects();
        $wpdb->update($t_proj, [
            'published_status' => $status,
            'updated_at'       => current_time('mysql', true),
        ], ['id' => $project_id]);

        self::log_audit('admin', $actor_id, 'project_status_changed', 'project', (string)$project_id, ['status' => $status]);
        return ['success' => true];
    }
}
