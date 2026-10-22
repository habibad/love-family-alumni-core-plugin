<?php
/**
 * Love Family Alumni — Conference Domain & Attendee Management System
 *
 * Implements conference event policies, ticket tiers, secure registrations,
 * attendee rosters, conference invoicing, immutable audit logs, and printable receipts.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Conference {
    public const SCHEMA_VERSION = '0.6.0';
    public const OPTION_SCHEMA_VER = 'lfa_conference_schema_version';
    public const OPTION_SETTINGS = 'lfa_conference_settings';

    // Registration lifecycle statuses
    public const STATUS_DRAFT               = 'draft';
    public const STATUS_AWAITING_PAYMENT    = 'awaiting_payment';
    public const STATUS_PAYMENT_PENDING     = 'payment_pending_verification';
    public const STATUS_CONFIRMED           = 'confirmed';
    public const STATUS_CANCELLED           = 'cancelled';
    public const STATUS_REFUND_PENDING      = 'refund_pending';
    public const STATUS_REFUNDED            = 'refunded';
    public const STATUS_WAITLISTED          = 'waitlisted';

    // Invoice statuses
    public const INVOICE_STATUS_UNPAID      = 'unpaid';
    public const INVOICE_STATUS_PAID        = 'paid';
    public const INVOICE_STATUS_CANCELLED   = 'cancelled';
    public const INVOICE_STATUS_REFUNDED    = 'refunded';

    // Capabilities
    public const CAP_MANAGE_CONFERENCES     = 'lfa_manage_conferences';
    public const CAP_PUBLISH_CONFERENCES    = 'lfa_publish_conferences';
    public const CAP_VIEW_ATTENDEES         = 'lfa_view_conference_attendees';
    public const CAP_EXPORT_ATTENDEES       = 'lfa_export_conference_attendees';

    // Roles
    public const ROLE_CONFERENCE_ADMIN      = 'lfa_conference_admin';

    public static function init(): void {
        if (get_option(self::OPTION_SCHEMA_VER) !== self::SCHEMA_VERSION) {
            self::install_schema();
        }
        add_action('init', [__CLASS__, 'register_capabilities']);
        add_action('init', [__CLASS__, 'register_rewrites']);
        add_filter('query_vars', [__CLASS__, 'register_query_vars']);
        add_action('init', [__CLASS__, 'maybe_flush_rewrites'], 99);
        add_action('admin_post_lfa_conference_receipt', [__CLASS__, 'handle_admin_post_receipt']);
    }

    public static function maybe_flush_rewrites(): void {
        if (get_option('lfa_flush_conference_rewrites')) {
            delete_option('lfa_flush_conference_rewrites');
            flush_rewrite_rules(false);
        }
    }

    public static function register_rewrites(): void {
        add_rewrite_rule(
            '^conference/([^/]+)/?$',
            'index.php?pagename=conference&lfa_conf_slug=$matches[1]',
            'top'
        );
    }

    public static function register_query_vars(array $vars): array {
        $vars[] = 'lfa_conf_slug';
        return $vars;
    }

    public static function register_capabilities(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $caps = [
                self::CAP_MANAGE_CONFERENCES,
                self::CAP_PUBLISH_CONFERENCES,
                self::CAP_VIEW_ATTENDEES,
                self::CAP_EXPORT_ATTENDEES,
            ];
            foreach ($caps as $cap) {
                if (!$admin->has_cap($cap)) {
                    $admin->add_cap($cap);
                }
            }
        }

        // Conference Administrator Role (Has manage, view, export; does NOT have publish by default)
        if (!get_role(self::ROLE_CONFERENCE_ADMIN)) {
            add_role(self::ROLE_CONFERENCE_ADMIN, 'Conference Administrator', [
                'read'                       => true,
                self::CAP_MANAGE_CONFERENCES => true,
                self::CAP_VIEW_ATTENDEES     => true,
                self::CAP_EXPORT_ATTENDEES   => true,
            ]);
        }
    }


    /* Table Name Helpers */
    public static function table_conferences(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_conferences';
    }

    public static function table_ticket_types(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_conference_ticket_types';
    }

    public static function table_registrations(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_conference_registrations';
    }

    public static function table_attendees(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_conference_attendees';
    }

    public static function table_invoices(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_conference_invoices';
    }

    public static function table_audit(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_conference_audit';
    }

    public static function table_speakers(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_conference_speakers';
    }

    /**
     * Versioned, idempotent schema installation & data generalization using dbDelta
     */
    public static function install_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        // 1. Conferences Table
        $t_conf = self::table_conferences();
        $sql_conf = "CREATE TABLE {$t_conf} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(64) NOT NULL,
            title varchar(255) NOT NULL,
            theme varchar(255) NOT NULL,
            verse varchar(100) NOT NULL,
            starts_at datetime NOT NULL,
            ends_at datetime NOT NULL,
            timezone varchar(64) NOT NULL DEFAULT 'America/New_York',
            location_summary varchar(255) NOT NULL,
            published_status varchar(30) NOT NULL DEFAULT 'published',
            registration_status varchar(30) NOT NULL DEFAULT 'closed',
            registration_opens_at datetime NULL,
            registration_closes_at datetime NULL,
            guest_registration_enabled tinyint(1) NOT NULL DEFAULT 0,
            policy_version varchar(50) NOT NULL DEFAULT 'v1.0-unconfigured',
            details_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug_unique (slug),
            KEY published_status (published_status),
            KEY registration_status (registration_status)
        ) {$charset_collate};";

        // 2. Ticket Types Table
        $t_tickets = self::table_ticket_types();
        $sql_tickets = "CREATE TABLE {$t_tickets} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conference_id bigint(20) unsigned NOT NULL,
            name varchar(100) NOT NULL,
            slug varchar(64) NOT NULL,
            description text NULL,
            amount_cents int(11) NULL,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            eligibility varchar(30) NOT NULL DEFAULT 'members_only',
            capacity int(11) NULL,
            registered_count int(11) NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 0,
            policy_version varchar(50) NOT NULL DEFAULT 'v1.0-unconfigured',
            updated_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY conf_ticket_slug (conference_id, slug),
            KEY conference_id (conference_id),
            KEY active (active)
        ) {$charset_collate};";

        // 3. Conference Registrations Table
        $t_reg = self::table_registrations();
        $sql_reg = "CREATE TABLE {$t_reg} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            registration_number varchar(64) NOT NULL,
            conference_id bigint(20) unsigned NOT NULL,
            owner_user_id bigint(20) unsigned NULL,
            guest_email varchar(150) NULL,
            ticket_type_id bigint(20) unsigned NOT NULL,
            ticket_name_snapshot varchar(100) NOT NULL,
            amount_cents_snapshot int(11) NOT NULL,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            status varchar(30) NOT NULL DEFAULT 'draft',
            policy_version varchar(50) NOT NULL DEFAULT 'v1.0-unconfigured',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY registration_number_unique (registration_number),
            KEY conference_id (conference_id),
            KEY owner_user_id (owner_user_id),
            KEY status (status)
        ) {$charset_collate};";

        // 4. Conference Attendees Table
        $t_att = self::table_attendees();
        $sql_att = "CREATE TABLE {$t_att} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            registration_id bigint(20) unsigned NOT NULL,
            conference_id bigint(20) unsigned NOT NULL,
            attendee_type varchar(30) NOT NULL DEFAULT 'primary',
            full_name varchar(150) NOT NULL,
            email varchar(150) NULL,
            phone varchar(50) NULL,
            chapter varchar(100) NULL,
            dietary_accessibility text NULL,
            emergency_contact varchar(150) NULL,
            consent_agreed tinyint(1) NOT NULL DEFAULT 1,
            status varchar(30) NOT NULL DEFAULT 'registered',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY registration_id (registration_id),
            KEY conference_id (conference_id),
            KEY status (status)
        ) {$charset_collate};";

        // 5. Conference Invoices Table (Separate from Dues Invoices)
        $t_inv = self::table_invoices();
        $sql_inv = "CREATE TABLE {$t_inv} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            invoice_number varchar(64) NOT NULL,
            registration_id bigint(20) unsigned NOT NULL,
            conference_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NULL,
            amount_cents int(11) NOT NULL,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            status varchar(30) NOT NULL DEFAULT 'unpaid',
            policy_version varchar(50) NOT NULL DEFAULT 'v1.0-unconfigured',
            issued_at datetime NOT NULL,
            paid_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY invoice_number_unique (invoice_number),
            UNIQUE KEY registration_id_unique (registration_id),
            KEY conference_id (conference_id),
            KEY user_id (user_id),
            KEY status (status)
        ) {$charset_collate};";

        // 6. Conference Audit Table
        $t_audit = self::table_audit();
        $sql_audit = "CREATE TABLE {$t_audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_type varchar(30) NOT NULL DEFAULT 'system',
            actor_id bigint(20) unsigned NULL,
            action varchar(100) NOT NULL,
            entity_type varchar(50) NOT NULL,
            entity_id varchar(100) NOT NULL,
            details_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY actor_id (actor_id),
            KEY action (action),
            KEY entity_type (entity_type)
        ) {$charset_collate};";

        // 7. Conference Speakers Table
        $t_speakers = self::table_speakers();
        $sql_speakers = "CREATE TABLE {$t_speakers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conference_id bigint(20) unsigned NOT NULL,
            name varchar(255) NOT NULL,
            designation varchar(255) DEFAULT '',
            organization varchar(255) DEFAULT '',
            role varchar(50) NOT NULL DEFAULT 'speaker',
            image_id bigint(20) unsigned DEFAULT NULL,
            image_alt varchar(255) DEFAULT '',
            keynote_title varchar(255) DEFAULT '',
            keynote_abstract longtext DEFAULT NULL,
            biography longtext DEFAULT NULL,
            session_title varchar(255) DEFAULT '',
            session_schedule varchar(255) DEFAULT '',
            sort_order int(11) NOT NULL DEFAULT 0,
            published_status varchar(30) NOT NULL DEFAULT 'published',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY conference_id (conference_id),
            KEY sort_order (sort_order),
            KEY published_status (published_status)
        ) {$charset_collate};";

        dbDelta($sql_conf);
        dbDelta($sql_tickets);
        dbDelta($sql_reg);
        dbDelta($sql_att);
        dbDelta($sql_inv);
        dbDelta($sql_audit);
        dbDelta($sql_speakers);

        // 8. Generalize Payment Tables for Polymorphic Payable Reference
        self::generalize_payment_schema();

        // 9. Seed Default 2027 Conference if not exists
        self::seed_2027_conference();

        // 10. Seed 2027 Keynote Speaker (Dr. Uche Igbokwe) if not exists
        self::seed_2027_speakers();

        update_option('lfa_flush_conference_rewrites', 1);

        update_option(self::OPTION_SCHEMA_VER, self::SCHEMA_VERSION);
    }

    /**
     * Backward-compatible generalization of Phase 04 payment tables
     * Adds payable_type and payable_id without altering existing dues records
     */
    public static function generalize_payment_schema(): void {
        global $wpdb;
        $t_attempts = $wpdb->prefix . 'lfa_payment_attempts';
        $t_captures = $wpdb->prefix . 'lfa_payment_captures';

        // Check if attempts table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_attempts}'") === $t_attempts) {
            $cols = $wpdb->get_col("DESCRIBE `{$t_attempts}`");
            if (!in_array('payable_type', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_attempts}` ADD COLUMN `payable_type` varchar(30) NOT NULL DEFAULT 'membership_due' AFTER `user_id`");
                $wpdb->query("ALTER TABLE `{$t_attempts}` ADD INDEX `payable_type` (`payable_type`)");
            }
            if (!in_array('payable_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_attempts}` ADD COLUMN `payable_id` bigint(20) unsigned NULL AFTER `payable_type`");
                $wpdb->query("ALTER TABLE `{$t_attempts}` ADD INDEX `payable_id` (`payable_id`)");
                // Backfill existing legacy rows
                $wpdb->query("UPDATE `{$t_attempts}` SET `payable_id` = `invoice_id` WHERE `payable_id` IS NULL AND `payable_type` = 'membership_due'");
            }
        }

        // Check if captures table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_captures}'") === $t_captures) {
            $cols = $wpdb->get_col("DESCRIBE `{$t_captures}`");
            if (!in_array('payable_type', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_captures}` ADD COLUMN `payable_type` varchar(30) NOT NULL DEFAULT 'membership_due' AFTER `attempt_id`");
                $wpdb->query("ALTER TABLE `{$t_captures}` ADD INDEX `payable_type` (`payable_type`)");
            }
            if (!in_array('payable_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_captures}` ADD COLUMN `payable_id` bigint(20) unsigned NULL AFTER `payable_type`");
                $wpdb->query("ALTER TABLE `{$t_captures}` ADD INDEX `payable_id` (`payable_id`)");
                // Backfill existing legacy rows
                $wpdb->query("UPDATE `{$t_captures}` SET `payable_id` = `invoice_id` WHERE `payable_id` IS NULL AND `payable_type` = 'membership_due'");
            }
        }
    }

    /**
     * Seed authoritative 2027 Conference with verified client details
     */
    public static function seed_2027_conference(): void {
        global $wpdb;
        $t_conf = self::table_conferences();
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_conf} WHERE slug = %s", '2027-biennial-conference'));

        $now = current_time('mysql', true);
        if (!$existing) {
            $details = [
                'organizer'      => 'Love Family Alumni — North America Zone',
                'keynote_speaker'=> 'Dr. Uche Igbokwe, U.K.',
                'speaker_status' => 'confirmed',
                'venue_name'     => 'North America Zone Host City TBA',
                'hotel_info'     => 'Host hotel booking arrangements are being finalized and will be announced once contract negotiations are completed.',
                'program_info'   => 'The detailed daily schedule and workshop tracks will be released by the conference planning committee.',
                'sponsorship'    => 'Corporate and personal legacy sponsorship packages will be announced alongside official registration.',
                'refund_policy'  => 'Refund and cancellation guidelines are pending executive committee policy confirmation.',
            ];

            $wpdb->insert($t_conf, [
                'slug'                       => '2027-biennial-conference',
                'title'                      => '2027 North America Zone Conference',
                'theme'                      => 'Thy Kingdom Come',
                'verse'                      => 'Matthew 6:10',
                'starts_at'                  => '2027-09-16 09:00:00',
                'ends_at'                    => '2027-09-19 18:00:00',
                'timezone'                   => 'America/New_York',
                'location_summary'           => 'North America Zone (Host City TBA)',
                'published_status'           => 'published',
                'registration_status'        => 'closed', // Closed until tickets configured
                'registration_opens_at'      => null,
                'registration_closes_at'     => null,
                'guest_registration_enabled' => 0,       // Guest registration OFF by default
                'policy_version'             => 'v1.0-unconfigured',
                'details_json'               => wp_json_encode($details),
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);
            $conf_id = $wpdb->insert_id;

            // Seed ticket classes with amount_cents = NULL (fee unknown != 0 or free)
            $t_tickets = self::table_ticket_types();
            $wpdb->insert($t_tickets, [
                'conference_id'    => $conf_id,
                'name'             => 'Standard Alumni Member Pass',
                'slug'             => 'standard-member-pass',
                'description'      => 'Full 4-day conference access for verified alumni members including opening banquet and legacy sessions.',
                'amount_cents'     => null, // Must remain NULL until client configures pricing
                'currency'         => 'USD',
                'eligibility'      => 'members_only',
                'capacity'         => null,
                'registered_count' => 0,
                'active'           => 0, // Inactive until approved
                'policy_version'   => 'v1.0-unconfigured',
                'updated_by'       => 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            $wpdb->insert($t_tickets, [
                'conference_id'    => $conf_id,
                'name'             => 'Couples Alumni Pass',
                'slug'             => 'couples-alumni-pass',
                'description'      => 'Full conference pass for alumnus and spouse/partner.',
                'amount_cents'     => null,
                'currency'         => 'USD',
                'eligibility'      => 'members_only',
                'capacity'         => null,
                'registered_count' => 0,
                'active'           => 0,
                'policy_version'   => 'v1.0-unconfigured',
                'updated_by'       => 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }

    /**
     * Retrieve all conferences
     */
    public static function get_all_conferences(bool $only_published = false): array {
        global $wpdb;
        $t_conf = self::table_conferences();
        if ($only_published) {
            return $wpdb->get_results("SELECT * FROM {$t_conf} WHERE published_status = 'published' ORDER BY starts_at ASC");
        }
        return $wpdb->get_results("SELECT * FROM {$t_conf} ORDER BY id DESC");
    }

    /**
     * Retrieve conference by ID
     */
    public static function get_conference_by_id(int $id): ?object {
        global $wpdb;
        $t_conf = self::table_conferences();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_conf} WHERE id = %d", $id));
    }

    /**
     * Retrieve active or primary conference
     */
    public static function get_conference(?string $slug = '2027-biennial-conference'): ?object {
        global $wpdb;
        $t_conf = self::table_conferences();
        if (empty($slug)) {
            return $wpdb->get_row("SELECT * FROM {$t_conf} ORDER BY id ASC LIMIT 1");
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_conf} WHERE slug = %s", $slug));
    }

    public static function get_conference_by_slug(string $slug): ?object {
        return self::get_conference($slug);
    }

    public static function get_published_conferences(): array {
        return self::get_all_conferences(true);
    }

    public static function get_conference_url(?object $conf = null): string {
        if (!$conf) {
            return home_url('/conference/');
        }
        if ($conf->slug === '2027-biennial-conference' || $conf->slug === '2027' || $conf->slug === 'thy-kingdom-come-2027') {
            return home_url('/conference/2027/');
        }
        return home_url('/conference/' . $conf->slug . '/');
    }

    /**
     * Create a new conference (defaults to draft and registration closed)
     */
    public static function create_conference(array $data, int $actor_id): array {
        if (!current_user_can(self::CAP_MANAGE_CONFERENCES)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Unauthorized: Conference management capability required.'];
        }

        global $wpdb;
        $t_conf = self::table_conferences();

        $title = sanitize_text_field($data['title'] ?? '');
        $slug = sanitize_title($data['slug'] ?? $title);
        $theme = sanitize_text_field($data['theme'] ?? '');
        $verse = sanitize_text_field($data['verse'] ?? '');
        $starts_at = !empty($data['starts_at']) ? sanitize_text_field($data['starts_at']) : null;
        $ends_at = !empty($data['ends_at']) ? sanitize_text_field($data['ends_at']) : null;
        $tz = sanitize_text_field($data['timezone'] ?? 'America/New_York');
        $location = sanitize_text_field($data['location_summary'] ?? '');
        $guest_allowed = !empty($data['guest_registration_enabled']) ? 1 : 0;

        if (empty($title) || empty($slug)) {
            return ['success' => false, 'code' => 'REQUIRED_FIELDS', 'error' => 'Conference title and slug are required.'];
        }

        // Check slug uniqueness
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t_conf} WHERE slug = %s", $slug));
        if ($existing) {
            return ['success' => false, 'code' => 'SLUG_EXISTS', 'error' => 'A conference with this slug already exists. Please choose a unique slug.'];
        }

        $details = [
            'organizer'       => sanitize_text_field($data['organizer'] ?? 'Love Family Alumni — North America Zone'),
            'keynote_speaker' => sanitize_text_field($data['keynote_speaker'] ?? ''),
            'speaker_status'  => sanitize_text_field($data['speaker_status'] ?? 'pending'),
            'venue_name'      => sanitize_text_field($data['venue_name'] ?? ''),
            'hotel_info'      => sanitize_textarea_field($data['hotel_info'] ?? ''),
            'program_info'    => sanitize_textarea_field($data['program_info'] ?? ''),
            'sponsorship'     => sanitize_textarea_field($data['sponsorship'] ?? ''),
            'refund_policy'   => sanitize_textarea_field($data['refund_policy'] ?? ''),
        ];

        $now = current_time('mysql', true);
        $wpdb->insert($t_conf, [
            'slug'                       => $slug,
            'title'                      => $title,
            'theme'                      => $theme,
            'verse'                      => $verse,
            'starts_at'                  => $starts_at ?: $now,
            'ends_at'                    => $ends_at ?: $now,
            'timezone'                   => $tz,
            'location_summary'           => $location,
            'published_status'           => 'draft', // Always draft on creation
            'registration_status'        => 'closed', // Always closed on creation
            'registration_opens_at'      => null,
            'registration_closes_at'     => null,
            'guest_registration_enabled' => $guest_allowed,
            'policy_version'             => 'v1.0-unconfigured',
            'details_json'               => wp_json_encode($details),
            'created_at'                 => $now,
            'updated_at'                 => $now,
        ]);

        $conf_id = (int)$wpdb->insert_id;
        self::log_audit('staff', $actor_id, 'conference_created', 'conference', (string)$conf_id, ['slug' => $slug, 'status' => 'draft']);

        return ['success' => true, 'conference_id' => $conf_id, 'slug' => $slug, 'status' => 'draft'];
    }

    /**
     * Update an existing conference
     */
    public static function update_conference(int $id, array $data, int $actor_id): array {
        if (!current_user_can(self::CAP_MANAGE_CONFERENCES)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Unauthorized: Conference management capability required.'];
        }

        global $wpdb;
        $t_conf = self::table_conferences();
        $conf = self::get_conference_by_id($id);
        if (!$conf) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Conference not found.'];
        }

        $title = sanitize_text_field($data['title'] ?? $conf->title);
        $slug = sanitize_title($data['slug'] ?? $conf->slug);
        $theme = sanitize_text_field($data['theme'] ?? $conf->theme);
        $verse = sanitize_text_field($data['verse'] ?? $conf->verse);
        $starts_at = !empty($data['starts_at']) ? sanitize_text_field($data['starts_at']) : $conf->starts_at;
        $ends_at = !empty($data['ends_at']) ? sanitize_text_field($data['ends_at']) : $conf->ends_at;
        $tz = sanitize_text_field($data['timezone'] ?? $conf->timezone);
        $location = sanitize_text_field($data['location_summary'] ?? $conf->location_summary);
        $guest_allowed = isset($data['guest_registration_enabled']) ? (!empty($data['guest_registration_enabled']) ? 1 : 0) : $conf->guest_registration_enabled;

        // Check slug uniqueness
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t_conf} WHERE slug = %s AND id != %d", $slug, $id));
        if ($existing) {
            return ['success' => false, 'code' => 'SLUG_EXISTS', 'error' => 'A conference with this slug already exists.'];
        }

        $details = json_decode($conf->details_json ?: '{}', true) ?: [];
        if (isset($data['organizer'])) $details['organizer'] = sanitize_text_field($data['organizer']);
        if (isset($data['keynote_speaker'])) $details['keynote_speaker'] = sanitize_text_field($data['keynote_speaker']);
        if (isset($data['speaker_status'])) $details['speaker_status'] = sanitize_text_field($data['speaker_status']);
        if (isset($data['venue_name'])) $details['venue_name'] = sanitize_text_field($data['venue_name']);
        if (isset($data['hotel_info'])) $details['hotel_info'] = sanitize_textarea_field($data['hotel_info']);
        if (isset($data['program_info'])) $details['program_info'] = sanitize_textarea_field($data['program_info']);
        if (isset($data['sponsorship'])) $details['sponsorship'] = sanitize_textarea_field($data['sponsorship']);
        if (isset($data['refund_policy'])) $details['refund_policy'] = sanitize_textarea_field($data['refund_policy']);

        $now = current_time('mysql', true);
        $update_fields = [
            'title'                      => $title,
            'slug'                       => $slug,
            'theme'                      => $theme,
            'verse'                      => $verse,
            'starts_at'                  => $starts_at,
            'ends_at'                    => $ends_at,
            'timezone'                   => $tz,
            'location_summary'           => $location,
            'guest_registration_enabled' => $guest_allowed,
            'details_json'               => wp_json_encode($details),
            'updated_at'                 => $now,
        ];

        if (isset($data['registration_status']) && in_array($data['registration_status'], ['open', 'closed', 'paused'], true)) {
            $update_fields['registration_status'] = $data['registration_status'];
        }

        $wpdb->update($t_conf, $update_fields, ['id' => $id]);
        self::log_audit('staff', $actor_id, 'conference_updated', 'conference', (string)$id, ['slug' => $slug]);

        return ['success' => true, 'conference_id' => $id, 'slug' => $slug];
    }

    /**
     * Publish / Unpublish / Archive conference (Strictly checks lfa_publish_conferences)
     */
    public static function set_published_status(int $id, string $status, int $actor_id): array {
        if (!current_user_can(self::CAP_PUBLISH_CONFERENCES)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Unauthorized: Publishing privilege (lfa_publish_conferences) is required to publish, unpublish or archive conferences.'];
        }

        if (!in_array($status, ['published', 'draft', 'unpublished', 'archived'], true)) {
            return ['success' => false, 'code' => 'INVALID_STATUS', 'error' => 'Invalid published status.'];
        }

        global $wpdb;
        $t_conf = self::table_conferences();
        $conf = self::get_conference_by_id($id);
        if (!$conf) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Conference not found.'];
        }

        $now = current_time('mysql', true);
        $wpdb->update($t_conf, [
            'published_status' => $status,
            'updated_at'       => $now,
        ], ['id' => $id]);

        self::log_audit('admin', $actor_id, 'conference_status_changed', 'conference', (string)$id, [
            'from' => $conf->published_status,
            'to'   => $status,
        ]);

        return ['success' => true, 'conference_id' => $id, 'status' => $status];
    }

    /**
     * Delete draft conference (Refuses deletion if registrations or invoices exist)
     */
    public static function delete_conference(int $id, int $actor_id): array {
        if (!current_user_can(self::CAP_PUBLISH_CONFERENCES)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Unauthorized: Administrative publish capability required.'];
        }

        global $wpdb;
        $t_reg = self::table_registrations();
        $reg_count = (int)$wpdb->get_var($wpdb->prepare("SELECT count(*) FROM {$t_reg} WHERE conference_id = %d", $id));
        if ($reg_count > 0) {
            return ['success' => false, 'code' => 'HAS_REGISTRATIONS', 'error' => 'Cannot delete conference with existing registration records. Archive the conference instead to preserve financial and attendee history.'];
        }

        $t_inv = self::table_invoices();
        $inv_count = (int)$wpdb->get_var($wpdb->prepare("SELECT count(*) FROM {$t_inv} WHERE conference_id = %d", $id));
        if ($inv_count > 0) {
            return ['success' => false, 'code' => 'HAS_INVOICES', 'error' => 'Cannot delete conference with existing invoices. Archive the conference instead.'];
        }

        $t_conf = self::table_conferences();
        $wpdb->delete($t_conf, ['id' => $id]);
        self::log_audit('admin', $actor_id, 'conference_deleted', 'conference', (string)$id);

        return ['success' => true, 'message' => 'Conference draft deleted.'];
    }


    /**
     * Retrieve ticket types for a conference
     */
    public static function get_ticket_types(int $conference_id, bool $active_only = false): array {
        global $wpdb;
        $t_tickets = self::table_ticket_types();
        $sql = "SELECT * FROM {$t_tickets} WHERE conference_id = %d";
        if ($active_only) {
            $sql .= " AND active = 1 AND amount_cents IS NOT NULL";
        }
        $sql .= " ORDER BY id ASC";
        return $wpdb->get_results($wpdb->prepare($sql, $conference_id));
    }

    /**
     * Retrieve a specific ticket type
     */
    public static function get_ticket_type(int $ticket_id): ?object {
        global $wpdb;
        $t_tickets = self::table_ticket_types();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_tickets} WHERE id = %d", $ticket_id));
    }

    /**
     * Audit logger for conference subsystem
     * PII and secrets are strictly scrubbed
     */
    public static function log_audit(string $actor_type, ?int $actor_id, string $action, string $entity_type, string $entity_id, array $data = []): void {
        global $wpdb;
        $t_audit = self::table_audit();

        // Scrub sensitive fields
        unset($data['password'], $data['client_secret'], $data['token'], $data['auth_header']);
        if (isset($data['attendee_phone'])) {
            $data['attendee_phone'] = '[REDACTED]';
        }
        if (isset($data['emergency_contact'])) {
            $data['emergency_contact'] = '[REDACTED]';
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
     * Authoritative Server-Side Registration Validation
     */
    public static function validate_registration(array $input, int $user_id): array {
        $conf_id = (int) ($input['conference_id'] ?? 0);
        $conf = $conf_id > 0 ? self::get_conference_by_id($conf_id) : self::get_conference();
        if (!$conf) {
            return ['valid' => false, 'code' => 'CONF_NOT_FOUND', 'error' => 'Conference record not found.'];
        }

        // 1. Check conference registration status
        if ($conf->registration_status !== 'open') {
            return [
                'valid' => false,
                'code'  => 'REGISTRATION_CLOSED',
                'error' => 'Conference registration is currently closed or pending announcement. Please check back soon.',
            ];
        }

        // Check registration window if dates configured
        $now_ts = current_time('timestamp', true);
        if (!empty($conf->registration_opens_at) && strtotime($conf->registration_opens_at) > $now_ts) {
            return [
                'valid' => false,
                'code'  => 'REGISTRATION_NOT_STARTED',
                'error' => 'Conference registration has not opened yet.',
            ];
        }
        if (!empty($conf->registration_closes_at) && strtotime($conf->registration_closes_at) < $now_ts) {
            return [
                'valid' => false,
                'code'  => 'REGISTRATION_EXPIRED',
                'error' => 'Conference registration deadline has passed.',
            ];
        }

        // 2. Ownership & Strict Paid-Member Eligibility
        $is_guest = ($user_id <= 0);
        if ($is_guest) {
            return [
                'valid' => false,
                'code'  => 'MEMBERSHIP_REQUIRED',
                'error' => 'Conference registration is available exclusively to active Love Family Alumni members.',
            ];
        }

        // Administrator bypass for platform management
        $is_admin = user_can($user_id, 'manage_options') || user_can($user_id, 'edit_users');

        if (!$is_admin) {
            // Check member status
            $member_status = LFA_Core::member_status($user_id);
            if ($member_status !== 'approved') {
                return [
                    'valid' => false,
                    'code'  => 'ACCOUNT_NOT_APPROVED',
                    'error' => 'Your alumni membership account must be verified and administrator-approved before registering for the conference.',
                ];
            }

            // Check active paid dues entitlement
            if (class_exists('LFA_Payment_Entitlements')) {
                $dues = LFA_Payment_Entitlements::get_dues_standing($user_id, true);
                if ($dues['standing'] === 'expired') {
                    return [
                        'valid' => false,
                        'code'  => 'MEMBERSHIP_EXPIRED',
                        'error' => 'Your annual membership has expired. Renew your $100 annual dues to register for this conference.',
                    ];
                }
                if (empty($dues['is_paid']) || $dues['standing'] !== 'current') {
                    return [
                        'valid' => false,
                        'code'  => 'MEMBERSHIP_UNPAID',
                        'error' => 'You must complete your $100 annual membership dues before registering for this conference.',
                    ];
                }
            }
        }

        // 3. Ticket Type Validation
        $ticket_id = (int) ($input['ticket_type_id'] ?? 0);
        $ticket = self::get_ticket_type($ticket_id);
        if (!$ticket || (int)$ticket->conference_id !== (int)$conf->id) {
            return ['valid' => false, 'code' => 'INVALID_TICKET', 'error' => 'Selected ticket type is invalid.'];
        }

        if (empty($ticket->active)) {
            return ['valid' => false, 'code' => 'TICKET_INACTIVE', 'error' => 'Selected ticket category is not currently active for enrollment.'];
        }

        // Eligibility check: conference attendance requires active paid membership standing
        if (!$is_admin && class_exists('LFA_Payment_Entitlements') && !LFA_Payment_Entitlements::is_full_member($user_id, true)) {
            return [
                'valid' => false,
                'code'  => 'MEMBERSHIP_DUES_REQUIRED',
                'error' => 'Conference registration is reserved for active alumni members in good financial standing. Please settle your annual dues ($100.00 USD) to register.',
            ];
        }

        if (is_null($ticket->amount_cents) || (int)$ticket->amount_cents <= 0) {
            return [
                'valid' => false,
                'code'  => 'TICKET_PRICE_UNCONFIGURED',
                'error' => 'Ticket fee is pending administrator configuration. Missing ticket amount cannot be registered.',
            ];
        }

        if ($ticket->currency !== 'USD') {
            return ['valid' => false, 'code' => 'INVALID_CURRENCY', 'error' => 'Only USD currency is supported.'];
        }

        // Check capacity
        if (!is_null($ticket->capacity) && (int)$ticket->registered_count >= (int)$ticket->capacity) {
            return [
                'valid' => false,
                'code'  => 'CAPACITY_REACHED',
                'error' => 'Selected ticket category has reached maximum seating capacity.',
            ];
        }

        // 4. Duplicate Registration Prevention
        global $wpdb;
        $t_reg = self::table_registrations();
        if (!$is_guest) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, status FROM {$t_reg} WHERE conference_id = %d AND owner_user_id = %d AND status IN ('confirmed', 'awaiting_payment', 'payment_pending_verification')",
                $conf->id,
                $user_id
            ));
            if ($existing) {
                return [
                    'valid' => false,
                    'code'  => 'DUPLICATE_REGISTRATION',
                    'error' => 'You already have an active registration for ' . esc_html($conf->title) . ' (Status: ' . esc_html(ucfirst(str_replace('_', ' ', $existing->status))) . ').',
                    'existing_registration_id' => $existing->id,
                ];
            }
        }

        // 5. Attendee Field Validation
        $attendees = $input['attendees'] ?? [];
        if (!is_array($attendees) || empty($attendees)) {
            return ['valid' => false, 'code' => 'NO_ATTENDEES', 'error' => 'At least one attendee record must be provided.'];
        }

        $validated_attendees = [];
        foreach ($attendees as $idx => $att) {
            $full_name = sanitize_text_field(trim($att['full_name'] ?? ''));
            if (empty($full_name) || strlen($full_name) < 2) {
                return ['valid' => false, 'code' => 'INVALID_ATTENDEE_NAME', 'error' => "Attendee #" . ($idx + 1) . " requires a valid full name."];
            }

            $email = sanitize_email(trim($att['email'] ?? ''));
            if (!empty($email) && !is_email($email)) {
                return ['valid' => false, 'code' => 'INVALID_ATTENDEE_EMAIL', 'error' => "Attendee #" . ($idx + 1) . " has an invalid email format."];
            }

            $consent = !empty($att['consent_agreed']);
            if (!$consent) {
                return ['valid' => false, 'code' => 'CONSENT_REQUIRED', 'error' => "Attendee #" . ($idx + 1) . " must agree to the conference participation policy."];
            }

            $validated_attendees[] = [
                'attendee_type'         => sanitize_key($att['attendee_type'] ?? ($idx === 0 ? 'primary' : 'guest')),
                'full_name'             => $full_name,
                'email'                 => $email ?: null,
                'phone'                 => sanitize_text_field(trim($att['phone'] ?? '')) ?: null,
                'chapter'               => sanitize_text_field(trim($att['chapter'] ?? '')) ?: null,
                'dietary_accessibility' => sanitize_textarea_field(trim($att['dietary_accessibility'] ?? '')) ?: null,
                'emergency_contact'     => sanitize_text_field(trim($att['emergency_contact'] ?? '')) ?: null,
                'consent_agreed'        => 1,
            ];
        }

        return [
            'valid'      => true,
            'conference' => $conf,
            'ticket'     => $ticket,
            'attendees'  => $validated_attendees,
            'is_guest'   => $is_guest,
        ];
    }

    /**
     * Authoritative Atomic Registration & Invoice Creation
     */
    public static function create_registration(array $input, int $user_id): array {
        $validation = self::validate_registration($input, $user_id);
        if (!$validation['valid']) {
            return ['success' => false, 'code' => $validation['code'], 'error' => $validation['error']];
        }

        global $wpdb;
        $conf   = $validation['conference'];
        $ticket = $validation['ticket'];
        $atts   = $validation['attendees'];
        $is_guest = $validation['is_guest'];

        $t_reg     = self::table_registrations();
        $t_att     = self::table_attendees();
        $t_inv     = self::table_invoices();
        $t_tickets = self::table_ticket_types();

        $now = current_time('mysql', true);
        $reg_year = !empty($conf->starts_at) ? substr($conf->starts_at, 0, 4) : '2027';
        $reg_number = 'REG-' . $reg_year . '-' . strtoupper(wp_generate_password(8, false, false));
        $inv_number = 'CONF-INV-' . $reg_year . '-' . strtoupper(wp_generate_password(8, false, false));

        // Begin transaction
        $wpdb->query('START TRANSACTION');

        try {
            // 1. Insert Registration
            $reg_data = [
                'registration_number'   => $reg_number,
                'conference_id'         => (int) $conf->id,
                'owner_user_id'         => $is_guest ? null : $user_id,
                'guest_email'           => $is_guest ? sanitize_email($input['guest_email'] ?? '') : null,
                'ticket_type_id'        => (int) $ticket->id,
                'ticket_name_snapshot'  => (string) $ticket->name,
                'amount_cents_snapshot' => (int) $ticket->amount_cents,
                'currency'              => 'USD',
                'status'                => self::STATUS_AWAITING_PAYMENT,
                'policy_version'        => (string) $conf->policy_version,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
            $wpdb->insert($t_reg, $reg_data);
            $registration_id = $wpdb->insert_id;

            if (!$registration_id) {
                throw new Exception('Failed to insert conference registration record.');
            }

            // 2. Insert Attendees
            foreach ($atts as $att) {
                $att_data = array_merge($att, [
                    'registration_id' => $registration_id,
                    'conference_id'   => (int) $conf->id,
                    'status'          => 'registered',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
                $wpdb->insert($t_att, $att_data);
            }

            // 3. Insert Authoritative Conference Invoice
            $inv_data = [
                'invoice_number' => $inv_number,
                'registration_id'=> $registration_id,
                'conference_id'  => (int) $conf->id,
                'user_id'        => $is_guest ? null : $user_id,
                'amount_cents'   => (int) $ticket->amount_cents,
                'currency'       => 'USD',
                'status'         => self::INVOICE_STATUS_UNPAID,
                'policy_version' => (string) $conf->policy_version,
                'issued_at'      => $now,
                'paid_at'        => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
            $wpdb->insert($t_inv, $inv_data);
            $invoice_id = $wpdb->insert_id;

            if (!$invoice_id) {
                throw new Exception('Failed to create conference invoice.');
            }

            // 4. Atomically increment ticket registered_count if capacity tracked
            if (!is_null($ticket->capacity)) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$t_tickets} SET registered_count = registered_count + 1 WHERE id = %d AND (capacity IS NULL OR registered_count < capacity)",
                    $ticket->id
                ));
            }

            $wpdb->query('COMMIT');

            // Audit log
            self::log_audit($is_guest ? 'guest' : 'member', $user_id ?: null, 'registration_created', 'registration', (string)$registration_id, [
                'registration_number' => $reg_number,
                'invoice_number'      => $inv_number,
                'ticket_slug'         => $ticket->slug,
                'amount_cents'        => $ticket->amount_cents,
                'attendees_count'     => count($atts),
            ]);

            return [
                'success'             => true,
                'registration_id'     => $registration_id,
                'registration_number' => $reg_number,
                'invoice_id'          => $invoice_id,
                'invoice_number'      => $inv_number,
                'amount_cents'        => (int)$ticket->amount_cents,
                'amount_dollars'      => number_format($ticket->amount_cents / 100, 2),
                'status'              => self::STATUS_AWAITING_PAYMENT,
                'message'             => 'Registration created successfully. Please proceed to payment to confirm your booking.',
            ];

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'code' => 'TRANSACTION_FAILED', 'error' => $e->getMessage()];
        }
    }

    /**
     * Settle Conference Registration upon Verified Payment Capture
     */
    public static function settle_registration(int $invoice_id, string $capture_id, int $amount_cents): array {
        global $wpdb;
        $t_inv = self::table_invoices();
        $t_reg = self::table_registrations();
        $t_att = self::table_attendees();

        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));
        if (!$inv) {
            return ['success' => false, 'error' => 'Conference invoice not found.'];
        }

        if ($inv->status === self::INVOICE_STATUS_PAID) {
            return ['success' => true, 'already_settled' => true, 'message' => 'Invoice was already settled.'];
        }

        // Amount verification
        if ((int)$inv->amount_cents !== $amount_cents) {
            self::log_audit('system', null, 'conference_settle_mismatch', 'invoice', (string)$invoice_id, [
                'expected' => $inv->amount_cents,
                'received' => $amount_cents,
            ]);
            return ['success' => false, 'error' => 'Captured amount does not match authoritative invoice amount.'];
        }

        $now = current_time('mysql', true);
        $user_id = (int)$inv->user_id;

        // Recheck member eligibility at confirmation instant (Phase 08 requirement)
        $is_eligible = false;
        if ($user_id > 0) {
            if (user_can($user_id, 'manage_options') || user_can($user_id, 'edit_users')) {
                $is_eligible = true;
            } elseif (class_exists('LFA_Payment_Entitlements')) {
                $is_eligible = LFA_Payment_Entitlements::is_full_member($user_id, true);
            }
        }

        // 1. Mark Invoice Paid (preserve capture record unconditionally)
        $wpdb->update($t_inv, [
            'status'     => self::INVOICE_STATUS_PAID,
            'paid_at'    => $now,
            'updated_at' => $now,
        ], ['id' => $invoice_id]);

        if (!$is_eligible) {
            // Payment captured, but member eligibility lapsed before confirmation.
            // Hold registration for administrator review; do not discard capture.
            $wpdb->update($t_reg, [
                'status'     => 'flagged_eligibility_lapsed',
                'updated_at' => $now,
            ], ['id' => $inv->registration_id]);

            self::log_audit('system', $user_id ?: null, 'conference_settle_eligibility_lapsed', 'registration', (string)$inv->registration_id, [
                'invoice_number' => $inv->invoice_number,
                'capture_id'     => $capture_id,
                'amount_cents'   => $amount_cents,
                'note'           => 'Payment was captured, but active paid member eligibility lapsed before confirmation.',
            ]);

            return [
                'success'        => true,
                'held'           => true,
                'invoice_number' => $inv->invoice_number,
                'status'         => 'flagged_eligibility_lapsed',
                'message'        => 'Payment was successfully captured, but registration is held for administrative review due to membership standing.',
            ];
        }

        // 2. Mark Registration Confirmed
        $wpdb->update($t_reg, [
            'status'     => self::STATUS_CONFIRMED,
            'updated_at' => $now,
        ], ['id' => $inv->registration_id]);

        // 3. Mark Attendees Confirmed
        $wpdb->update($t_att, [
            'status'     => 'confirmed',
            'updated_at' => $now,
        ], ['registration_id' => $inv->registration_id]);

        // 4. Audit Log
        self::log_audit('system', $inv->user_id ?: null, 'conference_payment_settled', 'registration', (string)$inv->registration_id, [
            'invoice_number' => $inv->invoice_number,
            'capture_id'     => $capture_id,
            'amount_cents'   => $amount_cents,
        ]);

        // 5. Send confirmation email
        self::send_confirmation_email((int)$inv->registration_id, $capture_id);

        return [
            'success'        => true,
            'invoice_number' => $inv->invoice_number,
            'status'         => self::STATUS_CONFIRMED,
        ];
    }

    /**
     * Send genuine Conference Confirmation Email via wp_mail()
     */
    public static function send_confirmation_email(int $registration_id, string $capture_id): bool {
        global $wpdb;
        $t_reg = self::table_registrations();
        $t_att = self::table_attendees();
        $t_inv = self::table_invoices();

        $reg = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_reg} WHERE id = %d", $registration_id));
        if (!$reg || $reg->status !== self::STATUS_CONFIRMED) {
            return false;
        }

        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE registration_id = %d", $registration_id));
        $attendees = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t_att} WHERE registration_id = %d", $registration_id));

        $recipient_email = '';
        $recipient_name  = 'Alumni Member';

        if (!empty($reg->owner_user_id)) {
            $user = get_user_by('id', (int)$reg->owner_user_id);
            if ($user) {
                $recipient_email = $user->user_email;
                $recipient_name  = $user->display_name;
            }
        } elseif (!empty($reg->guest_email)) {
            $recipient_email = $reg->guest_email;
            if (!empty($attendees[0]->full_name)) {
                $recipient_name = $attendees[0]->full_name;
            }
        }

        if (empty($recipient_email) || !is_email($recipient_email)) {
            return false;
        }

        // Deduplication check: verify we have not already sent for this capture
        $t_audit = self::table_audit();
        $already_sent = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t_audit} WHERE action = 'confirmation_email_sent' AND entity_type = 'registration' AND entity_id = %s",
            (string)$registration_id
        ));
        if ($already_sent) {
            return true; // Idempotently avoid double emails
        }

        $conf = self::get_conference();
        $amount_str = '$' . number_format($reg->amount_cents_snapshot / 100, 2) . ' USD';

        $att_lines = [];
        foreach ($attendees as $idx => $a) {
            $att_lines[] = "  " . ($idx + 1) . ". " . esc_html($a->full_name) . " (" . ucfirst($a->attendee_type) . ")";
        }
        $att_text = implode("\n", $att_lines);

        $subject = "Registration Confirmed — 2027 Love Family Alumni Biennial Conference (#{$reg->registration_number})";

        $message = "Dear " . esc_html($recipient_name) . ",\n\n"
            . "We are delighted to confirm your registration for the 2027 Love Family Alumni Biennial Conference!\n\n"
            . "CONFERENCE DETAILS:\n"
            . "--------------------------------------------------\n"
            . "Event:             " . esc_html($conf ? $conf->title : '2027 Biennial Conference') . "\n"
            . "Theme:             “" . esc_html($conf ? $conf->theme : 'Thy Kingdom Come') . "” (" . esc_html($conf ? $conf->verse : 'Matthew 6:10') . ")\n"
            . "Dates:             September 16–19, 2027\n"
            . "Location:          North America Zone (Host City TBA)\n"
            . "--------------------------------------------------\n\n"
            . "REGISTRATION & PAYMENT SUMMARY:\n"
            . "--------------------------------------------------\n"
            . "Registration No:   " . esc_html($reg->registration_number) . "\n"
            . "Ticket Type:       " . esc_html($reg->ticket_name_snapshot) . "\n"
            . "Amount Paid:       " . esc_html($amount_str) . "\n"
            . "PayPal Reference:  " . esc_html($capture_id) . "\n"
            . "Registration Date: " . current_time('mysql') . "\n"
            . "Status:            CONFIRMED\n"
            . "--------------------------------------------------\n\n"
            . "ATTENDEE ROSTER:\n"
            . $att_text . "\n\n"
            . "You can view your full conference record and download your official receipt in your Member Dashboard:\n"
            . home_url('/member-dashboard/#conference') . "\n\n"
            . "Warm regards,\n"
            . "Love Family Alumni Conference Planning Committee — North America Zone\n";

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $sent = wp_mail($recipient_email, $subject, $message, $headers);

        // Security-hardened metadata-only email logging
        $domain = substr(strrchr($recipient_email, '@') ?: '@unknown', 1);
        self::log_audit('system', $reg->owner_user_id ?: null, 'confirmation_email_sent', 'registration', (string)$registration_id, [
            'registration_number' => $reg->registration_number,
            'recipient_domain'    => $domain,
            'status'              => $sent ? 'dispatched' : 'failed',
        ]);

        return $sent;
    }

    /**
     * Authoritative Printable Conference Receipt Payload
     */
    public static function get_receipt_data(int $registration_id, int $viewer_user_id): ?array {
        global $wpdb;
        $t_reg = self::table_registrations();
        $t_inv = self::table_invoices();
        $t_att = self::table_attendees();

        $reg = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_reg} WHERE id = %d", $registration_id));
        if (!$reg) {
            return null;
        }

        // Authorization check: must be owner or have lfa_manage_conferences
        $is_owner = ($reg->owner_user_id && (int)$reg->owner_user_id === $viewer_user_id);
        $is_admin = user_can($viewer_user_id, self::CAP_MANAGE_CONFERENCES) || user_can($viewer_user_id, 'manage_options');

        if (!$is_owner && !$is_admin) {
            return null;
        }

        // Unpaid registrations cannot produce a paid receipt
        if ($reg->status !== self::STATUS_CONFIRMED) {
            return [
                'error'   => 'RECEIPT_NOT_AVAILABLE',
                'message' => 'Official paid conference receipt is generated only after full payment settlement is confirmed.',
                'status'  => $reg->status,
            ];
        }

        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE registration_id = %d", $registration_id));
        $attendees = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t_att} WHERE registration_id = %d", $registration_id));

        // Locate capture from payment ledger
        $t_cap = $wpdb->prefix . 'lfa_payment_captures';
        $capture = null;
        if ($inv && $wpdb->get_var("SHOW TABLES LIKE '{$t_cap}'") === $t_cap) {
            $capture = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t_cap} WHERE payable_type = 'conference' AND payable_id = %d ORDER BY id DESC LIMIT 1",
                $inv->id
            ));
        }

        $conf = self::get_conference();
        $user = $reg->owner_user_id ? get_user_by('id', (int)$reg->owner_user_id) : null;

        return [
            'registration_id'     => $reg->id,
            'registration_number' => $reg->registration_number,
            'invoice_number'      => $inv ? $inv->invoice_number : 'N/A',
            'conference_title'    => $conf ? $conf->title : '2027 Biennial Conference',
            'theme'               => $conf ? $conf->theme : 'Thy Kingdom Come',
            'verse'               => $conf ? $conf->verse : 'Matthew 6:10',
            'dates'               => 'September 16–19, 2027',
            'ticket_name'         => $reg->ticket_name_snapshot,
            'registrant_name'     => $user ? $user->display_name : ($attendees[0]->full_name ?? 'Alumni Registrant'),
            'registrant_email'    => $user ? $user->user_email : ($reg->guest_email ?: ''),
            'amount_dollars'      => number_format($reg->amount_cents_snapshot / 100, 2),
            'currency'            => 'USD',
            'capture_id'          => $capture ? $capture->paypal_capture_id : 'VERIFIED-GATEWAY-CAPTURE',
            'payment_date'        => $inv && $inv->paid_at ? $inv->paid_at : $reg->updated_at,
            'status'              => 'CONFIRMED',
            'attendees'           => array_map(static fn($a) => [
                'name'    => $a->full_name,
                'type'    => ucfirst($a->attendee_type),
                'chapter' => $a->chapter ?: 'General',
            ], $attendees),
        ];
    }

    /**
     * Render Printable HTML Receipt
     */
    public static function render_receipt_html(array $r): string {
        $logo_url = function_exists('lfa_image_url') ? lfa_image_url('brand-logo-source.jpg') : '';
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Conference Receipt — <?php echo esc_html($r['registration_number']); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 40px 20px; }
                .receipt-card { max-width: 680px; margin: 0 auto; background: #ffffff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); padding: 40px; border: 1px solid #e2e8f0; }
                .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0f2942; padding-bottom: 24px; margin-bottom: 28px; }
                .logo-wrap { display: flex; align-items: center; gap: 14px; }
                .logo-wrap img { width: 54px; height: 54px; border-radius: 8px; }
                .title-block h1 { margin: 0; font-size: 18px; color: #0f2942; letter-spacing: 0.04em; text-transform: uppercase; }
                .title-block p { margin: 2px 0 0 0; font-size: 13px; color: #64748b; font-weight: 600; }
                .badge { background: #dcfce7; color: #166534; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 12px; letter-spacing: 0.05em; }
                .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
                .cell { background: #f8fafc; padding: 14px 18px; border-radius: 8px; border: 1px solid #edf2f7; }
                .cell small { display: block; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 4px; }
                .cell strong { font-size: 14px; color: #0f172a; }
                .amount-hero { background: #0f2942; color: #ffffff; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 24px; }
                .amount-hero small { font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #c6933d; font-weight: 700; }
                .amount-hero .val { font-size: 32px; font-weight: 800; margin-top: 4px; }
                .attendees-table { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
                .attendees-table th, .attendees-table td { padding: 10px 14px; text-align: left; font-size: 13px; border-bottom: 1px solid #e2e8f0; }
                .attendees-table th { background: #f1f5f9; color: #475569; font-size: 11px; text-transform: uppercase; }
                .footer { border-top: 1px solid #e2e8f0; padding-top: 20px; text-align: center; font-size: 12px; color: #64748b; }
                .print-btn { display: block; margin: 24px auto 0; background: #0f2942; color: #ffffff; border: none; padding: 10px 24px; border-radius: 6px; font-weight: 600; cursor: pointer; }
                @media print { .print-btn { display: none; } body { background: #ffffff; padding: 0; } .receipt-card { box-shadow: none; border: none; padding: 0; } }
            </style>
        </head>
        <body>
            <div class="receipt-card">
                <div class="head">
                    <div class="logo-wrap">
                        <?php if ($logo_url) : ?>
                            <img src="<?php echo esc_url($logo_url); ?>" alt="LFA Logo">
                        <?php endif; ?>
                        <div class="title-block">
                            <h1>The Love Family Alumni</h1>
                            <p>North America Zone &middot; Official Conference Receipt</p>
                        </div>
                    </div>
                    <span class="badge">PAID &amp; CONFIRMED</span>
                </div>

                <div class="amount-hero">
                    <small>TOTAL REGISTRATION FEE SETTLED</small>
                    <div class="val">$<?php echo esc_html($r['amount_dollars']); ?> <?php echo esc_html($r['currency']); ?></div>
                </div>

                <div class="grid">
                    <div class="cell">
                        <small>CONFERENCE</small>
                        <strong><?php echo esc_html($r['conference_title']); ?></strong><br>
                        <span style="font-size:12px;color:#c6933d;font-weight:600;">“<?php echo esc_html($r['theme']); ?>” (<?php echo esc_html($r['verse']); ?>)</span>
                    </div>
                    <div class="cell">
                        <small>EVENT DATES</small>
                        <strong><?php echo esc_html($r['dates']); ?></strong><br>
                        <span style="font-size:12px;color:#64748b;">North America Zone</span>
                    </div>
                    <div class="cell">
                        <small>REGISTRATION NUMBER</small>
                        <code><?php echo esc_html($r['registration_number']); ?></code>
                    </div>
                    <div class="cell">
                        <small>CONFERENCE INVOICE</small>
                        <code><?php echo esc_html($r['invoice_number']); ?></code>
                    </div>
                    <div class="cell">
                        <small>REGISTRANT</small>
                        <strong><?php echo esc_html($r['registrant_name']); ?></strong><br>
                        <span style="font-size:12px;color:#64748b;"><?php echo esc_html($r['registrant_email']); ?></span>
                    </div>
                    <div class="cell">
                        <small>PAYMENT REFERENCE</small>
                        <code style="word-break:break-all;"><?php echo esc_html($r['capture_id']); ?></code><br>
                        <span style="font-size:12px;color:#64748b;">Date: <?php echo esc_html($r['payment_date']); ?></span>
                    </div>
                </div>

                <h3 style="font-size:14px;color:#0f2942;margin-bottom:12px;text-transform:uppercase;letter-spacing:0.04em;">Confirmed Attendees</h3>
                <table class="attendees-table">
                    <thead>
                        <tr><th>#</th><th>Attendee Name</th><th>Role / Type</th><th>Chapter</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($r['attendees'] as $idx => $att) : ?>
                            <tr>
                                <td><?php echo (int)($idx + 1); ?></td>
                                <td><strong><?php echo esc_html($att['name']); ?></strong></td>
                                <td><?php echo esc_html($att['type']); ?></td>
                                <td><?php echo esc_html($att['chapter']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="footer">
                    <p>Love Family Alumni — North America Zone &middot; Official Conference Records</p>
                    <p>This is a verified electronic confirmation of conference registration and fee settlement.</p>
                </div>

                <button class="print-btn" onclick="window.print()">Print Official Receipt</button>
            </div>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Handle Authenticated Admin-Post Receipt Display
     */
    public static function handle_admin_post_receipt(): void {
        if (!is_user_logged_in()) {
            wp_safe_redirect(home_url('/member-login/?lfa_notice=login_required'));
            exit;
        }

        $reg_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $user_id = get_current_user_id();

        $receipt = self::get_receipt_data($reg_id, $user_id);
        if (!$receipt) {
            wp_die('Unauthorized: You do not have permission to view this receipt, or the registration was not found.', 'Forbidden', ['response' => 403]);
        }

        if (!empty($receipt['error'])) {
            wp_die(esc_html($receipt['message']), 'Receipt Unavailable', ['response' => 400]);
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo self::render_receipt_html($receipt);
        exit;
    }

    /**
     * CSV Export with Formula Injection Neutralization
     */
    public static function export_attendees_csv(int $conference_id): void {
        if (!current_user_can(self::CAP_EXPORT_ATTENDEES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        global $wpdb;
        $t_att = self::table_attendees();
        $t_reg = self::table_registrations();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, r.registration_number, r.ticket_name_snapshot, r.amount_cents_snapshot, r.status as reg_status, r.created_at as reg_date
             FROM {$t_att} a
             INNER JOIN {$t_reg} r ON a.registration_id = r.id
             WHERE a.conference_id = %d
             ORDER BY a.id ASC",
            $conference_id
        ));

        // Sanitize for CSV formula injection
        $sanitize_cell = static function($val): string {
            $str = (string) $val;
            if (isset($str[0]) && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                return "'" . $str;
            }
            return $str;
        };

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="lfa-conference-attendees-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // BOM for Excel UTF-8
        fputs($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'Attendee ID',
            'Full Name',
            'Attendee Type',
            'Email',
            'Chapter',
            'Registration No',
            'Ticket Category',
            'Amount Paid',
            'Status',
            'Registered Date'
        ]);

        foreach ($rows as $r) {
            $amount = '$' . number_format($r->amount_cents_snapshot / 100, 2);
            fputcsv($out, [
                $sanitize_cell($r->id),
                $sanitize_cell($r->full_name),
                $sanitize_cell($r->attendee_type),
                $sanitize_cell($r->email),
                $sanitize_cell($r->chapter),
                $sanitize_cell($r->registration_number),
                $sanitize_cell($r->ticket_name_snapshot),
                $sanitize_cell($amount),
                $sanitize_cell($r->status),
                $sanitize_cell($r->reg_date),
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * Seed authoritative 2027 Keynote Speaker (Dr. Uche Igbokwe, U.K.)
     */
    public static function seed_2027_speakers(): void {
        global $wpdb;
        $t_speakers = self::table_speakers();
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_speakers}'") !== $t_speakers) {
            return;
        }

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t_speakers} WHERE conference_id = %d LIMIT 1", 1));
        if (!$existing) {
            $now = current_time('mysql', true);
            $wpdb->insert($t_speakers, [
                'conference_id'    => 1,
                'name'             => 'Dr. Uche Igbokwe',
                'designation'      => 'Confirmed Keynote Speaker',
                'organization'     => 'United Kingdom',
                'role'             => 'keynote',
                'image_id'         => null,
                'image_alt'        => 'Dr. Uche Igbokwe — Keynote Speaker',
                'keynote_title'    => 'Thy Kingdom Come — The Generational Commission',
                'keynote_abstract' => 'Keynote ministry address aligned with the 2027 Conference Theme: Thy Kingdom Come (Matthew 6:10). An inspiring exploration of spiritual heritage, professional stewardship, and generational impact for the Love Family Alumni across the North America Zone and international chapters.',
                'biography'        => 'Dr. Uche Igbokwe represents the Love Family Alumni United Kingdom fellowship. A distinguished minister and scholar, he brings decades of commitment to pastoral leadership, discipleship, and the generational mission of the Love Family fellowship.',
                'session_title'    => 'Keynote Ministry & Presidential Commission',
                'session_schedule' => 'September 17, 2027 · Morning Plenary',
                'sort_order'       => 1,
                'published_status' => 'published',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }

    /**
     * Retrieve speakers for a conference
     */
    public static function get_conference_speakers(int $conference_id, bool $published_only = true): array {
        global $wpdb;
        $t_speakers = self::table_speakers();
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_speakers}'") !== $t_speakers) {
            return [];
        }

        $sql = "SELECT * FROM {$t_speakers} WHERE conference_id = %d";
        if ($published_only) {
            $sql .= " AND published_status = 'published'";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";

        return $wpdb->get_results($wpdb->prepare($sql, $conference_id)) ?: [];
    }

    /**
     * Retrieve a specific speaker
     */
    public static function get_speaker(int $speaker_id): ?object {
        global $wpdb;
        $t_speakers = self::table_speakers();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_speakers} WHERE id = %d", $speaker_id));
    }

    /**
     * Create or update speaker
     */
    public static function save_speaker(array $data, int $actor_id): array {
        if (!current_user_can(self::CAP_MANAGE_CONFERENCES)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Unauthorized: Conference management capability required.'];
        }

        global $wpdb;
        $t_speakers = self::table_speakers();

        $id = (int)($data['id'] ?? 0);
        $conf_id = (int)($data['conference_id'] ?? 0);
        $name = sanitize_text_field($data['name'] ?? '');

        if ($conf_id <= 0 || empty($name)) {
            return ['success' => false, 'code' => 'REQUIRED_FIELDS', 'error' => 'Speaker name and conference association are required.'];
        }

        $role = sanitize_key($data['role'] ?? 'speaker');
        if (!in_array($role, ['keynote', 'speaker', 'panelist', 'host'], true)) {
            $role = 'speaker';
        }

        $published_status = sanitize_key($data['published_status'] ?? 'published');
        if (!in_array($published_status, ['published', 'draft'], true)) {
            $published_status = 'published';
        }

        $image_id = !empty($data['image_id']) ? (int)$data['image_id'] : null;
        if ($image_id) {
            // Verify attachment exists and is image
            $mime = get_post_mime_type($image_id);
            if (!$mime || !str_starts_with($mime, 'image/')) {
                $image_id = null;
            }
        }

        $fields = [
            'conference_id'    => $conf_id,
            'name'             => $name,
            'designation'      => sanitize_text_field($data['designation'] ?? ''),
            'organization'     => sanitize_text_field($data['organization'] ?? ''),
            'role'             => $role,
            'image_id'         => $image_id,
            'image_alt'        => sanitize_text_field($data['image_alt'] ?? $name),
            'keynote_title'    => sanitize_text_field($data['keynote_title'] ?? ''),
            'keynote_abstract' => sanitize_textarea_field($data['keynote_abstract'] ?? ''),
            'biography'        => sanitize_textarea_field($data['biography'] ?? ''),
            'session_title'    => sanitize_text_field($data['session_title'] ?? ''),
            'session_schedule' => sanitize_text_field($data['session_schedule'] ?? ''),
            'sort_order'       => (int)($data['sort_order'] ?? 0),
            'published_status' => $published_status,
            'updated_at'       => current_time('mysql', true),
        ];

        if ($id > 0) {
            $wpdb->update($t_speakers, $fields, ['id' => $id]);
            $speaker_id = $id;
            self::log_audit('staff', $actor_id, 'speaker_updated', 'speaker', (string)$id, ['name' => $name, 'role' => $role]);
        } else {
            $fields['created_at'] = current_time('mysql', true);
            $wpdb->insert($t_speakers, $fields);
            $speaker_id = $wpdb->insert_id;
            self::log_audit('staff', $actor_id, 'speaker_created', 'speaker', (string)$speaker_id, ['name' => $name, 'role' => $role]);
        }

        return ['success' => true, 'speaker_id' => $speaker_id];
    }

    /**
     * Delete a speaker
     */
    public static function delete_speaker(int $speaker_id, int $actor_id): array {
        if (!current_user_can(self::CAP_MANAGE_CONFERENCES)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Unauthorized: Conference management capability required.'];
        }

        global $wpdb;
        $t_speakers = self::table_speakers();
        $speaker = self::get_speaker($speaker_id);
        if (!$speaker) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Speaker record not found.'];
        }

        $wpdb->delete($t_speakers, ['id' => $speaker_id]);
        self::log_audit('staff', $actor_id, 'speaker_deleted', 'speaker', (string)$speaker_id, ['name' => $speaker->name]);

        return ['success' => true, 'message' => 'Speaker deleted.'];
    }
}
