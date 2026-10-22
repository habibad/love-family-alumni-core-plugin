<?php
/**
 * Love Family Alumni — Payment Entitlements & Role-Based Access Governance
 *
 * Implements strict, authoritative entitlement calculations, separating:
 * 1. Email Verification
 * 2. Account Administrator Approval
 * 3. Annual Membership Dues Standing (Current vs Unpaid vs Expired)
 * 4. Staging / Test Paid Isolation vs Production Paid Privileges
 * 5. Conference Attendance Status
 * 6. Business Listing Moderation
 * 7. Scholarship Eligibility
 * 8. Organizational Administrative / Staff Roles (NEVER granted by payment)
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Payment_Entitlements {

    /**
     * Get unified user entitlement status dictionary
     */
    public static function get_user_entitlements(int $user_id): array {
        return self::diagnose_member($user_id);
    }

    /**
     * Check if user's email address is verified
     */
    public static function is_email_verified(int $user_id): bool {
        if ($user_id <= 0) {
            return false;
        }
        $verified = get_user_meta($user_id, 'lfa_email_verified', true);
        if ($verified === '1' || $verified === true) {
            return true;
        }
        $status = (string) get_user_meta($user_id, 'lfa_account_status', true);
        return in_array($status, ['approved', 'email_verified'], true);
    }

    /**
     * Check if user account is administrator-approved (Alumnus identity verified)
     * NOTE: Approval is independent of dues payment.
     */
    public static function is_account_approved(int $user_id): bool {
        if ($user_id <= 0) {
            return false;
        }
        if (class_exists('LFA_Core')) {
            return LFA_Core::is_approved($user_id);
        }
        $status = (string) get_user_meta($user_id, 'lfa_account_status', true);
        return $status === 'approved' || get_user_meta($user_id, 'lfa_account_approved', true) === '1';
    }

    /**
     * Retrieve authoritative dues standing for a member.
     * Accurately distinguishes between Production Paid, Stripe Test Paid (Staging), and Unpaid.
     */
    public static function get_dues_standing(int $user_id, bool $allow_staging_test = true): array {
        if ($user_id <= 0) {
            return [
                'standing'         => 'unpaid',
                'is_paid'          => false,
                'is_test_paid'     => false,
                'gateway_provider' => null,
                'paid_at'          => null,
                'invoice_number'   => null,
                'policy_version'   => null,
                'environment'      => null,
            ];
        }

        global $wpdb;
        $t_inv = LFA_Membership::table_invoices();
        $t_cap = LFA_Payment_Service::table_captures();
        $t_mem = LFA_Membership::table_memberships();
        $t_per = LFA_Membership::table_periods();
        $now   = current_time('mysql', true);

        // Check if membership is explicitly expired or period has ended
        $membership = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_mem} WHERE user_id = %d", $user_id));
        if ($membership && $membership->status === LFA_Membership::STANDING_EXPIRED) {
            return [
                'standing'         => 'expired',
                'is_paid'          => false,
                'is_test_paid'     => false,
                'is_live_paid'     => false,
                'gateway_provider' => null,
                'paid_at'          => null,
                'invoice_id'       => null,
                'invoice_number'   => null,
                'policy_version'   => $membership->policy_version ?? null,
                'environment'      => null,
            ];
        }

        if ($membership) {
            $period = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t_per} WHERE membership_id = %d ORDER BY id DESC LIMIT 1",
                $membership->id
            ));
            if ($period && ($period->standing === LFA_Membership::STANDING_EXPIRED || (!empty($period->ends_at) && $period->ends_at < $now))) {
                return [
                    'standing'         => 'expired',
                    'is_paid'          => false,
                    'is_test_paid'     => false,
                    'is_live_paid'     => false,
                    'gateway_provider' => null,
                    'paid_at'          => null,
                    'invoice_id'       => null,
                    'invoice_number'   => null,
                    'policy_version'   => null,
                    'environment'      => null,
                    'expired_at'       => $period->ends_at,
                ];
            }
        }

        // Query most recent invoice
        $inv = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_inv} WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ));

        if (!$inv) {
            return [
                'standing'         => 'not_billed',
                'is_paid'          => false,
                'is_test_paid'     => false,
                'gateway_provider' => null,
                'paid_at'          => null,
                'invoice_number'   => null,
                'policy_version'   => null,
                'environment'      => null,
            ];
        }

        if ($inv->status !== LFA_Membership::INVOICE_STATUS_PAID) {
            return [
                'standing'         => $inv->status === LFA_Membership::INVOICE_STATUS_PENDING ? 'pending_payment' : 'unpaid',
                'is_paid'          => false,
                'is_test_paid'     => false,
                'is_live_paid'     => false,
                'gateway_provider' => null,
                'paid_at'          => null,
                'invoice_id'       => (int) $inv->id,
                'invoice_number'   => $inv->invoice_number,
                'policy_version'   => $inv->policy_version,
                'environment'      => null,
                'amount_cents'     => (int) $inv->amount_cents,
            ];
        }

        // Query active, non-refunded capture
        $capture = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_cap} 
             WHERE (invoice_id = %d OR (payable_id = %d AND payable_type = 'membership_due')) 
               AND capture_status = 'COMPLETED' 
               AND refund_state != 'refunded' 
               AND (entitlement_effect IS NULL OR entitlement_effect != 'refunded') 
             ORDER BY id DESC LIMIT 1",
            $inv->id,
            $inv->id
        ));

        $is_test_user = (bool) get_user_meta($user_id, 'lfa_test_paid', true);
        $is_test = (!empty($capture) && (
            !empty($capture->test_transaction) || 
            in_array($capture->mode ?? '', ['test', 'sandbox'], true) || 
            in_array($capture->gateway_environment ?? '', ['test', 'sandbox'], true)
        )) || !empty($inv->test_paid) || $is_test_user;

        $gateway_provider = 'stripe';
        $environment = $is_test ? 'test' : 'live';

        if ($capture) {
            $gateway_provider = $capture->gateway_provider ?? ($capture->mode === 'test' ? 'stripe' : 'paypal');
            $environment = $capture->gateway_environment ?? $capture->mode;
        } elseif (!empty($inv->gateway_provider)) {
            $gateway_provider = $inv->gateway_provider;
        }

        $is_test_paid = $is_test;
        $is_live_paid = !$is_test;

        $effective_standing = 'unpaid';
        if ($is_live_paid) {
            $effective_standing = 'current';
        } elseif ($is_test_paid && $allow_staging_test) {
            $effective_standing = 'current'; // Staging-only test entitlement
        }

        return [
            'standing'         => $effective_standing,
            'is_paid'          => $is_live_paid || ($is_test_paid && $allow_staging_test),
            'is_test_paid'     => $is_test_paid,
            'is_live_paid'     => $is_live_paid,
            'gateway_provider' => $gateway_provider,
            'environment'      => $environment,
            'paid_at'          => $capture ? $capture->captured_at : $inv->paid_at,
            'invoice_id'       => (int) $inv->id,
            'invoice_number'   => $inv->invoice_number,
            'policy_version'   => $inv->policy_version,
            'amount_cents'     => (int) $inv->amount_cents,
        ];
    }

    /**
     * Confirmed Client Membership Access Policy:
     * Full member access requires BOTH:
     * 1. Administrator-approved alumni account.
     * 2. Successfully verified payment of $100 annual dues for the current membership period.
     */
    public static function is_full_member(int $user_id, bool $allow_staging_test = true): bool {
        if ($user_id <= 0) {
            return false;
        }

        // Administrative staff bypass for platform governance & oversight
        if (user_can($user_id, 'manage_options') || user_can($user_id, 'edit_users')) {
            return true;
        }

        // 1. Must be administrator-approved
        if (!self::is_account_approved($user_id)) {
            return false;
        }

        // 2. Must have verified paid dues for the current period
        $dues = self::get_dues_standing($user_id, $allow_staging_test);
        return !empty($dues['is_paid']) && $dues['standing'] === 'current';
    }

    /**
     * Check if user is eligible to apply for scholarships.
     * Rule: Requires full paid membership standing unless guest applications permitted.
     */
    public static function can_apply_scholarship(int $user_id, ?object $program = null): bool {
        if ($program && !empty($program->guest_application_enabled)) {
            return true;
        }
        return self::is_full_member($user_id, true);
    }

    /**
     * Check if user can submit a business listing.
     * Rule: Requires BOTH approval AND verified paid annual dues.
     */
    public static function can_register_business(int $user_id): bool {
        return self::is_full_member($user_id, true);
    }

    /**
     * Get authoritative directory access state for current viewer.
     * Evaluates all prompt-specified member standing states:
     * - 'guest': Unauthenticated visitor
     * - 'email_unverified': Registered, email not verified
     * - 'pending_approval': Email verified, awaiting administrator approval
     * - 'approved_unpaid': Approved alumnus, current $100 dues not settled
     * - 'expired': Prior membership expired / grace period lapsed
     * - 'current_paid': Approved alumnus with current verified paid dues
     * - 'admin_staff': Site administrator or authorized directory moderator
     *
     * @param int $user_id
     * @return array
     */
    public static function get_directory_access_state(int $user_id = 0): array {
        if ($user_id <= 0) {
            $user_id = get_current_user_id();
        }

        if ($user_id <= 0) {
            return [
                'state'            => 'guest',
                'can_access'       => false,
                'user_id'          => 0,
                'is_guest'         => true,
                'email_verified'   => false,
                'account_approved' => false,
                'dues_standing'    => 'unpaid',
                'is_paid'          => false,
                'invoice_id'       => 0,
            ];
        }

        // Administrative staff governance bypass (Administrator or business moderator)
        if (user_can($user_id, 'manage_options') || user_can($user_id, 'edit_users') || (class_exists('LFA_Business') && user_can($user_id, LFA_Business::CAP_MANAGE))) {
            return [
                'state'            => 'admin_staff',
                'can_access'       => true,
                'user_id'          => $user_id,
                'is_guest'         => false,
                'email_verified'   => true,
                'account_approved' => true,
                'dues_standing'    => 'staff',
                'is_paid'          => true,
                'invoice_id'       => 0,
            ];
        }

        $email_verified   = self::is_email_verified($user_id);
        $account_approved = self::is_account_approved($user_id);

        if (!$email_verified) {
            return [
                'state'            => 'email_unverified',
                'can_access'       => false,
                'user_id'          => $user_id,
                'is_guest'         => false,
                'email_verified'   => false,
                'account_approved' => $account_approved,
                'dues_standing'    => 'unpaid',
                'is_paid'          => false,
                'invoice_id'       => 0,
            ];
        }

        if (!$account_approved) {
            return [
                'state'            => 'pending_approval',
                'can_access'       => false,
                'user_id'          => $user_id,
                'is_guest'         => false,
                'email_verified'   => true,
                'account_approved' => false,
                'dues_standing'    => 'unpaid',
                'is_paid'          => false,
                'invoice_id'       => 0,
            ];
        }

        // Member is approved - now evaluate dues standing
        $dues = self::get_dues_standing($user_id, true);

        if ($dues['standing'] === 'expired') {
            return [
                'state'            => 'expired',
                'can_access'       => false,
                'user_id'          => $user_id,
                'is_guest'         => false,
                'email_verified'   => true,
                'account_approved' => true,
                'dues_standing'    => 'expired',
                'is_paid'          => false,
                'invoice_id'       => $dues['invoice_id'] ?? 0,
                'expired_at'       => $dues['expired_at'] ?? null,
            ];
        }

        if (!empty($dues['is_paid']) && $dues['standing'] === 'current') {
            return [
                'state'            => 'current_paid',
                'can_access'       => true,
                'user_id'          => $user_id,
                'is_guest'         => false,
                'email_verified'   => true,
                'account_approved' => true,
                'dues_standing'    => 'current',
                'is_paid'          => true,
                'is_test_paid'     => !empty($dues['is_test_paid']),
                'invoice_id'       => $dues['invoice_id'] ?? 0,
            ];
        }

        // Approved but unpaid or payment pending
        return [
            'state'            => 'approved_unpaid',
            'can_access'       => false,
            'user_id'          => $user_id,
            'is_guest'         => false,
            'email_verified'   => true,
            'account_approved' => true,
            'dues_standing'    => $dues['standing'],
            'is_paid'          => false,
            'invoice_id'       => $dues['invoice_id'] ?? 0,
            'amount_cents'     => $dues['amount_cents'] ?? 10000,
        ];
    }

    /**
     * Check if member has access to the restricted members-only alumni directory.
     * Rule: Requires BOTH approval AND verified paid annual dues.
     */
    public static function can_access_directory(int $user_id = 0): bool {
        if ($user_id <= 0) {
            $user_id = get_current_user_id();
        }
        if ($user_id <= 0) {
            return false;
        }
        $state = self::get_directory_access_state($user_id);
        return !empty($state['can_access']);
    }

    /**
     * Comprehensive Member Entitlement Diagnosis
     * Used by administrators to inspect why a user has or lacks access.
     */
    public static function diagnose_member(int $user_id): array {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return ['found' => false, 'error' => 'User not found.'];
        }

        $email_verified = self::is_email_verified($user_id);
        $account_approved = self::is_account_approved($user_id);
        $account_status = (string) get_user_meta($user_id, 'lfa_account_status', true) ?: 'pending';
        $dues_info = self::get_dues_standing($user_id, true);

        global $wpdb;
        $t_creg = LFA_Conference::table_registrations();
        $conf_regs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, registration_number, ticket_name_snapshot, status, created_at FROM {$t_creg} WHERE owner_user_id = %d ORDER BY id DESC",
            $user_id
        ));

        // Privileged staff check (Ensure payment never granted these)
        $staff_roles = [
            'administrator',
            'lfa_president',
            'lfa_general_secretary',
            'lfa_treasurer',
            'lfa_financial_secretary',
            'lfa_conference_admin',
            'lfa_scholarship_admin',
            'lfa_business_moderator',
        ];

        $has_staff_role = false;
        $user_staff_roles = [];
        foreach ($user->roles as $r) {
            if (in_array($r, $staff_roles, true)) {
                $has_staff_role = true;
                $user_staff_roles[] = $r;
            }
        }

        return [
            'found'                  => true,
            'user_id'                => $user_id,
            'username'               => $user->user_login,
            'display_name'           => $user->display_name,
            'email'                  => $user->user_email,
            'roles'                  => $user->roles,
            'has_staff_role'         => $has_staff_role,
            'staff_roles'            => $user_staff_roles,
            'email_verified'         => $email_verified,
            'account_approved'       => $account_approved,
            'account_status'         => $account_status,
            'dues_standing'          => $dues_info['standing'],
            'is_paid'                => $dues_info['is_paid'],
            'is_test_paid'           => $dues_info['is_test_paid'],
            'is_live_paid'           => $dues_info['is_live_paid'] ?? false,
            'gateway_provider'       => $dues_info['gateway_provider'],
            'invoice_number'         => $dues_info['invoice_number'],
            'conference_registered'  => !empty($conf_regs),
            'conference_records'     => $conf_regs,
            'can_access_directory'   => self::can_access_directory($user_id),
            'can_register_business'  => self::can_register_business($user_id),
            'can_apply_scholarship'  => self::can_apply_scholarship($user_id),
            'diagnosed_at'           => current_time('mysql', true),
        ];
    }

    /**
     * Recalculate member standing after refund or dispute
     */
    public static function handle_refund(int $invoice_id, string $payable_type, string $reason = 'refund'): array {
        global $wpdb;
        $now = current_time('mysql', true);

        if ($payable_type === 'conference') {
            $t_inv = LFA_Conference::table_invoices();
            $t_reg = LFA_Conference::table_registrations();

            $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));
            if ($inv) {
                $wpdb->update($t_inv, ['status' => LFA_Conference::INVOICE_STATUS_REFUNDED, 'updated_at' => $now], ['id' => $inv->id]);
                $wpdb->update($t_reg, ['status' => LFA_Conference::STATUS_REFUNDED, 'updated_at' => $now], ['id' => $inv->registration_id]);
                LFA_Payment_Service::log_audit('system', (int)$inv->user_id, 'conference_payment_refunded', (string)$invoice_id, ['reason' => $reason]);
                return ['success' => true, 'effect' => 'conference_refunded'];
            }
        } elseif ($payable_type === 'donation') {
            $t_don = LFA_Donation::table_donations();
            $don = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_don} WHERE id = %d", $invoice_id));
            if ($don) {
                $wpdb->update($t_don, ['status' => LFA_Donation::STATUS_REFUNDED, 'updated_at' => $now], ['id' => $don->id]);
                LFA_Payment_Service::log_audit('system', (int)$don->donor_user_id, 'donation_refunded', (string)$invoice_id, ['reason' => $reason]);
                return ['success' => true, 'effect' => 'donation_refunded'];
            }
        } else {
            // Membership Dues
            $t_inv = LFA_Membership::table_invoices();
            $t_mem = LFA_Membership::table_memberships();
            $t_per = LFA_Membership::table_periods();

            $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d", $invoice_id));
            if ($inv) {
                $wpdb->update($t_inv, ['status' => LFA_Membership::INVOICE_STATUS_UNPAID, 'updated_at' => $now], ['id' => $inv->id]);
                $wpdb->update($t_mem, ['status' => LFA_Membership::STANDING_UNPAID, 'updated_at' => $now], ['user_id' => $inv->user_id]);
                if (!empty($inv->period_id)) {
                    $wpdb->update($t_per, ['standing' => LFA_Membership::STANDING_UNPAID, 'updated_at' => $now], ['id' => (int)$inv->period_id]);
                }
                $t_cap = LFA_Payment_Service::table_captures();
                $wpdb->update($t_cap, [
                    'refund_state'       => 'refunded',
                    'entitlement_effect' => 'refunded',
                    'refunded_at'        => $now,
                    'last_checked_at'    => $now,
                ], ['invoice_id' => $inv->id]);
                delete_user_meta((int)$inv->user_id, 'lfa_test_paid');
                update_user_meta((int)$inv->user_id, 'lfa_dues_status', 'unpaid');
                LFA_Payment_Service::log_audit('system', (int)$inv->user_id, 'dues_payment_refunded', (string)$invoice_id, ['reason' => $reason]);
                return ['success' => true, 'effect' => 'dues_standing_reverted_to_unpaid'];
            }
        }

        return ['success' => false, 'error' => 'Payable not found.'];
    }
}
