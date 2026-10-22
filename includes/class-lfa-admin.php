<?php
/**
 * Love Family Alumni — Administrative Management Controller
 *
 * Implements member approvals, normalized membership oversight, invoice management,
 * policy configuration, and audit logging.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Admin {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_lfa_member_decision', [__CLASS__, 'decide']);
        add_action('admin_post_lfa_resend_member_verification', [__CLASS__, 'resend']);
        add_action('admin_post_lfa_save_dues_policy', [__CLASS__, 'save_policy']);
        add_action('admin_post_lfa_admin_issue_invoice', [__CLASS__, 'issue_invoice']);
    }

    public static function menu(): void {
        // Approvals Screen
        add_users_page('Member Approvals', 'Member Approvals', 'edit_users', 'lfa-members', [__CLASS__, 'page_approvals']);

        // Memberships & Standing Screen
        add_users_page('Alumni Memberships', 'Alumni Memberships', 'edit_users', 'lfa-memberships', [__CLASS__, 'page_memberships']);

        // Dues Invoices Screen
        add_users_page('Dues Invoices', 'Dues Invoices', 'edit_users', 'lfa-invoices', [__CLASS__, 'page_invoices']);

        // Policy & Configuration Screen
        add_users_page('Dues Policy & Config', 'Dues Policy & Config', 'manage_options', 'lfa-dues-policy', [__CLASS__, 'page_policy']);

        // Audit Log Screen
        add_users_page('Membership Audit Log', 'Membership Audit Log', 'edit_users', 'lfa-audit-log', [__CLASS__, 'page_audit']);
    }

    /**
     * Screen 1: Member Approvals
     */
    public static function page_approvals(): void {
        if (!current_user_can('edit_users')) { wp_die('Unauthorized', 'Access Denied', ['response' => 403]); }
        $users = get_users(['role' => LFA_Core::ROLE, 'number' => 100, 'orderby' => 'registered', 'order' => 'DESC']);
        ?>
        <div class="wrap">
            <h1>Love Family Alumni &mdash; Member Approvals</h1>
            <p>Review new member registrations, verify identities, and assign official North America Alumni IDs.</p>
            <?php if (isset($_GET['lfa_admin_notice'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['lfa_admin_notice']))); ?></p></div>
            <?php endif; ?>
            <table class="widefat striped">
                <thead>
                    <tr><th>Member</th><th>Email</th><th>Verification Status</th><th>Membership</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php if (empty($users)) : ?>
                    <tr><td colspan="5">No alumni member accounts registered yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($users as $user) :
                        $uid = (int) $user->ID;
                        $status = LFA_Core::member_status($uid); ?>
                        <tr>
                            <td><strong><?php echo esc_html($user->display_name); ?></strong> (ID #<?php echo esc_html((string)$uid); ?>)</td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td><span class="badge" style="padding:3px 8px;border-radius:4px;font-weight:600;background:<?php echo $status === 'approved' ? '#e6f4ea;color:#137333;' : ($status === 'rejected' ? '#fce8e6;color:#c5221f;' : '#fef7e0;color:#b06000;'); ?>"><?php echo esc_html(ucfirst(str_replace('_', ' ', $status ?: 'unknown'))); ?></span></td>
                            <td><?php echo esc_html(ucfirst((string) get_user_meta($uid, 'lfa_membership_type', true) ?: 'Individual')); ?></td>
                            <td>
                                <?php if ($status === 'pending_approval' || $status === 'rejected') : ?>
                                    <form method="post" style="display:inline-block;margin-right:4px;" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="lfa_member_decision">
                                        <input type="hidden" name="user_id" value="<?php echo esc_attr((string)$uid); ?>">
                                        <input type="hidden" name="decision" value="approve">
                                        <?php wp_nonce_field('lfa_member_decision_' . $uid); ?>
                                        <button type="submit" class="button button-primary">Approve</button>
                                    </form>
                                    <form method="post" style="display:inline-block;margin-right:4px;" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="lfa_member_decision">
                                        <input type="hidden" name="user_id" value="<?php echo esc_attr((string)$uid); ?>">
                                        <input type="hidden" name="decision" value="reject">
                                        <?php wp_nonce_field('lfa_member_decision_' . $uid); ?>
                                        <button type="submit" class="button">Reject</button>
                                    </form>
                                <?php elseif ($status === 'pending_email') : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="lfa_resend_member_verification">
                                        <input type="hidden" name="user_id" value="<?php echo esc_attr((string)$uid); ?>">
                                        <?php wp_nonce_field('lfa_member_resend_' . $uid); ?>
                                        <button type="submit" class="button">Resend Verification Email</button>
                                    </form>
                                <?php elseif ($status === 'approved') :
                                    $alumni_id = get_user_meta($uid, LFA_Core::META_ID, true); ?>
                                    <span class="badge" style="background:#e6f4ea;color:#137333;padding:4px 8px;border-radius:4px;font-weight:600;">ID: <?php echo esc_html($alumni_id ?: 'Pending ID'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Screen 2: Memberships & Standing
     */
    public static function page_memberships(): void {
        if (!current_user_can('edit_users')) { wp_die('Unauthorized', 'Access Denied', ['response' => 403]); }
        global $wpdb;
        $t_memberships = LFA_Membership::table_memberships();
        $memberships = $wpdb->get_results("SELECT * FROM {$t_memberships} ORDER BY id DESC LIMIT 100");
        ?>
        <div class="wrap">
            <h1>Alumni Memberships &amp; Standing</h1>
            <p>Authoritative lifecycle records for enrolled alumni members. Account Approval is maintained independently from Dues Standing.</p>
            <table class="widefat striped">
                <thead>
                    <tr><th>Record ID</th><th>Member</th><th>Alumni ID</th><th>Type</th><th>Partner Details</th><th>Account Approval</th><th>Dues Standing</th><th>Enrolled</th><th>Invoicing</th></tr>
                </thead>
                <tbody>
                <?php if (empty($memberships)) : ?>
                    <tr><td colspan="9">No membership enrollments recorded.</td></tr>
                <?php else : ?>
                    <?php foreach ($memberships as $m) :
                        $u = get_user_by('id', $m->user_id);
                        $alumni_id = get_user_meta($m->user_id, LFA_Core::META_ID, true);
                        $approval = LFA_Core::member_status((int)$m->user_id); ?>
                        <tr>
                            <td><code>#<?php echo esc_html($m->id); ?></code></td>
                            <td><strong><?php echo esc_html($u ? $u->display_name : 'Unknown User #' . $m->user_id); ?></strong><br><small><?php echo esc_html($u ? $u->user_email : ''); ?></small></td>
                            <td><code><?php echo esc_html($alumni_id ?: 'None'); ?></code></td>
                            <td><span class="badge"><?php echo esc_html(ucfirst($m->membership_type)); ?></span></td>
                            <td>
                                <?php if ($m->membership_type === 'couple') : ?>
                                    <strong><?php echo esc_html($m->partner_name ?: 'None specified'); ?></strong><br>
                                    <small><?php echo esc_html($m->partner_email ?: ''); ?></small>
                                <?php else : ?>
                                    <span style="color:#888;">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge" style="background:<?php echo $approval === 'approved' ? '#e6f4ea;color:#137333;' : '#fce8e6;color:#c5221f;'; ?>padding:3px 6px;border-radius:3px;font-weight:600;"><?php echo esc_html(ucfirst($approval)); ?></span></td>
                            <td><span class="badge" style="background:#fee2e2;color:#991b1b;padding:3px 6px;border-radius:3px;font-weight:600;"><?php echo esc_html(ucfirst($m->status)); ?></span></td>
                            <td><?php echo esc_html(date('Y-m-d', strtotime($m->created_at))); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="lfa_admin_issue_invoice">
                                    <input type="hidden" name="membership_id" value="<?php echo esc_attr((string)$m->id); ?>">
                                    <?php wp_nonce_field('lfa_admin_issue_invoice_' . $m->id); ?>
                                    <button type="submit" class="button button-small">Issue / Check Invoice</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Screen 3: Dues Invoices
     */
    public static function page_invoices(): void {
        if (!current_user_can('edit_users')) { wp_die('Unauthorized', 'Access Denied', ['response' => 403]); }
        global $wpdb;
        $t_inv = LFA_Membership::table_invoices();
        $invoices = $wpdb->get_results("SELECT * FROM {$t_inv} ORDER BY id DESC LIMIT 100");
        ?>
        <div class="wrap">
            <h1>Annual Dues Invoices</h1>
            <p>Authoritative bookkeeping records for annual membership dues. <em>Note: Payment capture is scheduled for Phase 04 via PayPal merchant credentials. No fake receipts or simulated transactions are generated.</em></p>
            <table class="widefat striped">
                <thead>
                    <tr><th>Invoice Number</th><th>Member</th><th>Quoted Amount</th><th>Currency</th><th>Status</th><th>Policy Version</th><th>Issued (UTC)</th><th>Notes</th></tr>
                </thead>
                <tbody>
                <?php if (empty($invoices)) : ?>
                    <tr><td colspan="8">No invoices generated yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($invoices as $inv) :
                        $u = get_user_by('id', $inv->user_id);
                        $dollars = number_format($inv->amount_cents / 100, 2); ?>
                        <tr>
                            <td><strong><code><?php echo esc_html($inv->invoice_number); ?></code></strong></td>
                            <td><?php echo esc_html($u ? $u->display_name : 'User #' . $inv->user_id); ?><br><small><?php echo esc_html($u ? $u->user_email : ''); ?></small></td>
                            <td><strong>$<?php echo esc_html($dollars); ?></strong></td>
                            <td><code><?php echo esc_html($inv->currency); ?></code></td>
                            <td><span class="badge" style="background:#fee2e2;color:#991b1b;padding:3px 6px;border-radius:3px;font-weight:600;"><?php echo esc_html(ucfirst($inv->status)); ?></span></td>
                            <td><code><?php echo esc_html($inv->policy_version); ?></code></td>
                            <td><?php echo esc_html($inv->created_at); ?></td>
                            <td><small><?php echo esc_html($inv->notes ?: 'Annual dues schedule'); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Screen 4: Annual Dues Policy & Configuration
     */
    public static function page_policy(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized', 'Access Denied', ['response' => 403]); }
        $policy = LFA_Membership::get_policy();
        ?>
        <div class="wrap">
            <h1>Annual Dues Policy &amp; Configuration</h1>
            <p>Authoritative financial parameters for North America Zone membership dues. Requires explicit administrator sign-off per <code>CLIENT_POLICY_DECISIONS.md</code>.</p>
            
            <?php if (isset($_GET['lfa_policy_notice'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['lfa_policy_notice']))); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:760px;background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:8px;margin-top:20px;">
                <input type="hidden" name="action" value="lfa_save_dues_policy">
                <?php wp_nonce_field('lfa_save_dues_policy_action', 'lfa_policy_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="individual_base_dollars">Individual Annual Dues *</label></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span>$</span>
                                <input type="number" id="individual_base_dollars" name="individual_base_dollars" step="1" min="1" value="<?php echo esc_attr((int)($policy['individual_base_cents'] / 100)); ?>" required class="regular-text" style="width:120px;">
                                <span>USD (Documented base fee: $100.00 / 10,000 cents)</span>
                            </div>
                            <p class="description">Authoritative annual membership base fee specified in client brief.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="couple_base_dollars">Couple Membership Dues</label></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span>$</span>
                                <input type="number" id="couple_base_dollars" name="couple_base_dollars" step="1" min="0" value="<?php echo !empty($policy['couple_base_cents']) ? esc_attr((int)($policy['couple_base_cents'] / 100)) : ''; ?>" placeholder="Unconfigured" class="regular-text" style="width:120px;">
                                <span>USD</span>
                            </div>
                            <div style="margin-top:8px;padding:8px 12px;background:#fff8e5;border-left:4px solid #dba617;font-size:12px;">
                                <strong>Client Policy Pending:</strong> Leave blank to keep couple dues unconfigured. In accordance with policy contract, couple invoices cannot be issued without an approved fee.
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="billing_cycle">Billing Cycle</label></th>
                        <td>
                            <select id="billing_cycle" name="billing_cycle" class="regular-text">
                                <option value="unconfigured" <?php selected($policy['billing_cycle'], 'unconfigured'); ?>>Unconfigured (Awaiting client confirmation)</option>
                                <option value="calendar" <?php selected($policy['billing_cycle'], 'calendar'); ?>>Calendar Year (Jan 1 &ndash; Dec 31)</option>
                                <option value="anniversary" <?php selected($policy['billing_cycle'], 'anniversary'); ?>>Anniversary of Approval (1 year from approval date)</option>
                            </select>
                            <p class="description">Due dates and expiration dates will not be fabricated while set to Unconfigured.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="grace_period_days">Grace Period (Days)</label></th>
                        <td>
                            <input type="number" id="grace_period_days" name="grace_period_days" min="0" max="365" value="<?php echo !empty($policy['grace_period_days']) ? esc_attr($policy['grace_period_days']) : ''; ?>" placeholder="None / Unconfigured" class="small-text">
                            <p class="description">Members will not be auto-expired or marked overdue without an approved grace policy.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Renewal Mode</th>
                        <td>
                            <p><strong>Manual Renewal Only</strong> <span class="badge" style="background:#eef1f6;color:#555;padding:2px 6px;border-radius:4px;">Locked</span></p>
                            <p class="description">Automatic recurring debits are strictly prohibited in Phase 03 per client specification.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Policy Version Tracking</th>
                        <td>
                            <p>Current active version: <strong><code><?php echo esc_html($policy['policy_version']); ?></code></strong> &middot; Last updated: <?php echo esc_html($policy['updated_at']); ?> (UTC)</p>
                            <p class="description">Every policy change increments the version and creates an immutable audit trail entry.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Save Policy Changes</button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Screen 5: Audit Log
     */
    public static function page_audit(): void {
        if (!current_user_can('edit_users')) { wp_die('Unauthorized', 'Access Denied', ['response' => 403]); }
        $logs = LFA_Membership::get_audit_logs(100);
        ?>
        <div class="wrap">
            <h1>Membership &amp; Dues Audit Log</h1>
            <p>Chronological administrative record of all membership migrations, invoice issuances, and policy updates. Excludes all private credentials, tokens, and PII.</p>
            <table class="widefat striped">
                <thead>
                    <tr><th>ID</th><th>Timestamp (UTC)</th><th>Actor</th><th>Subject</th><th>Action</th><th>Object</th><th>Metadata</th></tr>
                </thead>
                <tbody>
                <?php if (empty($logs)) : ?>
                    <tr><td colspan="7">No audit logs recorded yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($logs as $l) :
                        $actor = $l->actor_user_id > 0 ? get_user_by('id', $l->actor_user_id) : null;
                        $subject = $l->subject_user_id > 0 ? get_user_by('id', $l->subject_user_id) : null; ?>
                        <tr>
                            <td><code>#<?php echo esc_html($l->id); ?></code></td>
                            <td><?php echo esc_html($l->created_at); ?></td>
                            <td><?php echo esc_html($actor ? $actor->display_name : ($l->actor_user_id === 0 ? 'System / Auto' : 'User #' . $l->actor_user_id)); ?></td>
                            <td><?php echo esc_html($subject ? $subject->display_name : ($l->subject_user_id === 0 ? 'Global' : 'User #' . $l->subject_user_id)); ?></td>
                            <td><code><?php echo esc_html($l->action); ?></code></td>
                            <td><?php echo esc_html($l->object_type . ' #' . $l->object_id); ?></td>
                            <td><small><code><?php echo esc_html($l->metadata_json); ?></code></small></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * POST Action: Approve or Reject Member
     */
    public static function decide(): void {
        if (!current_user_can('edit_users')) {
            wp_die('Unauthorized', 'Unauthorized', ['response' => 403]);
        }
        $uid = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        if (!$uid) {
            wp_die('Invalid member', 'Invalid', ['response' => 400]);
        }
        check_admin_referer('lfa_member_decision_' . $uid);
        $decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
        $user = get_user_by('id', $uid);
        if (!$user || !in_array(LFA_Core::ROLE, (array) $user->roles, true) || !current_user_can('edit_user', $uid)) {
            wp_die('Invalid member account', 'Unauthorized', ['response' => 403]);
        }

        $current = LFA_Core::member_status($uid);
        if ($decision === 'approve' && in_array($current, ['pending_approval', 'rejected'], true)) {
            update_user_meta($uid, LFA_Core::META_STATUS, 'approved');
            if (!get_user_meta($uid, LFA_Core::META_ID, true)) {
                update_user_meta($uid, LFA_Core::META_ID, 'LFA-NA-' . str_pad((string)$uid, 6, '0', STR_PAD_LEFT));
            }
            update_user_meta($uid, 'lfa_approved_by', get_current_user_id());
            update_user_meta($uid, 'lfa_approved_at', current_time('mysql', true));

            // Sync with authoritative membership record
            LFA_Membership::get_or_create_membership($uid);

            $subject = 'Love Family Alumni membership approved';
            $message = 'Hello ' . esc_html($user->first_name) . ",\n\n"
                . "Your Love Family Alumni membership account has been approved!\n\n"
                . "You can now sign in to your Member Portal: " . LFA_Core::account_url('member-login') . "\n\n"
                . "Your assigned Alumni ID: " . get_user_meta($uid, LFA_Core::META_ID, true) . "\n\n"
                . "Membership dues remain separate from account approval.\n\n"
                . "Love Family Alumni — North America Zone";

            LFA_Core::log_email_event('membership_approved', $uid, $user->user_email, 'dispatched');
            wp_mail($user->user_email, $subject, $message);
        } elseif ($decision === 'reject' && $current === 'pending_approval') {
            update_user_meta($uid, LFA_Core::META_STATUS, 'rejected');
            update_user_meta($uid, 'lfa_rejected_by', get_current_user_id());
            update_user_meta($uid, 'lfa_rejected_at', current_time('mysql', true));

            $subject = 'Love Family Alumni membership update';
            $message = 'Hello ' . esc_html($user->first_name) . ",\n\n"
                . "Thank you for your interest in Love Family Alumni Association. Your membership application was not approved at this time.\n\n"
                . "Please contact the membership team if you believe this is in error.\n\n"
                . "Love Family Alumni — North America Zone";

            LFA_Core::log_email_event('membership_rejected', $uid, $user->user_email, 'dispatched');
            wp_mail($user->user_email, $subject, $message);
        } else {
            wp_die('Invalid status transition', 'Invalid', ['response' => 409]);
        }

        wp_safe_redirect(admin_url('users.php?page=lfa-members&lfa_admin_notice=Decision+applied+successfully.'));
        exit;
    }

    /**
     * POST Action: Resend Verification Email
     */
    public static function resend(): void {
        if (!current_user_can('edit_users')) {
            wp_die('Unauthorized', 'Unauthorized', ['response' => 403]);
        }
        $uid = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        if (!$uid || !current_user_can('edit_user', $uid)) {
            wp_die('Invalid member', 'Invalid', ['response' => 400]);
        }
        check_admin_referer('lfa_member_resend_' . $uid);

        $key = 'lfa_resend_' . $uid;
        if (!get_transient($key)) {
            LFA_Auth::send_verification($uid);
            set_transient($key, 1, 5 * MINUTE_IN_SECONDS);
        }

        wp_safe_redirect(admin_url('users.php?page=lfa-members&lfa_admin_notice=Verification+email+resent.'));
        exit;
    }

    /**
     * POST Action: Save Policy
     */
    public static function save_policy(): void {
        if (!current_user_can('manage_options') && !current_user_can('lfa_manage_dues_policy')) {
            wp_die('Unauthorized: Capability lfa_manage_dues_policy required.', 'Unauthorized', ['response' => 403]);
        }

        check_admin_referer('lfa_save_dues_policy_action', 'lfa_policy_nonce');

        $indiv_dollars = isset($_POST['individual_base_dollars']) ? absint($_POST['individual_base_dollars']) : 100;
        $couple_dollars = !empty($_POST['couple_base_dollars']) ? absint($_POST['couple_base_dollars']) : null;
        $cycle = isset($_POST['billing_cycle']) ? sanitize_key($_POST['billing_cycle']) : 'unconfigured';
        $grace = !empty($_POST['grace_period_days']) ? absint($_POST['grace_period_days']) : null;

        $new_policy = [
            'individual_base_cents' => $indiv_dollars * 100,
            'couple_base_cents'     => $couple_dollars ? ($couple_dollars * 100) : null,
            'billing_cycle'         => $cycle,
            'grace_period_days'     => $grace,
        ];

        LFA_Membership::update_policy($new_policy, get_current_user_id());

        wp_safe_redirect(admin_url('users.php?page=lfa-dues-policy&lfa_policy_notice=Dues+policy+saved+successfully.'));
        exit;
    }

    /**
     * POST Action: Admin Issue Invoice
     */
    public static function issue_invoice(): void {
        if (!current_user_can('edit_users') && !current_user_can('lfa_manage_memberships')) {
            wp_die('Unauthorized: Capability lfa_manage_memberships required.', 'Unauthorized', ['response' => 403]);
        }

        $mid = isset($_POST['membership_id']) ? absint($_POST['membership_id']) : 0;
        if (!$mid) {
            wp_die('Invalid membership ID.', 'Invalid Request', ['response' => 400]);
        }

        check_admin_referer('lfa_admin_issue_invoice_' . $mid);

        $result = LFA_Membership::issue_invoice($mid, get_current_user_id(), 'Administrator manual invoice issuance');
        if (!$result['success']) {
            wp_die(esc_html($result['error']), 'Invoicing Blocked', ['response' => 400]);
        }

        wp_safe_redirect(admin_url('users.php?page=lfa-invoices'));
        exit;
    }
}
