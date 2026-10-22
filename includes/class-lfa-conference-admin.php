<?php
/**
 * Love Family Alumni — Conference Administrative Management Screens
 *
 * Implements protected WordPress admin screens for Multi-Conference Creation,
 * Drafting, Publishing/Unpublishing/Archiving, Ticket Configuration, Attendee Rosters,
 * CSV Export, and Change Auditing.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Conference_Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_admin_menus'], 26);
        add_action('admin_post_lfa_save_conference', [__CLASS__, 'handle_save_conference']);
        add_action('admin_post_lfa_set_conference_publish_status', [__CLASS__, 'handle_set_publish_status']);
        add_action('admin_post_lfa_delete_conference', [__CLASS__, 'handle_delete_conference']);
        add_action('admin_post_lfa_save_conference_policy', [__CLASS__, 'handle_save_policy']);
        add_action('admin_post_lfa_save_conference_ticket', [__CLASS__, 'handle_save_ticket']);
        add_action('admin_post_lfa_export_conference_attendees', [__CLASS__, 'handle_export_csv']);
        add_action('admin_post_lfa_save_conference_speaker', [__CLASS__, 'handle_save_speaker']);
        add_action('admin_post_lfa_delete_conference_speaker', [__CLASS__, 'handle_delete_speaker']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }

    public static function enqueue_admin_assets(string $hook): void {
        if (isset($_GET['page']) && strpos($_GET['page'], 'lfa-conference') !== false) {
            wp_enqueue_media();
        }
    }

    public static function register_admin_menus(): void {
        $parent = 'users.php';

        // 1. Conferences Management (List & Actions)
        add_submenu_page(
            $parent,
            'Manage Conferences',
            'Conferences',
            LFA_Conference::CAP_MANAGE_CONFERENCES,
            'lfa-conferences',
            [__CLASS__, 'render_conferences_list_page']
        );

        // 2. Create / Edit Conference
        add_submenu_page(
            null,
            'Edit Conference',
            'Edit Conference',
            LFA_Conference::CAP_MANAGE_CONFERENCES,
            'lfa-conference-edit',
            [__CLASS__, 'render_edit_conference_page']
        );

        // 3. Conference Policy & Tickets
        add_submenu_page(
            $parent,
            'Conference Policy & Tickets',
            'Conference Policy',
            LFA_Conference::CAP_MANAGE_CONFERENCES,
            'lfa-conference-policy',
            [__CLASS__, 'render_policy_page']
        );

        // 4. Conference Attendees
        add_submenu_page(
            $parent,
            'Conference Attendees',
            'Conference Attendees',
            LFA_Conference::CAP_VIEW_ATTENDEES,
            'lfa-conference-attendees',
            [__CLASS__, 'render_attendees_page']
        );

        // 5. Conference Audit Log
        add_submenu_page(
            $parent,
            'Conference Audit Log',
            'Conference Audit',
            LFA_Conference::CAP_MANAGE_CONFERENCES,
            'lfa-conference-audit',
            [__CLASS__, 'render_audit_page']
        );

        // 6. Conference Speakers
        add_submenu_page(
            null,
            'Manage Conference Speakers',
            'Speakers',
            LFA_Conference::CAP_MANAGE_CONFERENCES,
            'lfa-conference-speakers',
            [__CLASS__, 'render_speakers_page']
        );
    }

    /**
     * Handle Create or Edit Conference POST
     */
    public static function handle_save_conference(): void {
        if (!current_user_can(LFA_Conference::CAP_MANAGE_CONFERENCES)) {
            wp_die('Unauthorized: Insufficient permissions to manage conferences.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_save_conference_action', 'lfa_conf_nonce');

        $conf_id = (int)($_POST['conference_id'] ?? 0);
        $data = [
            'title'                      => sanitize_text_field($_POST['title'] ?? ''),
            'slug'                       => sanitize_title($_POST['slug'] ?? ''),
            'theme'                      => sanitize_text_field($_POST['theme'] ?? ''),
            'verse'                      => sanitize_text_field($_POST['verse'] ?? ''),
            'starts_at'                  => sanitize_text_field($_POST['starts_at'] ?? ''),
            'ends_at'                    => sanitize_text_field($_POST['ends_at'] ?? ''),
            'timezone'                   => sanitize_text_field($_POST['timezone'] ?? 'America/New_York'),
            'location_summary'           => sanitize_text_field($_POST['location_summary'] ?? ''),
            'guest_registration_enabled' => !empty($_POST['guest_registration_enabled']) ? 1 : 0,
            'organizer'                  => sanitize_text_field($_POST['organizer'] ?? 'Love Family Alumni — North America Zone'),
            'keynote_speaker'            => sanitize_text_field($_POST['keynote_speaker'] ?? ''),
            'speaker_status'             => sanitize_text_field($_POST['speaker_status'] ?? 'pending'),
            'venue_name'                 => sanitize_text_field($_POST['venue_name'] ?? ''),
            'hotel_info'                 => sanitize_textarea_field($_POST['hotel_info'] ?? ''),
            'program_info'               => sanitize_textarea_field($_POST['program_info'] ?? ''),
            'sponsorship'                => sanitize_textarea_field($_POST['sponsorship'] ?? ''),
            'refund_policy'              => sanitize_textarea_field($_POST['refund_policy'] ?? ''),
        ];

        if ($conf_id > 0) {
            $res = LFA_Conference::update_conference($conf_id, $data, get_current_user_id());
        } else {
            $res = LFA_Conference::create_conference($data, get_current_user_id());
        }

        if ($res['success']) {
            wp_safe_redirect(add_query_arg(['page' => 'lfa-conferences', 'lfa_notice' => 'conf_saved'], admin_url('users.php')));
        } else {
            wp_die(esc_html($res['error']));
        }
        exit;
    }

    /**
     * Handle Set Conference Published Status (Publish / Unpublish / Archive)
     * Strictly verifies lfa_publish_conferences capability!
     */
    public static function handle_set_publish_status(): void {
        if (!current_user_can(LFA_Conference::CAP_PUBLISH_CONFERENCES)) {
            wp_die('Unauthorized: Publishing privilege (lfa_publish_conferences) is required to publish or archive conferences.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_set_conf_status_action', 'lfa_status_nonce');

        $conf_id = (int)($_POST['conference_id'] ?? 0);
        $new_status = sanitize_key($_POST['published_status'] ?? '');

        $res = LFA_Conference::set_published_status($conf_id, $new_status, get_current_user_id());
        if ($res['success']) {
            wp_safe_redirect(add_query_arg(['page' => 'lfa-conferences', 'lfa_notice' => 'status_' . $new_status], admin_url('users.php')));
        } else {
            wp_die(esc_html($res['error']));
        }
        exit;
    }

    /**
     * Handle Delete Conference (Draft only)
     */
    public static function handle_delete_conference(): void {
        if (!current_user_can(LFA_Conference::CAP_PUBLISH_CONFERENCES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_delete_conf_action', 'lfa_del_nonce');

        $conf_id = (int)($_POST['conference_id'] ?? 0);
        $res = LFA_Conference::delete_conference($conf_id, get_current_user_id());

        if ($res['success']) {
            wp_safe_redirect(add_query_arg(['page' => 'lfa-conferences', 'lfa_notice' => 'conf_deleted'], admin_url('users.php')));
        } else {
            wp_die(esc_html($res['error']));
        }
        exit;
    }

    /**
     * Handle Save Conference Policy POST
     */
    public static function handle_save_policy(): void {
        if (!current_user_can(LFA_Conference::CAP_MANAGE_CONFERENCES)) {
            wp_die('Unauthorized: Insufficient permissions to manage conference policy.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_save_conference_policy_action', 'lfa_conf_policy_nonce');

        global $wpdb;
        $t_conf = LFA_Conference::table_conferences();
        $conf_id = (int)($_POST['conference_id'] ?? 1);
        $conf = LFA_Conference::get_conference_by_id($conf_id);
        if (!$conf) {
            $conf = LFA_Conference::get_conference();
        }
        if (!$conf) {
            wp_die('Conference record not found.');
        }

        $reg_status = sanitize_key($_POST['registration_status'] ?? 'closed');
        if (!in_array($reg_status, ['open', 'closed', 'paused'], true)) {
            $reg_status = 'closed';
        }

        $guest_enabled = !empty($_POST['guest_registration_enabled']) ? 1 : 0;
        $timezone      = sanitize_text_field($_POST['timezone'] ?? 'America/New_York');
        $location      = sanitize_text_field($_POST['location_summary'] ?? 'North America Zone (Host City TBA)');

        $opens_at  = !empty($_POST['registration_opens_at']) ? sanitize_text_field($_POST['registration_opens_at']) : null;
        $closes_at = !empty($_POST['registration_closes_at']) ? sanitize_text_field($_POST['registration_closes_at']) : null;

        $now = current_time('mysql', true);
        $wpdb->update($t_conf, [
            'registration_status'        => $reg_status,
            'guest_registration_enabled' => $guest_enabled,
            'timezone'                   => $timezone,
            'location_summary'           => $location,
            'registration_opens_at'      => $opens_at,
            'registration_closes_at'     => $closes_at,
            'updated_at'                 => $now,
        ], ['id' => $conf->id]);

        LFA_Conference::log_audit('admin', get_current_user_id(), 'policy_updated', 'conference', (string)$conf->id, [
            'registration_status'        => $reg_status,
            'guest_registration_enabled' => $guest_enabled,
            'timezone'                   => $timezone,
        ]);

        wp_safe_redirect(add_query_arg([
            'page'       => 'lfa-conference-policy',
            'id'         => $conf->id,
            'lfa_notice' => 'policy_saved',
        ], admin_url('users.php')));
        exit;
    }

    /**
     * Handle Save/Update Ticket Type POST
     */
    public static function handle_save_ticket(): void {
        if (!current_user_can(LFA_Conference::CAP_MANAGE_CONFERENCES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_save_conference_ticket_action', 'lfa_ticket_nonce');

        global $wpdb;
        $t_tickets = LFA_Conference::table_ticket_types();
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $conf_id = (int)($_POST['conference_id'] ?? 1);
        $ticket = LFA_Conference::get_ticket_type($ticket_id);
        if (!$ticket) {
            wp_die('Ticket type not found.');
        }

        $name        = sanitize_text_field($_POST['name'] ?? $ticket->name);
        $description = sanitize_textarea_field($_POST['description'] ?? $ticket->description);
        $active      = !empty($_POST['active']) ? 1 : 0;
        $capacity    = (isset($_POST['capacity']) && $_POST['capacity'] !== '') ? (int)$_POST['capacity'] : null;

        // Price in USD: convert dollars to integer cents
        // If left empty: remains NULL! Missing amount is NOT 0 or free
        $amount_cents = null;
        if (isset($_POST['amount_dollars']) && trim($_POST['amount_dollars']) !== '') {
            $dollars = (float) trim($_POST['amount_dollars']);
            if ($dollars > 0) {
                $amount_cents = (int) round($dollars * 100);
            }
        }

        // If ticket is set active, amount_cents MUST be non-null and > 0
        if ($active && (is_null($amount_cents) || $amount_cents <= 0)) {
            wp_safe_redirect(add_query_arg([
                'page'       => 'lfa-conference-policy',
                'id'         => $conf_id,
                'lfa_notice' => 'ticket_price_required',
            ], admin_url('users.php')));
            exit;
        }

        $now = current_time('mysql', true);
        $wpdb->update($t_tickets, [
            'name'         => $name,
            'description'  => $description,
            'amount_cents' => $amount_cents,
            'capacity'     => $capacity,
            'active'       => $active,
            'updated_by'   => get_current_user_id(),
            'updated_at'   => $now,
        ], ['id' => $ticket_id]);

        LFA_Conference::log_audit('admin', get_current_user_id(), 'ticket_updated', 'ticket_type', (string)$ticket_id, [
            'name'         => $name,
            'amount_cents' => $amount_cents,
            'active'       => $active,
            'capacity'     => $capacity,
        ]);

        wp_safe_redirect(add_query_arg([
            'page'       => 'lfa-conference-policy',
            'id'         => $conf_id,
            'lfa_notice' => 'ticket_saved',
        ], admin_url('users.php')));
        exit;
    }

    /**
     * Handle CSV Export POST
     */
    public static function handle_export_csv(): void {
        if (!current_user_can(LFA_Conference::CAP_EXPORT_ATTENDEES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_export_conference_attendees_action', 'lfa_export_nonce');
        $conf_id = (int)($_POST['conference_id'] ?? 1);
        LFA_Conference::export_attendees_csv($conf_id);
        exit;
    }

    /**
     * Screen 1: Conferences List (Manage All Conferences)
     */
    public static function render_conferences_list_page(): void {
        $conferences = LFA_Conference::get_all_conferences();
        $can_publish = current_user_can(LFA_Conference::CAP_PUBLISH_CONFERENCES);
        $notice = $_GET['lfa_notice'] ?? '';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Conferences &amp; Gatherings Management</h1>
            <a href="<?php echo esc_url(admin_url('users.php?page=lfa-conference-edit')); ?>" class="page-title-action">Add New Conference</a>
            <hr class="wp-header-end">

            <?php if ($notice === 'conf_saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Conference saved successfully.</p></div>
            <?php elseif (str_starts_with($notice, 'status_')): ?>
                <div class="notice notice-success is-dismissible"><p>Conference publication status updated to <strong><?php echo esc_html(substr($notice, 7)); ?></strong>.</p></div>
            <?php elseif ($notice === 'conf_deleted'): ?>
                <div class="notice notice-success is-dismissible"><p>Conference draft deleted.</p></div>
            <?php endif; ?>

            <p>Manage all biennial and regional conferences. Separate publication controls require explicit publishing authorization (<code>lfa_publish_conferences</code>).</p>

            <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th style="width:45px;">ID</th>
                        <th>Conference Title</th>
                        <th style="width:170px;">Slug</th>
                        <th style="width:160px;">Dates</th>
                        <th style="width:110px;">Publication</th>
                        <th style="width:105px;">Registration</th>
                        <th style="width:75px;">Attendees</th>
                        <th style="width:230px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($conferences)): ?>
                        <tr><td colspan="8">No conferences found.</td></tr>
                    <?php else: foreach ($conferences as $c):
                        global $wpdb;
                        $t_att = LFA_Conference::table_attendees();
                        $attendee_count = (int)$wpdb->get_var($wpdb->prepare("SELECT count(*) FROM {$t_att} WHERE conference_id = %d", $c->id));
                        $is_published = ($c->published_status === 'published');
                    ?>
                        <tr>
                            <td><?php echo (int)$c->id; ?></td>
                            <td>
                                <strong><?php echo esc_html($c->title); ?></strong>
                                <?php if (!empty($c->theme)): ?>
                                    <br><small><em>Theme: <?php echo esc_html($c->theme); ?> (<?php echo esc_html($c->verse); ?>)</em></small>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html($c->slug); ?></code></td>
                            <td>
                                <?php echo esc_html(substr($c->starts_at, 0, 10) . ' to ' . substr($c->ends_at, 0, 10)); ?>
                            </td>
                            <td>
                                <span class="badge" style="background:#<?php echo match($c->published_status) {
                                    'published' => '46b450',
                                    'draft'     => '72777c',
                                    'archived'  => '555d66',
                                    default     => 'dc3232',
                                }; ?>; color:#fff; padding:3px 7px; border-radius:3px; font-size:11px; font-weight:700;">
                                    <?php echo esc_html(strtoupper($c->published_status)); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge" style="background:#<?php echo match($c->registration_status) {
                                    'open'   => '0073aa',
                                    'paused' => 'ffb900',
                                    default  => '72777c',
                                }; ?>; color:#fff; padding:3px 7px; border-radius:3px; font-size:11px; font-weight:700;">
                                    <?php echo esc_html(strtoupper($c->registration_status)); ?>
                                </span>
                            </td>
                            <td><strong><?php echo $attendee_count; ?></strong></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('users.php?page=lfa-conference-edit&id=' . $c->id)); ?>" class="button button-small">Edit</a>
                                <a href="<?php echo esc_url(admin_url('users.php?page=lfa-conference-speakers&id=' . $c->id)); ?>" class="button button-small">Manage Speakers</a>
                                <a href="<?php echo esc_url(admin_url('users.php?page=lfa-conference-policy&id=' . $c->id)); ?>" class="button button-small">Policy &amp; Tickets</a>

                                <?php if ($can_publish): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                        <input type="hidden" name="action" value="lfa_set_conference_publish_status">
                                        <input type="hidden" name="conference_id" value="<?php echo (int)$c->id; ?>">
                                        <?php wp_nonce_field('lfa_set_conf_status_action', 'lfa_status_nonce'); ?>
                                        <?php if ($is_published): ?>
                                            <input type="hidden" name="published_status" value="unpublished">
                                            <button type="submit" class="button button-small" onclick="return confirm('Unpublish this conference?');">Unpublish</button>
                                        <?php else: ?>
                                            <input type="hidden" name="published_status" value="published">
                                            <button type="submit" class="button button-small button-primary">Publish</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Screen 2: Create / Edit Conference
     */
    public static function render_edit_conference_page(): void {
        $conf_id = (int)($_GET['id'] ?? 0);
        $conf = $conf_id > 0 ? LFA_Conference::get_conference_by_id($conf_id) : null;
        $details = $conf ? (json_decode($conf->details_json ?: '{}', true) ?: []) : [];
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo $conf ? 'Edit Conference: ' . esc_html($conf->title) : 'Create New Conference'; ?></h1>
            <a href="<?php echo esc_url(admin_url('users.php?page=lfa-conferences')); ?>" class="page-title-action">&larr; Back to Conferences</a>
            <hr class="wp-header-end">

            <div style="max-width:880px; margin-top:20px;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="lfa_save_conference">
                    <input type="hidden" name="conference_id" value="<?php echo $conf ? (int)$conf->id : 0; ?>">
                    <?php wp_nonce_field('lfa_save_conference_action', 'lfa_conf_nonce'); ?>

                    <div class="postbox" style="padding:20px;">
                        <h2>Conference Identity &amp; Dates</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="title">Conference Title <span class="required">*</span></label></th>
                                <td><input type="text" name="title" id="title" class="regular-text widefat" value="<?php echo esc_attr($conf->title ?? ''); ?>" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="slug">URL Slug <span class="required">*</span></label></th>
                                <td>
                                    <input type="text" name="slug" id="slug" class="regular-text widefat" value="<?php echo esc_attr($conf->slug ?? ''); ?>" required>
                                    <p class="description">Must be globally unique. Used in detail permalink (e.g. <code>2027-biennial-conference</code>).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="theme">Conference Theme</label></th>
                                <td><input type="text" name="theme" id="theme" class="regular-text widefat" value="<?php echo esc_attr($conf->theme ?? ''); ?>" placeholder="e.g. Thy Kingdom Come"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="verse">Theme Scriptural Verse</label></th>
                                <td><input type="text" name="verse" id="verse" class="regular-text" value="<?php echo esc_attr($conf->verse ?? ''); ?>" placeholder="e.g. Matthew 6:10"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="starts_at">Start Date &amp; Time (YYYY-MM-DD HH:MM:SS)</label></th>
                                <td><input type="text" name="starts_at" id="starts_at" class="regular-text" value="<?php echo esc_attr($conf->starts_at ?? date('Y-m-d 09:00:00', strtotime('+6 months'))); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ends_at">End Date &amp; Time (YYYY-MM-DD HH:MM:SS)</label></th>
                                <td><input type="text" name="ends_at" id="ends_at" class="regular-text" value="<?php echo esc_attr($conf->ends_at ?? date('Y-m-d 18:00:00', strtotime('+6 months +3 days'))); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="timezone">Event Timezone</label></th>
                                <td><input type="text" name="timezone" id="timezone" class="regular-text" value="<?php echo esc_attr($conf->timezone ?? 'America/New_York'); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="location_summary">Venue / Host City</label></th>
                                <td><input type="text" name="location_summary" id="location_summary" class="regular-text widefat" value="<?php echo esc_attr($conf->location_summary ?? 'North America Zone (Host City TBA)'); ?>"></td>
                            </tr>
                        </table>

                        <h2 style="margin-top:30px; border-top:1px solid #eee; padding-top:20px;">Speaker, Venue &amp; Planning Information</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="keynote_speaker">Keynote Speaker</label></th>
                                <td><input type="text" name="keynote_speaker" id="keynote_speaker" class="regular-text widefat" value="<?php echo esc_attr($details['keynote_speaker'] ?? ''); ?>" placeholder="e.g. Dr. Uche Igbokwe, U.K."></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="venue_name">Venue / Facility Name</label></th>
                                <td><input type="text" name="venue_name" id="venue_name" class="regular-text widefat" value="<?php echo esc_attr($details['venue_name'] ?? ''); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="hotel_info">Accommodation &amp; Hotel Details</label></th>
                                <td><textarea name="hotel_info" id="hotel_info" class="widefat" rows="3"><?php echo esc_textarea($details['hotel_info'] ?? ''); ?></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="program_info">Daily Schedule &amp; Workshops</label></th>
                                <td><textarea name="program_info" id="program_info" class="widefat" rows="3"><?php echo esc_textarea($details['program_info'] ?? ''); ?></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="sponsorship">Sponsorship &amp; Partnership Information</label></th>
                                <td><textarea name="sponsorship" id="sponsorship" class="widefat" rows="2"><?php echo esc_textarea($details['sponsorship'] ?? ''); ?></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="refund_policy">Refund &amp; Cancellation Policy</label></th>
                                <td><textarea name="refund_policy" id="refund_policy" class="widefat" rows="2"><?php echo esc_textarea($details['refund_policy'] ?? ''); ?></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row">Guest Registration</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="guest_registration_enabled" value="1" <?php checked(!empty($conf->guest_registration_enabled)); ?>>
                                        Allow non-member guest registration (Default is OFF &mdash; members only)
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary button-large">Save Conference</button>
                            <a href="<?php echo esc_url(admin_url('users.php?page=lfa-conferences')); ?>" class="button button-large">Cancel</a>
                        </p>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Screen 3: Policy & Tickets
     */
    public static function render_policy_page(): void {
        if (!current_user_can(LFA_Conference::CAP_MANAGE_CONFERENCES)) {
            wp_die('Unauthorized');
        }

        $conf_id = (int)($_GET['id'] ?? 0);
        $conf = $conf_id > 0 ? LFA_Conference::get_conference_by_id($conf_id) : LFA_Conference::get_conference();
        if (!$conf) {
            $conf = LFA_Conference::get_conference_by_id(1);
        }

        $all_confs = LFA_Conference::get_all_conferences();
        $tickets = $conf ? LFA_Conference::get_ticket_types((int)$conf->id) : [];
        $notice = $_GET['lfa_notice'] ?? '';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Conference Policy &amp; Ticket Configuration</h1>
            <hr class="wp-header-end">

            <!-- Conference Selector -->
            <div style="margin:15px 0;">
                <label for="conf-select"><strong>Select Conference:</strong></label>
                <select id="conf-select" onchange="window.location.href='<?php echo esc_url(admin_url('users.php?page=lfa-conference-policy&id=')); ?>' + this.value;">
                    <?php foreach ($all_confs as $c): ?>
                        <option value="<?php echo (int)$c->id; ?>" <?php selected($conf && $conf->id == $c->id); ?>>
                            <?php echo esc_html($c->title . ' (' . $c->slug . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($notice === 'policy_saved') : ?>
                <div class="notice notice-success is-dismissible"><p><strong>Conference policy updated successfully.</strong></p></div>
            <?php elseif ($notice === 'ticket_saved') : ?>
                <div class="notice notice-success is-dismissible"><p><strong>Ticket category updated successfully.</strong></p></div>
            <?php elseif ($notice === 'ticket_price_required') : ?>
                <div class="notice notice-error is-dismissible"><p><strong>Error:</strong> An active ticket category must have a confirmed positive USD price. Missing amounts cannot be activated or treated as free.</p></div>
            <?php endif; ?>

            <?php if ($conf) : ?>
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin-bottom:24px;max-width:960px;">
                    <h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">Event Overview</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Conference Title</th>
                            <td><strong><?php echo esc_html($conf->title); ?></strong></td>
                        </tr>
                        <tr>
                            <th scope="row">Theme &amp; Verse</th>
                            <td><em>“<?php echo esc_html($conf->theme); ?>”</em> &mdash; <strong><?php echo esc_html($conf->verse); ?></strong></td>
                        </tr>
                        <tr>
                            <th scope="row">Dates</th>
                            <td><strong><?php echo esc_html($conf->starts_at . ' to ' . $conf->ends_at); ?></strong></td>
                        </tr>
                        <tr>
                            <th scope="row">Publication Status</th>
                            <td><span class="badge" style="background:#46b450;color:#fff;padding:3px 8px;border-radius:4px;font-weight:700;"><?php echo esc_html(strtoupper($conf->published_status)); ?></span></td>
                        </tr>
                    </table>
                </div>

                <!-- Registration Controls Form -->
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin-bottom:24px;max-width:960px;">
                    <h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">Registration Policy &amp; Availability</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="lfa_save_conference_policy">
                        <input type="hidden" name="conference_id" value="<?php echo (int)$conf->id; ?>">
                        <?php wp_nonce_field('lfa_save_conference_policy_action', 'lfa_conf_policy_nonce'); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="registration_status">Registration Status</label></th>
                                <td>
                                    <select name="registration_status" id="registration_status">
                                        <option value="closed" <?php selected($conf->registration_status, 'closed'); ?>>Closed (Default &mdash; Not Yet Open Notice)</option>
                                        <option value="open" <?php selected($conf->registration_status, 'open'); ?>>Open (Active Registration Enabled)</option>
                                        <option value="paused" <?php selected($conf->registration_status, 'paused'); ?>>Paused / Waitlist Only</option>
                                    </select>
                                    <p class="description">Registration must remain closed until ticket rates are confirmed by the committee.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Guest Registration</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="guest_registration_enabled" value="1" <?php checked($conf->guest_registration_enabled, 1); ?>>
                                        Allow non-member guest registration (Default is OFF &mdash; members only)
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="timezone">Event Timezone</label></th>
                                <td><input type="text" name="timezone" id="timezone" value="<?php echo esc_attr($conf->timezone); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="location_summary">Venue / Host City</label></th>
                                <td><input type="text" name="location_summary" id="location_summary" value="<?php echo esc_attr($conf->location_summary); ?>" class="regular-text"></td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary">Save Conference Policy</button>
                        </p>
                    </form>
                </div>

                <!-- Ticket Categories Configuration -->
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;max-width:960px;">
                    <h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">Ticket Categories &amp; Rates</h2>
                    <p><em>Rule: Unknown ticket amounts remain unconfigured (NULL). They are never treated as $0 or free.</em></p>

                    <table class="widefat striped" style="margin-top:16px;">
                        <thead>
                            <tr>
                                <th>Category Name</th>
                                <th>Description</th>
                                <th>Fee (USD)</th>
                                <th>Capacity</th>
                                <th>Enrolled</th>
                                <th>Status</th>
                                <th>Configure</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tickets)) : ?>
                                <tr><td colspan="7">No ticket categories configured for this event.</td></tr>
                            <?php else : foreach ($tickets as $t) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($t->name); ?></strong><br><code><?php echo esc_html($t->slug); ?></code></td>
                                    <td><?php echo esc_html($t->description); ?></td>
                                    <td>
                                        <?php if (is_null($t->amount_cents)) : ?>
                                            <span style="color:#d9534f;font-weight:700;">TBA (Unconfigured)</span>
                                        <?php else : ?>
                                            <strong>$<?php echo number_format($t->amount_cents / 100, 2); ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo is_null($t->capacity) ? 'Unlimited' : (int)$t->capacity; ?></td>
                                    <td><?php echo (int)$t->registered_count; ?></td>
                                    <td>
                                        <?php if ($t->active) : ?>
                                            <span class="badge" style="background:#46b450;color:#fff;padding:2px 6px;border-radius:3px;">ACTIVE</span>
                                        <?php else : ?>
                                            <span class="badge" style="background:#999;color:#fff;padding:2px 6px;border-radius:3px;">INACTIVE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <details>
                                            <summary class="button button-small">Edit</summary>
                                            <div style="margin-top:10px;padding:12px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <input type="hidden" name="action" value="lfa_save_conference_ticket">
                                                    <input type="hidden" name="ticket_id" value="<?php echo (int)$t->id; ?>">
                                                    <input type="hidden" name="conference_id" value="<?php echo (int)$conf->id; ?>">
                                                    <?php wp_nonce_field('lfa_save_conference_ticket_action', 'lfa_ticket_nonce'); ?>

                                                    <p>
                                                        <label>Category Name:<br>
                                                        <input type="text" name="name" value="<?php echo esc_attr($t->name); ?>" class="widefat"></label>
                                                    </p>
                                                    <p>
                                                        <label>Description:<br>
                                                        <textarea name="description" class="widefat" rows="2"><?php echo esc_textarea($t->description); ?></textarea></label>
                                                    </p>
                                                    <p>
                                                        <label>Confirmed Price (USD):<br>
                                                        <input type="number" step="0.01" min="0" name="amount_dollars" value="<?php echo is_null($t->amount_cents) ? '' : number_format($t->amount_cents / 100, 2, '.', ''); ?>" placeholder="Leave blank if TBA" class="small-text" style="width:120px;"></label>
                                                    </p>
                                                    <p>
                                                        <label>Max Capacity:<br>
                                                        <input type="number" name="capacity" value="<?php echo is_null($t->capacity) ? '' : (int)$t->capacity; ?>" placeholder="Blank = unlimited" class="small-text"></label>
                                                    </p>
                                                    <p>
                                                        <label>
                                                            <input type="checkbox" name="active" value="1" <?php checked($t->active, 1); ?>>
                                                            Activate this ticket class (Requires confirmed price &gt; $0)
                                                        </label>
                                                    </p>
                                                    <p>
                                                        <button type="submit" class="button button-primary button-small">Update Ticket Category</button>
                                                    </p>
                                                </form>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Screen 4: Conference Attendees Roster & CSV Export
     */
    public static function render_attendees_page(): void {
        if (!current_user_can(LFA_Conference::CAP_VIEW_ATTENDEES)) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $all_confs = LFA_Conference::get_all_conferences();
        $primary_conf = LFA_Conference::get_conference();
        $conf_id = !empty($_GET['conf_id']) ? (int)$_GET['conf_id'] : ($primary_conf ? (int)$primary_conf->id : (int)($all_confs[0]->id ?? 1));

        $t_att = LFA_Conference::table_attendees();
        $t_reg = LFA_Conference::table_registrations();

        $attendees = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, r.registration_number, r.ticket_name_snapshot, r.amount_cents_snapshot, r.status as reg_status
             FROM {$t_att} a
             INNER JOIN {$t_reg} r ON a.registration_id = r.id
             WHERE a.conference_id = %d
             ORDER BY a.id DESC",
            $conf_id
        ));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Conference Attendee Roster</h1>

            <?php if (current_user_can(LFA_Conference::CAP_EXPORT_ATTENDEES)) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left:15px;">
                    <input type="hidden" name="action" value="lfa_export_conference_attendees">
                    <input type="hidden" name="conference_id" value="<?php echo $conf_id; ?>">
                    <?php wp_nonce_field('lfa_export_conference_attendees_action', 'lfa_export_nonce'); ?>
                    <button type="submit" class="button button-secondary">Export Attendee Roster (CSV)</button>
                </form>
            <?php endif; ?>

            <hr class="wp-header-end">

            <!-- Conference Filter -->
            <div style="margin:15px 0;">
                <label for="conf-attendee-select"><strong>Filter by Conference:</strong></label>
                <select id="conf-attendee-select" onchange="window.location.href='<?php echo esc_url(admin_url('users.php?page=lfa-conference-attendees&conf_id=')); ?>' + this.value;">
                    <?php foreach ($all_confs as $c): ?>
                        <option value="<?php echo (int)$c->id; ?>" <?php selected($conf_id == $c->id); ?>>
                            <?php echo esc_html($c->title . ' (' . $c->slug . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th>Attendee Name</th>
                        <th>Email &amp; Contact</th>
                        <th>Registration #</th>
                        <th>Ticket Class</th>
                        <th>Chapter</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Registered Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendees)) : ?>
                        <tr><td colspan="8">No attendees registered for this conference yet.</td></tr>
                    <?php else : foreach ($attendees as $att) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($att->full_name); ?></strong></td>
                            <td>
                                <?php echo esc_html($att->email); ?><br>
                                <small><?php echo esc_html($att->phone ?: 'No phone provided'); ?></small>
                            </td>
                            <td><code><?php echo esc_html($att->registration_number); ?></code></td>
                            <td><?php echo esc_html($att->ticket_name_snapshot); ?></td>
                            <td><?php echo esc_html($att->chapter ?: 'N/A'); ?></td>
                            <td><?php echo esc_html(ucfirst($att->attendee_type)); ?></td>
                            <td>
                                <?php
                                $badge_bg = '#72777c';
                                if ($att->status === 'confirmed') {
                                    $badge_bg = '#16a34a';
                                } elseif ($att->status === 'registered' || $att->status === 'awaiting_payment') {
                                    $badge_bg = '#d97706';
                                } elseif ($att->status === 'cancelled') {
                                    $badge_bg = '#dc2626';
                                }
                                ?>
                                <span class="badge" style="background:<?php echo esc_attr($badge_bg); ?>;color:#fff;padding:3px 8px;border-radius:4px;font-weight:700;font-size:11px;letter-spacing:0.5px;">
                                    <?php echo esc_html(strtoupper($att->status)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($att->created_at); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Screen 5: Audit Log
     */
    public static function render_audit_page(): void {
        if (!current_user_can(LFA_Conference::CAP_MANAGE_CONFERENCES)) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $t_audit = LFA_Conference::table_audit();
        $logs = $wpdb->get_results("SELECT * FROM {$t_audit} ORDER BY id DESC LIMIT 100");
        ?>
        <div class="wrap">
            <h1>Conference Subsystem Audit Log</h1>
            <p>Immutable administrative log of all conference policy updates, ticket configuration revisions, and registrations.</p>

            <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th style="width:100px;">Actor</th>
                        <th style="width:160px;">Action</th>
                        <th style="width:120px;">Entity</th>
                        <th>Audit Details</th>
                        <th style="width:160px;">Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)) : ?>
                        <tr><td colspan="6">No audit records found.</td></tr>
                    <?php else : foreach ($logs as $l) : ?>
                        <tr>
                            <td><?php echo (int)$l->id; ?></td>
                            <td><?php echo esc_html($l->actor_type . ' #' . $l->actor_id); ?></td>
                            <td><code><?php echo esc_html($l->action); ?></code></td>
                            <td><?php echo esc_html($l->entity_type . ' #' . $l->entity_id); ?></td>
                            <td><code><?php echo esc_html($l->details_json); ?></code></td>
                            <td><?php echo esc_html($l->created_at); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Handle Save Speaker POST
     */
    public static function handle_save_speaker(): void {
        if (!current_user_can(LFA_Conference::CAP_MANAGE_CONFERENCES)) {
            wp_die('Unauthorized: Insufficient permissions to manage speakers.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_save_speaker_action', 'lfa_speaker_nonce');

        $conf_id = (int)($_POST['conference_id'] ?? 0);
        $data = [
            'id'               => (int)($_POST['speaker_id'] ?? 0),
            'conference_id'    => $conf_id,
            'name'             => sanitize_text_field($_POST['name'] ?? ''),
            'designation'      => sanitize_text_field($_POST['designation'] ?? ''),
            'organization'     => sanitize_text_field($_POST['organization'] ?? ''),
            'role'             => sanitize_key($_POST['role'] ?? 'speaker'),
            'image_id'         => !empty($_POST['image_id']) ? (int)$_POST['image_id'] : null,
            'image_alt'        => sanitize_text_field($_POST['image_alt'] ?? ''),
            'keynote_title'    => sanitize_text_field($_POST['keynote_title'] ?? ''),
            'keynote_abstract' => sanitize_textarea_field($_POST['keynote_abstract'] ?? ''),
            'biography'        => sanitize_textarea_field($_POST['biography'] ?? ''),
            'session_title'    => sanitize_text_field($_POST['session_title'] ?? ''),
            'session_schedule' => sanitize_text_field($_POST['session_schedule'] ?? ''),
            'sort_order'       => (int)($_POST['sort_order'] ?? 0),
            'published_status' => sanitize_key($_POST['published_status'] ?? 'published'),
        ];

        $res = LFA_Conference::save_speaker($data, get_current_user_id());
        if ($res['success']) {
            wp_safe_redirect(add_query_arg([
                'page'       => 'lfa-conference-speakers',
                'id'         => $conf_id,
                'lfa_notice' => 'speaker_saved',
            ], admin_url('users.php')));
        } else {
            wp_die(esc_html($res['error']));
        }
        exit;
    }

    /**
     * Handle Delete Speaker POST
     */
    public static function handle_delete_speaker(): void {
        if (!current_user_can(LFA_Conference::CAP_MANAGE_CONFERENCES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_delete_speaker_action', 'lfa_del_speaker_nonce');

        $speaker_id = (int)($_POST['speaker_id'] ?? 0);
        $conf_id = (int)($_POST['conference_id'] ?? 0);

        $res = LFA_Conference::delete_speaker($speaker_id, get_current_user_id());
        if ($res['success']) {
            wp_safe_redirect(add_query_arg([
                'page'       => 'lfa-conference-speakers',
                'id'         => $conf_id,
                'lfa_notice' => 'speaker_deleted',
            ], admin_url('users.php')));
        } else {
            wp_die(esc_html($res['error']));
        }
        exit;
    }

    /**
     * Screen 6: Conference Speakers & Keynote Management
     */
    public static function render_speakers_page(): void {
        $conf_id = (int)($_GET['id'] ?? 1);
        $conf = LFA_Conference::get_conference_by_id($conf_id);
        if (!$conf) {
            $conf = LFA_Conference::get_conference();
            $conf_id = $conf ? (int)$conf->id : 1;
        }

        $all_confs = LFA_Conference::get_all_conferences(false);
        $speakers = LFA_Conference::get_conference_speakers($conf_id, false);

        $edit_id = (int)($_GET['edit_speaker_id'] ?? 0);
        $edit_speaker = $edit_id > 0 ? LFA_Conference::get_speaker($edit_id) : null;
        $notice = sanitize_key($_GET['lfa_notice'] ?? '');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Conference Speakers &amp; Keynotes</h1>
            <a href="<?php echo esc_url(admin_url('users.php?page=lfa-conferences')); ?>" class="page-title-action">&larr; Back to Conferences</a>
            <hr class="wp-header-end">

            <?php if ($notice === 'speaker_saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Speaker saved successfully.</p></div>
            <?php elseif ($notice === 'speaker_deleted'): ?>
                <div class="notice notice-success is-dismissible"><p>Speaker deleted.</p></div>
            <?php endif; ?>

            <!-- Conference Selector -->
            <div style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin:20px 0; border-radius:4px;">
                <label for="conf-switcher" style="font-weight:700; margin-right:10px;">Select Conference:</label>
                <select id="conf-switcher" onchange="window.location.href='<?php echo esc_url(admin_url('users.php?page=lfa-conference-speakers&id=')); ?>' + this.value;">
                    <?php foreach ($all_confs as $c): ?>
                        <option value="<?php echo (int)$c->id; ?>" <?php selected($c->id, $conf_id); ?>>
                            <?php echo esc_html($c->title . ' (' . strtoupper($c->published_status) . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span style="margin-left:15px; color:#555;">Managing speakers for: <strong><?php echo esc_html($conf->title); ?></strong></span>
            </div>

            <div style="display:grid; grid-template-columns: 1.2fr 1fr; gap:24px; align-items:start;">
                <!-- Left: Speakers Table -->
                <div class="postbox" style="padding:15px;">
                    <h2>Current Speakers (<?php echo count($speakers); ?>)</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:60px;">Photo</th>
                                <th>Name &amp; Role</th>
                                <th>Keynote / Session</th>
                                <th style="width:75px;">Status</th>
                                <th style="width:110px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($speakers)): ?>
                                <tr><td colspan="5">No speakers configured for this conference yet.</td></tr>
                            <?php else: foreach ($speakers as $s):
                                $img_url = $s->image_id ? wp_get_attachment_image_url($s->image_id, 'thumbnail') : '';
                            ?>
                                <tr>
                                    <td>
                                        <?php if ($img_url): ?>
                                            <img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($s->name); ?>" style="width:44px; height:44px; border-radius:50%; object-fit:cover;">
                                        <?php else: ?>
                                            <div style="width:44px; height:44px; border-radius:50%; background:#0a203a; color:#c6953d; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px;">
                                                ✦
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($s->name); ?></strong>
                                        <br>
                                        <span class="badge" style="background:#<?php echo $s->role === 'keynote' ? 'c6953d' : '0073aa'; ?>; color:#fff; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:700;">
                                            <?php echo esc_html(strtoupper($s->role)); ?>
                                        </span>
                                        <?php if (!empty($s->designation) || !empty($s->organization)): ?>
                                            <small style="color:#666;"><br><?php echo esc_html(implode(', ', array_filter([$s->designation, $s->organization]))); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($s->role === 'keynote' && !empty($s->keynote_title)): ?>
                                            <strong>Keynote:</strong> <?php echo esc_html($s->keynote_title); ?>
                                        <?php elseif (!empty($s->session_title)): ?>
                                            <strong>Session:</strong> <?php echo esc_html($s->session_title); ?>
                                        <?php else: ?>
                                            <span style="color:#888;">TBA</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="color:<?php echo $s->published_status === 'published' ? '#46b450' : '#999'; ?>; font-weight:600;">
                                            <?php echo esc_html(ucfirst($s->published_status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('users.php?page=lfa-conference-speakers&id=' . $conf_id . '&edit_speaker_id=' . $s->id)); ?>" class="button button-small">Edit</a>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;" onsubmit="return confirm('Delete this speaker?');">
                                            <input type="hidden" name="action" value="lfa_delete_conference_speaker">
                                            <input type="hidden" name="speaker_id" value="<?php echo (int)$s->id; ?>">
                                            <input type="hidden" name="conference_id" value="<?php echo (int)$conf_id; ?>">
                                            <?php wp_nonce_field('lfa_delete_speaker_action', 'lfa_del_speaker_nonce'); ?>
                                            <button type="submit" class="button button-small button-link-delete">Del</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Right: Add / Edit Form -->
                <div class="postbox" style="padding:20px;">
                    <h2><?php echo $edit_speaker ? 'Edit Speaker: ' . esc_html($edit_speaker->name) : 'Add New Speaker'; ?></h2>
                    <?php if ($edit_speaker): ?>
                        <p><a href="<?php echo esc_url(admin_url('users.php?page=lfa-conference-speakers&id=' . $conf_id)); ?>">&larr; Cancel Edit (Add New)</a></p>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="lfa_save_conference_speaker">
                        <input type="hidden" name="conference_id" value="<?php echo (int)$conf_id; ?>">
                        <input type="hidden" name="speaker_id" value="<?php echo $edit_speaker ? (int)$edit_speaker->id : 0; ?>">
                        <?php wp_nonce_field('lfa_save_speaker_action', 'lfa_speaker_nonce'); ?>

                        <table class="form-table" style="margin-top:0;">
                            <tr>
                                <th scope="row"><label for="spk_name">Speaker Name <span class="required">*</span></label></th>
                                <td><input type="text" name="name" id="spk_name" class="regular-text widefat" value="<?php echo esc_attr($edit_speaker->name ?? ''); ?>" required placeholder="e.g. Dr. Uche Igbokwe"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="spk_role">Speaker Role</label></th>
                                <td>
                                    <select name="role" id="spk_role" class="regular-text">
                                        <option value="keynote" <?php selected($edit_speaker->role ?? '', 'keynote'); ?>>Keynote Speaker</option>
                                        <option value="speaker" <?php selected($edit_speaker->role ?? '', 'speaker'); ?>>General Speaker</option>
                                        <option value="panelist" <?php selected($edit_speaker->role ?? '', 'panelist'); ?>>Panelist</option>
                                        <option value="host" <?php selected($edit_speaker->role ?? '', 'host'); ?>>Session Host / Moderator</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="spk_desig">Designation</label></th>
                                <td><input type="text" name="designation" id="spk_desig" class="regular-text widefat" value="<?php echo esc_attr($edit_speaker->designation ?? ''); ?>" placeholder="e.g. Senior Pastor / Professor"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="spk_org">Organization / Country</label></th>
                                <td><input type="text" name="organization" id="spk_org" class="regular-text widefat" value="<?php echo esc_attr($edit_speaker->organization ?? ''); ?>" placeholder="e.g. United Kingdom"></td>
                            </tr>

                            <!-- Photograph Picker -->
                            <tr>
                                <th scope="row"><label>Photograph</label></th>
                                <td>
                                    <input type="hidden" name="image_id" id="spk_image_id" value="<?php echo esc_attr($edit_speaker->image_id ?? ''); ?>">
                                    <div id="spk_image_preview_wrap" style="margin-bottom:10px;">
                                        <?php if (!empty($edit_speaker->image_id) && ($img_src = wp_get_attachment_image_url($edit_speaker->image_id, 'medium'))): ?>
                                            <img id="spk_image_preview" src="<?php echo esc_url($img_src); ?>" style="max-width:120px; max-height:120px; border-radius:6px; display:block; border:1px solid #ddd; object-fit:cover;">
                                        <?php else: ?>
                                            <img id="spk_image_preview" src="" style="max-width:120px; max-height:120px; border-radius:6px; display:none; border:1px solid #ddd; object-fit:cover;">
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="button" id="spk_select_image_btn">Select Photo from Media</button>
                                    <button type="button" class="button" id="spk_remove_image_btn" style="<?php echo empty($edit_speaker->image_id) ? 'display:none;' : ''; ?>">Remove Photo</button>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="spk_image_alt">Photo Alt Text</label></th>
                                <td><input type="text" name="image_alt" id="spk_image_alt" class="regular-text widefat" value="<?php echo esc_attr($edit_speaker->image_alt ?? ''); ?>" placeholder="Accessible description for image"></td>
                            </tr>

                            <!-- Keynote Fields -->
                            <tr style="background:#fcfcfc;">
                                <th scope="row"><label for="spk_keynote_title">Keynote Title</label></th>
                                <td><input type="text" name="keynote_title" id="spk_keynote_title" class="regular-text widefat" value="<?php echo esc_attr($edit_speaker->keynote_title ?? ''); ?>" placeholder="Featured Keynote Heading"></td>
                            </tr>
                            <tr style="background:#fcfcfc;">
                                <th scope="row"><label for="spk_keynote_abstract">Keynote Abstract</label></th>
                                <td><textarea name="keynote_abstract" id="spk_keynote_abstract" rows="4" class="large-text"><?php echo esc_textarea($edit_speaker->keynote_abstract ?? ''); ?></textarea></td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="spk_bio">Biography</label></th>
                                <td><textarea name="biography" id="spk_bio" rows="4" class="large-text"><?php echo esc_textarea($edit_speaker->biography ?? ''); ?></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="spk_session">Session Title &amp; Schedule</label></th>
                                <td>
                                    <input type="text" name="session_title" id="spk_session" class="regular-text widefat" value="<?php echo esc_attr($edit_speaker->session_title ?? ''); ?>" placeholder="Session Title">
                                    <br><br>
                                    <input type="text" name="session_schedule" class="regular-text widefat" value="<?php echo esc_attr($edit_speaker->session_schedule ?? ''); ?>" placeholder="e.g. September 17, 2027 · Morning Plenary">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="spk_order">Display Order</label></th>
                                <td><input type="number" name="sort_order" id="spk_order" value="<?php echo (int)($edit_speaker->sort_order ?? 0); ?>" style="width:80px;"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="spk_status">Status</label></th>
                                <td>
                                    <select name="published_status" id="spk_status">
                                        <option value="published" <?php selected($edit_speaker->published_status ?? 'published', 'published'); ?>>Published</option>
                                        <option value="draft" <?php selected($edit_speaker->published_status ?? '', 'draft'); ?>>Draft / Hidden</option>
                                    </select>
                                </td>
                            </tr>
                        </table>

                        <p class="submit" style="margin-top:20px;">
                            <button type="submit" class="button button-primary"><?php echo $edit_speaker ? 'Update Speaker' : 'Save Speaker'; ?></button>
                        </p>
                    </form>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var selectBtn = document.getElementById('spk_select_image_btn');
            var removeBtn = document.getElementById('spk_remove_image_btn');
            var imgInput  = document.getElementById('spk_image_id');
            var imgPreview = document.getElementById('spk_image_preview');

            if (!selectBtn || typeof wp === 'undefined' || !wp.media) return;

            var frame;
            selectBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (frame) {
                    frame.open();
                    return;
                }
                frame = wp.media({
                    title: 'Select Speaker Photograph',
                    button: { text: 'Use this Photo' },
                    library: { type: 'image' },
                    multiple: false
                });

                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    imgInput.value = attachment.id;
                    imgPreview.src = attachment.url;
                    imgPreview.style.display = 'block';
                    removeBtn.style.display = 'inline-block';
                });

                frame.open();
            });

            removeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                imgInput.value = '';
                imgPreview.src = '';
                imgPreview.style.display = 'none';
                removeBtn.style.display = 'none';
            });
        });
        </script>
        <?php
    }
}
