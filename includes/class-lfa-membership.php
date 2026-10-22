<?php
/**
 * Love Family Alumni — Authoritative Membership & Dues Manager
 *
 * Implements normalized membership records, lifecycle states, immutable
 * integer-cents invoices, audit logging, and safe versioned database migrations.
 * Strictly adheres to Phase 03 contracts: NO PayPal payment capture in Phase 03.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Membership {
    public const SCHEMA_VERSION = '1.0.0';
    public const OPTION_SCHEMA_VER = 'lfa_membership_schema_version';
    public const OPTION_POLICY = 'lfa_dues_policy';

    public const STANDING_NOT_BILLED     = 'not_billed';
    public const STANDING_UNPAID         = 'unpaid';
    public const STANDING_PENDING        = 'pending_payment';
    public const STANDING_CURRENT        = 'current';
    public const STANDING_OVERDUE        = 'overdue';
    public const STANDING_EXPIRED        = 'expired';
    public const STANDING_WAIVED         = 'waived';

    public const INVOICE_STATUS_DRAFT    = 'draft';
    public const INVOICE_STATUS_UNPAID   = 'unpaid';
    public const INVOICE_STATUS_PENDING  = 'payment_pending';
    public const INVOICE_STATUS_PAID     = 'paid';
    public const INVOICE_STATUS_VOID     = 'void';
    public const INVOICE_STATUS_WAIVED   = 'waived';

    public static function init(): void {
        // Auto-run migration if schema is outdated or missing
        if (get_option(self::OPTION_SCHEMA_VER) !== self::SCHEMA_VERSION) {
            self::install_schema();
        }

        // Register custom administrative capabilities
        add_action('init', [__CLASS__, 'register_capabilities']);
    }

    public static function register_capabilities(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $caps = [
                'lfa_manage_memberships',
                'lfa_manage_dues_policy',
                'lfa_view_membership_reports',
            ];
            foreach ($caps as $cap) {
                if (!$admin->has_cap($cap)) {
                    $admin->add_cap($cap);
                }
            }
        }
    }

    /**
     * Database table name helpers using $wpdb->prefix
     */
    public static function table_memberships(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_memberships';
    }

    public static function table_periods(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_membership_periods';
    }

    public static function table_invoices(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_dues_invoices';
    }

    public static function table_audit(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_membership_audit';
    }

    /**
     * Idempotent versioned database schema migration using dbDelta
     */
    public static function install_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $t_memberships = self::table_memberships();
        $sql_memberships = "CREATE TABLE {$t_memberships} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            membership_type varchar(20) NOT NULL DEFAULT 'individual',
            status varchar(30) NOT NULL DEFAULT 'unpaid',
            account_approved_at datetime NULL,
            partner_name varchar(150) NULL,
            partner_email varchar(150) NULL,
            policy_version varchar(50) NOT NULL DEFAULT 'v1.0-unconfigured',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_id_unique (user_id),
            KEY membership_type (membership_type),
            KEY status (status)
        ) {$charset_collate};";

        $t_periods = self::table_periods();
        $sql_periods = "CREATE TABLE {$t_periods} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            membership_id bigint(20) unsigned NOT NULL,
            period_key varchar(50) NOT NULL,
            starts_at datetime NULL,
            ends_at datetime NULL,
            standing varchar(30) NOT NULL DEFAULT 'unpaid',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY membership_period_unique (membership_id, period_key),
            KEY membership_id (membership_id),
            KEY standing (standing)
        ) {$charset_collate};";

        $t_invoices = self::table_invoices();
        $sql_invoices = "CREATE TABLE {$t_invoices} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            invoice_number varchar(64) NOT NULL,
            membership_id bigint(20) unsigned NOT NULL,
            period_id bigint(20) unsigned NULL,
            user_id bigint(20) unsigned NOT NULL,
            amount_cents bigint(20) NOT NULL DEFAULT 10000,
            currency char(3) NOT NULL DEFAULT 'USD',
            status varchar(30) NOT NULL DEFAULT 'unpaid',
            policy_version varchar(50) NOT NULL DEFAULT 'v1.0-unconfigured',
            due_date date NULL,
            notes text NULL,
            paid_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY invoice_number_unique (invoice_number),
            KEY membership_id (membership_id),
            KEY user_id (user_id),
            KEY status (status)
        ) {$charset_collate};";

        $t_audit = self::table_audit();
        $sql_audit = "CREATE TABLE {$t_audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_user_id bigint(20) unsigned NOT NULL,
            subject_user_id bigint(20) unsigned NOT NULL,
            action varchar(64) NOT NULL,
            object_type varchar(64) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            metadata_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY actor_user_id (actor_user_id),
            KEY subject_user_id (subject_user_id),
            KEY action (action)
        ) {$charset_collate};";

        dbDelta($sql_memberships);
        dbDelta($sql_periods);
        dbDelta($sql_invoices);
        dbDelta($sql_audit);

        update_option(self::OPTION_SCHEMA_VER, self::SCHEMA_VERSION);

        // Initialize default policy if not set
        self::ensure_default_policy();

        // Migrate existing Phase 02 members into normalized records
        self::migrate_phase02_users();
    }

    /**
     * Clean schema rollback function (drops custom tables safely without touching wp_users)
     */
    public static function drop_schema(): void {
        global $wpdb;
        $t_audit       = self::table_audit();
        $t_invoices    = self::table_invoices();
        $t_periods     = self::table_periods();
        $t_memberships = self::table_memberships();

        $wpdb->query("DROP TABLE IF EXISTS {$t_audit}");
        $wpdb->query("DROP TABLE IF EXISTS {$t_invoices}");
        $wpdb->query("DROP TABLE IF EXISTS {$t_periods}");
        $wpdb->query("DROP TABLE IF EXISTS {$t_memberships}");

        delete_option(self::OPTION_SCHEMA_VER);
    }

    /**
     * Dues policy defaults and settings
     */
    public static function ensure_default_policy(): array {
        $policy = get_option(self::OPTION_POLICY);
        if (!is_array($policy)) {
            $policy = [
                'individual_base_cents' => 10000, // Documented $100.00 USD
                'couple_base_cents'     => null,  // STRICTLY unconfigured by default (per CLIENT_POLICY_DECISIONS.md)
                'billing_cycle'         => 'unconfigured', // 'unconfigured', 'calendar', 'anniversary'
                'grace_period_days'     => null,  // unconfigured
                'auto_recurring'        => false, // manual renewal only
                'policy_version'        => 'v1.0-unconfigured',
                'updated_by'            => 1,
                'updated_at'            => current_time('mysql', true),
            ];
            update_option(self::OPTION_POLICY, $policy, false);
        }
        return $policy;
    }

    public static function get_policy(): array {
        return self::ensure_default_policy();
    }

    public static function update_policy(array $new_policy, int $actor_id): bool {
        $current = self::get_policy();

        $individual_cents = isset($new_policy['individual_base_cents']) ? absint($new_policy['individual_base_cents']) : 10000;
        $couple_cents = !empty($new_policy['couple_base_cents']) ? absint($new_policy['couple_base_cents']) : null;
        $cycle = in_array($new_policy['billing_cycle'] ?? '', ['calendar', 'anniversary'], true) ? $new_policy['billing_cycle'] : 'unconfigured';
        $grace = !empty($new_policy['grace_period_days']) ? absint($new_policy['grace_period_days']) : null;

        $version_num = (float) filter_var($current['policy_version'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $new_version = 'v' . number_format($version_num > 0 ? $version_num + 0.1 : 1.1, 1);

        $policy = [
            'individual_base_cents' => $individual_cents,
            'couple_base_cents'     => $couple_cents,
            'billing_cycle'         => $cycle,
            'grace_period_days'     => $grace,
            'auto_recurring'        => false, // Strictly false in Phase 03
            'policy_version'        => $new_version,
            'updated_by'            => $actor_id,
            'updated_at'            => current_time('mysql', true),
        ];

        update_option(self::OPTION_POLICY, $policy, false);

        self::log_audit($actor_id, 0, 'policy_updated', 'policy', 0, [
            'version'          => $new_version,
            'individual_cents' => $individual_cents,
            'couple_cents'     => $couple_cents,
            'cycle'            => $cycle,
        ]);

        return true;
    }

    /**
     * Migrate existing Phase 02 users into normalized lfa_memberships
     */
    public static function migrate_phase02_users(): void {
        global $wpdb;
        $t_memberships = self::table_memberships();

        $users = get_users(['role' => LFA_Core::ROLE]);
        $now = current_time('mysql', true);
        $policy = self::get_policy();

        foreach ($users as $user) {
            $uid = (int) $user->ID;
            $type = (string) get_user_meta($uid, 'lfa_membership_type', true) ?: 'individual';
            if (!in_array($type, ['individual', 'couple'], true)) {
                $type = 'individual';
            }

            $partner_name  = ($type === 'couple') ? (string) get_user_meta($uid, 'lfa_partner_name', true) : null;
            $partner_email = ($type === 'couple') ? (string) get_user_meta($uid, 'lfa_partner_email', true) : null;
            $approved_at   = (string) get_user_meta($uid, 'lfa_approved_at', true) ?: null;

            // Idempotent insert or update without overwriting
            $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$t_memberships} WHERE user_id = %d", $uid));
            if (!$existing) {
                $wpdb->insert($t_memberships, [
                    'user_id'             => $uid,
                    'membership_type'     => $type,
                    'status'              => self::STANDING_UNPAID,
                    'account_approved_at' => $approved_at,
                    'partner_name'        => $partner_name,
                    'partner_email'       => $partner_email,
                    'policy_version'      => $policy['policy_version'],
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
                $membership_id = $wpdb->insert_id;

                self::log_audit(0, $uid, 'member_migrated', 'membership', $membership_id, [
                    'type' => $type,
                ]);

                // Create initial period record
                $t_periods = self::table_periods();
                $period_key = '2026/2027';
                $wpdb->insert($t_periods, [
                    'membership_id' => $membership_id,
                    'period_key'    => $period_key,
                    'starts_at'     => null,
                    'ends_at'       => null,
                    'standing'      => self::STANDING_UNPAID,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                $period_id = $wpdb->insert_id;

                // For individual members, issue initial unpaid invoice
                if ($type === 'individual') {
                    self::issue_invoice_internal($membership_id, $period_id, $uid, $policy['individual_base_cents'], 'Initial membership dues schedule (2026/2027)');
                }
                // For couple members: DO NOT auto-generate invoice if couple price is unconfigured! (C036)
            }
        }
    }

    /**
     * Retrieve or create membership record for user
     */
    public static function get_membership_by_user(int $user_id): ?object {
        global $wpdb;
        $t = self::table_memberships();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE user_id = %d", $user_id));
    }

    public static function get_or_create_membership(int $user_id): object {
        $mem = self::get_membership_by_user($user_id);
        if ($mem) {
            return $mem;
        }

        global $wpdb;
        $t_memberships = self::table_memberships();
        $type = (string) get_user_meta($user_id, 'lfa_membership_type', true) ?: 'individual';
        $partner_name  = ($type === 'couple') ? (string) get_user_meta($user_id, 'lfa_partner_name', true) : null;
        $partner_email = ($type === 'couple') ? (string) get_user_meta($user_id, 'lfa_partner_email', true) : null;
        $approved_at   = (string) get_user_meta($user_id, 'lfa_approved_at', true) ?: null;
        $now = current_time('mysql', true);
        $policy = self::get_policy();

        $wpdb->insert($t_memberships, [
            'user_id'             => $user_id,
            'membership_type'     => $type,
            'status'              => self::STANDING_UNPAID,
            'account_approved_at' => $approved_at,
            'partner_name'        => $partner_name,
            'partner_email'       => $partner_email,
            'policy_version'      => $policy['policy_version'],
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $membership_id = $wpdb->insert_id;

        // Period
        $t_periods = self::table_periods();
        $period_key = '2026/2027';
        $wpdb->insert($t_periods, [
            'membership_id' => $membership_id,
            'period_key'    => $period_key,
            'standing'      => self::STANDING_UNPAID,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $period_id = $wpdb->insert_id;

        if ($type === 'individual') {
            self::issue_invoice_internal($membership_id, $period_id, $user_id, $policy['individual_base_cents'], 'Initial membership dues schedule (2026/2027)');
        }

        return self::get_membership_by_user($user_id);
    }

    /**
     * Issues an invoice idempotently.
     * Prevents duplicate unpaid invoices (C028, C029).
     * Enforces couple unconfigured restriction (C036).
     */
    public static function issue_invoice(int $membership_id, int $actor_id, string $notes = ''): array {
        global $wpdb;
        $t_mem = self::table_memberships();
        $membership = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_mem} WHERE id = %d", $membership_id));
        if (!$membership) {
            return ['success' => false, 'error' => 'Membership record not found.'];
        }

        $policy = self::get_policy();
        $amount_cents = $policy['individual_base_cents'];

        if ($membership->membership_type === 'couple') {
            if (empty($policy['couple_base_cents']) || $policy['couple_base_cents'] <= 0) {
                return [
                    'success' => false,
                    'error'   => 'Couple Membership annual dues price is unconfigured. Awaiting administrator policy confirmation before invoicing.',
                ];
            }
            $amount_cents = (int) $policy['couple_base_cents'];
        }

        // Check for existing period
        $t_periods = self::table_periods();
        $period_key = '2026/2027';
        $period = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$t_periods} WHERE membership_id = %d AND period_key = %s",
            $membership_id, $period_key
        ));
        $period_id = $period ? (int) $period->id : 0;
        if (!$period_id) {
            $now = current_time('mysql', true);
            $wpdb->insert($t_periods, [
                'membership_id' => $membership_id,
                'period_key'    => $period_key,
                'standing'      => self::STANDING_UNPAID,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            $period_id = $wpdb->insert_id;
        }

        $inv = self::issue_invoice_internal($membership_id, $period_id, (int) $membership->user_id, $amount_cents, $notes);
        if ($inv) {
            self::log_audit($actor_id, (int) $membership->user_id, 'invoice_issued', 'invoice', (int) $inv->id, [
                'invoice_number' => $inv->invoice_number,
                'amount_cents'   => $amount_cents,
            ]);
            return ['success' => true, 'invoice' => $inv];
        }

        return ['success' => false, 'error' => 'Could not create invoice.'];
    }

    private static function issue_invoice_internal(int $membership_id, int $period_id, int $user_id, int $amount_cents, string $notes = ''): ?object {
        global $wpdb;
        $t_invoices = self::table_invoices();

        // Idempotency check: if an unpaid or live invoice already exists for this membership & period, return it! (C028, C029)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_invoices} WHERE membership_id = %d AND period_id = %d AND status IN ('unpaid', 'payment_pending', 'draft') LIMIT 1",
            $membership_id, $period_id
        ));
        if ($existing) {
            return $existing;
        }

        $now = current_time('mysql', true);
        $policy = self::get_policy();
        $invoice_number = sprintf('LFA-INV-%s-%06d', date('Y'), $membership_id);

        // Ensure unique invoice number in rare collision
        $check_inv = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t_invoices} WHERE invoice_number = %s", $invoice_number));
        if ($check_inv) {
            $invoice_number = sprintf('LFA-INV-%s-%06d-%s', date('Y'), $membership_id, substr(md5(uniqid()), 0, 4));
        }

        $inserted = $wpdb->insert($t_invoices, [
            'invoice_number' => $invoice_number,
            'membership_id'  => $membership_id,
            'period_id'      => $period_id,
            'user_id'        => $user_id,
            'amount_cents'   => $amount_cents,
            'currency'       => 'USD',
            'status'         => self::INVOICE_STATUS_UNPAID,
            'policy_version' => $policy['policy_version'],
            'due_date'       => null,
            'notes'          => sanitize_text_field($notes),
            'paid_at'        => null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        if ($inserted === false) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_invoices} WHERE id = %d", $wpdb->insert_id));
    }

    /**
     * Invoices query for current authenticated member (strictly scoped by user_id)
     */
    public static function get_invoices_for_user(int $user_id): array {
        global $wpdb;
        $t = self::table_invoices();
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC",
            $user_id
        ));
        return is_array($results) ? $results : [];
    }

    public static function get_invoice(int $invoice_id): ?object {
        global $wpdb;
        $t = self::table_invoices();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $invoice_id));
    }

    public static function get_invoice_by_number(string $invoice_num): ?object {
        global $wpdb;
        $t = self::table_invoices();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE invoice_number = %s", $invoice_num));
    }

    /**
     * Audit log handler
     */
    public static function log_audit(int $actor_id, int $subject_id, string $action, string $object_type, int $object_id, array $metadata = []): void {
        global $wpdb;
        $t = self::table_audit();
        $now = current_time('mysql', true);

        // Sanitize metadata allowlist, absolutely NO passwords, tokens, full emails, or PII
        $clean_meta = [];
        foreach ($metadata as $k => $v) {
            if (in_array($k, ['token', 'password', 'user_pass', 'secret', 'email'], true)) {
                continue;
            }
            $clean_meta[sanitize_key($k)] = is_scalar($v) ? sanitize_text_field((string)$v) : $v;
        }

        $wpdb->insert($t, [
            'actor_user_id'   => $actor_id,
            'subject_user_id' => $subject_id,
            'action'          => sanitize_key($action),
            'object_type'     => sanitize_key($object_type),
            'object_id'       => absint($object_id),
            'metadata_json'   => wp_json_encode($clean_meta),
            'created_at'      => $now,
        ]);
    }

    public static function get_audit_logs(int $limit = 50, int $offset = 0): array {
        global $wpdb;
        $t = self::table_audit();
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ));
        return is_array($results) ? $results : [];
    }

    /**
     * Mark membership as expired according to approved policy
     */
    public static function expire_membership(int $user_id): bool {
        global $wpdb;
        $t_mem = self::table_memberships();
        $t_per = self::table_periods();
        $now = current_time('mysql', true);

        $mem = self::get_membership_by_user($user_id);
        if (!$mem) {
            return false;
        }

        $wpdb->update($t_mem, [
            'status'     => self::STANDING_EXPIRED,
            'updated_at' => $now,
        ], ['id' => $mem->id]);

        $wpdb->update($t_per, [
            'standing'   => self::STANDING_EXPIRED,
            'ends_at'    => date('Y-m-d H:i:s', strtotime('-1 day')),
            'updated_at' => $now,
        ], ['membership_id' => $mem->id]);

        update_user_meta($user_id, 'lfa_dues_status', self::STANDING_EXPIRED);

        self::log_audit(0, $user_id, 'membership_expired', 'membership', $mem->id, [
            'previous_status' => $mem->status,
        ]);

        return true;
    }

    /**
     * Issue renewal invoice for an expired or renewing member
     */
    public static function renew_membership(int $user_id, int $actor_id = 0): ?object {
        global $wpdb;
        $mem = self::get_or_create_membership($user_id);
        $policy = self::get_policy();
        $now = current_time('mysql', true);
        $t_per = self::table_periods();

        // Resolve period key safely (handle existing period records)
        $current_year = (int) date('Y');
        $period_key = $current_year . '/' . ($current_year + 1);
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$t_per} WHERE membership_id = %d AND period_key = %s",
            $mem->id, $period_key
        ));

        if ($existing) {
            $period_key = ($current_year + 1) . '/' . ($current_year + 2);
            $existing2 = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$t_per} WHERE membership_id = %d AND period_key = %s",
                $mem->id, $period_key
            ));
            if ($existing2) {
                $period_id = (int)$existing2->id;
                $wpdb->update($t_per, ['standing' => self::STANDING_UNPAID, 'updated_at' => $now], ['id' => $period_id]);
            } else {
                $wpdb->insert($t_per, [
                    'membership_id' => $mem->id,
                    'period_key'    => $period_key,
                    'standing'      => self::STANDING_UNPAID,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                $period_id = (int) $wpdb->insert_id;
            }
        } else {
            $wpdb->insert($t_per, [
                'membership_id' => $mem->id,
                'period_key'    => $period_key,
                'standing'      => self::STANDING_UNPAID,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            $period_id = (int) $wpdb->insert_id;
        }

        $inv = self::issue_invoice_internal($mem->id, $period_id, $user_id, $policy['individual_base_cents'], "Annual membership dues renewal ({$period_key})");

        self::log_audit($actor_id, $user_id, 'membership_renew_invoiced', 'invoice', $inv ? $inv->id : 0, [
            'period_key' => $period_key,
        ]);

        return $inv;
    }
}
