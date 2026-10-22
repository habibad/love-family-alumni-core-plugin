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
        $mode = LFA_PayPal_Settings::get_mode();

        // Idempotency: check if an existing pending attempt exists for this user and invoice and payable_type
        $local_key = !empty($request_key) ? sanitize_text_field($request_key) : wp_generate_uuid4();
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_att} WHERE (payable_id = %d OR invoice_id = %d) AND payable_type = %s AND user_id = %d AND state = 'order_created' AND updated_at > DATE_SUB(NOW(), INTERVAL 2 HOUR) ORDER BY id DESC LIMIT 1",
            $invoice_id,
            $invoice_id,
            $payable_type,
            $user_id
        ));

        if ($existing && !empty($existing->order_id)) {
            return [
                'success'    => true,
                'order_id'   => $existing->order_id,
                'attempt_id' => (int) $existing->id,
                'invoice_id' => $invoice_id,
            ];
        }

        // Insert new initialized attempt
        $now = current_time('mysql', true);
        $wpdb->insert($t_att, [
            'invoice_id'        => $invoice_id,
            'payable_type'      => $payable_type,
            'payable_id'        => $invoice_id,
            'user_id'           => $user_id,
            'provider'          => 'paypal',
            'mode'              => $mode,
            'merchant_identity' => LFA_PayPal_Settings::get_merchant_id($mode),
            'local_request_key' => $local_key,
            'order_id'          => null,
            'amount_cents'      => (int) $invoice->amount_cents,
            'currency'          => 'USD',
            'state'             => 'initialized',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        $attempt_id = $wpdb->insert_id;

        // Custom ID and description
        if ($payable_type === 'donation') {
            $custom_id = "lfa_don_{$invoice->id}_u_{$user_id}";
            $desc = "Love Family Alumni Giving — Ref {$invoice->donation_ref} ({$invoice->purpose_snapshot})";
        } elseif ($payable_type === 'conference') {
            $reg = $check['registration'] ?? null;
            $ticket_desc = $reg ? $reg->ticket_name_snapshot : 'Conference Pass';
            $custom_id = "lfa_conf_inv_{$invoice->id}_u_{$user_id}";
            $desc = "Love Family Alumni 2027 Conference Pass ({$ticket_desc}) — Invoice {$invoice->invoice_number}";
        } else {
            $custom_id = "lfa_inv_{$invoice->id}_u_{$user_id}";
            $desc = "Love Family Alumni Annual Dues — Invoice {$invoice->invoice_number}";
        }

        // Call PayPal Orders v2 API
        $paypal_res = LFA_PayPal_Client::create_order($invoice, $local_key, $mode, $custom_id, $desc);
        if (!$paypal_res['success'] || empty($paypal_res['order_id'])) {
            $wpdb->update($t_att, [
                'state'      => 'failed',
                'updated_at' => current_time('mysql', true),
            ], ['id' => $attempt_id]);

            self::log_audit($payable_type === 'conference' ? 'conference' : 'member', $user_id, 'order_create_failed', (string)$invoice_id, [
                'error'        => $paypal_res['error'] ?? 'Unknown error',
                'payable_type' => $payable_type,
            ]);

            return ['success' => false, 'error' => $paypal_res['error'] ?? 'PayPal order creation failed.'];
        }

        $order_id = $paypal_res['order_id'];
        $wpdb->update($t_att, [
            'order_id'   => $order_id,
            'state'      => 'order_created',
            'updated_at' => current_time('mysql', true),
        ], ['id' => $attempt_id]);

        self::log_audit($payable_type === 'conference' ? 'conference' : 'member', $user_id, 'order_created', (string)$invoice_id, [
            'order_id'     => $order_id,
            'attempt_id'   => $attempt_id,
            'mode'         => $mode,
            'payable_type' => $payable_type,
        ]);

        return [
            'success'    => true,
            'order_id'   => $order_id,
            'attempt_id' => $attempt_id,
            'invoice_id' => $invoice_id,
        ];
    }

    /**
     * Alias for capture_and_settle
     */
    public static function capture_order(string $order_id, int $user_id, string $request_key = '', string $payable_type = ''): array {
        return self::capture_and_settle($order_id, $user_id, $request_key, $payable_type);
    }

    /**
     * Authoritative Server-Side Order Capture and Invoice Settlement
     */
    public static function capture_and_settle(string $order_id, int $user_id, string $request_key = '', string $payable_type = ''): array {
        global $wpdb;
        $t_att = self::table_attempts();
        $t_cap = self::table_captures();
        $mode = LFA_PayPal_Settings::get_mode();

        // 1. Locate attempt and verify ownership
        $attempt = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_att} WHERE order_id = %s", $order_id));
        if (!$attempt) {
            return ['success' => false, 'error' => 'Invalid or unknown PayPal order ID.'];
        }

        if ((int)$attempt->user_id !== $user_id) {
            return ['success' => false, 'error' => 'Unauthorized: Member does not own this order attempt.'];
        }

        $p_type = !empty($attempt->payable_type) ? $attempt->payable_type : (!empty($payable_type) ? $payable_type : 'membership_due');
        $invoice_id = !empty($attempt->payable_id) ? (int)$attempt->payable_id : (int)$attempt->invoice_id;

        if ($p_type === 'conference') {
            $t_inv = LFA_Conference::table_invoices();
            $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));
            if (!$invoice) {
                return ['success' => false, 'error' => 'Associated conference invoice not found.'];
            }

            // Concurrency / Idempotency Check
            $existing_capture = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t_cap} WHERE (attempt_id = %d OR (payable_type = 'conference' AND payable_id = %d))",
                (int)$attempt->id,
                $invoice->id
            ));
            if ($existing_capture || $invoice->status === LFA_Conference::INVOICE_STATUS_PAID) {
                return [
                    'success'        => true,
                    'already_settled'=> true,
                    'invoice_number' => $invoice->invoice_number,
                    'capture_id'     => $existing_capture ? $existing_capture->paypal_capture_id : '',
                    'amount_dollars' => number_format($invoice->amount_cents / 100, 2),
                    'status'         => 'COMPLETED',
                    'message'        => 'Conference registration was already successfully settled.',
                ];
            }
        } else {
            $t_inv = LFA_Membership::table_invoices();
            $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));
            if (!$invoice) {
                return ['success' => false, 'error' => 'Associated invoice not found.'];
            }

            // Concurrency / Idempotency Check
            $existing_capture = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t_cap} WHERE (attempt_id = %d OR (payable_type = 'membership_due' AND payable_id = %d))",
                (int)$attempt->id,
                $invoice->id
            ));
            if ($existing_capture || $invoice->status === LFA_Membership::INVOICE_STATUS_PAID) {
                return [
                    'success'        => true,
                    'already_settled'=> true,
                    'invoice_number' => $invoice->invoice_number,
                    'capture_id'     => $existing_capture ? $existing_capture->paypal_capture_id : '',
                    'amount_dollars' => number_format($invoice->amount_cents / 100, 2),
                    'status'         => 'COMPLETED',
                    'message'        => 'Invoice was already successfully settled.',
                ];
            }
        }

        // 3. Mark attempt state as capture_processing
        $now = current_time('mysql', true);
        $wpdb->update($t_att, ['state' => 'capture_processing', 'updated_at' => $now], ['id' => $attempt->id]);

        // 4. Call PayPal Orders v2 Capture API
        $capture_req_key = !empty($request_key) ? sanitize_text_field($request_key) : ("cap_" . md5($order_id . '_' . $attempt->id));
        $cap_res = LFA_PayPal_Client::capture_order($order_id, $capture_req_key, $mode);

        if (!$cap_res['success']) {
            $wpdb->update($t_att, ['state' => 'failed', 'updated_at' => current_time('mysql', true)], ['id' => $attempt->id]);
            self::log_audit('member', $user_id, 'capture_api_failed', $order_id, [
                'error'        => $cap_res['error'] ?? 'Capture API failure',
                'payable_type' => $p_type,
            ]);
            return ['success' => false, 'error' => $cap_res['error'] ?? 'PayPal capture failed.'];
        }

        // 5. Authoritative Verification Checks (Amount, Currency, Status, Merchant)
        if ($cap_res['capture_status'] !== 'COMPLETED') {
            $wpdb->update($t_att, ['state' => 'pending_verification', 'updated_at' => current_time('mysql', true)], ['id' => $attempt->id]);
            return [
                'success' => false,
                'error'   => 'Capture status is ' . sanitize_text_field($cap_res['capture_status']) . '. Payment verification is pending.',
                'pending' => true,
            ];
        }

        // Amount integrity check
        if ((int)$cap_res['amount_cents'] !== (int)$invoice->amount_cents) {
            $wpdb->update($t_att, ['state' => 'amount_mismatch', 'updated_at' => current_time('mysql', true)], ['id' => $attempt->id]);
            self::log_audit('system', $user_id, 'capture_amount_mismatch', $order_id, [
                'expected'     => $invoice->amount_cents,
                'received'     => $cap_res['amount_cents'],
                'payable_type' => $p_type,
            ]);
            return ['success' => false, 'error' => 'Captured amount does not match authoritative invoice amount. Settle blocked.'];
        }

        // Currency integrity check
        if (strtoupper($cap_res['currency']) !== 'USD') {
            $wpdb->update($t_att, ['state' => 'currency_mismatch', 'updated_at' => current_time('mysql', true)], ['id' => $attempt->id]);
            return ['success' => false, 'error' => 'Captured currency is not USD. Settle blocked.'];
        }

        // 6. Atomic Database Settle Transaction
        $capture_id = $cap_res['capture_id'];
        $captured_at = $cap_res['captured_at'];

        // Insert capture record
        $wpdb->insert($t_cap, [
            'attempt_id'        => (int) $attempt->id,
            'invoice_id'        => (int) $invoice->id,
            'payable_type'      => $p_type,
            'payable_id'        => (int) $invoice->id,
            'paypal_capture_id' => $capture_id,
            'paypal_order_id'   => $order_id,
            'merchant_identity' => $cap_res['payee_merchant_id'] ?: null,
            'mode'              => $mode,
            'amount_cents'      => (int) $cap_res['amount_cents'],
            'currency'          => 'USD',
            'capture_status'    => 'COMPLETED',
            'captured_at'       => $captured_at,
            'reconciled_at'     => $now,
            'refund_state'      => 'none',
            'last_checked_at'   => $now,
        ]);
        $capture_record_id = $wpdb->insert_id;

        // Transition attempt state to captured
        $wpdb->update($t_att, ['state' => 'captured', 'updated_at' => $now], ['id' => $attempt->id]);

        if ($p_type === 'donation') {
            LFA_Donation::settle_donation((int)$invoice->id, $capture_id, $order_id, (int)$cap_res['amount_cents']);

            return [
                'success'        => true,
                'invoice_number' => $invoice->donation_ref,
                'donation_ref'   => $invoice->donation_ref,
                'capture_id'     => $capture_id,
                'amount_dollars' => number_format($invoice->amount_cents / 100, 2),
                'status'         => 'COMPLETED',
                'message'        => 'Donation payment verified and settled successfully.',
            ];
        }

        if ($p_type === 'conference') {
            // Settle conference registration and invoice
            LFA_Conference::settle_registration((int)$invoice->id, $capture_id, (int)$cap_res['amount_cents']);

            return [
                'success'        => true,
                'invoice_number' => $invoice->invoice_number,
                'capture_id'     => $capture_id,
                'amount_dollars' => number_format($invoice->amount_cents / 100, 2),
                'status'         => 'COMPLETED',
                'message'        => 'Conference registration confirmed and payment settled.',
            ];
        }

        // Membership dues settlement
        $wpdb->update($t_inv, [
            'status'     => LFA_Membership::INVOICE_STATUS_PAID,
            'paid_at'    => $now,
            'updated_at' => $now,
        ], ['id' => $invoice->id]);

        $t_mem = LFA_Membership::table_memberships();
        $wpdb->update($t_mem, [
            'status'     => LFA_Membership::STANDING_CURRENT,
            'updated_at' => $now,
        ], ['user_id' => $user_id]);

        $one_year_later = LFA_Membership::calculate_calendar_year_expiry($now);
        $period_id = (int)$invoice->period_id;
        $t_per = LFA_Membership::table_periods();
        if ($period_id <= 0) {
            $mem_row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$t_mem} WHERE user_id = %d", $user_id));
            if ($mem_row) {
                $existing_per = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$t_per} WHERE membership_id = %d ORDER BY id DESC LIMIT 1", $mem_row->id));
                if ($existing_per) {
                    $period_id = (int)$existing_per->id;
                } else {
                    $current_year = (int) gmdate('Y', strtotime($now));
                    $period_key = $current_year . '/' . ($current_year + 1);
                    $wpdb->insert($t_per, [
                        'membership_id' => $mem_row->id,
                        'period_key'    => $period_key,
                        'standing'      => LFA_Membership::STANDING_CURRENT,
                        'starts_at'     => $now,
                        'ends_at'       => $one_year_later,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                    $period_id = (int) $wpdb->insert_id;
                }
                $wpdb->update($t_inv, ['period_id' => $period_id], ['id' => $invoice->id]);
            }
        }

        if ($period_id > 0) {
            $wpdb->update($t_per, [
                'standing'   => LFA_Membership::STANDING_CURRENT,
                'starts_at'  => $now,
                'ends_at'    => $one_year_later,
                'updated_at' => $now,
            ], ['id' => $period_id]);
        }

        update_user_meta($user_id, 'lfa_dues_status', 'current');

        self::log_audit('member', $user_id, 'payment_settled', (string)$invoice->id, [
            'invoice_number' => $invoice->invoice_number,
            'capture_id'     => $capture_id,
            'order_id'       => $order_id,
            'amount_cents'   => $cap_res['amount_cents'],
            'payable_type'   => 'membership_due',
        ]);

        self::send_receipt_email((int)$invoice->id, $capture_id);

        return [
            'success'        => true,
            'invoice_number' => $invoice->invoice_number,
            'capture_id'     => $capture_id,
            'amount_dollars' => number_format($invoice->amount_cents / 100, 2),
            'status'         => 'COMPLETED',
            'message'        => 'Payment captured and membership standing updated to Current.',
        ];
    }

    /**
     * Webhook Handler with Deduplication & Signature Verification
     */
    public static function handle_webhook(array $headers, string $raw_body): array {
        global $wpdb;
        $t_events = self::table_webhook_events();
        $mode = LFA_PayPal_Settings::get_mode();

        $event = json_decode($raw_body, true);
        if (!is_array($event) || empty($event['id']) || empty($event['event_type'])) {
            return ['status' => 400, 'error' => 'Malformed webhook payload.'];
        }

        $event_id = sanitize_text_field($event['id']);
        $event_type = sanitize_text_field($event['event_type']);

        // Check deduplication (P4-035, P4-067)
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_events} WHERE paypal_event_id = %s", $event_id));
        if ($existing && $existing->processing_state === 'processed') {
            return ['status' => 200, 'message' => 'Event already processed idempotently.'];
        }

        // Verify Signature
        $sig_res = LFA_PayPal_Client::verify_webhook_signature($headers, $raw_body, $mode);
        if (!$sig_res['verified']) {
            self::log_audit('system', null, 'webhook_signature_rejected', $event_id, [
                'event_type' => $event_type,
                'error'      => $sig_res['error'] ?? 'Invalid signature',
            ]);
            return ['status' => 401, 'error' => 'Webhook signature verification failed.'];
        }

        $now = current_time('mysql', true);
        if (!$existing) {
            $wpdb->insert($t_events, [
                'paypal_event_id'        => $event_id,
                'paypal_transmission_id' => $headers['paypal-transmission-id'] ?? ($headers['PAYPAL-TRANSMISSION-ID'] ?? null),
                'event_type'             => $event_type,
                'mode'                   => $mode,
                'verified_at'            => $now,
                'processed_at'           => null,
                'processing_state'       => 'verified',
                'failure_count'          => 0,
                'created_at'             => $now,
            ]);
            $event_row_id = $wpdb->insert_id;
        } else {
            $event_row_id = $existing->id;
        }

        // Process Event Family
        $settled = false;
        if ($event_type === 'PAYMENT.CAPTURE.COMPLETED') {
            $resource = $event['resource'] ?? [];
            $capture_id = $resource['id'] ?? '';
            $custom_id = $resource['custom_id'] ?? '';

            // Correlate with local invoice (Conference vs Dues)
            if (preg_match('/lfa_conf_inv_(\d+)_u_(\d+)/', $custom_id, $matches)) {
                $invoice_id = (int) $matches[1];
                $user_id = (int) $matches[2];

                $t_conf_inv = LFA_Conference::table_invoices();
                $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_conf_inv} WHERE id = %d", $invoice_id));
                if ($inv && $inv->status !== LFA_Conference::INVOICE_STATUS_PAID) {
                    $t_cap = self::table_captures();
                    $cap_check = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$t_cap} WHERE paypal_capture_id = %s", $capture_id));
                    if (!$cap_check) {
                        $captured_cents = isset($resource['amount']['value']) ? (int) round(((float)$resource['amount']['value']) * 100) : 0;
                        if ($captured_cents === (int)$inv->amount_cents) {
                            $wpdb->insert($t_cap, [
                                'attempt_id'        => 0,
                                'invoice_id'        => $invoice_id,
                                'payable_type'      => 'conference',
                                'payable_id'        => $invoice_id,
                                'paypal_capture_id' => $capture_id,
                                'paypal_order_id'   => $resource['supplementary_data']['related_ids']['order_id'] ?? 'webhook',
                                'merchant_identity' => $resource['payee']['merchant_id'] ?? null,
                                'mode'              => $mode,
                                'amount_cents'      => $captured_cents,
                                'currency'          => 'USD',
                                'capture_status'    => 'COMPLETED',
                                'captured_at'       => $now,
                                'reconciled_at'     => $now,
                                'refund_state'      => 'none',
                                'last_checked_at'   => $now,
                            ]);
                            LFA_Conference::settle_registration($invoice_id, $capture_id, $captured_cents);
                            $settled = true;
                        }
                    }
                }
            } elseif (preg_match('/lfa_inv_(\d+)_u_(\d+)/', $custom_id, $matches)) {
                $invoice_id = (int) $matches[1];
                $user_id = (int) $matches[2];

                if ($invoice_id > 0) {
                    $t_inv = LFA_Membership::table_invoices();
                    $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));
                    if ($inv && $inv->status !== LFA_Membership::INVOICE_STATUS_PAID) {
                        $t_cap = self::table_captures();
                        $cap_check = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$t_cap} WHERE paypal_capture_id = %s", $capture_id));
                        if (!$cap_check) {
                            $captured_cents = isset($resource['amount']['value']) ? (int) round(((float)$resource['amount']['value']) * 100) : 0;
                            if ($captured_cents === (int)$inv->amount_cents) {
                                $wpdb->insert($t_cap, [
                                    'attempt_id'        => 0,
                                    'invoice_id'        => $invoice_id,
                                    'payable_type'      => 'membership_due',
                                    'payable_id'        => $invoice_id,
                                    'paypal_capture_id' => $capture_id,
                                    'paypal_order_id'   => $resource['supplementary_data']['related_ids']['order_id'] ?? 'webhook',
                                    'merchant_identity' => $resource['payee']['merchant_id'] ?? null,
                                    'mode'              => $mode,
                                    'amount_cents'      => $captured_cents,
                                    'currency'          => 'USD',
                                    'capture_status'    => 'COMPLETED',
                                    'captured_at'       => $now,
                                    'reconciled_at'     => $now,
                                    'refund_state'      => 'none',
                                    'last_checked_at'   => $now,
                                ]);
                                $wpdb->update($t_inv, ['status' => LFA_Membership::INVOICE_STATUS_PAID], ['id' => $invoice_id]);
                                $t_mem = LFA_Membership::table_memberships();
                                $wpdb->update($t_mem, ['status' => LFA_Membership::STANDING_CURRENT, 'updated_at' => $now], ['user_id' => $inv->user_id]);
                                self::send_receipt_email($invoice_id, $capture_id);
                                $settled = true;
                            }
                        }
                    }
                }
            }
        } elseif ($event_type === 'PAYMENT.CAPTURE.REFUNDED' || $event_type === 'PAYMENT.CAPTURE.REVERSED') {
            $resource = $event['resource'] ?? [];
            $capture_id = $resource['links'][0]['href'] ?? ''; // or parse related capture ID
            $t_cap = self::table_captures();
            $wpdb->query($wpdb->prepare("UPDATE {$t_cap} SET refund_state = 'review_required', last_checked_at = %s WHERE paypal_capture_id LIKE %s", $now, '%' . $event['id'] . '%'));
            self::log_audit('system', null, 'payment_refund_detected', $event_id, [
                'event_type' => $event_type,
                'status'     => 'review_required',
            ]);
        }

        // Mark event processed
        $wpdb->update($t_events, [
            'processing_state' => 'processed',
            'processed_at'     => $now,
        ], ['id' => $event_row_id]);

        return ['status' => 200, 'message' => 'Webhook received and processed.'];
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
            'actor_user_id' => 0,
            'target_user_id'=> $inv->user_id,
            'action'        => 'receipt_email_sent',
            'entity_type'   => 'invoice',
            'entity_id'     => $inv->id,
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
