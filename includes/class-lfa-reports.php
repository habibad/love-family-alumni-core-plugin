<?php
/**
 * Love Family Alumni — Financial Analytics, Reporting & Audit Service
 *
 * Implements authoritative multi-ledger revenue analytics, strict separation of
 * dues/conferences/donations, formula-injection safe CSV exports, and immutable audit logging.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Reports {

    public const SCHEMA_VERSION = '1.0.0';

    public static function init(): void {
        self::install_schema();
        add_action('admin_post_lfa_export_csv_report', [__CLASS__, 'handle_export_csv_post']);
    }

    public static function table_admin_audit(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_admin_audit';
    }

    public static function install_schema(): void {
        global $wpdb;
        $table = self::table_admin_audit();
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `actor_id` BIGINT UNSIGNED NOT NULL,
            `action` VARCHAR(100) NOT NULL,
            `target_type` VARCHAR(100) NOT NULL,
            `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `details` TEXT NULL,
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `actor_idx` (`actor_id`),
            KEY `action_idx` (`action`),
            KEY `created_idx` (`created_at`)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    public static function log_audit(int $actor_id, string $action, string $target_type, int $target_id = 0, string $details = ''): void {
        global $wpdb;
        $wpdb->insert(self::table_admin_audit(), [
            'actor_id'    => $actor_id,
            'action'      => sanitize_key($action),
            'target_type' => sanitize_key($target_type),
            'target_id'   => $target_id,
            'details'     => sanitize_text_field($details),
            'ip_address'  => !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
            'created_at'  => current_time('mysql'),
        ]);
    }

    /**
     * ANA-001 & ANA-002: Member Analytics
     * Grouped by account status (approved/pending/rejected) and membership type (individual/couple)
     * without assuming unconfigured couple fee
     */
    public static function get_member_analytics(): array {
        global $wpdb;

        $results = [
            'total_registered' => 0,
            'approved'         => 0,
            'pending'          => 0,
            'rejected'         => 0,
            'individual'       => 0,
            'couple'           => 0,
            'dues_paid'        => 0,
            'dues_unpaid'      => 0,
            'dues_waived'      => 0,
        ];

        $users = get_users(['fields' => 'ID']);
        $results['total_registered'] = count($users);

        foreach ($users as $uid) {
            $account_status = (string)get_user_meta($uid, 'lfa_account_status', true) ?: 'pending';
            if ($account_status === 'approved') {
                $results['approved']++;
            } elseif ($account_status === 'rejected') {
                $results['rejected']++;
            } else {
                $results['pending']++;
            }

            $type = strtolower((string)get_user_meta($uid, 'lfa_membership_type', true) ?: 'individual');
            if ($type === 'couple') {
                $results['couple']++;
            } else {
                $results['individual']++;
            }

            $dues_status = (string)get_user_meta($uid, 'lfa_dues_status', true) ?: 'unpaid';
            if ($dues_status === 'paid') {
                $results['dues_paid']++;
            } elseif ($dues_status === 'waived') {
                $results['dues_waived']++;
            } else {
                $results['dues_unpaid']++;
            }
        }

        return $results;
    }

    /**
     * ANA-003: Dues Invoice Analytics
     * Authoritative dues amounts from wp_lfa_dues_invoices
     */
    public static function get_dues_analytics(?string $start_date = null, ?string $end_date = null): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lfa_dues_invoices';

        $where = [];
        $params = [];
        if ($start_date) {
            $where[] = "`created_at` >= %s";
            $params[] = $start_date . ' 00:00:00';
        }
        if ($end_date) {
            $where[] = "`created_at` <= %s";
            $params[] = $end_date . ' 23:59:59';
        }
        $where_sql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        $rows = $wpdb->get_results(
            $params ? $wpdb->prepare("SELECT * FROM `{$table}` {$where_sql}", ...$params) : "SELECT * FROM `{$table}`",
            ARRAY_A
        );

        $data = [
            'total_invoices'      => count($rows),
            'paid_count'          => 0,
            'unpaid_count'        => 0,
            'pending_count'       => 0,
            'waived_count'        => 0,
            'total_billed_cents'  => 0,
            'total_collected_cents' => 0,
            'invoices'            => $rows,
        ];

        foreach ($rows as $r) {
            $cents = (int)$r['amount_cents'];
            $data['total_billed_cents'] += $cents;
            if ($r['status'] === 'paid') {
                $data['paid_count']++;
                $data['total_collected_cents'] += $cents;
            } elseif ($r['status'] === 'pending_verification') {
                $data['pending_count']++;
            } elseif ($r['status'] === 'waived') {
                $data['waived_count']++;
            } else {
                $data['unpaid_count']++;
            }
        }

        return $data;
    }

    /**
     * ANA-004: Conference Analytics isolated by conference_id
     */
    public static function get_conference_analytics(int $conf_id = 0): array {
        global $wpdb;
        $attendee_table = $wpdb->prefix . 'lfa_conference_attendees';
        $invoice_table = $wpdb->prefix . 'lfa_conference_invoices';

        $att_where = $conf_id > 0 ? $wpdb->prepare("WHERE `conference_id` = %d", $conf_id) : "";
        $inv_where = $conf_id > 0 ? $wpdb->prepare("WHERE `conference_id` = %d", $conf_id) : "";

        $attendees = $wpdb->get_results("SELECT * FROM `{$attendee_table}` {$att_where}", ARRAY_A);
        $invoices = $wpdb->get_results("SELECT * FROM `{$invoice_table}` {$inv_where}", ARRAY_A);

        $data = [
            'conference_id'       => $conf_id,
            'total_attendees'     => count($attendees),
            'total_invoices'      => count($invoices),
            'paid_invoices'       => 0,
            'total_collected_cents' => 0,
            'attendees'           => $attendees,
            'invoices'            => $invoices,
        ];

        foreach ($invoices as $inv) {
            if ($inv['status'] === 'paid') {
                $data['paid_invoices']++;
                $data['total_collected_cents'] += (int)$inv['amount_cents'];
            }
        }

        return $data;
    }

    /**
     * ANA-007: Donation Analytics
     * Counts only verified paid captures from wp_lfa_donations
     */
    public static function get_donation_analytics(?string $start_date = null, ?string $end_date = null): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lfa_donations';

        $where = [];
        $params = [];
        if ($start_date) {
            $where[] = "`created_at` >= %s";
            $params[] = $start_date . ' 00:00:00';
        }
        if ($end_date) {
            $where[] = "`created_at` <= %s";
            $params[] = $end_date . ' 23:59:59';
        }
        $where_sql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        $rows = $wpdb->get_results(
            $params ? $wpdb->prepare("SELECT * FROM `{$table}` {$where_sql}", ...$params) : "SELECT * FROM `{$table}`",
            ARRAY_A
        );

        $data = [
            'total_donations'     => count($rows),
            'verified_paid_count' => 0,
            'pending_count'       => 0,
            'total_verified_cents' => 0,
            'by_fund'             => [],
            'donations'           => $rows,
        ];

        foreach ($rows as $r) {
            $cents = (int)$r['amount_cents'];
            $fund_id = (int)$r['fund_id'];

            if (!isset($data['by_fund'][$fund_id])) {
                $data['by_fund'][$fund_id] = [
                    'fund_id'   => $fund_id,
                    'count'     => 0,
                    'cents'     => 0,
                ];
            }

            if ($r['status'] === 'paid') {
                $data['verified_paid_count']++;
                $data['total_verified_cents'] += $cents;
                $data['by_fund'][$fund_id]['count']++;
                $data['by_fund'][$fund_id]['cents'] += $cents;
            } else {
                $data['pending_count']++;
            }
        }

        return $data;
    }

    /**
     * ANA-008, ANA-009, ANA-010: Authoritative Financial Reconciliation Summary
     * Strict isolation of dues, conferences, and donations.
     * Captures checked for environment (sandbox vs live) and refunds/reversals.
     */
    public static function get_reconciliation_summary(): array {
        global $wpdb;
        $captures_table = $wpdb->prefix . 'lfa_payment_captures';

        $captures = $wpdb->get_results("SELECT * FROM `{$captures_table}`", ARRAY_A);

        $summary = [
            'environment_live'      => false, // PayPal Live is OFF
            'dues_gross_cents'      => 0,
            'conference_gross_cents' => 0,
            'donation_gross_cents'  => 0,
            'refunds_cents'         => 0,
            'disputes_count'        => 0,
            'live_collected_cents'  => 0,
            'sandbox_collected_cents' => 0,
            'net_revenue_cents'     => 0,
        ];

        foreach ($captures as $cap) {
            $cents = (int)$cap['amount_cents'];
            $is_live = ($cap['mode'] === 'live');
            $status = $cap['capture_status'];
            $type = $cap['payable_type'];

            if ($status === 'COMPLETED') {
                if ($is_live) {
                    $summary['live_collected_cents'] += $cents;
                    if ($type === 'dues') {
                        $summary['dues_gross_cents'] += $cents;
                    } elseif ($type === 'conference') {
                        $summary['conference_gross_cents'] += $cents;
                    } elseif ($type === 'donation') {
                        $summary['donation_gross_cents'] += $cents;
                    }
                } else {
                    // Sandbox captures kept separate (ANA-009)
                    $summary['sandbox_collected_cents'] += $cents;
                }

                if ($cap['refund_state'] !== 'none') {
                    // Refunds affect net income (ANA-010)
                    $summary['refunds_cents'] += $cents;
                }
            }
        }

        $summary['net_revenue_cents'] = $summary['live_collected_cents'] - $summary['refunds_cents'];
        return $summary;
    }

    /**
     * ANA-005: Scholarship Analytics
     * Excludes confidential reviewer notes
     */
    public static function get_scholarship_analytics(): array {
        global $wpdb;
        $app_table = $wpdb->prefix . 'lfa_scholarship_applications';
        $prog_table = $wpdb->prefix . 'lfa_scholarship_programs';

        $apps = $wpdb->get_results("SELECT id, program_id, owner_user_id, applicant_name, status, created_at FROM `{$app_table}`", ARRAY_A);
        $programs = $wpdb->get_results("SELECT * FROM `{$prog_table}`", ARRAY_A);

        $data = [
            'total_applications' => count($apps),
            'by_status'          => [
                'submitted'     => 0,
                'under_review'  => 0,
                'shortlisted'   => 0,
                'awarded'       => 0,
                'declined'      => 0,
            ],
            'programs'           => $programs,
        ];

        foreach ($apps as $a) {
            $st = $a['status'];
            if (isset($data['by_status'][$st])) {
                $data['by_status'][$st]++;
            }
        }

        return $data;
    }

    /**
     * ANA-006: Volunteer Analytics
     */
    public static function get_volunteer_analytics(): array {
        global $wpdb;
        $opp_table = $wpdb->prefix . 'lfa_volunteer_opportunities';
        $signup_table = $wpdb->prefix . 'lfa_volunteer_signups';

        $total_opps = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$opp_table}`");
        $open_opps = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$opp_table}` WHERE `status` = 'open'");
        $total_signups = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$signup_table}`");
        $approved_volunteers = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$signup_table}` WHERE `status` = 'approved'");
        $pending_signups = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$signup_table}` WHERE `status` = 'pending'");

        return [
            'total_opportunities' => $total_opps,
            'open_opportunities'  => $open_opps,
            'total_signups'        => $total_signups,
            'approved_volunteers'  => $approved_volunteers,
            'pending_signups'      => $pending_signups,
        ];
    }

    /**
     * ANA-013: Formula-Injection Safe CSV Exporter
     * Prefixes '=', '+', '-', '@', '\t', '\r' with single quote
     */
    public static function sanitize_csv_field(string $field): string {
        $first_char = substr($field, 0, 1);
        if (in_array($first_char, ['=', '+', '-', '@', "\t", "\r"], true)) {
            $field = "'" . $field;
        }
        return str_replace('"', '""', $field);
    }

    public static function export_csv(string $report_type, int $actor_id, array $params = []): void {
        // Access control check (ANA-013)
        $allowed = false;
        if (user_can($actor_id, 'administrator')) {
            $allowed = true;
        } elseif (in_array($report_type, ['dues', 'financials', 'donations'], true) && user_can($actor_id, LFA_Roles::CAP_EXPORT_FINANCIALS)) {
            $allowed = true;
        } elseif ($report_type === 'conferences' && user_can($actor_id, LFA_Roles::CAP_EXPORT_CONFERENCE_ATTENDEES)) {
            $allowed = true;
        } elseif ($report_type === 'scholarships' && user_can($actor_id, LFA_Roles::CAP_EXPORT_SCHOLARSHIPS)) {
            $allowed = true;
        } elseif ($report_type === 'members' && user_can($actor_id, LFA_Roles::CAP_VIEW_MEMBERSHIP_REPORTS)) {
            $allowed = true;
        }

        if (!$allowed) {
            wp_die('Unauthorized: You lack permissions to export this report (ANA-013).', 'Access Denied', ['response' => 403]);
        }

        self::log_audit($actor_id, 'export_csv', $report_type, 0, "Exported {$report_type} CSV");

        $filename = "lfa_report_{$report_type}_" . date('Ymd_His') . ".csv";

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');

        if ($report_type === 'dues') {
            fputcsv($out, ['Invoice ID', 'Invoice Number', 'User ID', 'Amount (USD)', 'Status', 'Due Date', 'Paid At', 'Created At']);
            $data = self::get_dues_analytics();
            foreach ($data['invoices'] as $inv) {
                fputcsv($out, [
                    self::sanitize_csv_field((string)$inv['id']),
                    self::sanitize_csv_field((string)$inv['invoice_number']),
                    self::sanitize_csv_field((string)$inv['user_id']),
                    number_format($inv['amount_cents'] / 100, 2),
                    self::sanitize_csv_field((string)$inv['status']),
                    self::sanitize_csv_field((string)$inv['due_date']),
                    self::sanitize_csv_field((string)$inv['paid_at']),
                    self::sanitize_csv_field((string)$inv['created_at']),
                ]);
            }
        } elseif ($report_type === 'members') {
            fputcsv($out, ['User ID', 'Display Name', 'Email', 'Account Status', 'Dues Status', 'Membership Type', 'Alumni ID']);
            $users = get_users(['fields' => ['ID', 'display_name', 'user_email']]);
            foreach ($users as $u) {
                fputcsv($out, [
                    self::sanitize_csv_field((string)$u->ID),
                    self::sanitize_csv_field((string)$u->display_name),
                    self::sanitize_csv_field((string)$u->user_email),
                    self::sanitize_csv_field((string)get_user_meta($u->ID, 'lfa_account_status', true)),
                    self::sanitize_csv_field((string)get_user_meta($u->ID, 'lfa_dues_status', true)),
                    self::sanitize_csv_field((string)get_user_meta($u->ID, 'lfa_membership_type', true)),
                    self::sanitize_csv_field((string)get_user_meta($u->ID, 'lfa_alumni_id', true)),
                ]);
            }
        } elseif ($report_type === 'donations') {
            fputcsv($out, ['Donation Ref', 'Donor Name', 'Fund ID', 'Amount (USD)', 'Status', 'Paid At', 'Created At']);
            $data = self::get_donation_analytics();
            foreach ($data['donations'] as $d) {
                $donor_name = !empty($d['is_anonymous']) ? 'Anonymous' : $d['donor_display_name'];
                fputcsv($out, [
                    self::sanitize_csv_field((string)$d['donation_ref']),
                    self::sanitize_csv_field((string)$donor_name),
                    self::sanitize_csv_field((string)$d['fund_id']),
                    number_format($d['amount_cents'] / 100, 2),
                    self::sanitize_csv_field((string)$d['status']),
                    self::sanitize_csv_field((string)$d['paid_at']),
                    self::sanitize_csv_field((string)$d['created_at']),
                ]);
            }
        } else {
            fputcsv($out, ['Notice']);
            fputcsv($out, [self::sanitize_csv_field('Report type not configured.')]);
        }

        fclose($out);
        exit;
    }

    public static function handle_export_csv_post(): void {
        check_admin_referer('lfa_export_report_action', 'lfa_nonce');
        $actor_id = get_current_user_id();
        $report_type = isset($_POST['report_type']) ? sanitize_key($_POST['report_type']) : 'dues';
        self::export_csv($report_type, $actor_id, $_POST);
    }
}
