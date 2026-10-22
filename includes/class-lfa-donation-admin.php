<?php
/**
 * Love Family Alumni — Administrative Giving & Donations Management
 *
 * Implements giving funds & projects CRUD, donations ledger, restricted CSV exports
 * with formula neutralization, verified capture financial reports, and audit trail.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Donation_Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_admin_menus'], 27);
        add_action('admin_post_lfa_save_giving_fund', [__CLASS__, 'handle_save_fund']);
        add_action('admin_post_lfa_set_fund_status', [__CLASS__, 'handle_set_fund_status']);
        add_action('admin_post_lfa_save_giving_project', [__CLASS__, 'handle_save_project']);
        add_action('admin_post_lfa_set_project_status', [__CLASS__, 'handle_set_project_status']);
        add_action('admin_post_lfa_export_donations_csv', [__CLASS__, 'handle_export_csv']);
    }

    public static function register_admin_menus(): void {
        $parent = 'users.php';

        // 1. Giving Funds & Projects Management
        add_submenu_page(
            $parent,
            'Giving Funds & Projects',
            'Giving Funds',
            LFA_Donation::CAP_MANAGE_DONATIONS,
            'lfa-giving-funds',
            [__CLASS__, 'render_funds_page']
        );

        // 2. Donations Ledger
        add_submenu_page(
            $parent,
            'Donations Ledger',
            'Donations Ledger',
            LFA_Donation::CAP_VIEW_FINANCIALS,
            'lfa-donations-ledger',
            [__CLASS__, 'render_donations_ledger_page']
        );

        // 3. Financial Reports
        add_submenu_page(
            $parent,
            'Giving Financial Reports',
            'Giving Reports',
            LFA_Donation::CAP_VIEW_FINANCIALS,
            'lfa-donation-reports',
            [__CLASS__, 'render_financial_reports_page']
        );
    }

    /**
     * Screen 1: Giving Funds & Projects
     */
    public static function render_funds_page(): void {
        if (!current_user_can(LFA_Donation::CAP_MANAGE_DONATIONS)) {
            wp_die('Unauthorized: Insufficient permissions to manage giving funds.', 'Forbidden', ['response' => 403]);
        }

        $all_funds = LFA_Donation::get_all_funds();
        $all_projects = LFA_Donation::get_all_projects();

        $edit_fund_id = isset($_GET['edit_fund']) ? (int)$_GET['edit_fund'] : 0;
        $edit_fund = $edit_fund_id > 0 ? LFA_Donation::get_fund_by_id($edit_fund_id) : null;

        $notice = isset($_GET['notice']) ? sanitize_key($_GET['notice']) : '';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Giving Funds & Projects Oversight</h1>
            <p class="description">Administer official Love Family Alumni charitable funds, dedicated programs, welfare causes, and giving rules.</p>
            <hr class="wp-header-end">

            <?php if ($notice === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Giving Fund saved successfully.</p></div>
            <?php elseif ($notice === 'project_saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Project / Welfare cause saved successfully.</p></div>
            <?php elseif ($notice === 'status_updated'): ?>
                <div class="notice notice-success is-dismissible"><p>Status updated successfully.</p></div>
            <?php elseif ($notice === 'error'): ?>
                <div class="notice notice-error is-dismissible"><p>An error occurred. Please verify your inputs and try again.</p></div>
            <?php endif; ?>

            <div style="display: flex; gap: 24px; margin-top: 18px; flex-wrap: wrap;">
                <!-- Left: Funds List & Projects List -->
                <div style="flex: 2; min-width: 580px;">
                    <h2>Published & Configured Giving Funds</h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 40px;">ID</th>
                                <th>Fund Title / Slug</th>
                                <th>Category</th>
                                <th>Min - Max (USD)</th>
                                <th>Policy State</th>
                                <th>Guest Giving</th>
                                <th>Status</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_funds)): ?>
                                <tr><td colspan="8">No giving funds configured yet.</td></tr>
                            <?php else: foreach ($all_funds as $fund): ?>
                                <tr>
                                    <td>#<?php echo esc_html($fund->id); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($fund->title); ?></strong><br>
                                        <code><?php echo esc_html($fund->slug); ?></code>
                                        <?php if (!empty($fund->description)): ?>
                                            <p style="margin: 4px 0 0; color: #555; font-size: 12px;"><?php echo esc_html(wp_trim_words($fund->description, 16)); ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge" style="background:#eef;padding:2px 6px;border-radius:4px;font-size:11px;"><?php echo esc_html(strtoupper($fund->account_category)); ?></span></td>
                                    <td>
                                        $<?php echo number_format($fund->min_amount_cents / 100, 2); ?> &ndash; 
                                        $<?php echo number_format($fund->max_amount_cents / 100, 2); ?>
                                    </td>
                                    <td>
                                        <?php if (($fund->policy_status ?? 'unconfirmed') === 'approved'): ?>
                                            <span class="badge" style="background:#e6f4ea; color:#137333; padding:2px 6px; border-radius:4px; font-weight:600;">Approved</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#fef7e0; color:#b06000; padding:2px 6px; border-radius:4px; font-weight:600;">Unconfirmed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$fund->guest_giving_enabled === 1): ?>
                                            <span style="color: #137333; font-weight: 600;">Enabled</span>
                                        <?php else: ?>
                                            <span style="color: #666;">Member-Only</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status_colors = [
                                            'published' => '#e6f4ea; color:#137333;',
                                            'draft'     => '#fef7e0; color:#b06000;',
                                            'archived'  => '#f1f3f4; color:#5f6368;',
                                        ];
                                        $sc = $status_colors[$fund->published_status] ?? '#eee; color:#333;';
                                        ?>
                                        <span class="badge" style="padding: 3px 8px; border-radius: 4px; font-weight: 600; background: <?php echo esc_attr($sc); ?>">
                                            <?php echo esc_html(ucfirst($fund->published_status)); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <a href="<?php echo esc_url(admin_url('users.php?page=lfa-giving-funds&edit_fund=' . $fund->id)); ?>" class="button button-small">Edit</a>
                                        <?php if ($fund->published_status === 'published'): ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left: 4px;">
                                                <input type="hidden" name="action" value="lfa_set_fund_status">
                                                <input type="hidden" name="fund_id" value="<?php echo esc_attr($fund->id); ?>">
                                                <input type="hidden" name="new_status" value="draft">
                                                <?php wp_nonce_field('lfa_set_fund_status_' . $fund->id); ?>
                                                <button type="submit" class="button button-small" onclick="return confirm('Move this fund to draft status? It will no longer appear on the public Give page.');">Unpublish</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left: 4px;">
                                                <input type="hidden" name="action" value="lfa_set_fund_status">
                                                <input type="hidden" name="fund_id" value="<?php echo esc_attr($fund->id); ?>">
                                                <input type="hidden" name="new_status" value="published">
                                                <?php wp_nonce_field('lfa_set_fund_status_' . $fund->id); ?>
                                                <button type="submit" class="button button-small button-primary">Publish</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>

                    <h2 style="margin-top: 32px;">Projects & Welfare Initiatives</h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 40px;">ID</th>
                                <th>Project Title / Slug</th>
                                <th>Parent Fund</th>
                                <th>Type</th>
                                <th>Goal (USD)</th>
                                <th>Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_projects)): ?>
                                <tr><td colspan="7">No specific welfare projects configured under funds yet.</td></tr>
                            <?php else: foreach ($all_projects as $proj): 
                                $parent_fund = LFA_Donation::get_fund_by_id((int)$proj->fund_id);
                            ?>
                                <tr>
                                    <td>#<?php echo esc_html($proj->id); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($proj->title); ?></strong><br>
                                        <code><?php echo esc_html($proj->slug); ?></code>
                                    </td>
                                    <td><?php echo esc_html($parent_fund ? $parent_fund->title : 'Fund #' . $proj->fund_id); ?></td>
                                    <td><span class="badge" style="background:#e8f0fe;padding:2px 6px;border-radius:4px;"><?php echo esc_html(ucfirst($proj->project_type)); ?></span></td>
                                    <td><?php echo $proj->goal_amount_cents ? '$' . number_format($proj->goal_amount_cents / 100, 2) : '<em>Open</em>'; ?></td>
                                    <td>
                                        <span class="badge" style="padding: 2px 6px; border-radius: 4px; font-weight: 600; <?php echo $proj->published_status === 'published' ? 'background:#e6f4ea;color:#137333;' : 'background:#eee;color:#555;'; ?>">
                                            <?php echo esc_html(ucfirst($proj->published_status)); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                            <input type="hidden" name="action" value="lfa_set_project_status">
                                            <input type="hidden" name="project_id" value="<?php echo esc_attr($proj->id); ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $proj->published_status === 'published' ? 'draft' : 'published'; ?>">
                                            <?php wp_nonce_field('lfa_set_project_status_' . $proj->id); ?>
                                            <button type="submit" class="button button-small"><?php echo $proj->published_status === 'published' ? 'Unpublish' : 'Publish'; ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Right: Create/Edit Fund Form & Add Project Form -->
                <div style="flex: 1; min-width: 340px;">
                    <div class="card" style="margin-top: 0; padding: 16px 20px;">
                        <h2><?php echo $edit_fund ? 'Edit Giving Fund' : 'Add New Giving Fund'; ?></h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="lfa_save_giving_fund">
                            <input type="hidden" name="fund_id" value="<?php echo esc_attr($edit_fund ? $edit_fund->id : 0); ?>">
                            <?php wp_nonce_field('lfa_save_giving_fund_action', 'lfa_fund_nonce'); ?>

                            <p>
                                <label for="fund_title"><strong>Fund Title *</strong></label><br>
                                <input type="text" id="fund_title" name="title" class="widefat" required value="<?php echo esc_attr($edit_fund ? $edit_fund->title : ''); ?>">
                            </p>

                            <p>
                                <label for="fund_slug"><strong>Slug (Permanent Identifier)</strong></label><br>
                                <input type="text" id="fund_slug" name="slug" class="widefat" value="<?php echo esc_attr($edit_fund ? $edit_fund->slug : ''); ?>" placeholder="e.g. general-fund">
                            </p>

                            <p>
                                <label for="account_category"><strong>Accounting Category</strong></label><br>
                                <select id="account_category" name="account_category" class="widefat">
                                    <?php
                                    $cats = ['general' => 'General Ministry / Operations', 'scholarship' => 'Akpo Family Scholarship', 'archives' => 'Legacy & Archives', 'conference' => 'Conference & Fellowship', 'welfare' => 'Member Welfare'];
                                    $cur_cat = $edit_fund ? $edit_fund->account_category : 'general';
                                    foreach ($cats as $k => $v) {
                                        echo '<option value="' . esc_attr($k) . '" ' . selected($cur_cat, $k, false) . '>' . esc_html($v) . '</option>';
                                    }
                                    ?>
                                </select>
                            </p>

                            <p>
                                <label for="objective"><strong>Stated Objective</strong></label><br>
                                <input type="text" id="objective" name="objective" class="widefat" value="<?php echo esc_attr($edit_fund ? $edit_fund->objective : ''); ?>" placeholder="e.g. General Operational & Ministry Support">
                            </p>

                            <p>
                                <label for="fund_desc"><strong>Description / Purpose</strong></label><br>
                                <textarea id="fund_desc" name="description" rows="3" class="widefat"><?php echo esc_textarea($edit_fund ? $edit_fund->description : ''); ?></textarea>
                            </p>

                            <div style="display: flex; gap: 12px;">
                                <div style="flex: 1;">
                                    <label for="min_amount"><strong>Min ($ USD)</strong></label>
                                    <input type="number" id="min_amount" name="min_amount_dollars" min="1" step="1" class="widefat" value="<?php echo esc_attr($edit_fund ? ($edit_fund->min_amount_cents / 100) : 1); ?>">
                                </div>
                                <div style="flex: 1;">
                                    <label for="max_amount"><strong>Max ($ USD)</strong></label>
                                    <input type="number" id="max_amount" name="max_amount_dollars" min="1" step="1" class="widefat" value="<?php echo esc_attr($edit_fund ? ($edit_fund->max_amount_cents / 100) : 10000); ?>">
                                </div>
                            </div>

                            <p style="margin-top: 12px;">
                                <label>
                                    <input type="checkbox" name="guest_giving_enabled" value="1" <?php checked($edit_fund && (int)$edit_fund->guest_giving_enabled === 1); ?>>
                                    <strong>Allow Unauthenticated Guest Giving</strong>
                                </label>
                                <br><small style="color:#666;">When unchecked, guest gifts are held until member sign-in per executive policy.</small>
                            </p>

                            <p>
                                <label for="policy_status"><strong>Giving Limits Policy Status</strong></label><br>
                                <select id="policy_status" name="policy_status" class="widefat">
                                    <option value="unconfirmed" <?php selected($edit_fund ? ($edit_fund->policy_status ?? 'unconfirmed') : 'unconfirmed', 'unconfirmed'); ?>>Unconfirmed (Executive Policy Pending)</option>
                                    <option value="approved" <?php selected($edit_fund ? ($edit_fund->policy_status ?? '') : '', 'approved'); ?>>Approved (Documented Resolution)</option>
                                </select>
                                <small style="color:#666;">Per G0-C / GAT-007: Unconfirmed status marks limits as pending formal board approval without silently restricting giving.</small>
                            </p>

                            <p>
                                <label for="policy_approval_notes"><strong>Policy Approval Notes / Resolution Ref</strong></label><br>
                                <textarea id="policy_approval_notes" name="policy_approval_notes" rows="2" class="widefat" placeholder="e.g. Approved by LFA Board Resolution #2026-08"><?php echo esc_textarea($edit_fund ? ($edit_fund->policy_approval_notes ?? '') : ''); ?></textarea>
                            </p>

                            <p>
                                <label for="published_status"><strong>Status</strong></label><br>
                                <select id="published_status" name="published_status" class="widefat">
                                    <option value="published" <?php selected($edit_fund ? $edit_fund->published_status : 'published', 'published'); ?>>Published</option>
                                    <option value="draft" <?php selected($edit_fund ? $edit_fund->published_status : '', 'draft'); ?>>Draft</option>
                                    <option value="archived" <?php selected($edit_fund ? $edit_fund->published_status : '', 'archived'); ?>>Archived</option>
                                </select>
                            </p>

                            <p style="margin-top: 18px;">
                                <button type="submit" class="button button-primary"><?php echo $edit_fund ? 'Update Fund' : 'Create Fund'; ?></button>
                                <?php if ($edit_fund): ?>
                                    <a href="<?php echo esc_url(admin_url('users.php?page=lfa-giving-funds')); ?>" class="button">Cancel</a>
                                <?php endif; ?>
                            </p>
                        </form>
                    </div>

                    <!-- Add Project Card -->
                    <div class="card" style="margin-top: 20px; padding: 16px 20px;">
                        <h2>Add Welfare Project / Program</h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="lfa_save_giving_project">
                            <?php wp_nonce_field('lfa_save_giving_project_action', 'lfa_project_nonce'); ?>

                            <p>
                                <label for="proj_fund_id"><strong>Parent Giving Fund *</strong></label><br>
                                <select id="proj_fund_id" name="fund_id" class="widefat" required>
                                    <option value="">-- Select Parent Fund --</option>
                                    <?php foreach ($all_funds as $f): ?>
                                        <option value="<?php echo esc_attr($f->id); ?>"><?php echo esc_html($f->title); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </p>

                            <p>
                                <label for="proj_title"><strong>Project Title *</strong></label><br>
                                <input type="text" id="proj_title" name="title" class="widefat" required placeholder="e.g. North America Student Emergency Grant">
                            </p>

                            <p>
                                <label for="proj_type"><strong>Project Type</strong></label><br>
                                <select id="proj_type" name="project_type" class="widefat">
                                    <option value="project">Program Project</option>
                                    <option value="welfare">Member Welfare</option>
                                    <option value="grant">Academic / Fellowship Grant</option>
                                </select>
                            </p>

                            <p>
                                <label for="proj_goal"><strong>Fundraising Goal ($ USD, optional)</strong></label><br>
                                <input type="number" id="proj_goal" name="goal_amount_dollars" min="0" step="1" class="widefat" placeholder="Optional target amount">
                            </p>

                            <p>
                                <button type="submit" class="button">Save Project</button>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Screen 2: Donations Ledger
     */
    public static function render_donations_ledger_page(): void {
        if (!current_user_can(LFA_Donation::CAP_VIEW_FINANCIALS)) {
            wp_die('Unauthorized: Insufficient permissions to view financial ledger.', 'Forbidden', ['response' => 403]);
        }

        $filter_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $filter_fund = isset($_GET['fund_id']) ? (int)$_GET['fund_id'] : 0;

        $filters = [];
        if ($filter_status) $filters['status'] = $filter_status;
        if ($filter_fund) $filters['fund_id'] = $filter_fund;

        $donations = LFA_Donation::get_all_donations($filters);
        $all_funds = LFA_Donation::get_all_funds();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Donations & Giving Ledger</h1>
            <?php if (current_user_can(LFA_Donation::CAP_EXPORT_FINANCIALS)): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left: 10px;">
                    <input type="hidden" name="action" value="lfa_export_donations_csv">
                    <input type="hidden" name="status" value="<?php echo esc_attr($filter_status); ?>">
                    <input type="hidden" name="fund_id" value="<?php echo esc_attr($filter_fund); ?>">
                    <?php wp_nonce_field('lfa_export_donations_csv_action', 'lfa_export_nonce'); ?>
                    <button type="submit" class="button button-primary">Export CSV (Formula-Neutralized)</button>
                </form>
            <?php endif; ?>
            <p class="description">Authoritative, isolated giving transaction ledger. Strictly separated from annual dues and conference registrations.</p>
            <hr class="wp-header-end">

            <!-- Filter Bar -->
            <div class="tablenav top" style="margin: 12px 0;">
                <form method="get" action="<?php echo esc_url(admin_url('users.php')); ?>" style="display: flex; gap: 8px; align-items: center;">
                    <input type="hidden" name="page" value="lfa-donations-ledger">
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="paid" <?php selected($filter_status, 'paid'); ?>>Paid / Verified</option>
                        <option value="pending_payment" <?php selected($filter_status, 'pending_payment'); ?>>Pending Payment</option>
                        <option value="refunded" <?php selected($filter_status, 'refunded'); ?>>Refunded</option>
                        <option value="cancelled" <?php selected($filter_status, 'cancelled'); ?>>Cancelled</option>
                    </select>

                    <select name="fund_id">
                        <option value="0">All Giving Funds</option>
                        <?php foreach ($all_funds as $f): ?>
                            <option value="<?php echo esc_attr($f->id); ?>" <?php selected($filter_fund, $f->id); ?>><?php echo esc_html($f->title); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="button">Filter</button>
                    <?php if ($filter_status || $filter_fund): ?>
                        <a href="<?php echo esc_url(admin_url('users.php?page=lfa-donations-ledger')); ?>" class="button">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Donation Ref</th>
                        <th>Date</th>
                        <th>Donor (Privacy Governed)</th>
                        <th>Cause / Designated Fund</th>
                        <th>Amount (USD)</th>
                        <th>Status</th>
                        <th>PayPal Transaction ID</th>
                        <th>Audit ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($donations)): ?>
                        <tr><td colspan="8">No donation transactions match the specified filter.</td></tr>
                    <?php else: foreach ($donations as $don): ?>
                        <tr>
                            <td><strong><code><?php echo esc_html($don->donation_ref); ?></code></strong></td>
                            <td><?php echo esc_html(date_i18n('M j, Y H:i', strtotime($don->created_at))); ?></td>
                            <td>
                                <?php if ((int)$don->is_anonymous === 1): ?>
                                    <span class="badge" style="background:#fef7e0;color:#b06000;padding:2px 6px;border-radius:4px;font-weight:600;">ANONYMOUS</span>
                                    <small style="display:block;color:#666;">Confidential: <?php echo esc_html($don->donor_display_name); ?> (<?php echo esc_html($don->donor_email); ?>)</small>
                                <?php else: ?>
                                    <strong><?php echo esc_html($don->donor_display_name); ?></strong><br>
                                    <small style="color:#666;"><?php echo esc_html($don->donor_email); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($don->purpose_snapshot); ?></td>
                            <td><strong style="color:#137333;">$<?php echo number_format($don->amount_cents / 100, 2); ?></strong></td>
                            <td>
                                <?php
                                $sc = match($don->status) {
                                    'paid'            => 'background:#e6f4ea;color:#137333;',
                                    'pending_payment' => 'background:#fff8e1;color:#b06000;',
                                    'refunded'        => 'background:#fce8e6;color:#c5221f;',
                                    default           => 'background:#eee;color:#333;',
                                };
                                ?>
                                <span class="badge" style="padding: 3px 8px; border-radius: 4px; font-weight: 600; <?php echo esc_attr($sc); ?>">
                                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $don->status))); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($don->paypal_capture_id)): ?>
                                    <code><?php echo esc_html($don->paypal_capture_id); ?></code>
                                <?php else: ?>
                                    <span style="color: #999;">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>#<?php echo esc_html($don->id); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Screen 3: Financial Reports & Policy Transparency
     */
    public static function render_financial_reports_page(): void {
        if (!current_user_can(LFA_Donation::CAP_VIEW_FINANCIALS)) {
            wp_die('Unauthorized: Insufficient permissions to view financial reports.', 'Forbidden', ['response' => 403]);
        }

        $totals = LFA_Donation::get_donation_totals();
        global $wpdb;
        $t_don = LFA_Donation::table_donations();
        $t_funds = LFA_Donation::table_funds();

        // Breakdown by fund (verified captures only, GIV-014)
        $breakdown = $wpdb->get_results(
            "SELECT f.title, f.slug, count(d.id) as gift_count, COALESCE(sum(d.amount_cents), 0) as cents_total
             FROM {$t_funds} f
             LEFT JOIN {$t_don} d ON d.fund_id = f.id AND d.status = 'paid'
             GROUP BY f.id
             ORDER BY cents_total DESC, f.title ASC"
        ) ?: [];

        // Recent audit events
        $t_audit = LFA_Donation::table_audit();
        $recent_audits = $wpdb->get_results("SELECT * FROM {$t_audit} ORDER BY id DESC LIMIT 20") ?: [];
        ?>
        <div class="wrap">
            <h1>Giving Financial Reports & Audit Log</h1>
            <p class="description">Accurate, immutable financial totals derived exclusively from completed, verified PayPal captures.</p>
            <hr class="wp-header-end">

            <!-- Summary Cards -->
            <div style="display: flex; gap: 16px; margin: 20px 0; flex-wrap: wrap;">
                <div class="card" style="flex: 1; min-width: 200px; padding: 20px; border-left: 4px solid #137333;">
                    <div style="font-size: 13px; text-transform: uppercase; color: #666; font-weight: 600;">Total Verified Giving</div>
                    <div style="font-size: 32px; font-weight: 700; color: #137333; margin: 8px 0;">$<?php echo esc_html($totals['total_dollars']); ?></div>
                    <div style="font-size: 12px; color: #555;"><?php echo esc_html($totals['total_count']); ?> confirmed gift(s) across all funds</div>
                </div>

                <div class="card" style="flex: 1; min-width: 200px; padding: 20px; border-left: 4px solid #1a73e8;">
                    <div style="font-size: 13px; text-transform: uppercase; color: #666; font-weight: 600;">PayPal Gateway Mode</div>
                    <div style="font-size: 28px; font-weight: 700; color: #1a73e8; margin: 8px 0;"><?php echo esc_html(strtoupper(LFA_PayPal_Settings::get_mode())); ?></div>
                    <div style="font-size: 12px; color: #555;">
                        Live Mode: <strong style="color:<?php echo LFA_PayPal_Settings::is_live() ? '#c5221f' : '#137333'; ?>;"><?php echo LFA_PayPal_Settings::is_live() ? 'LIVE ACTIVE' : 'OFF (Sandbox Enforced)'; ?></strong>
                    </div>
                </div>

                <div class="card" style="flex: 1; min-width: 200px; padding: 20px; border-left: 4px solid #b06000;">
                    <div style="font-size: 13px; text-transform: uppercase; color: #666; font-weight: 600;">Tax Deductibility Policy</div>
                    <div style="font-size: 16px; font-weight: 600; color: #b06000; margin: 8px 0;">Official Status Pending</div>
                    <div style="font-size: 12px; color: #555;">Per Phase 08 specification: No 501(c)(3) tax deductions are claimed without formal client confirmation.</div>
                </div>
            </div>

            <!-- Breakdown by Fund -->
            <h2>Fund Totals (Verified Captures Only)</h2>
            <table class="widefat striped" style="max-width: 850px;">
                <thead>
                    <tr>
                        <th>Fund Category</th>
                        <th style="text-align: right;">Verified Gifts</th>
                        <th style="text-align: right;">Total Received (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($breakdown as $row): ?>
                        <tr>
                            <td><strong><?php echo esc_html($row->title); ?></strong></td>
                            <td style="text-align: right;"><?php echo esc_html($row->gift_count); ?></td>
                            <td style="text-align: right; font-weight: 600; color: #137333;">$<?php echo number_format($row->cents_total / 100, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Audit Trail -->
            <h2 style="margin-top: 36px;">Giving Subsystem Audit Trail (Redacted PII)</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>Timestamp</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Target Entity</th>
                        <th>Audit Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_audits)): ?>
                        <tr><td colspan="6">No audit records logged yet.</td></tr>
                    <?php else: foreach ($recent_audits as $audit): ?>
                        <tr>
                            <td>#<?php echo esc_html($audit->id); ?></td>
                            <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($audit->created_at))); ?></td>
                            <td><?php echo esc_html($audit->actor_type . ($audit->actor_id ? ' #' . $audit->actor_id : '')); ?></td>
                            <td><code><?php echo esc_html($audit->action); ?></code></td>
                            <td><?php echo esc_html($audit->entity_type . ' ' . $audit->entity_id); ?></td>
                            <td><code style="font-size: 11px;"><?php echo esc_html($audit->details_json); ?></code></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Handlers for admin form actions
     */
    public static function handle_save_fund(): void {
        if (!current_user_can(LFA_Donation::CAP_MANAGE_DONATIONS)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }
        check_admin_referer('lfa_save_giving_fund_action', 'lfa_fund_nonce');

        $res = LFA_Donation::save_fund($_POST, get_current_user_id());
        $notice = $res['success'] ? 'saved' : 'error';
        wp_safe_redirect(admin_url('users.php?page=lfa-giving-funds&notice=' . $notice));
        exit;
    }

    public static function handle_set_fund_status(): void {
        $fund_id = isset($_POST['fund_id']) ? (int)$_POST['fund_id'] : 0;
        $new_status = isset($_POST['new_status']) ? sanitize_key($_POST['new_status']) : '';
        check_admin_referer('lfa_set_fund_status_' . $fund_id);

        $res = LFA_Donation::set_fund_status($fund_id, $new_status, get_current_user_id());
        wp_safe_redirect(admin_url('users.php?page=lfa-giving-funds&notice=' . ($res['success'] ? 'status_updated' : 'error')));
        exit;
    }

    public static function handle_save_project(): void {
        if (!current_user_can(LFA_Donation::CAP_MANAGE_DONATIONS)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }
        check_admin_referer('lfa_save_giving_project_action', 'lfa_project_nonce');

        $res = LFA_Donation::save_project($_POST, get_current_user_id());
        wp_safe_redirect(admin_url('users.php?page=lfa-giving-funds&notice=' . ($res['success'] ? 'project_saved' : 'error')));
        exit;
    }

    public static function handle_set_project_status(): void {
        $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
        $new_status = isset($_POST['new_status']) ? sanitize_key($_POST['new_status']) : '';
        check_admin_referer('lfa_set_project_status_' . $project_id);

        $res = LFA_Donation::set_project_status($project_id, $new_status, get_current_user_id());
        wp_safe_redirect(admin_url('users.php?page=lfa-giving-funds&notice=' . ($res['success'] ? 'status_updated' : 'error')));
        exit;
    }

    /**
     * CSV Export with Formula Neutralization (GIV-013)
     */
    public static function handle_export_csv(): void {
        if (!current_user_can(LFA_Donation::CAP_EXPORT_FINANCIALS)) {
            wp_die('Unauthorized: Insufficient permissions to export financial records.', 'Forbidden', ['response' => 403]);
        }
        check_admin_referer('lfa_export_donations_csv_action', 'lfa_export_nonce');

        $filter_status = isset($_POST['status']) ? sanitize_key($_POST['status']) : '';
        $filter_fund = isset($_POST['fund_id']) ? (int)$_POST['fund_id'] : 0;

        $filters = [];
        if ($filter_status) $filters['status'] = $filter_status;
        if ($filter_fund) $filters['fund_id'] = $filter_fund;

        $donations = LFA_Donation::get_all_donations($filters);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="lfa-donations-export-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Donation Reference',
            'Date Created',
            'Date Paid',
            'Donor Display Name',
            'Donor Email',
            'Is Anonymous',
            'Fund/Cause Purpose',
            'Amount Cents',
            'Amount Dollars USD',
            'Status',
            'PayPal Capture ID',
            'PayPal Order ID',
        ]);

        foreach ($donations as $d) {
            fputcsv($out, [
                LFA_Donation::neutralize_csv_cell((string)$d->donation_ref),
                LFA_Donation::neutralize_csv_cell((string)$d->created_at),
                LFA_Donation::neutralize_csv_cell((string)($d->paid_at ?: '')),
                LFA_Donation::neutralize_csv_cell((string)$d->donor_display_name),
                LFA_Donation::neutralize_csv_cell((string)$d->donor_email),
                (int)$d->is_anonymous === 1 ? 'Yes' : 'No',
                LFA_Donation::neutralize_csv_cell((string)$d->purpose_snapshot),
                (int)$d->amount_cents,
                number_format($d->amount_cents / 100, 2),
                LFA_Donation::neutralize_csv_cell((string)$d->status),
                LFA_Donation::neutralize_csv_cell((string)($d->paypal_capture_id ?: '')),
                LFA_Donation::neutralize_csv_cell((string)($d->paypal_order_id ?: '')),
            ]);
        }

        fclose($out);
        exit;
    }
}
