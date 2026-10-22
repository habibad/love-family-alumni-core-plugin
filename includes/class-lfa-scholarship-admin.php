<?php
/**
 * Love Family Alumni — Scholarship Administrative Management Screens
 *
 * Implements protected WordPress admin screens for Scholarship Programs,
 * Application Review Queue, Detailed Review & Notes, Decisioning, Audit Trail,
 * and Formula-Neutralized CSV Export.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Scholarship_Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_admin_menus'], 28);
        add_action('admin_post_lfa_save_scholarship_program', [__CLASS__, 'handle_save_program']);
        add_action('admin_post_lfa_publish_scholarship_program', [__CLASS__, 'handle_publish_program']);
        add_action('admin_post_lfa_review_scholarship_application', [__CLASS__, 'handle_review_action']);
        add_action('admin_post_lfa_decision_scholarship_application', [__CLASS__, 'handle_decision_action']);
        add_action('admin_post_lfa_assign_scholarship_reviewer', [__CLASS__, 'handle_assign_reviewer']);
        add_action('admin_post_lfa_publish_scholarship_recipient', [__CLASS__, 'handle_publish_recipient']);
        add_action('admin_post_lfa_export_scholarships', [__CLASS__, 'handle_export_csv']);
    }

    public static function register_admin_menus(): void {
        $parent = 'users.php';

        // 1. Scholarship Programs
        add_submenu_page(
            $parent,
            'Scholarship Programs',
            'Scholarship Programs',
            LFA_Scholarship::CAP_MANAGE_SCHOLARSHIPS,
            'lfa-scholarship-programs',
            [__CLASS__, 'render_programs_page']
        );

        // 2. Scholarship Applications Queue (Reviewers & Admins)
        add_submenu_page(
            $parent,
            'Scholarship Applications',
            'Scholarship Applications',
            LFA_Scholarship::CAP_REVIEW_SCHOLARSHIPS,
            'lfa-scholarship-applications',
            [__CLASS__, 'render_applications_page']
        );

        // 3. Application Detail Screen (Submenu hidden from direct nav bar if needed, but accessible via link)
        add_submenu_page(
            null,
            'Application Review Detail',
            'Application Review Detail',
            LFA_Scholarship::CAP_REVIEW_SCHOLARSHIPS,
            'lfa-scholarship-detail',
            [__CLASS__, 'render_detail_page']
        );

        // 4. Scholarship Audit Log
        add_submenu_page(
            $parent,
            'Scholarship Audit',
            'Scholarship Audit',
            LFA_Scholarship::CAP_MANAGE_SCHOLARSHIPS,
            'lfa-scholarship-audit',
            [__CLASS__, 'render_audit_page']
        );

        // 5. Scholarship Recipients
        add_submenu_page(
            $parent,
            'Scholarship Recipients',
            'Scholarship Recipients',
            LFA_Scholarship::CAP_PUBLISH_RECIPIENTS,
            'lfa-scholarship-recipients',
            [__CLASS__, 'render_recipients_page']
        );
    }

    /**
     * Handle Save / Edit Program POST
     */
    public static function handle_save_program(): void {
        if (!current_user_can(LFA_Scholarship::CAP_MANAGE_SCHOLARSHIPS)) {
            wp_die('Unauthorized: You do not have permission to manage scholarship programs.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_save_scholarship_program_action', 'lfa_prog_nonce');

        global $wpdb;
        $t = LFA_Scholarship::table_programs();
        $prog_id = (int)($_POST['program_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        $slug = sanitize_title($_POST['slug'] ?? $title);
        $purpose = sanitize_textarea_field($_POST['purpose'] ?? '');
        $eligibility = sanitize_textarea_field($_POST['eligibility_text'] ?? '');
        $instructions = sanitize_textarea_field($_POST['instructions'] ?? '');
        $start = !empty($_POST['application_window_start']) ? sanitize_text_field($_POST['application_window_start']) : null;
        $end = !empty($_POST['application_window_end']) ? sanitize_text_field($_POST['application_window_end']) : null;
        $tz = sanitize_text_field($_POST['timezone'] ?? 'America/New_York');
        $status = sanitize_key($_POST['status'] ?? LFA_Scholarship::PROGRAM_DRAFT);
        $guest_allowed = !empty($_POST['guest_application_enabled']) ? 1 : 0;

        if (empty($title) || empty($slug)) {
            wp_die('Program title and slug are required.');
        }

        $now = current_time('mysql', true);
        if ($prog_id > 0) {
            $wpdb->update($t, [
                'title'                    => $title,
                'slug'                     => $slug,
                'purpose'                  => $purpose,
                'eligibility_text'         => $eligibility,
                'instructions'             => $instructions,
                'application_window_start' => $start,
                'application_window_end'   => $end,
                'timezone'                 => $tz,
                'status'                   => $status,
                'guest_application_enabled'=> $guest_allowed,
                'updated_at'               => $now,
            ], ['id' => $prog_id]);

            LFA_Scholarship::log_audit(null, get_current_user_id(), 'admin', 'program_updated', null, $status, 'Updated program ID: ' . $prog_id);
        } else {
            $wpdb->insert($t, [
                'title'                    => $title,
                'slug'                     => $slug,
                'purpose'                  => $purpose,
                'eligibility_text'         => $eligibility,
                'instructions'             => $instructions,
                'application_window_start' => $start,
                'application_window_end'   => $end,
                'timezone'                 => $tz,
                'status'                   => $status,
                'guest_application_enabled'=> $guest_allowed,
                'created_by'               => get_current_user_id(),
                'created_at'               => $now,
                'updated_at'               => $now,
            ]);
            $prog_id = (int)$wpdb->insert_id;
            LFA_Scholarship::log_audit(null, get_current_user_id(), 'admin', 'program_created', null, $status, 'Created program ID: ' . $prog_id);
        }

        wp_safe_redirect(add_query_arg(['page' => 'lfa-scholarship-programs', 'msg' => 'program_saved'], admin_url('users.php')));
        exit;
    }

    /**
     * Handle Review Action (Add Review Notes / Request Info / Shortlist)
     */
    public static function handle_review_action(): void {
        if (!current_user_can(LFA_Scholarship::CAP_REVIEW_SCHOLARSHIPS)) {
            wp_die('Unauthorized: Review permissions required.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_review_scholarship_action', 'lfa_review_nonce');

        $app_id = (int)($_POST['application_id'] ?? 0);
        $review_state = sanitize_key($_POST['review_state'] ?? 'under_review');
        $notes = sanitize_textarea_field($_POST['private_notes'] ?? '');
        $applicant_feedback = sanitize_textarea_field($_POST['applicant_feedback'] ?? '');

        $res = LFA_Scholarship::add_review_notes($app_id, get_current_user_id(), $review_state, $notes, $applicant_feedback);

        $redirect = admin_url('users.php?page=lfa-scholarship-detail&id=' . $app_id);
        if ($res['success']) {
            $redirect = add_query_arg('msg', 'review_saved', $redirect);
        } else {
            $redirect = add_query_arg(['msg' => 'error', 'err' => urlencode($res['message'])], $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Handle Final Decision Action (Award / Decline) - Admin Only
     */
    public static function handle_decision_action(): void {
        if (!current_user_can(LFA_Scholarship::CAP_MANAGE_SCHOLARSHIPS)) {
            wp_die('Unauthorized: Only scholarship administrators can record decisions.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_decision_scholarship_action', 'lfa_decision_nonce');

        $app_id = (int)($_POST['application_id'] ?? 0);
        $decision = sanitize_key($_POST['decision'] ?? '');
        $notes = sanitize_textarea_field($_POST['decision_notes'] ?? '');

        $res = LFA_Scholarship::record_decision($app_id, $decision, $notes, get_current_user_id());

        $redirect = admin_url('users.php?page=lfa-scholarship-detail&id=' . $app_id);
        if ($res['success']) {
            $redirect = add_query_arg('msg', 'decision_saved', $redirect);
        } else {
            $redirect = add_query_arg(['msg' => 'error', 'err' => urlencode($res['message'])], $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Handle Assign Reviewer Action - Admin Only
     */
    public static function handle_assign_reviewer(): void {
        if (!current_user_can(LFA_Scholarship::CAP_MANAGE_SCHOLARSHIPS)) {
            wp_die('Unauthorized: Only scholarship administrators can assign reviewers.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_assign_reviewer_action', 'lfa_assign_nonce');

        $app_id = (int)($_POST['application_id'] ?? 0);
        $reviewer_id = (int)($_POST['reviewer_id'] ?? 0);

        $res = LFA_Scholarship::assign_reviewer($app_id, $reviewer_id, get_current_user_id());

        $redirect = admin_url('users.php?page=lfa-scholarship-detail&id=' . $app_id);
        if ($res['success']) {
            $redirect = add_query_arg('msg', 'reviewer_assigned', $redirect);
        } else {
            $redirect = add_query_arg(['msg' => 'error', 'err' => urlencode($res['message'])], $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Handle Publish Recipient Action
     */
    public static function handle_publish_recipient(): void {
        if (!current_user_can(LFA_Scholarship::CAP_PUBLISH_RECIPIENTS)) {
            wp_die('Unauthorized: Publishing permission required.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_publish_recipient_action', 'lfa_pub_rec_nonce');

        $app_id = (int)($_POST['application_id'] ?? 0);
        $consent = !empty($_POST['consent_given']) ? 1 : 0;
        $public_name = sanitize_text_field($_POST['public_name'] ?? '');
        $academic_year = sanitize_text_field($_POST['academic_year'] ?? date('Y'));
        $institution = sanitize_text_field($_POST['institution'] ?? '');
        $award_details = sanitize_textarea_field($_POST['award_details'] ?? '');
        $photo_url = esc_url_raw($_POST['photo_url'] ?? '');

        $res = LFA_Scholarship::publish_recipient($app_id, [
            'consent_given' => $consent,
            'public_name'   => $public_name,
            'academic_year' => $academic_year,
            'institution'   => $institution,
            'award_details' => $award_details,
            'photo_url'     => $photo_url,
        ], get_current_user_id());

        $redirect = admin_url('users.php?page=lfa-scholarship-recipients');
        if ($res['success']) {
            $redirect = add_query_arg('msg', 'recipient_published', $redirect);
        } else {
            $redirect = add_query_arg(['msg' => 'error', 'err' => urlencode($res['message'])], $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * CSV Export with Formula Neutralization (Prefix =, +, -, @, \t, \r)
     */
    public static function handle_export_csv(): void {
        if (!current_user_can(LFA_Scholarship::CAP_EXPORT_SCHOLARSHIPS)) {
            wp_die('Unauthorized: You do not have permission to export scholarship data.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_export_scholarships_action', 'lfa_export_nonce');

        global $wpdb;
        $t_app = LFA_Scholarship::table_applications();
        $t_prog = LFA_Scholarship::table_programs();

        $rows = $wpdb->get_results(
            "SELECT a.application_ref, p.title as program_name, a.applicant_name, a.applicant_email, a.school_name,
                    a.program_of_study, a.status, a.submitted_at, a.created_at
             FROM {$t_app} a
             INNER JOIN {$t_prog} p ON a.program_id = p.id
             ORDER BY a.id DESC"
        );

        if (ob_get_level()) { ob_end_clean(); }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="lfa_scholarship_export_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // CSV Header
        fputcsv($out, ['Application Ref', 'Program', 'Applicant Name', 'Email', 'School / Institution', 'Program of Study', 'Status', 'Submitted At', 'Created At']);

        foreach ($rows as $r) {
            fputcsv($out, [
                self::neutralize_csv($r->application_ref),
                self::neutralize_csv($r->program_name),
                self::neutralize_csv($r->applicant_name),
                self::neutralize_csv($r->applicant_email),
                self::neutralize_csv($r->school_name),
                self::neutralize_csv($r->program_of_study),
                self::neutralize_csv($r->status),
                self::neutralize_csv($r->submitted_at ?: 'N/A'),
                self::neutralize_csv($r->created_at),
            ]);
        }

        fclose($out);
        exit;
    }

    private static function neutralize_csv(?string $val): string {
        if ($val === null || $val === '') {
            return '';
        }
        $val = (string)$val;
        $first = $val[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $val;
        }
        return $val;
    }

    /* Admin Renderers */
    public static function render_programs_page(): void {
        $programs = LFA_Scholarship::get_all_programs();
        $edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
        $edit_program = $edit_id > 0 ? LFA_Scholarship::get_program($edit_id) : null;
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Scholarship Programs Management</h1>
            <hr class="wp-header-end">

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'program_saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Scholarship program configuration saved successfully.</p></div>
            <?php endif; ?>

            <div style="display:flex; gap:24px; margin-top:20px; flex-wrap:wrap;">
                <!-- Programs Table -->
                <div style="flex:1; min-width:500px;">
                    <div class="postbox" style="padding:16px;">
                        <h2>Configured Programs</h2>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Slug</th>
                                    <th>Status</th>
                                    <th>Window (Start &ndash; End)</th>
                                    <th>Guest Apply</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($programs)): ?>
                                    <tr><td colspan="7">No programs found.</td></tr>
                                <?php else: foreach ($programs as $p): ?>
                                    <tr>
                                        <td><?php echo (int)$p->id; ?></td>
                                        <td><strong><?php echo esc_html($p->title); ?></strong></td>
                                        <td><code><?php echo esc_html($p->slug); ?></code></td>
                                        <td>
                                            <span class="badge" style="background:#<?php echo $p->status === 'open' ? '46b450' : ($p->status === 'closed' ? 'dc3232' : '72777c'); ?>; color:#fff; padding:3px 8px; border-radius:3px; font-size:11px; font-weight:600;">
                                                <?php echo esc_html(strtoupper($p->status)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($p->application_window_start) && !empty($p->application_window_end)): ?>
                                                <?php echo esc_html($p->application_window_start . ' to ' . $p->application_window_end); ?>
                                            <?php else: ?>
                                                <em>Schedule TBA (Closed)</em>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $p->guest_application_enabled ? 'Allowed' : 'Members Only'; ?></td>
                                        <td>
                                            <a href="<?php echo esc_url(admin_url('users.php?page=lfa-scholarship-programs&edit=' . $p->id)); ?>" class="button button-small">Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Program Edit Form -->
                <div style="width:420px; min-width:320px;">
                    <div class="postbox" style="padding:20px;">
                        <h2><?php echo $edit_program ? 'Edit Program' : 'Create New Program'; ?></h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="lfa_save_scholarship_program">
                            <input type="hidden" name="program_id" value="<?php echo $edit_program ? (int)$edit_program->id : 0; ?>">
                            <?php wp_nonce_field('lfa_save_scholarship_program_action', 'lfa_prog_nonce'); ?>

                            <p>
                                <label for="title"><strong>Program Title:</strong></label><br>
                                <input type="text" name="title" id="title" class="widefat" value="<?php echo esc_attr($edit_program->title ?? ''); ?>" required>
                            </p>
                            <p>
                                <label for="slug"><strong>Slug:</strong></label><br>
                                <input type="text" name="slug" id="slug" class="widefat" value="<?php echo esc_attr($edit_program->slug ?? ''); ?>" required>
                            </p>
                            <p>
                                <label for="purpose"><strong>Purpose:</strong></label><br>
                                <textarea name="purpose" id="purpose" class="widefat" rows="3" required><?php echo esc_textarea($edit_program->purpose ?? ''); ?></textarea>
                            </p>
                            <p>
                                <label for="eligibility_text"><strong>Eligibility Criteria (Leave blank if TBA):</strong></label><br>
                                <textarea name="eligibility_text" id="eligibility_text" class="widefat" rows="3"><?php echo esc_textarea($edit_program->eligibility_text ?? ''); ?></textarea>
                            </p>
                            <p>
                                <label for="instructions"><strong>Instructions:</strong></label><br>
                                <textarea name="instructions" id="instructions" class="widefat" rows="2"><?php echo esc_textarea($edit_program->instructions ?? ''); ?></textarea>
                            </p>
                            <p>
                                <label for="status"><strong>Program Status:</strong></label><br>
                                <select name="status" id="status" class="widefat">
                                    <option value="draft" <?php selected($edit_program->status ?? 'closed', 'draft'); ?>>Draft</option>
                                    <option value="published" <?php selected($edit_program->status ?? 'closed', 'published'); ?>>Published (Info visible, Submissions closed)</option>
                                    <option value="open" <?php selected($edit_program->status ?? 'closed', 'open'); ?>>Open (Accepting Submissions within Window)</option>
                                    <option value="closed" <?php selected($edit_program->status ?? 'closed', 'closed'); ?>>Closed</option>
                                    <option value="archived" <?php selected($edit_program->status ?? 'closed', 'archived'); ?>>Archived</option>
                                </select>
                            </p>
                            <p>
                                <label for="application_window_start"><strong>Window Start (YYYY-MM-DD HH:MM:SS):</strong></label><br>
                                <input type="text" name="application_window_start" id="application_window_start" class="widefat" value="<?php echo esc_attr($edit_program->application_window_start ?? ''); ?>" placeholder="e.g. 2027-01-01 09:00:00">
                            </p>
                            <p>
                                <label for="application_window_end"><strong>Window End (YYYY-MM-DD HH:MM:SS):</strong></label><br>
                                <input type="text" name="application_window_end" id="application_window_end" class="widefat" value="<?php echo esc_attr($edit_program->application_window_end ?? ''); ?>" placeholder="e.g. 2027-03-31 18:00:00">
                            </p>
                            <p>
                                <label for="timezone"><strong>Timezone:</strong></label><br>
                                <input type="text" name="timezone" id="timezone" class="widefat" value="<?php echo esc_attr($edit_program->timezone ?? 'America/New_York'); ?>">
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="guest_application_enabled" value="1" <?php checked(!empty($edit_program->guest_application_enabled)); ?>>
                                    Allow Guest Submissions (Default is Members Only)
                                </label>
                            </p>
                            <p>
                                <button type="submit" class="button button-primary button-large">Save Program Configuration</button>
                                <?php if ($edit_program): ?>
                                    <a href="<?php echo esc_url(admin_url('users.php?page=lfa-scholarship-programs')); ?>" class="button">Cancel</a>
                                <?php endif; ?>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function render_applications_page(): void {
        global $wpdb;
        $t_app = LFA_Scholarship::table_applications();
        $t_prog = LFA_Scholarship::table_programs();

        $status_filter = sanitize_key($_GET['status'] ?? '');
        $search = sanitize_text_field($_GET['s'] ?? '');
        $current_user_id = get_current_user_id();
        $is_admin = current_user_can(LFA_Scholarship::CAP_MANAGE_SCHOLARSHIPS);

        $where = ['1=1'];
        $params = [];

        // If reviewer only (not admin), show only assigned applications
        if (!$is_admin) {
            $where[] = "a.assigned_reviewer_id = %d";
            $params[] = $current_user_id;
        }

        if (!empty($status_filter)) {
            $where[] = "a.status = %s";
            $params[] = $status_filter;
        }

        if (!empty($search)) {
            $kw = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(a.application_ref LIKE %s OR a.applicant_name LIKE %s OR a.applicant_email LIKE %s OR a.school_name LIKE %s)";
            $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
        }

        $where_sql = implode(' AND ', $where);
        $query = "SELECT a.*, p.title as program_title
                  FROM {$t_app} a
                  INNER JOIN {$t_prog} p ON a.program_id = p.id
                  WHERE {$where_sql}
                  ORDER BY a.id DESC";

        $applications = !empty($params) ? $wpdb->get_results($wpdb->prepare($query, ...$params)) : $wpdb->get_results($query);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Scholarship Applications Review Queue</h1>
            <?php if ($is_admin): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left:15px;">
                    <input type="hidden" name="action" value="lfa_export_scholarships">
                    <?php wp_nonce_field('lfa_export_scholarships_action', 'lfa_export_nonce'); ?>
                    <button type="submit" class="button button-secondary">Export Applications (CSV)</button>
                </form>
            <?php endif; ?>
            <hr class="wp-header-end">

            <!-- Filter Tabs -->
            <ul class="subsubsub">
                <li><a href="<?php echo esc_url(admin_url('users.php?page=lfa-scholarship-applications')); ?>" class="<?php echo empty($status_filter) ? 'current' : ''; ?>">All</a> |</li>
                <li><a href="<?php echo esc_url(admin_url('users.php?page=lfa-scholarship-applications&status=submitted')); ?>" class="<?php echo $status_filter === 'submitted' ? 'current' : ''; ?>">Submitted</a> |</li>
                <li><a href="<?php echo esc_url(admin_url('users.php?page=lfa-scholarship-applications&status=under_review')); ?>" class="<?php echo $status_filter === 'under_review' ? 'current' : ''; ?>">Under Review</a> |</li>
                <li><a href="<?php echo esc_url(admin_url('users.php?page=lfa-scholarship-applications&status=shortlisted')); ?>" class="<?php echo $status_filter === 'shortlisted' ? 'current' : ''; ?>">Shortlisted</a> |</li>
                <li><a href="<?php echo esc_url(admin_url('users.php?page=lfa-scholarship-applications&status=awarded')); ?>" class="<?php echo $status_filter === 'awarded' ? 'current' : ''; ?>">Awarded</a> |</li>
                <li><a href="<?php echo esc_url(admin_url('users.php?page=lfa-scholarship-applications&status=declined')); ?>" class="<?php echo $status_filter === 'declined' ? 'current' : ''; ?>">Declined</a></li>
            </ul>

            <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th style="width:140px;">Ref #</th>
                        <th>Applicant</th>
                        <th>Program</th>
                        <th>School / Program of Study</th>
                        <th style="width:110px;">Status</th>
                        <th style="width:120px;">Assigned To</th>
                        <th style="width:140px;">Submitted</th>
                        <th style="width:110px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applications)): ?>
                        <tr><td colspan="8">No scholarship applications found in this view.</td></tr>
                    <?php else: foreach ($applications as $app): ?>
                        <tr>
                            <td><strong><?php echo esc_html($app->application_ref); ?></strong></td>
                            <td>
                                <strong><?php echo esc_html($app->applicant_name); ?></strong><br>
                                <small><?php echo esc_html($app->applicant_email); ?></small>
                            </td>
                            <td><?php echo esc_html($app->program_title); ?></td>
                            <td>
                                <?php echo esc_html($app->school_name); ?><br>
                                <small><?php echo esc_html($app->program_of_study); ?></small>
                            </td>
                            <td>
                                <span class="badge" style="background:#<?php
                                    echo match($app->status) {
                                        'awarded' => '46b450',
                                        'shortlisted' => '0073aa',
                                        'under_review' => 'f56e28',
                                        'submitted' => '826eb4',
                                        'declined' => 'dc3232',
                                        default => '72777c',
                                    }; ?>; color:#fff; padding:3px 7px; border-radius:3px; font-size:11px; font-weight:600;">
                                    <?php echo esc_html(strtoupper(str_replace('_', ' ', $app->status))); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                if (!empty($app->assigned_reviewer_id)) {
                                    $reviewer = get_user_by('id', $app->assigned_reviewer_id);
                                    echo esc_html($reviewer ? $reviewer->display_name : 'User #' . $app->assigned_reviewer_id);
                                } else {
                                    echo '<em>Unassigned</em>';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html($app->submitted_at ? substr($app->submitted_at, 0, 16) : 'Draft'); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('users.php?page=lfa-scholarship-detail&id=' . $app->id)); ?>" class="button button-small button-primary">View & Review</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function render_detail_page(): void {
        $app_id = (int)($_GET['id'] ?? 0);
        $app = LFA_Scholarship::get_application($app_id);
        if (!$app) {
            wp_die('Application record not found.', 'Not Found', ['response' => 404]);
        }

        $current_user_id = get_current_user_id();
        $is_admin = current_user_can(LFA_Scholarship::CAP_MANAGE_SCHOLARSHIPS);

        // Security check: Unassigned reviewer cannot view
        if (!$is_admin && (int)$app->assigned_reviewer_id !== $current_user_id) {
            wp_die('Unauthorized: You are not assigned to review this application.', 'Forbidden', ['response' => 403]);
        }

        $program = LFA_Scholarship::get_program((int)$app->program_id);
        $documents = LFA_Scholarship::get_application_documents($app_id);

        global $wpdb;
        $t_reviews = LFA_Scholarship::table_reviews();
        $reviews = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t_reviews} WHERE application_id = %d ORDER BY id DESC", $app_id));

        // Available reviewers for assignment
        $reviewers = get_users(['role__in' => ['administrator', LFA_Scholarship::ROLE_REVIEWER, LFA_Scholarship::ROLE_ADMIN]]);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Application Review &mdash; <?php echo esc_html($app->application_ref); ?></h1>
            <a href="<?php echo esc_url(admin_url('users.php?page=lfa-scholarship-applications')); ?>" class="page-title-action">&larr; Back to Queue</a>
            <hr class="wp-header-end">

            <?php if (isset($_GET['msg'])): ?>
                <div class="notice notice-success is-dismissible"><p>Action processed successfully.</p></div>
            <?php endif; ?>

            <div style="display:flex; gap:24px; margin-top:20px; flex-wrap:wrap;">
                <!-- Left: Application Details & Statement -->
                <div style="flex:2; min-width:550px;">
                    <div class="postbox" style="padding:20px;">
                        <h2>Applicant & Academic Profile</h2>
                        <table class="form-table" style="margin-top:0;">
                            <tr>
                                <th style="width:160px;">Program</th>
                                <td><strong><?php echo esc_html($program ? $program->title : 'Scholarship'); ?></strong></td>
                            </tr>
                            <tr>
                                <th>Applicant Name</th>
                                <td><?php echo esc_html($app->applicant_name); ?></td>
                            </tr>
                            <tr>
                                <th>Applicant Email</th>
                                <td><a href="mailto:<?php echo esc_attr($app->applicant_email); ?>"><?php echo esc_html($app->applicant_email); ?></a></td>
                            </tr>
                            <tr>
                                <th>School / University</th>
                                <td><?php echo esc_html($app->school_name); ?></td>
                            </tr>
                            <tr>
                                <th>Program of Study</th>
                                <td><?php echo esc_html($app->program_of_study); ?></td>
                            </tr>
                            <tr>
                                <th>Academic Level</th>
                                <td><?php echo esc_html($app->academic_level ?: 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>GPA & Academic Details</th>
                                <td><?php echo nl2br(esc_html($app->gpa_academic_details ?: 'None supplied')); ?></td>
                            </tr>
                            <tr>
                                <th>Current Status</th>
                                <td>
                                    <span class="badge" style="background:#0073aa; color:#fff; padding:4px 10px; border-radius:4px; font-weight:700;">
                                        <?php echo esc_html(strtoupper(str_replace('_', ' ', $app->status))); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Submitted At</th>
                                <td><?php echo esc_html($app->submitted_at ?: 'Draft'); ?></td>
                            </tr>
                        </table>

                        <h3 style="margin-top:30px; border-top:1px solid #eee; padding-top:20px;">Personal Statement</h3>
                        <div style="background:#f9f9f9; padding:18px; border:1px solid #ddd; border-radius:6px; line-height:1.7;">
                            <?php echo wpautop(esc_html($app->personal_statement)); ?>
                        </div>

                        <h3 style="margin-top:30px; border-top:1px solid #eee; padding-top:20px;">Supporting Documents (Stored in Private Quarantine)</h3>
                        <?php if (empty($documents)): ?>
                            <p><em>No supporting documents uploaded.</em></p>
                        <?php else: ?>
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th>File Display Name</th>
                                        <th>Category</th>
                                        <th>MIME Type</th>
                                        <th>Size</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($documents as $doc): ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($doc->display_filename); ?></strong></td>
                                            <td><?php echo esc_html($doc->category); ?></td>
                                            <td><code><?php echo esc_html($doc->mime_type); ?></code></td>
                                            <td><?php echo esc_html(round($doc->file_size / 1024, 1)) . ' KB'; ?></td>
                                            <td>
                                                <a href="<?php echo esc_url(admin_url('admin-post.php?action=lfa_download_scholarship_doc&doc_ref=' . $doc->document_ref)); ?>" class="button button-small">Download (Secure Stream)</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!-- Review History -->
                    <div class="postbox" style="padding:20px;">
                        <h2>Internal Review History & Notes</h2>
                        <?php if (empty($reviews)): ?>
                            <p><em>No review notes recorded yet.</em></p>
                        <?php else: ?>
                            <?php foreach ($reviews as $rev):
                                $rev_user = get_user_by('id', $rev->reviewer_id);
                            ?>
                                <div style="border-left:4px solid #0073aa; padding:10px 14px; margin-bottom:14px; background:#f6f7f7;">
                                    <strong><?php echo esc_html($rev_user ? $rev_user->display_name : 'Staff #' . $rev->reviewer_id); ?></strong>
                                    &mdash; <em><?php echo esc_html($rev->created_at); ?></em>
                                    <span style="float:right; font-weight:600; text-transform:uppercase; font-size:11px;"><?php echo esc_html($rev->review_state); ?></span>
                                    <p style="margin:8px 0;"><?php echo nl2br(esc_html($rev->private_notes)); ?></p>
                                    <?php if (!empty($rev->applicant_feedback)): ?>
                                        <p style="color:#666; font-size:12px; margin-top:5px;"><strong>Applicant Feedback:</strong> <?php echo esc_html($rev->applicant_feedback); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Staff Review Actions & Admin Decision -->
                <div style="flex:1; min-width:320px;">
                    <!-- Reviewer Assignment (Admin Only) -->
                    <?php if ($is_admin): ?>
                        <div class="postbox" style="padding:16px;">
                            <h3>Assign Reviewer</h3>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="lfa_assign_scholarship_reviewer">
                                <input type="hidden" name="application_id" value="<?php echo (int)$app->id; ?>">
                                <?php wp_nonce_field('lfa_assign_reviewer_action', 'lfa_assign_nonce'); ?>
                                <p>
                                    <select name="reviewer_id" class="widefat">
                                        <option value="0">-- Select Staff Member --</option>
                                        <?php foreach ($reviewers as $r): ?>
                                            <option value="<?php echo (int)$r->ID; ?>" <?php selected($app->assigned_reviewer_id, $r->ID); ?>>
                                                <?php echo esc_html($r->display_name . ' (' . $r->user_email . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </p>
                                <button type="submit" class="button button-secondary">Assign Reviewer</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <!-- Staff Review Form -->
                    <div class="postbox" style="padding:16px;">
                        <h3>Submit Review Assessment</h3>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="lfa_review_scholarship_application">
                            <input type="hidden" name="application_id" value="<?php echo (int)$app->id; ?>">
                            <?php wp_nonce_field('lfa_review_scholarship_action', 'lfa_review_nonce'); ?>

                            <p>
                                <label for="review_state"><strong>Assessment State:</strong></label><br>
                                <select name="review_state" id="review_state" class="widefat">
                                    <option value="under_review">Under Review (Ongoing)</option>
                                    <option value="shortlisted">Shortlisted for Award Consideration</option>
                                    <option value="info_requested">Request Additional Information</option>
                                </select>
                            </p>
                            <p>
                                <label for="private_notes"><strong>Confidential Reviewer Notes (Staff Only):</strong></label><br>
                                <textarea name="private_notes" id="private_notes" class="widefat" rows="4" placeholder="Evaluation criteria, merits, academic verification notes..." required></textarea>
                            </p>
                            <p>
                                <label for="applicant_feedback"><strong>Applicant Facing Notes (Sent only if requesting info):</strong></label><br>
                                <textarea name="applicant_feedback" id="applicant_feedback" class="widefat" rows="2" placeholder="Instructions for the applicant if additional information is required..."></textarea>
                            </p>
                            <button type="submit" class="button button-primary">Save Review Assessment</button>
                        </form>
                    </div>

                    <!-- Final Decision Panel (Admin Only) -->
                    <?php if ($is_admin): ?>
                        <div class="postbox" style="padding:16px; border:2px solid #0073aa;">
                            <h3 style="color:#0073aa;">Final Administrative Decision</h3>
                            <p class="description">Recording an award records the selection decision; payout is handled outside this system.</p>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="lfa_decision_scholarship_application">
                                <input type="hidden" name="application_id" value="<?php echo (int)$app->id; ?>">
                                <?php wp_nonce_field('lfa_decision_scholarship_action', 'lfa_decision_nonce'); ?>

                                <p>
                                    <label for="decision"><strong>Decision:</strong></label><br>
                                    <select name="decision" id="decision" class="widefat">
                                        <option value="awarded" <?php selected($app->status, 'awarded'); ?>>Award Scholarship</option>
                                        <option value="declined" <?php selected($app->status, 'declined'); ?>>Decline Application</option>
                                    </select>
                                </p>
                                <p>
                                    <label for="decision_notes"><strong>Decision Rationale / Notes:</strong></label><br>
                                    <textarea name="decision_notes" id="decision_notes" class="widefat" rows="3" placeholder="Executive committee justification..."></textarea>
                                </p>
                                <button type="submit" class="button button-primary" onclick="return confirm('Confirm recording this scholarship decision?');">Record Final Decision</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function render_audit_page(): void {
        global $wpdb;
        $t = LFA_Scholarship::table_audit();
        $logs = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC LIMIT 100");
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Scholarship Audit Trail</h1>
            <hr class="wp-header-end">
            <p>Immutable record of all scholarship application state changes and actions. Sensitive payloads and essays are excluded from logs.</p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:70px;">ID</th>
                        <th style="width:90px;">App ID</th>
                        <th style="width:120px;">Actor</th>
                        <th style="width:160px;">Action</th>
                        <th style="width:130px;">Status Transition</th>
                        <th>Details</th>
                        <th style="width:160px;">Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="7">No audit logs recorded yet.</td></tr>
                    <?php else: foreach ($logs as $l): ?>
                        <tr>
                            <td><?php echo (int)$l->id; ?></td>
                            <td><?php echo $l->application_id ? '#' . (int)$l->application_id : '&mdash;'; ?></td>
                            <td><?php echo esc_html($l->actor_type . ' #' . (int)$l->actor_id); ?></td>
                            <td><code><?php echo esc_html($l->action); ?></code></td>
                            <td>
                                <?php if ($l->from_status || $l->to_status): ?>
                                    <?php echo esc_html($l->from_status ?: 'start') . ' &rarr; ' . esc_html($l->to_status ?: 'end'); ?>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $d = json_decode($l->details_json, true);
                                echo esc_html($d['details'] ?? $l->details_json);
                                ?>
                            </td>
                            <td><?php echo esc_html($l->created_at); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function render_recipients_page(): void {
        global $wpdb;
        $t_rec = LFA_Scholarship::table_recipients();
        $t_prog = LFA_Scholarship::table_programs();
        $recipients = $wpdb->get_results("SELECT r.*, p.title as program_title FROM {$t_rec} r INNER JOIN {$t_prog} p ON r.program_id = p.id ORDER BY r.id DESC");

        // Awarded applications eligible for publication
        $t_app = LFA_Scholarship::table_applications();
        $awarded_apps = $wpdb->get_results("SELECT a.*, p.title as program_title FROM {$t_app} a INNER JOIN {$t_prog} p ON a.program_id = p.id WHERE a.status = 'awarded' ORDER BY a.id DESC");
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Scholarship Recipients Publication</h1>
            <hr class="wp-header-end">

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'recipient_published'): ?>
                <div class="notice notice-success is-dismissible"><p>Scholarship recipient published successfully.</p></div>
            <?php endif; ?>

            <div style="display:flex; gap:24px; margin-top:20px; flex-wrap:wrap;">
                <div style="flex:2; min-width:500px;">
                    <div class="postbox" style="padding:16px;">
                        <h2>Published Recipients</h2>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Recipient Name</th>
                                    <th>Program</th>
                                    <th>Year</th>
                                    <th>Institution</th>
                                    <th>Award Details</th>
                                    <th>Consent Verified</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recipients)): ?>
                                    <tr><td colspan="7">No recipients published yet. Only authorized awarded applicants with explicit consent can be publicized.</td></tr>
                                <?php else: foreach ($recipients as $rec): ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($rec->public_name); ?></strong></td>
                                        <td><?php echo esc_html($rec->program_title); ?></td>
                                        <td><?php echo esc_html($rec->academic_year); ?></td>
                                        <td><?php echo esc_html($rec->institution); ?></td>
                                        <td><?php echo esc_html($rec->award_details); ?></td>
                                        <td><?php echo $rec->consent_given ? 'Verified Yes' : 'No'; ?></td>
                                        <td><span class="badge" style="background:#46b450; color:#fff; padding:3px 7px; border-radius:3px;">Published</span></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="flex:1; min-width:320px;">
                    <div class="postbox" style="padding:16px;">
                        <h2>Publicize Awarded Applicant</h2>
                        <?php if (empty($awarded_apps)): ?>
                            <p><em>No awarded applicants available to publish. Applications must reach 'awarded' status first.</em></p>
                        <?php else: ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="lfa_publish_scholarship_recipient">
                                <?php wp_nonce_field('lfa_publish_recipient_action', 'lfa_pub_rec_nonce'); ?>

                                <p>
                                    <label for="application_id"><strong>Select Awarded Application:</strong></label><br>
                                    <select name="application_id" id="application_id" class="widefat" required>
                                        <option value="">-- Select Awarded Candidate --</option>
                                        <?php foreach ($awarded_apps as $a): ?>
                                            <option value="<?php echo (int)$a->id; ?>">
                                                <?php echo esc_html($a->applicant_name . ' (' . $a->application_ref . ') &mdash; ' . $a->school_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </p>
                                <p>
                                    <label for="public_name"><strong>Public Display Name:</strong></label><br>
                                    <input type="text" name="public_name" id="public_name" class="widefat" placeholder="Name as confirmed for public release">
                                </p>
                                <p>
                                    <label for="academic_year"><strong>Academic Year:</strong></label><br>
                                    <input type="text" name="academic_year" id="academic_year" class="widefat" value="<?php echo date('Y'); ?>">
                                </p>
                                <p>
                                    <label for="institution"><strong>Institution:</strong></label><br>
                                    <input type="text" name="institution" id="institution" class="widefat">
                                </p>
                                <p>
                                    <label for="award_details"><strong>Award Description / Citation:</strong></label><br>
                                    <textarea name="award_details" id="award_details" class="widefat" rows="2"></textarea>
                                </p>
                                <p style="background:#fff2d6; border-left:4px solid #ffb900; padding:10px;">
                                    <label>
                                        <input type="checkbox" name="consent_given" value="1" required>
                                        <strong>I certify that explicit recipient consent has been received for public publication.</strong>
                                    </label>
                                </p>
                                <button type="submit" class="button button-primary button-large">Publish Recipient</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
