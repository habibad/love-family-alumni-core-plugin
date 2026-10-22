<?php
/**
 * Love Family Alumni — Business Directory Admin Portal
 *
 * Implements WordPress Admin moderation screens, approval/rejection actions,
 * status filters, and protected CSV exports.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Business_Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_admin_menus']);
        add_action('admin_post_lfa_review_business', [__CLASS__, 'handle_admin_review']);
        add_action('admin_post_lfa_export_businesses', [__CLASS__, 'handle_export_csv']);
        add_action('admin_post_lfa_business_logo', ['LFA_Business', 'serve_logo']);
        add_action('admin_post_nopriv_lfa_business_logo', ['LFA_Business', 'serve_logo']);
    }

    public static function register_admin_menus(): void {
        // Main business directory menu
        add_submenu_page(
            'users.php',
            'Alumni Business Directory',
            'Business Directory',
            LFA_Business::CAP_MANAGE,
            'lfa-businesses',
            [__CLASS__, 'render_all_businesses_page']
        );

        // Pending review queue
        add_submenu_page(
            'users.php',
            'Pending Business Reviews',
            'Business Moderation Queue',
            LFA_Business::CAP_MANAGE,
            'lfa-business-pending',
            [__CLASS__, 'render_pending_queue_page']
        );

        // Moderation Audit Log
        add_submenu_page(
            'users.php',
            'Business Moderation Audit',
            'Business Audit Log',
            LFA_Business::CAP_MANAGE,
            'lfa-business-audit',
            [__CLASS__, 'render_audit_log_page']
        );
    }

    /**
     * Handle moderation action: Approve / Reject / Archive
     */
    public static function handle_admin_review(): void {
        if (!current_user_can(LFA_Business::CAP_MANAGE)) {
            wp_die('Unauthorized: You do not have permission to moderate businesses.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_admin_review_action', '_lfa_review_nonce');

        $business_id = isset($_POST['business_id']) ? absint($_POST['business_id']) : 0;
        $action      = isset($_POST['review_action']) ? sanitize_key($_POST['review_action']) : '';
        $notes       = isset($_POST['moderation_notes']) ? sanitize_textarea_field(wp_unslash($_POST['moderation_notes'])) : '';

        $res = LFA_Business::review_business($business_id, $action, $notes, get_current_user_id());

        $redirect = wp_get_referer() ?: admin_url('users.php?page=lfa-businesses');
        if ($res['success']) {
            $redirect = add_query_arg('msg', $action . '_success', $redirect);
        } else {
            $redirect = add_query_arg(['msg' => 'error', 'err' => urlencode($res['error'])], $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Export CSV
     */
    public static function handle_export_csv(): void {
        LFA_Business::export_csv();
    }

    /**
     * Render All Businesses Page with status tabs & search
     */
    public static function render_all_businesses_page(): void {
        global $wpdb;
        $t = LFA_Business::table_businesses();

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $s      = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $where = ['1=1'];
        $params = [];

        if (!empty($status)) {
            $where[] = "status = %s";
            $params[] = $status;
        }

        if (!empty($s)) {
            $kw = '%' . $wpdb->esc_like($s) . '%';
            $where[] = "(name LIKE %s OR category LIKE %s OR business_ref LIKE %s OR city LIKE %s)";
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }

        $where_sql = implode(' AND ', $where);
        $total = (int) $wpdb->get_var(!empty($params) ? $wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$where_sql}", ...$params) : "SELECT COUNT(*) FROM {$t} WHERE {$where_sql}");

        $page     = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per_page = 20;
        $offset   = ($page - 1) * $per_page;

        $paged_params = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d", ...$paged_params));

        // Counts per status
        $cnt_pending  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status = 'pending_review'");
        $cnt_approved = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status = 'approved'");
        $cnt_rejected = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status = 'rejected'");
        $cnt_draft    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status = 'draft'");
        $cnt_archived = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status = 'archived'");
        $cnt_all      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Alumni Business Directory Management</h1>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lfa_export_businesses'), 'lfa_export_businesses_action', '_lfa_export_nonce')); ?>" class="page-title-action button-primary">Export CSV</a>
            <hr class="wp-header-end">

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'approve_success') : ?>
                <div class="notice notice-success is-dismissible"><p><strong>Listing successfully approved and published!</strong></p></div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'reject_success') : ?>
                <div class="notice notice-warning is-dismissible"><p><strong>Listing has been rejected and returned to owner with feedback.</strong></p></div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'error') : ?>
                <div class="notice notice-error is-dismissible"><p><strong>Error:</strong> <?php echo esc_html(urldecode($_GET['err'] ?? 'Operation failed.')); ?></p></div>
            <?php endif; ?>

            <ul class="subsubsub">
                <li><a href="<?php echo admin_url('users.php?page=lfa-businesses'); ?>" class="<?php echo empty($status) ? 'current' : ''; ?>">All <span class="count">(<?php echo $cnt_all; ?>)</span></a> |</li>
                <li><a href="<?php echo admin_url('users.php?page=lfa-businesses&status=pending_review'); ?>" class="<?php echo $status === 'pending_review' ? 'current' : ''; ?>">Pending Review <span class="count">(<?php echo $cnt_pending; ?>)</span></a> |</li>
                <li><a href="<?php echo admin_url('users.php?page=lfa-businesses&status=approved'); ?>" class="<?php echo $status === 'approved' ? 'current' : ''; ?>">Approved <span class="count">(<?php echo $cnt_approved; ?>)</span></a> |</li>
                <li><a href="<?php echo admin_url('users.php?page=lfa-businesses&status=rejected'); ?>" class="<?php echo $status === 'rejected' ? 'current' : ''; ?>">Rejected <span class="count">(<?php echo $cnt_rejected; ?>)</span></a> |</li>
                <li><a href="<?php echo admin_url('users.php?page=lfa-businesses&status=draft'); ?>" class="<?php echo $status === 'draft' ? 'current' : ''; ?>">Drafts <span class="count">(<?php echo $cnt_draft; ?>)</span></a> |</li>
                <li><a href="<?php echo admin_url('users.php?page=lfa-businesses&status=archived'); ?>" class="<?php echo $status === 'archived' ? 'current' : ''; ?>">Archived <span class="count">(<?php echo $cnt_archived; ?>)</span></a></li>
            </ul>

            <form method="get" style="margin-bottom:15px;clear:both;">
                <input type="hidden" name="page" value="lfa-businesses">
                <?php if ($status) : ?><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>"><?php endif; ?>
                <p class="search-box">
                    <label class="screen-reader-text" for="post-search-input">Search Businesses:</label>
                    <input type="search" id="post-search-input" name="s" value="<?php echo esc_attr($s); ?>">
                    <input type="submit" id="search-submit" class="button" value="Search Listings">
                </p>
            </form>

            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th style="width:110px;">Ref ID</th>
                        <th>Business Name</th>
                        <th>Category</th>
                        <th>Owner</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Consent</th>
                        <th>Revision</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="9" style="text-align:center;padding:24px;">No business listings found matching criteria.</td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $r) :
                            $owner = get_userdata((int)$r->owner_user_id);
                            $owner_name = $owner ? $owner->display_name : 'User #' . $r->owner_user_id;
                            $badge_style = match ($r->status) {
                                'approved'       => 'background:#dcfce7;color:#166534;padding:3px 8px;border-radius:4px;font-weight:700;',
                                'pending_review' => 'background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:4px;font-weight:700;',
                                'rejected'       => 'background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:4px;font-weight:700;',
                                'archived'       => 'background:#f1f5f9;color:#64748b;padding:3px 8px;border-radius:4px;font-weight:700;',
                                default          => 'background:#e2e8f0;color:#334155;padding:3px 8px;border-radius:4px;',
                            };
                        ?>
                            <tr>
                                <td><code><?php echo esc_html($r->business_ref); ?></code></td>
                                <td>
                                    <strong><?php echo esc_html($r->name); ?></strong>
                                    <?php if (!empty($r->pending_changes_json)) : ?>
                                        <br><span style="color:#b45309;font-size:11px;font-weight:600;">(Revisions Pending Review)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($r->category); ?></td>
                                <td><?php echo esc_html($owner_name); ?></td>
                                <td><?php echo esc_html($r->city . ', ' . $r->country); ?></td>
                                <td><span style="<?php echo $badge_style; ?>"><?php echo esc_html(ucwords(str_replace('_', ' ', $r->status))); ?></span></td>
                                <td><?php echo $r->contact_consent ? '✓ Opted In' : 'Private'; ?></td>
                                <td>v<?php echo esc_html($r->revision_version); ?></td>
                                <td>
                                    <?php if ($r->status === 'pending_review' || !empty($r->pending_changes_json)) : ?>
                                        <button type="button" class="button button-small button-primary" onclick="openReviewModal(<?php echo (int)$r->id; ?>, '<?php echo esc_js($r->name); ?>')">Review / Approve</button>
                                    <?php elseif ($r->status === 'approved') : ?>
                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                            <?php wp_nonce_field('lfa_admin_review_action', '_lfa_review_nonce'); ?>
                                            <input type="hidden" name="action" value="lfa_review_business">
                                            <input type="hidden" name="business_id" value="<?php echo (int)$r->id; ?>">
                                            <input type="hidden" name="review_action" value="archive">
                                            <input type="submit" class="button button-small" value="Archive" onclick="return confirm('Archive this business listing?')">
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Moderation Modal Dialog -->
        <div id="lfa-review-dialog" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:999999;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:8px;padding:24px;width:520px;max-width:90%;">
                <h2 id="modal-biz-title" style="margin-top:0;">Moderate Listing</h2>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('lfa_admin_review_action', '_lfa_review_nonce'); ?>
                    <input type="hidden" name="action" value="lfa_review_business">
                    <input type="hidden" name="business_id" id="modal-biz-id" value="">
                    
                    <p>
                        <label for="modal-review-action"><strong>Moderation Decision:</strong></label><br>
                        <select name="review_action" id="modal-review-action" style="width:100%;margin-top:4px;" required>
                            <option value="approve">✓ Approve &amp; Publish</option>
                            <option value="reject">✗ Reject &amp; Request Revisions</option>
                            <option value="archive">⚑ Archive Listing</option>
                        </select>
                    </p>

                    <p>
                        <label for="modal-notes"><strong>Moderator Feedback / Reason (Required for Rejection):</strong></label><br>
                        <textarea name="moderation_notes" id="modal-notes" rows="4" style="width:100%;margin-top:4px;" placeholder="Provide clear feedback for the alumnus..."></textarea>
                    </p>

                    <div style="text-align:right;margin-top:16px;">
                        <button type="button" class="button" onclick="closeReviewModal()">Cancel</button>
                        <button type="submit" class="button button-primary">Confirm Decision</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        function openReviewModal(id, name) {
            document.getElementById('modal-biz-id').value = id;
            document.getElementById('modal-biz-title').innerText = 'Moderate: ' + name;
            document.getElementById('lfa-review-dialog').style.display = 'flex';
        }
        function closeReviewModal() {
            document.getElementById('lfa-review-dialog').style.display = 'none';
        }
        </script>
        <?php
    }

    /**
     * Render Dedicated Pending Queue Page
     */
    public static function render_pending_queue_page(): void {
        $_GET['status'] = 'pending_review';
        self::render_all_businesses_page();
    }

    /**
     * Render Audit Log Page
     */
    public static function render_audit_log_page(): void {
        global $wpdb;
        $t = LFA_Business::table_audit();
        $tb = LFA_Business::table_businesses();

        $rows = $wpdb->get_results("
            SELECT a.*, b.name as business_name, b.business_ref 
            FROM {$t} a 
            LEFT JOIN {$tb} b ON a.business_id = b.id 
            ORDER BY a.id DESC 
            LIMIT 100
        ");

        ?>
        <div class="wrap">
            <h1>Business Moderation Audit Log</h1>
            <p>Immutable history of business submissions, reviews, status transitions, and administrative actions.</p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Business</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Transition</th>
                        <th>Notes</th>
                        <th>Timestamp (UTC)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="7">No audit logs recorded yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $r) :
                            $actor = get_userdata((int)$r->actor_id);
                            $actor_name = $actor ? $actor->display_name : ($r->actor_id > 0 ? 'User #' . $r->actor_id : 'System');
                        ?>
                            <tr>
                                <td>#<?php echo (int)$r->id; ?></td>
                                <td>
                                    <strong><?php echo esc_html($r->business_name ?: 'Listing #' . $r->business_id); ?></strong>
                                    <?php if ($r->business_ref) : ?><br><code><?php echo esc_html($r->business_ref); ?></code><?php endif; ?>
                                </td>
                                <td><?php echo esc_html($actor_name); ?> (<?php echo esc_html($r->actor_role); ?>)</td>
                                <td><code><?php echo esc_html($r->action); ?></code></td>
                                <td><?php echo esc_html(($r->old_status ?: 'none') . ' → ' . ($r->new_status ?: 'none')); ?></td>
                                <td><?php echo esc_html($r->note ?: '—'); ?></td>
                                <td><?php echo esc_html($r->created_at); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
