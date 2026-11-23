<?php
/**
 * Love Family Alumni — Core Payment Service & State Machine
 *
 * Implements authoritative server-side payment lifecycle, Orders v2 settlement,
 * idempotent invoicing, double-click/replay prevention, signed webhook reconciliation,
 * audit trail, and genuine receipt generation.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Payment_Service {
    public const SCHEMA_VERSION = '1.0.0';
    public const OPTION_SCHEMA_VER = 'lfa_payment_schema_version';

    public static function init(): void {
        if (get_option(self::OPTION_SCHEMA_VER) !== self::SCHEMA_VERSION) {
            self::install_schema();
        }
        if (class_exists('LFA_Payment_Orchestrator')) {
            LFA_Payment_Orchestrator::init();
        }
        add_action('init', [__CLASS__, 'register_capabilities']);
    }

    public static function register_capabilities(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $caps = [
                LFA_PayPal_Settings::CAPABILITY,
                'lfa_view_payment_transactions',
                'lfa_reconcile_payments',
            ];
            foreach ($caps as $cap) {
                if (!$admin->has_cap($cap)) {
                    $admin->add_cap($cap);
                }
            }
        }
    }

    public static function table_attempts(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_payment_attempts';
    }

    public static function table_captures(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_payment_captures';
    }

    public static function table_webhook_events(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_payment_webhook_events';
    }

    public static function table_audit(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_payment_audit';
    }

    /**
     * Idempotent Phase 04 schema installation using dbDelta
     */
    public static function install_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $t_attempts = self::table_attempts();
        $sql_attempts = "CREATE TABLE {$t_attempts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            invoice_id bigint(20) unsigned NOT NULL,
            payable_type varchar(30) NOT NULL DEFAULT 'membership_due',
            payable_id bigint(20) unsigned NULL,
            user_id bigint(20) unsigned NOT NULL,
            provider varchar(20) NOT NULL DEFAULT 'paypal',
            mode varchar(20) NOT NULL DEFAULT 'sandbox',
            merchant_identity varchar(100) NULL,
            local_request_key varchar(64) NOT NULL,
            order_id varchar(100) NULL,
            amount_cents int(11) NOT NULL,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            state varchar(30) NOT NULL DEFAULT 'initialized',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY local_request_key_unique (local_request_key),
            UNIQUE KEY order_id_unique (order_id),
            KEY invoice_id (invoice_id),
            KEY payable_type (payable_type),
            KEY payable_id (payable_id),
            KEY user_id (user_id),
            KEY state (state)
        ) {$charset_collate};";

        $t_captures = self::table_captures();
        $sql_captures = "CREATE TABLE {$t_captures} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attempt_id bigint(20) unsigned NOT NULL,
            invoice_id bigint(20) unsigned NOT NULL,
            payable_type varchar(30) NOT NULL DEFAULT 'membership_due',
            payable_id bigint(20) unsigned NULL,
            paypal_capture_id varchar(100) NOT NULL,
            paypal_order_id varchar(100) NOT NULL,
            merchant_identity varchar(100) NULL,
            mode varchar(20) NOT NULL DEFAULT 'sandbox',
            amount_cents int(11) NOT NULL,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            capture_status varchar(30) NOT NULL DEFAULT 'COMPLETED',
            captured_at datetime NOT NULL,
            reconciled_at datetime NULL,
            refund_state varchar(30) NOT NULL DEFAULT 'none',
            last_checked_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY paypal_capture_id_unique (paypal_capture_id),
            KEY attempt_id (attempt_id),
            KEY invoice_id (invoice_id),
            KEY payable_type (payable_type),
            KEY payable_id (payable_id),
            KEY paypal_order_id (paypal_order_id),
            KEY capture_status (capture_status)
        ) {$charset_collate};";

        $t_webhook_events = self::table_webhook_events();
        $sql_webhook_events = "CREATE TABLE {$t_webhook_events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            paypal_event_id varchar(100) NOT NULL,
            paypal_transmission_id varchar(100) NULL,
            event_type varchar(100) NOT NULL,
            mode varchar(20) NOT NULL DEFAULT 'sandbox',
            verified_at datetime NULL,
            processed_at datetime NULL,
            processing_state varchar(30) NOT NULL DEFAULT 'received_unverified',
            failure_count int(11) NOT NULL DEFAULT 0,
            last_error_code varchar(100) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY paypal_event_id_unique (paypal_event_id),
            KEY event_type (event_type),
            KEY processing_state (processing_state)
        ) {$charset_collate};";

        $t_audit = self::table_audit();
        $sql_audit = "CREATE TABLE {$t_audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_type varchar(30) NOT NULL DEFAULT 'system',
            actor_id bigint(20) unsigned NULL,
            event_type varchar(100) NOT NULL,
            related_object_id varchar(100) NULL,
            changes_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY actor_id (actor_id),
            KEY event_type (event_type)
        ) {$charset_collate};";

        dbDelta($sql_attempts);
        dbDelta($sql_captures);
        dbDelta($sql_webhook_events);
        dbDelta($sql_audit);

        self::upgrade_columns();

        update_option(self::OPTION_SCHEMA_VER, self::SCHEMA_VERSION);
    }

    /**
     * Idempotent column upgrades for dual gateway support (PayPal + Stripe)
     */
    public static function upgrade_columns(): void {
        global $wpdb;
        $t_att = self::table_attempts();
        $t_cap = self::table_captures();
        $t_events = self::table_webhook_events();

        // 1. Attempts table
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_att}'") === $t_att) {
            $cols = $wpdb->get_col("DESCRIBE `{$t_att}`");
            if (!in_array('checkout_session_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_att}` ADD COLUMN `checkout_session_id` varchar(150) NULL AFTER `order_id`");
                $wpdb->query("ALTER TABLE `{$t_att}` ADD INDEX `checkout_session_id` (`checkout_session_id`)");
            }
            if (!in_array('provider_payment_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_att}` ADD COLUMN `provider_payment_id` varchar(150) NULL AFTER `checkout_session_id`");
                $wpdb->query("ALTER TABLE `{$t_att}` ADD INDEX `provider_payment_id` (`provider_payment_id`)");
            }
            if (!in_array('gateway_environment', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_att}` ADD COLUMN `gateway_environment` varchar(20) NOT NULL DEFAULT 'test' AFTER `mode`");
            }
            if (!in_array('test_transaction', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_att}` ADD COLUMN `test_transaction` tinyint(1) NOT NULL DEFAULT 1 AFTER `state`");
            }
        }

        // 2. Captures table
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_cap}'") === $t_cap) {
            $cols = $wpdb->get_col("DESCRIBE `{$t_cap}`");
            if (!in_array('gateway_provider', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD COLUMN `gateway_provider` varchar(20) NOT NULL DEFAULT 'paypal' AFTER `invoice_id`");
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD INDEX `gateway_provider` (`gateway_provider`)");
            }
            if (!in_array('gateway_environment', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD COLUMN `gateway_environment` varchar(20) NOT NULL DEFAULT 'sandbox' AFTER `mode`");
            }
            if (!in_array('checkout_session_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD COLUMN `checkout_session_id` varchar(150) NULL AFTER `paypal_order_id`");
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD INDEX `checkout_session_id` (`checkout_session_id`)");
            }
            if (!in_array('provider_payment_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD COLUMN `provider_payment_id` varchar(150) NULL AFTER `checkout_session_id`");
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD INDEX `provider_payment_id` (`provider_payment_id`)");
            }
            if (!in_array('provider_event_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD COLUMN `provider_event_id` varchar(150) NULL AFTER `provider_payment_id`");
            }
            if (!in_array('payment_status', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD COLUMN `payment_status` varchar(30) NOT NULL DEFAULT 'COMPLETED' AFTER `capture_status`");
            }
            if (!in_array('verified_at', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD COLUMN `verified_at` datetime NULL AFTER `captured_at`");
            }
            if (!in_array('settled_at', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD COLUMN `settled_at` datetime NULL AFTER `reconciled_at`");
            }
            if (!in_array('refunded_at', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD COLUMN `refunded_at` datetime NULL AFTER `refund_state`");
            }
            if (!in_array('entitlement_effect', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD COLUMN `entitlement_effect` varchar(50) NULL AFTER `refunded_at`");
            }
            if (!in_array('test_transaction', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cap}` ADD COLUMN `test_transaction` tinyint(1) NOT NULL DEFAULT 1 AFTER `entitlement_effect`");
            }
        }

        // 3. Webhook events table
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_events}'") === $t_events) {
            $cols = $wpdb->get_col("DESCRIBE `{$t_events}`");
            if (!in_array('gateway_provider', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_events}` ADD COLUMN `gateway_provider` varchar(20) NOT NULL DEFAULT 'paypal' AFTER `paypal_event_id`");
            }
            if (!in_array('provider_event_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_events}` ADD COLUMN `provider_event_id` varchar(150) NULL AFTER `gateway_provider`");
                $wpdb->query("ALTER TABLE `{$t_events}` ADD INDEX `provider_event_id` (`provider_event_id`)");
            }
        }

        // 4. Invoices and Payable tables
        $t_dues = $wpdb->prefix . 'lfa_dues_invoices';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_dues}'") === $t_dues) {
            $cols = $wpdb->get_col("DESCRIBE `{$t_dues}`");
            if (!in_array('gateway_provider', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_dues}` ADD COLUMN `gateway_provider` varchar(20) NULL AFTER `status`");
            }
            if (!in_array('provider_payment_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_dues}` ADD COLUMN `provider_payment_id` varchar(150) NULL AFTER `gateway_provider`");
            }
            if (!in_array('test_paid', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_dues}` ADD COLUMN `test_paid` tinyint(1) NOT NULL DEFAULT 0 AFTER `provider_payment_id`");
            }
        }

        $t_cinv = $wpdb->prefix . 'lfa_conference_invoices';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_cinv}'") === $t_cinv) {
            $cols = $wpdb->get_col("DESCRIBE `{$t_cinv}`");
            if (!in_array('gateway_provider', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cinv}` ADD COLUMN `gateway_provider` varchar(20) NULL AFTER `status`");
            }
            if (!in_array('provider_payment_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cinv}` ADD COLUMN `provider_payment_id` varchar(150) NULL AFTER `gateway_provider`");
            }
            if (!in_array('test_paid', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_cinv}` ADD COLUMN `test_paid` tinyint(1) NOT NULL DEFAULT 0 AFTER `provider_payment_id`");
            }
        }

        $t_creg = $wpdb->prefix . 'lfa_conference_registrations';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_creg}'") === $t_creg) {
            $cols = $wpdb->get_col("DESCRIBE `{$t_creg}`");
            if (!in_array('gateway_provider', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_creg}` ADD COLUMN `gateway_provider` varchar(20) NULL AFTER `status`");
            }
            if (!in_array('provider_payment_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_creg}` ADD COLUMN `provider_payment_id` varchar(150) NULL AFTER `gateway_provider`");
            }
            if (!in_array('test_paid', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_creg}` ADD COLUMN `test_paid` tinyint(1) NOT NULL DEFAULT 0 AFTER `provider_payment_id`");
            }
        }

        $t_don = $wpdb->prefix . 'lfa_donations';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_don}'") === $t_don) {
            $cols = $wpdb->get_col("DESCRIBE `{$t_don}`");
            if (!in_array('gateway_provider', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_don}` ADD COLUMN `gateway_provider` varchar(20) NULL AFTER `status`");
            }
            if (!in_array('provider_payment_id', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_don}` ADD COLUMN `provider_payment_id` varchar(150) NULL AFTER `gateway_provider`");
            }
            if (!in_array('test_transaction', $cols, true)) {
                $wpdb->query("ALTER TABLE `{$t_don}` ADD COLUMN `test_transaction` tinyint(1) NOT NULL DEFAULT 0 AFTER `provider_payment_id`");
            }
        }
    }

    /**
     * Audit logger for payment transactions
     */
    public static function log_audit(string $actor_type, ?int $actor_id, string $event_type, ?string $object_id, array $data = []): void {
        global $wpdb;
        $t = self::table_audit();
        // Scrub any secrets or PII
        unset($data['client_secret'], $data['token'], $data['auth_header']);

        $wpdb->insert($t, [
            'actor_type'        => sanitize_key($actor_type),
            'actor_id'          => $actor_id ?: null,
            'event_type'        => sanitize_key($event_type),
            'related_object_id' => $object_id ? sanitize_text_field($object_id) : null,
            'changes_json'      => wp_json_encode($data),
            'created_at'        => current_time('mysql', true),
        ]);
    }

    /**
     * Authoritative invoice validation for checkout
     */
    public static function validate_invoice_for_checkout(int $invoice_id, int $user_id, string $payable_type = 'membership_due'): array {
        global $wpdb;

        if ($payable_type === 'donation') {
            if (!class_exists('LFA_Donation')) {
                return ['valid' => false, 'code' => 'DONATION_UNAVAILABLE', 'error' => 'Donation system unavailable.'];
            }
            $donation = LFA_Donation::get_donation_by_id($invoice_id);
            if (!$donation) {
                return ['valid' => false, 'code' => 'NOT_FOUND', 'error' => 'Donation record not found.'];
            }

            if (!empty($donation->donor_user_id) && $user_id > 0 && (int)$donation->donor_user_id !== $user_id) {
                return ['valid' => false, 'code' => 'FORBIDDEN', 'error' => 'You are not authorized to pay this donation.'];
            }

            if ($donation->status !== LFA_Donation::STATUS_PENDING_PAYMENT) {
                return ['valid' => false, 'code' => 'INVALID_STATUS', 'error' => 'Donation is not in a pending payment state (current: ' . esc_html($donation->status) . ').'];
            }

            if ((int)$donation->amount_cents <= 0 || $donation->currency !== 'USD') {
                return ['valid' => false, 'code' => 'INVALID_AMOUNT', 'error' => 'Donation amount or currency is invalid.'];
            }

            // Verify payment gateway enabled (PayPal or Stripe Test)
            $gateway_ready = false;
            if (class_exists('LFA_Gateway_Settings')) {
                if (!LFA_Gateway_Settings::is_global_kill_switch_active()) {
                    $gateway_ready = LFA_Gateway_Settings::is_stripe_test_enabled() || LFA_Gateway_Settings::is_paypal_enabled();
                }
            } elseif (class_exists('LFA_PayPal_Settings')) {
                $gateway_ready = LFA_PayPal_Settings::is_payments_enabled();
            }

            if (!$gateway_ready) {
                return [
                    'valid' => false,
                    'code'  => 'GATEWAY_DISABLED',
                    'error' => 'Online donation payment is currently disabled or pending gateway configuration.',
                ];
            }

            return ['valid' => true, 'invoice' => $donation, 'donation' => $donation, 'payable_type' => 'donation'];
        }

        if ($payable_type === 'conference' || $payable_type === 'conference_pass') {
            $t_inv = LFA_Conference::table_invoices();
            $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));

            if (!$inv) {
                return ['valid' => false, 'code' => 'NOT_FOUND', 'error' => 'Conference invoice not found.'];
            }

            if (!empty($inv->user_id) && (int)$inv->user_id !== $user_id) {
                return ['valid' => false, 'code' => 'FORBIDDEN', 'error' => 'You are not authorized to pay this conference invoice.'];
            }

            if ($inv->status !== LFA_Conference::INVOICE_STATUS_UNPAID) {
                return ['valid' => false, 'code' => 'INVALID_STATUS', 'error' => 'Conference invoice is not in an unpaid state (current: ' . esc_html($inv->status) . ').'];
            }

            if ((int)$inv->amount_cents <= 0 || $inv->currency !== 'USD') {
                return ['valid' => false, 'code' => 'INVALID_AMOUNT', 'error' => 'Conference invoice amount or currency is invalid.'];
            }

            // Verify payment gateway enabled (PayPal or Stripe Test)
            $gateway_ready = false;
            if (class_exists('LFA_Gateway_Settings')) {
                if (!LFA_Gateway_Settings::is_global_kill_switch_active()) {
                    $gateway_ready = LFA_Gateway_Settings::is_stripe_test_enabled() || LFA_Gateway_Settings::is_paypal_enabled();
                }
            } elseif (class_exists('LFA_PayPal_Settings')) {
                $gateway_ready = LFA_PayPal_Settings::is_payments_enabled();
            }

            if (!$gateway_ready) {
                return [
                    'valid' => false,
                    'code'  => 'GATEWAY_DISABLED',
                    'error' => 'Online conference registration payment is currently disabled or pending gateway configuration.',
                ];
            }

            $t_reg = LFA_Conference::table_registrations();
            $reg = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_reg} WHERE id = %d", (int)$inv->registration_id));
            if (!$reg) {
                return ['valid' => false, 'code' => 'REGISTRATION_NOT_FOUND', 'error' => 'Associated conference registration was not found.'];
            }

            // Strict Conference Access Control: Verify active paid membership at checkout initiation
            if (class_exists('LFA_Payment_Entitlements') && !LFA_Payment_Entitlements::is_full_member((int)$inv->user_id, true)) {
                return [
                    'valid' => false,
                    'code'  => 'MEMBERSHIP_REQUIRED',
                    'error' => 'Active paid membership is required to pay and confirm conference registration.',
                ];
            }

            return ['valid' => true, 'invoice' => $inv, 'registration' => $reg, 'payable_type' => 'conference'];
        }

        // Default: membership dues validation
        $t_inv = LFA_Membership::table_invoices();
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));

        if (!$inv) {
            return ['valid' => false, 'code' => 'NOT_FOUND', 'error' => 'Invoice not found.'];
        }

        if ((int)$inv->user_id !== $user_id) {
            return ['valid' => false, 'code' => 'FORBIDDEN', 'error' => 'You are not authorized to pay this invoice.'];
        }

        if ($inv->status !== LFA_Membership::INVOICE_STATUS_UNPAID) {
            return ['valid' => false, 'code' => 'INVALID_STATUS', 'error' => 'Invoice is not in an unpaid state (current: ' . esc_html($inv->status) . ').'];
        }

        if ((int)$inv->amount_cents <= 0 || $inv->currency !== 'USD') {
            return ['valid' => false, 'code' => 'INVALID_AMOUNT', 'error' => 'Invoice amount or currency is invalid.'];
        }

        // Verify account approval
        if (!LFA_Core::is_approved($user_id)) {
            return ['valid' => false, 'code' => 'ACCOUNT_NOT_APPROVED', 'error' => 'Member account must be verified and administrator-approved before dues payment.'];
        }

        // Verify membership record exists
        $mem = LFA_Membership::get_membership_by_user($user_id);
        if (!$mem) {
            return ['valid' => false, 'code' => 'NO_MEMBERSHIP', 'error' => 'No active membership record found for member.'];
        }

        // Verify billing period integrity
        if (empty($inv->period_id)) {
            return [
                'valid' => false,
                'code'  => 'PERIOD_UNCONFIGURED',
                'error' => 'Invoice billing period is missing or undefined.',
            ];
        }

        $t_per = LFA_Membership::table_periods();
        $period = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_per} WHERE id = %d", (int)$inv->period_id));
        if (!$period) {
            return [
                'valid' => false,
                'code'  => 'PERIOD_NOT_FOUND',
                'error' => 'Referenced membership period record was not found.',
            ];
        }

        // If billing cycle is unconfigured and period dates are missing, block checkout per policy (P4-004)
        $policy = LFA_Membership::get_policy();
        if (($policy['billing_cycle'] === 'unconfigured' || empty($policy['billing_cycle'])) && empty($period->starts_at)) {
            return [
                'valid' => false,
                'code'  => 'CYCLE_POLICY_UNCONFIGURED',
                'error' => 'Annual billing cycle policy is currently pending administrator confirmation. Online checkout will open once the billing schedule is finalized.',
            ];
        }

        // Verify payment gateway enabled (PayPal or Stripe Test)
        $gateway_ready = false;
        if (class_exists('LFA_Gateway_Settings')) {
            if (!LFA_Gateway_Settings::is_global_kill_switch_active()) {
                $gateway_ready = LFA_Gateway_Settings::is_stripe_test_enabled() || LFA_Gateway_Settings::is_paypal_enabled();
            }
        } elseif (class_exists('LFA_PayPal_Settings')) {
            $gateway_ready = LFA_PayPal_Settings::is_payments_enabled();
        }

        if (!$gateway_ready) {
            return [
                'valid' => false,
                'code'  => 'GATEWAY_DISABLED',
                'error' => 'Online dues payment is currently disabled or pending gateway configuration.',
            ];
        }

        return ['valid' => true, 'invoice' => $inv, 'membership' => $mem, 'period' => $period, 'payable_type' => 'membership_due'];
    }

    /**
     * Build PayPal order context (custom_id, description, return/cancel URLs) for a payable.
     */
    private static function build_order_context(string $payable_type, object $invoice, int $user_id, array $check): array {
        if ($payable_type === 'donation') {
            $ref = rawurlencode((string) $invoice->donation_ref);
            return [
                'custom_id'  => "lfa_don_{$invoice->id}_u_{$user_id}",
                'desc'       => "Love Family Alumni Giving — Ref {$invoice->donation_ref} ({$invoice->purpose_snapshot})",
                'return_url' => home_url("/give/?gateway=paypal&context=donation&ref={$ref}"),
                'cancel_url' => home_url("/give/?payment_cancelled=1&gateway=paypal&ref={$ref}"),
            ];
        }
        if ($payable_type === 'conference') {
            $reg   = $check['registration'] ?? null;
            $conf  = $reg && class_exists('LFA_Conference') ? LFA_Conference::get_conference_by_id((int) $reg->conference_id) : null;
            $title = $conf ? $conf->title : 'Conference';
            $tier  = $reg ? $reg->ticket_name_snapshot : 'Conference Pass';
            return [
                'custom_id'  => "lfa_conf_inv_{$invoice->id}_u_{$user_id}",
                'desc'       => mb_substr("{$title} — {$tier} — Invoice {$invoice->invoice_number}", 0, 127),
                'return_url' => home_url('/member-dashboard/?gateway=paypal&context=conference#conference'),
                'cancel_url' => home_url('/member-dashboard/?payment_cancelled=1&gateway=paypal&context=conference#conference'),
            ];
        }
        return [
            'custom_id'  => "lfa_inv_{$invoice->id}_u_{$user_id}",
            'desc'       => "Love Family Alumni Annual Dues — Invoice {$invoice->invoice_number}",
            'return_url' => home_url('/member-dashboard/?gateway=paypal&context=dues#dues'),
            'cancel_url' => home_url('/member-dashboard/?payment_cancelled=1&gateway=paypal&context=dues#dues'),
        ];
    }

    /**
     * Approve / payer-action link from an Orders v2 representation.
     */
    private static function find_approve_link(array $order): string {
        foreach (($order['links'] ?? []) as $link) {
            if (in_array($link['rel'] ?? '', ['approve', 'payer-action'], true)) {
                return (string) $link['href'];
            }
        }
        return '';
    }

    /**
     * Create PayPal Order from authoritative local invoice
     */
    public static function create_order(int $invoice_id, int $user_id, string $request_key = '', string $payable_type = 'membership_due'): array {
        $check = self::validate_invoice_for_checkout($invoice_id, $user_id, $payable_type);
        if (!$check['valid']) {
            return ['success' => false, 'error' => $check['error'], 'code' => $check['code']];
        }

        $invoice = $check['invoice'];
        global $wpdb;
        $t_att = self::table_attempts();
        $mode  = LFA_PayPal_Settings::get_mode();
        $ctx   = self::build_order_context($payable_type, $invoice, $user_id, $check);

        // Reuse a recent unpaid order for the same payable/amount/environment (prevents duplicate orders on re-click).
        // NOTE: timestamps are stored in UTC, so compare against UTC_TIMESTAMP() (not NOW()).
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_att}
             WHERE provider = 'paypal' AND payable_type = %s AND payable_id = %d AND user_id = %d
               AND mode = %s AND amount_cents = %d AND state = 'order_created' AND order_id IS NOT NULL
               AND updated_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 HOUR)
             ORDER BY id DESC LIMIT 1",
            $payable_type, $invoice_id, $user_id, $mode, (int) $invoice->amount_cents
        ));

        if ($existing) {
            $order_res = LFA_PayPal_Client::get_order($existing->order_id, $mode);
            $status    = $order_res['order']['status'] ?? '';
            $url       = '';
            if (in_array($status, ['CREATED', 'PAYER_ACTION_REQUIRED'], true)) {
                $url = self::find_approve_link($order_res['order']);
            } elseif (in_array($status, ['APPROVED', 'COMPLETED'], true)) {
                // Payer already approved (or it was captured) — send them to the return page to finalise.
                $url = add_query_arg('token', rawurlencode($existing->order_id), $ctx['return_url']);
            }
            if ($url !== '') {
                return [
                    'success'      => true,
                    'order_id'     => $existing->order_id,
                    'checkout_url' => $url,
                    'session_url'  => $url,
                    'approve_url'  => $url,
                    'attempt_id'   => (int) $existing->id,
                    'invoice_id'   => $invoice_id,
                    'gateway'      => 'paypal',
                    'reused'       => true,
                ];
            }
            // Order expired/voided/unknown — retire it and create a fresh one.
            $wpdb->update($t_att, ['state' => 'expired', 'updated_at' => current_time('mysql', true)], ['id' => $existing->id]);
        }

        $local_key = !empty($request_key) ? sanitize_text_field($request_key) : wp_generate_uuid4();
        $now = current_time('mysql', true);
        $wpdb->insert($t_att, [
            'invoice_id'        => $invoice_id,
            'payable_type'      => $payable_type,
            'payable_id'        => $invoice_id,
            'user_id'           => $user_id,
            'provider'          => 'paypal',
            'mode'              => $mode,
            'gateway_environment' => $mode,
            'test_transaction'  => $mode === 'live' ? 0 : 1,
            'merchant_identity' => LFA_PayPal_Settings::get_merchant_id($mode) ?: null,
            'local_request_key' => $local_key,
            'order_id'          => null,
            'amount_cents'      => (int) $invoice->amount_cents,
            'currency'          => 'USD',
            'state'             => 'initialized',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        $attempt_id = (int) $wpdb->insert_id;

        $paypal_res = LFA_PayPal_Client::create_order($invoice, $local_key, $mode, $ctx['custom_id'], $ctx['desc'], $ctx['return_url'], $ctx['cancel_url']);
        if (!$paypal_res['success'] || empty($paypal_res['order_id'])) {
            $wpdb->update($t_att, ['state' => 'failed', 'updated_at' => current_time('mysql', true)], ['id' => $attempt_id]);
            self::log_audit($payable_type === 'conference' ? 'conference' : 'member', $user_id, 'order_create_failed', (string) $invoice_id, [
                'error'        => $paypal_res['error'] ?? 'Unknown error',
                'issue'        => $paypal_res['issue'] ?? '',
                'payable_type' => $payable_type,
                'mode'         => $mode,
            ]);
            return ['success' => false, 'error' => $paypal_res['error'] ?? 'PayPal order creation failed.'];
        }

        $order_id     = $paypal_res['order_id'];
        $checkout_url = $paypal_res['checkout_url'] ?? ($paypal_res['approve_url'] ?? '');

        $wpdb->update($t_att, [
            'order_id'   => $order_id,
            'state'      => 'order_created',
            'updated_at' => current_time('mysql', true),
        ], ['id' => $attempt_id]);

        self::log_audit($payable_type === 'conference' ? 'conference' : 'member', $user_id, 'order_created', (string) $invoice_id, [
            'order_id'     => $order_id,
            'attempt_id'   => $attempt_id,
            'mode'         => $mode,
            'payable_type' => $payable_type,
        ]);

        return [
            'success'      => true,
            'order_id'     => $order_id,
            'checkout_url' => $checkout_url,
            'session_url'  => $checkout_url,
            'approve_url'  => $checkout_url,
            'attempt_id'   => $attempt_id,
            'invoice_id'   => $invoice_id,
            'gateway'      => 'paypal',
        ];
    }

    /**
     * Alias for capture_and_settle
     */
    public static function capture_order(string $order_id, int $user_id, string $request_key = '', string $payable_type = ''): array {
        return self::capture_and_settle($order_id, $user_id, $request_key, $payable_type);
    }

    /**
     * Load a payable row and whether it is already paid.
     *
     * @return array{row:?object,paid:bool,ref:string}
     */
    private static function load_payable(string $p_type, int $id): array {
        global $wpdb;
        if ($p_type === 'donation') {
            $row = class_exists('LFA_Donation') ? LFA_Donation::get_donation_by_id($id) : null;
            return ['row' => $row, 'paid' => $row && $row->status === LFA_Donation::STATUS_PAID, 'ref' => $row ? (string) $row->donation_ref : ''];
        }
        if ($p_type === 'conference') {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . LFA_Conference::table_invoices() . ' WHERE id = %d', $id));
            return ['row' => $row, 'paid' => $row && $row->status === LFA_Conference::INVOICE_STATUS_PAID, 'ref' => $row ? (string) $row->invoice_number : ''];
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . LFA_Membership::table_invoices() . ' WHERE id = %d', $id));
        return ['row' => $row, 'paid' => $row && $row->status === LFA_Membership::INVOICE_STATUS_PAID, 'ref' => $row ? (string) $row->invoice_number : ''];
    }

    private static function settled_response(string $p_type, object $row, string $ref, string $capture_id, bool $already): array {
        $labels = [
            'donation'   => 'Donation payment verified and settled successfully.',
            'conference' => 'Conference registration confirmed and payment settled.',
        ];
        $res = [
            'success'         => true,
            'reconciled'      => true,
            'already_settled' => $already,
            'payable_type'    => $p_type,
            'invoice_number'  => $ref,
            'capture_id'      => $capture_id,
            'amount_dollars'  => number_format(((int) $row->amount_cents) / 100, 2),
            'status'          => 'COMPLETED',
            'message'         => $already ? 'Payment was already settled.' : ($labels[$p_type] ?? 'Payment captured and membership standing updated to Current.'),
        ];
        if ($p_type === 'donation') {
            $res['donation_ref']   = $ref;
            $res['receipt_number'] = $ref;
        }
        return $res;
    }

    /**
     * Authoritative Server-Side Order Capture and Invoice Settlement
     *
     * Safe to call repeatedly (return page reload, JS + server double call, webhook race):
     *  - ownership enforced, attempt is atomically claimed, PayPal capture is idempotent (PayPal-Request-Id),
     *  - un-approved (cancelled) orders are detected BEFORE capture,
     *  - already-captured orders are recovered via GET order instead of failing.
     */
    public static function capture_and_settle(string $order_id, int $user_id, string $request_key = '', string $payable_type = ''): array {
        global $wpdb;
        $t_att = self::table_attempts();
        $order_id = sanitize_text_field($order_id);

        // 1. Locate attempt and verify ownership
        $attempt = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_att} WHERE order_id = %s AND provider = 'paypal'", $order_id));
        if (!$attempt) {
            return ['success' => false, 'error' => 'Invalid or unknown PayPal order ID.'];
        }
        if ((int) $attempt->user_id !== 0 && (int) $attempt->user_id !== $user_id) {
            return ['success' => false, 'error' => 'Unauthorized: Member does not own this order attempt.'];
        }

        $p_type     = !empty($attempt->payable_type) ? $attempt->payable_type : ($payable_type ?: 'membership_due');
        $invoice_id = !empty($attempt->payable_id) ? (int) $attempt->payable_id : (int) $attempt->invoice_id;
        $mode       = in_array($attempt->mode, ['sandbox', 'live'], true) ? $attempt->mode : LFA_PayPal_Settings::get_mode();

        $payable = self::load_payable($p_type, $invoice_id);
        if (!$payable['row']) {
            return ['success' => false, 'error' => 'Associated payable record not found.'];
        }

        // 2. Already settled?
        $t_cap = self::table_captures();
        $existing_capture = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_cap} WHERE attempt_id = %d OR (payable_type = %s AND payable_id = %d AND refund_state = 'none') ORDER BY id DESC LIMIT 1",
            (int) $attempt->id, $p_type, $invoice_id
        ));
        if ($payable['paid'] || ($existing_capture && !empty($existing_capture->settled_at))) {
            return self::settled_response($p_type, $payable['row'], $payable['ref'], $existing_capture ? (string) $existing_capture->paypal_capture_id : '', true);
        }

        // 3. Atomically claim the attempt (prevents concurrent double capture/settle)
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$t_att} SET state = 'capture_processing', updated_at = %s
             WHERE id = %d AND (state IN ('initialized','order_created','failed','pending_verification')
                    OR (state = 'capture_processing' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)))",
            current_time('mysql', true), (int) $attempt->id
        ));
        if (!$claimed) {
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT state FROM {$t_att} WHERE id = %d", (int) $attempt->id));
            if ($fresh && $fresh->state === 'captured') {
                $payable = self::load_payable($p_type, $invoice_id);
                return self::settled_response($p_type, $payable['row'], $payable['ref'], '', true);
            }
            return ['success' => false, 'pending' => true, 'error' => 'This payment is already being processed. Please wait a moment and refresh.'];
        }
        $set_state = static function (string $state) use ($wpdb, $t_att, $attempt): void {
            $wpdb->update($t_att, ['state' => $state, 'updated_at' => current_time('mysql', true)], ['id' => (int) $attempt->id]);
        };

        // 4. Inspect order first: never try to capture an order the payer did not approve (e.g. cancelled).
        $cap_res   = null;
        $order_res = LFA_PayPal_Client::get_order($order_id, $mode);
        $status    = $order_res['order']['status'] ?? '';

        if (in_array($status, ['CREATED', 'PAYER_ACTION_REQUIRED', 'SAVED'], true)) {
            $set_state('order_created');
            return ['success' => false, 'not_approved' => true, 'error' => 'Payment was not approved in PayPal (cancelled or not completed). Your invoice remains unpaid — you can try again.'];
        }
        if ($status === 'VOIDED') {
            $set_state('voided');
            return ['success' => false, 'error' => 'This PayPal order was voided. Please start a new payment.'];
        }
        if ($status === 'COMPLETED') {
            $cap_res = LFA_PayPal_Client::extract_capture_from_order($order_res['order'], $order_id);
        }

        // 5. Capture (idempotent per attempt via PayPal-Request-Id)
        if (!$cap_res) {
            $capture_req_key = !empty($request_key) ? sanitize_text_field($request_key) : ('cap_' . md5($order_id . '_' . $attempt->id));
            $cap_res = LFA_PayPal_Client::capture_order($order_id, $capture_req_key, $mode);

            if (!$cap_res['success'] && ($cap_res['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED') {
                $again = LFA_PayPal_Client::get_order($order_id, $mode);
                if (!empty($again['success'])) {
                    $cap_res = LFA_PayPal_Client::extract_capture_from_order($again['order'], $order_id);
                }
            }
        }

        if (!$cap_res['success']) {
            $issue = $cap_res['issue'] ?? '';
            $set_state($issue === 'ORDER_NOT_APPROVED' ? 'order_created' : 'failed');
            self::log_audit('member', $user_id, 'capture_api_failed', $order_id, [
                'error'        => $cap_res['error'] ?? 'Capture API failure',
                'issue'        => $issue,
                'payable_type' => $p_type,
                'mode'         => $mode,
            ]);
            $friendly = [
                'INSTRUMENT_DECLINED' => 'Your PayPal funding source was declined. Please try again with a different card or funding source.',
                'ORDER_NOT_APPROVED'  => 'Payment was not approved in PayPal. Your invoice remains unpaid.',
                'PAYER_ACTION_REQUIRED' => 'PayPal requires additional action from the payer. Please restart the payment.',
            ];
            return ['success' => false, 'error' => $friendly[$issue] ?? ($cap_res['error'] ?? 'PayPal capture failed.')];
        }

        // 6. Verification checks
        if ($cap_res['capture_status'] !== 'COMPLETED') {
            $set_state('pending_verification');
            self::log_audit('system', $user_id, 'capture_pending', $order_id, [
                'capture_id' => $cap_res['capture_id'], 'status' => $cap_res['capture_status'], 'payable_type' => $p_type,
            ]);
            return [
                'success' => false,
                'pending' => true,
                'error'   => 'PayPal reports the payment as ' . sanitize_text_field($cap_res['capture_status']) . '. It will be confirmed automatically once PayPal completes it.',
            ];
        }

        $res = self::settle_verified_capture($p_type, $invoice_id, $cap_res, $order_id, $mode, (int) $attempt->id, $user_id);
        if (!$res['success']) {
            $set_state($res['state'] ?? 'failed');
        }
        return $res;
    }

    /**
     * Verify and settle a COMPLETED PayPal capture against a local payable.
     * Shared by the return-page flow and the webhook. Idempotent.
     */
    private static function settle_verified_capture(string $p_type, int $invoice_id, array $cap, string $order_id, string $mode, int $attempt_id, int $actor_id): array {
        global $wpdb;
        $t_cap = self::table_captures();
        $t_att = self::table_attempts();
        $now   = current_time('mysql', true);

        $payable = self::load_payable($p_type, $invoice_id);
        $row     = $payable['row'];
        if (!$row) {
            return ['success' => false, 'error' => 'Associated payable record not found.'];
        }

        // Amount / currency integrity (money may already be captured → flag for review, never settle)
        if ((int) $cap['amount_cents'] !== (int) $row->amount_cents || strtoupper((string) $cap['currency']) !== 'USD') {
            self::log_audit('system', $actor_id, 'capture_amount_mismatch', $order_id, [
                'expected'     => (int) $row->amount_cents,
                'received'     => (int) $cap['amount_cents'],
                'currency'     => $cap['currency'],
                'capture_id'   => $cap['capture_id'],
                'payable_type' => $p_type,
                'action'       => 'REVIEW_AND_REFUND_REQUIRED',
            ]);
            return ['success' => false, 'state' => 'amount_mismatch', 'error' => 'Captured amount does not match the invoice. The payment has been flagged for administrator review.'];
        }

        // Payee check (orders are created with our own credentials; mismatch indicates misconfiguration)
        $expected_payee = LFA_PayPal_Settings::get_merchant_id($mode);
        if ($expected_payee !== '' && !empty($cap['payee_merchant_id']) && $cap['payee_merchant_id'] !== $expected_payee) {
            self::log_audit('system', $actor_id, 'capture_payee_mismatch', $order_id, [
                'expected' => $expected_payee, 'received' => $cap['payee_merchant_id'], 'mode' => $mode,
            ]);
        }

        // A capture row may already exist: settled (→ done) or left half-finished by an earlier crash (→ finish it).
        $prior = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_cap} WHERE paypal_capture_id = %s", $cap['capture_id']));
        if ($prior && !empty($prior->settled_at) && $payable['paid']) {
            if ($attempt_id) {
                $wpdb->update($t_att, ['state' => 'captured', 'updated_at' => $now], ['id' => $attempt_id]);
            }
            return self::settled_response($p_type, $row, $payable['ref'], $cap['capture_id'], true);
        }

        // Record capture — UNIQUE(paypal_capture_id) makes this the idempotency gate.
        $inserted = $prior ? false : $wpdb->insert($t_cap, [
            'attempt_id'          => $attempt_id,
            'invoice_id'          => $invoice_id,
            'gateway_provider'    => 'paypal',
            'payable_type'        => $p_type,
            'payable_id'          => $invoice_id,
            'paypal_capture_id'   => $cap['capture_id'],
            'paypal_order_id'     => $order_id,
            'provider_payment_id' => $order_id,
            'merchant_identity'   => $cap['payee_merchant_id'] ?: null,
            'mode'                => $mode,
            'gateway_environment' => $mode,
            'amount_cents'        => (int) $cap['amount_cents'],
            'currency'            => 'USD',
            'capture_status'      => 'COMPLETED',
            'payment_status'      => 'COMPLETED',
            'captured_at'         => $cap['captured_at'],
            'verified_at'         => $now,
            'reconciled_at'       => $now,
            'refund_state'        => 'none',
            'last_checked_at'     => $now,
            'test_transaction'    => $mode === 'live' ? 0 : 1,
        ]);
        if ($prior) {
            $capture_record_id = (int) $prior->id; // recover an unfinished settlement
        } elseif (!$inserted) {
            // A concurrent request (e.g. webhook vs return page) recorded this capture first and is settling it.
            if ($attempt_id) {
                $wpdb->update($t_att, ['state' => 'captured', 'updated_at' => $now], ['id' => $attempt_id]);
            }
            $payable = self::load_payable($p_type, $invoice_id);
            return self::settled_response($p_type, $payable['row'], $payable['ref'], $cap['capture_id'], true);
        } else {
            $capture_record_id = (int) $wpdb->insert_id;
        }
        $test_flag = $mode === 'live' ? 0 : 1;

        if ($p_type === 'donation') {
            LFA_Donation::settle_donation($invoice_id, $cap['capture_id'], $order_id, (int) $cap['amount_cents']);
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . LFA_Donation::table_donations() . " SET gateway_provider = 'paypal', provider_payment_id = %s, test_transaction = %d WHERE id = %d",
                $order_id, $test_flag, $invoice_id
            ));
        } elseif ($p_type === 'conference') {
            LFA_Conference::settle_registration($invoice_id, $cap['capture_id'], (int) $cap['amount_cents']);
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . LFA_Conference::table_invoices() . " SET gateway_provider = 'paypal', provider_payment_id = %s, test_paid = %d WHERE id = %d",
                $order_id, $test_flag, $invoice_id
            ));
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . LFA_Conference::table_registrations() . " SET gateway_provider = 'paypal', provider_payment_id = %s, test_paid = %d WHERE id = %d",
                $order_id, $test_flag, (int) $row->registration_id
            ));
        } else {
            self::settle_membership_invoice($invoice_id, $order_id, $test_flag);
        }

        $wpdb->update($t_cap, ['settled_at' => $now, 'entitlement_effect' => 'granted'], ['id' => $capture_record_id]);
        if ($attempt_id) {
            $wpdb->update($t_att, ['state' => 'captured', 'provider_payment_id' => $cap['capture_id'], 'updated_at' => $now], ['id' => $attempt_id]);
        }

        self::log_audit('member', $actor_id ?: null, 'payment_settled_paypal', (string) $invoice_id, [
            'payable_type' => $p_type,
            'capture_id'   => $cap['capture_id'],
            'order_id'     => $order_id,
            'amount_cents' => (int) $cap['amount_cents'],
            'mode'         => $mode,
        ]);

        if ($p_type === 'membership_due') {
            self::send_receipt_email($invoice_id, $cap['capture_id']);
        }
        if (class_exists('LFA_Gateway_Settings')) {
            LFA_Gateway_Settings::record_last_transaction('paypal', [
                'payable_type' => $p_type, 'amount_cents' => (int) $cap['amount_cents'], 'at' => $now,
            ], $mode);
        }

        $payable = self::load_payable($p_type, $invoice_id);
        return self::settled_response($p_type, $payable['row'], $payable['ref'], $cap['capture_id'], false);
    }

    /**
     * Mark a membership dues invoice paid and advance the member's standing (same rules as Stripe).
     */
    public static function settle_membership_invoice(int $invoice_id, string $provider_payment_id, int $test_flag): void {
        global $wpdb;
        $t_inv = LFA_Membership::table_invoices();
        $t_mem = LFA_Membership::table_memberships();
        $t_per = LFA_Membership::table_periods();
        $now   = current_time('mysql', true);

        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));
        if (!$inv) {
            return;
        }
        $owner = (int) $inv->user_id;

        $wpdb->update($t_inv, ['status' => LFA_Membership::INVOICE_STATUS_PAID, 'paid_at' => $now, 'updated_at' => $now], ['id' => $invoice_id]);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$t_inv} SET gateway_provider = 'paypal', provider_payment_id = %s, test_paid = %d WHERE id = %d",
            $provider_payment_id, $test_flag, $invoice_id
        ));
        $wpdb->update($t_mem, ['status' => LFA_Membership::STANDING_CURRENT, 'updated_at' => $now], ['user_id' => $owner]);

        $expiry    = LFA_Membership::calculate_calendar_year_expiry($now);
        $period_id = (int) $inv->period_id;
        if ($period_id <= 0) {
            $mem_row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$t_mem} WHERE user_id = %d", $owner));
            if ($mem_row) {
                $existing_per = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$t_per} WHERE membership_id = %d ORDER BY id DESC LIMIT 1", $mem_row->id));
                if ($existing_per) {
                    $period_id = (int) $existing_per->id;
                } else {
                    $y = (int) gmdate('Y', strtotime($now));
                    $wpdb->insert($t_per, [
                        'membership_id' => $mem_row->id,
                        'period_key'    => $y . '/' . ($y + 1),
                        'standing'      => LFA_Membership::STANDING_CURRENT,
                        'starts_at'     => $now,
                        'ends_at'       => $expiry,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                    $period_id = (int) $wpdb->insert_id;
                }
                $wpdb->update($t_inv, ['period_id' => $period_id], ['id' => $invoice_id]);
            }
        }
        if ($period_id > 0) {
            $wpdb->update($t_per, [
                'standing'   => LFA_Membership::STANDING_CURRENT,
                'starts_at'  => $now,
                'ends_at'    => $expiry,
                'updated_at' => $now,
            ], ['id' => $period_id]);
        }

        update_user_meta($owner, 'lfa_dues_status', 'current');
        if ($test_flag) {
            update_user_meta($owner, 'lfa_test_paid', 1);
        }
    }

    /**
     * Parse our custom_id → [payable_type, payable_id, user_id]
     */
    private static function parse_custom_id(string $custom_id): ?array {
        if (preg_match('/^lfa_conf_inv_(\d+)_u_(\d+)$/', $custom_id, $m)) {
            return ['conference', (int) $m[1], (int) $m[2]];
        }
        if (preg_match('/^lfa_don_(\d+)_u_(\d+)$/', $custom_id, $m)) {
            return ['donation', (int) $m[1], (int) $m[2]];
        }
        if (preg_match('/^lfa_inv_(\d+)_u_(\d+)$/', $custom_id, $m)) {
            return ['membership_due', (int) $m[1], (int) $m[2]];
        }
        return null;
    }

    /**
     * Webhook Handler with Deduplication & Signature Verification
     */
    public static function handle_webhook(array $headers, string $raw_body): array {
        global $wpdb;
        $t_events = self::table_webhook_events();
        $t_cap    = self::table_captures();
        $t_att    = self::table_attempts();

        $event = json_decode($raw_body, true);
        if (!is_array($event) || empty($event['id']) || empty($event['event_type'])) {
            return ['status' => 400, 'error' => 'Malformed webhook payload.'];
        }

        $event_id   = sanitize_text_field($event['id']);
        $event_type = sanitize_text_field($event['event_type']);

        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_events} WHERE paypal_event_id = %s", $event_id));
        if ($existing && $existing->processing_state === 'processed') {
            return ['status' => 200, 'message' => 'Event already processed idempotently.'];
        }

        // Verify signature against the active environment first, then the other configured one
        // (lets sandbox + live webhooks both point at this endpoint safely).
        $active = LFA_PayPal_Settings::get_mode();
        $mode   = null;
        $sig_error = '';
        foreach (array_unique([$active, $active === 'live' ? 'sandbox' : 'live']) as $candidate) {
            if (LFA_PayPal_Settings::get_webhook_id($candidate) === '' || !LFA_PayPal_Settings::get_secret($candidate)) {
                continue;
            }
            $sig = LFA_PayPal_Client::verify_webhook_signature($headers, $raw_body, $candidate);
            if (!empty($sig['verified'])) {
                $mode = $candidate;
                break;
            }
            $sig_error = $sig['error'] ?? 'Invalid signature';
        }
        if (!$mode) {
            self::log_audit('system', null, 'webhook_signature_rejected', $event_id, [
                'event_type' => $event_type,
                'error'      => $sig_error ?: 'No environment with webhook ID + credentials configured.',
            ]);
            return ['status' => 401, 'error' => 'Webhook signature verification failed.'];
        }

        $now = current_time('mysql', true);
        if (!$existing) {
            $wpdb->insert($t_events, [
                'paypal_event_id'        => $event_id,
                'gateway_provider'       => 'paypal',
                'provider_event_id'      => $event_id,
                'paypal_transmission_id' => $headers['paypal_transmission_id'][0] ?? ($headers['paypal-transmission-id'] ?? null),
                'event_type'             => $event_type,
                'mode'                   => $mode,
                'verified_at'            => $now,
                'processed_at'           => null,
                'processing_state'       => 'verified',
                'failure_count'          => 0,
                'created_at'             => $now,
            ]);
            $event_row_id = (int) $wpdb->insert_id;
        } else {
            $event_row_id = (int) $existing->id;
        }

        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $note     = 'ignored';

        try {
            switch ($event_type) {
                case 'PAYMENT.CAPTURE.COMPLETED':
                    $parsed = self::parse_custom_id((string) ($resource['custom_id'] ?? ''));
                    if (!$parsed) {
                        $note = 'unrecognised custom_id';
                        break;
                    }
                    [$p_type, $pid, $uid] = $parsed;
                    $order_id = (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');
                    $attempt  = $order_id !== '' ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_att} WHERE order_id = %s", $order_id)) : null;

                    $payable = self::load_payable($p_type, $pid);
                    if (!$payable['row'] || $payable['paid']) {
                        $note = $payable['row'] ? 'already settled' : 'payable not found';
                        if ($attempt && $payable['paid']) {
                            $wpdb->update($t_att, ['state' => 'captured', 'updated_at' => $now], ['id' => $attempt->id]);
                        }
                        break;
                    }

                    $cap = [
                        'capture_id'        => (string) ($resource['id'] ?? ''),
                        'capture_status'    => (string) ($resource['status'] ?? 'COMPLETED'),
                        'amount_cents'      => isset($resource['amount']['value']) ? (int) round(((float) $resource['amount']['value']) * 100) : 0,
                        'currency'          => (string) ($resource['amount']['currency_code'] ?? ''),
                        'payee_merchant_id' => (string) ($resource['payee']['merchant_id'] ?? ''),
                        'captured_at'       => !empty($resource['create_time']) ? gmdate('Y-m-d H:i:s', strtotime($resource['create_time'])) : $now,
                    ];
                    $res = self::settle_verified_capture($p_type, $pid, $cap, $order_id ?: 'webhook', $mode, $attempt ? (int) $attempt->id : 0, $uid);
                    if (!$res['success'] && $attempt) {
                        $wpdb->update($t_att, ['state' => $res['state'] ?? 'failed', 'updated_at' => $now], ['id' => $attempt->id]);
                    }
                    $note = $res['success'] ? 'settled' : ($res['error'] ?? 'settle failed');
                    break;

                case 'PAYMENT.CAPTURE.DENIED':
                case 'PAYMENT.CAPTURE.DECLINED':
                    $order_id = (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');
                    if ($order_id !== '') {
                        $wpdb->update($t_att, ['state' => 'denied', 'updated_at' => $now], ['order_id' => $order_id]);
                    }
                    self::log_audit('system', null, 'payment_capture_denied', $event_id, ['order_id' => $order_id, 'capture_id' => $resource['id'] ?? '']);
                    $note = 'attempt marked denied';
                    break;

                case 'PAYMENT.CAPTURE.REFUNDED':
                case 'PAYMENT.CAPTURE.REVERSED':
                    // REFUNDED: resource = refund (capture via links rel=up). REVERSED: resource = the capture itself.
                    $capture_id = '';
                    if ($event_type === 'PAYMENT.CAPTURE.REVERSED') {
                        $capture_id = (string) ($resource['id'] ?? '');
                    } else {
                        foreach (($resource['links'] ?? []) as $link) {
                            if (($link['rel'] ?? '') === 'up' && preg_match('#/captures/([^/?\#]+)#', (string) ($link['href'] ?? ''), $mm)) {
                                $capture_id = $mm[1];
                                break;
                            }
                        }
                    }
                    $capture = $capture_id !== '' ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_cap} WHERE paypal_capture_id = %s", $capture_id)) : null;
                    if (!$capture) {
                        $note = 'capture not found locally';
                        break;
                    }

                    $refund_cents = isset($resource['amount']['value']) ? (int) round(((float) $resource['amount']['value']) * 100) : (int) $capture->amount_cents;
                    $total_refunded = isset($resource['seller_payable_breakdown']['total_refunded_amount']['value'])
                        ? (int) round(((float) $resource['seller_payable_breakdown']['total_refunded_amount']['value']) * 100)
                        : $refund_cents;
                    $is_full = $event_type === 'PAYMENT.CAPTURE.REVERSED' || $total_refunded >= (int) $capture->amount_cents;

                    if ($is_full) {
                        LFA_Payment_Entitlements::handle_refund((int) $capture->payable_id, (string) $capture->payable_type, strtolower($event_type));
                        $wpdb->update($t_cap, [
                            'refund_state'       => $event_type === 'PAYMENT.CAPTURE.REVERSED' ? 'reversed' : 'refunded',
                            'entitlement_effect' => 'revoked',
                            'refunded_at'        => $now,
                            'last_checked_at'    => $now,
                        ], ['id' => $capture->id]);
                        $note = 'full refund applied, entitlement revoked';
                    } else {
                        $wpdb->update($t_cap, ['refund_state' => 'partial_review_required', 'last_checked_at' => $now], ['id' => $capture->id]);
                        $note = 'partial refund flagged for review';
                    }
                    self::log_audit('system', null, 'payment_refund_detected', $event_id, [
                        'event_type'   => $event_type,
                        'capture_id'   => $capture_id,
                        'refund_cents' => $refund_cents,
                        'full'         => $is_full,
                    ]);
                    break;

                default:
                    $note = 'event type not handled';
            }
        } catch (Throwable $e) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$t_events} SET processing_state = 'failed', failure_count = failure_count + 1, last_error_code = %s WHERE id = %d",
                substr(sanitize_text_field($e->getMessage()), 0, 100), $event_row_id
            ));
            self::log_audit('system', null, 'webhook_processing_failed', $event_id, ['event_type' => $event_type, 'error' => $e->getMessage()]);
            // Non-2xx → PayPal retries delivery later.
            return ['status' => 500, 'error' => 'Webhook processing failed; will retry.'];
        }

        $wpdb->update($t_events, [
            'processing_state' => 'processed',
            'processed_at'     => $now,
            'last_error_code'  => substr($note, 0, 100),
        ], ['id' => $event_row_id]);

        if (class_exists('LFA_Gateway_Settings')) {
            LFA_Gateway_Settings::record_webhook_event('paypal', ['event_type' => $event_type, 'at' => $now, 'result' => $note], $mode);
        }

        return ['status' => 200, 'message' => 'Webhook processed: ' . $note];
    }

    /**
     * Dispatch Payment Receipt Email via wp_mail()
     */
    public static function send_receipt_email(int $invoice_id, string $capture_id): bool {
        global $wpdb;
        $t_inv = LFA_Membership::table_invoices();
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));
        if (!$inv) {
            return false;
        }

        $user = get_user_by('id', (int)$inv->user_id);
        if (!$user || empty($user->user_email)) {
            return false;
        }

        $amount = '$' . number_format($inv->amount_cents / 100, 2);
        $subject = "Payment Receipt — Love Family Alumni Annual Dues (Invoice #{$inv->invoice_number})";

        $message = "Dear " . esc_html($user->display_name) . ",\n\n"
            . "Thank you for supporting Love Family Alumni — North America Zone.\n"
            . "Your annual membership dues payment has been received and verified via PayPal.\n\n"
            . "PAYMENT DETAILS:\n"
            . "--------------------------------------------------\n"
            . "Invoice Number:   " . esc_html($inv->invoice_number) . "\n"
            . "Amount Paid:      " . esc_html($amount) . " USD\n"
            . "Payment Reference: " . esc_html($capture_id) . "\n"
            . "Date of Payment:  " . current_time('mysql') . "\n"
            . "Account Standing: Current (In Good Standing)\n"
            . "--------------------------------------------------\n\n"
            . "You may log in to your Member Dashboard to view and print your full official receipt:\n"
            . home_url('/member-dashboard/#dues') . "\n\n"
            . "Warm regards,\n"
            . "Love Family Alumni Executive Council — North America Zone\n";

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $sent = wp_mail($user->user_email, $subject, $message, $headers);

        // Metadata-only email logging
        $domain = substr(strrchr($user->user_email, '@') ?: '@unknown', 1);
        $wpdb->insert($wpdb->prefix . 'lfa_membership_audit', [
            'actor_user_id'   => 0,
            'subject_user_id' => (int) $inv->user_id,
            'action'          => 'receipt_email_sent',
            'object_type'     => 'invoice',
            'object_id'       => (int) $inv->id,
            'metadata_json' => wp_json_encode([
                'recipient_domain' => $domain,
                'invoice_number'   => $inv->invoice_number,
                'status'           => $sent ? 'dispatched' : 'failed',
            ]),
            'created_at'    => current_time('mysql', true),
        ]);

        return $sent;
    }

    /**
     * Authoritative Printable HTML Receipt Payload
     */
    public static function get_receipt_data(int $invoice_id, int $viewer_user_id): ?array {
        global $wpdb;
        $t_inv = LFA_Membership::table_invoices();
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));

        if (!$inv) {
            return null;
        }

        // Authorization: must be owner or have lfa_manage_memberships
        $is_owner = ((int)$inv->user_id === $viewer_user_id);
        $is_admin = user_can($viewer_user_id, 'lfa_manage_memberships') || user_can($viewer_user_id, 'edit_users');

        if (!$is_owner && !$is_admin) {
            return null;
        }

        // Only paid invoices can produce a paid receipt (P4-092)
        if ($inv->status !== LFA_Membership::INVOICE_STATUS_PAID) {
            return [
                'error'   => 'RECEIPT_NOT_AVAILABLE',
                'message' => 'Official paid receipt is only generated after full payment settlement.',
                'status'  => $inv->status,
            ];
        }

        $t_cap = self::table_captures();
        $capture = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_cap} WHERE invoice_id = %d ORDER BY id DESC LIMIT 1", $invoice_id));

        $gateway_label = 'PayPal';
        $is_test = false;
        if ($capture) {
            if (!empty($capture->gateway_provider) && $capture->gateway_provider === 'stripe') {
                $gateway_label = 'Stripe (Test Mode)';
                $is_test = true;
            } elseif ($capture->mode === 'test') {
                $gateway_label = 'Stripe (Test Mode)';
                $is_test = true;
            } elseif ($capture->mode === 'sandbox') {
                $gateway_label = 'PayPal (Sandbox)';
                $is_test = true;
            }
        }

        $user = get_user_by('id', (int)$inv->user_id);
        $mem = LFA_Membership::get_membership_by_user((int)$inv->user_id);

        return [
            'invoice_number'   => $inv->invoice_number,
            'invoice_id'       => $inv->id,
            'member_name'      => $user ? $user->display_name : 'Love Family Alumnus',
            'member_email'     => $user ? $user->user_email : '',
            'membership_type'  => $mem ? ucfirst($mem->membership_type) : 'Individual',
            'amount_dollars'   => number_format($inv->amount_cents / 100, 2),
            'currency'         => 'USD',
            'capture_id'       => $capture ? (!empty($capture->provider_payment_id) ? $capture->provider_payment_id : $capture->paypal_capture_id) : 'N/A',
            'payment_date'     => $capture ? $capture->captured_at : $inv->created_at,
            'gateway'          => $gateway_label,
            'is_test'          => $is_test,
            'status'           => 'PAID',
        ];
    }
}
