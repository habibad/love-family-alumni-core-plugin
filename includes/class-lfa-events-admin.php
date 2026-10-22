<?php
/**
 * Love Family Alumni — Administrative Events Management
 *
 * Implements full WordPress admin controls for Events: create, edit,
 * draft, schedule, publish, unpublish, archive, delete, and conference linking.
 * Enforces editorial vs publishing capabilities (EVE-003).
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Events_Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_admin_menus'], 28);
        add_action('admin_post_lfa_save_event', [__CLASS__, 'handle_save_event']);
        add_action('admin_post_lfa_set_event_status', [__CLASS__, 'handle_set_event_status']);
        add_action('admin_post_lfa_delete_event', [__CLASS__, 'handle_delete_event']);
    }

    public static function register_admin_menus(): void {
        add_menu_page(
            'LFA Events',
            'Events & Gatherings',
            LFA_Events::CAP_EDIT_EVENTS,
            'lfa-events-admin',
            [__CLASS__, 'render_events_list_page'],
            'dashicons-calendar-alt',
            26
        );

        add_submenu_page(
            'lfa-events-admin',
            'All Events',
            'All Events',
            LFA_Events::CAP_EDIT_EVENTS,
            'lfa-events-admin',
            [__CLASS__, 'render_events_list_page']
        );

        add_submenu_page(
            'lfa-events-admin',
            'Add New Event',
            'Add New Event',
            LFA_Events::CAP_EDIT_EVENTS,
            'lfa-event-edit',
            [__CLASS__, 'render_event_edit_page']
        );
    }

    /**
     * Events Listing Admin Screen
     */
    public static function render_events_list_page(): void {
        if (!current_user_can(LFA_Events::CAP_EDIT_EVENTS)) {
            wp_die('Unauthorized: Insufficient permissions to view events.', 'Forbidden', ['response' => 403]);
        }

        $notice = sanitize_key($_GET['notice'] ?? '');
        $status_filter = sanitize_key($_GET['status'] ?? 'all');

        $query_args = [
            'post_type'      => LFA_Events::POST_TYPE,
            'post_status'    => ($status_filter === 'all') ? ['publish', 'draft', 'future', 'trash'] : $status_filter,
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        $events = get_posts($query_args);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Events & Gatherings Directory</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-event-edit')); ?>" class="page-title-action">Add New Event</a>
            <p class="description">Manage regional fellowship gatherings, conventions, reunions, service programs, and conference links.</p>
            <hr class="wp-header-end">

            <?php if ($notice === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Event saved successfully.</p></div>
            <?php elseif ($notice === 'published'): ?>
                <div class="notice notice-success is-dismissible"><p>Event published successfully.</p></div>
            <?php elseif ($notice === 'unpublished'): ?>
                <div class="notice notice-warning is-dismissible"><p>Event unpublished. It is no longer accessible to the public.</p></div>
            <?php elseif ($notice === 'deleted'): ?>
                <div class="notice notice-success is-dismissible"><p>Event moved to trash.</p></div>
            <?php elseif ($notice === 'error_dates'): ?>
                <div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Event end date/time cannot be earlier than start date/time.</p></div>
            <?php elseif ($notice === 'error_permission'): ?>
                <div class="notice notice-error is-dismissible"><p><strong>Unauthorized:</strong> You do not possess publishing authority to modify live event publication states.</p></div>
            <?php endif; ?>

            <div class="tablenav top">
                <ul class="subsubsub">
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-events-admin')); ?>" class="<?php echo $status_filter === 'all' ? 'current' : ''; ?>">All</a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-events-admin&status=publish')); ?>" class="<?php echo $status_filter === 'publish' ? 'current' : ''; ?>">Published</a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-events-admin&status=draft')); ?>" class="<?php echo $status_filter === 'draft' ? 'current' : ''; ?>">Drafts</a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-events-admin&status=future')); ?>" class="<?php echo $status_filter === 'future' ? 'current' : ''; ?>">Scheduled</a></li>
                </ul>
            </div>

            <table class="widefat striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="width: 40px;">ID</th>
                        <th>Event Title / Slug</th>
                        <th>Category</th>
                        <th>Event Schedule & Timezone</th>
                        <th>Venue / Location</th>
                        <th>Linked Conference</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($events)): ?>
                        <tr><td colspan="8">No events found in this view.</td></tr>
                    <?php else: foreach ($events as $p):
                        $event_data = LFA_Events::format_event_item($p);
                        ?>
                        <tr>
                            <td>#<?php echo esc_html($p->ID); ?></td>
                            <td>
                                <strong><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-event-edit&event_id=' . $p->ID)); ?>"><?php echo esc_html($p->post_title); ?></a></strong><br>
                                <code><?php echo esc_html($p->post_name); ?></code>
                                <?php if (!empty($event_data['summary'])): ?>
                                    <p style="margin: 4px 0 0; color: #555; font-size: 12px;"><?php echo esc_html(wp_trim_words($event_data['summary'], 12)); ?></p>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge" style="background:#e8f0fe;padding:2px 6px;border-radius:4px;"><?php echo esc_html($event_data['category_label']); ?></span></td>
                            <td>
                                <strong><?php echo esc_html($event_data['date_label']); ?></strong><br>
                                <small style="color: #666;"><?php echo esc_html($event_data['timezone']); ?></small>
                            </td>
                            <td><?php echo esc_html($event_data['location']); ?></td>
                            <td>
                                <?php if ($event_data['linked_conf_id'] > 0): ?>
                                    <span style="color:#0073aa; font-weight:600;">Conference #<?php echo esc_html($event_data['linked_conf_id']); ?></span>
                                <?php else: ?>
                                    <span style="color:#999;">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status_badge = [
                                    'publish' => ['label' => 'Published', 'bg' => '#e6f4ea', 'color' => '#137333'],
                                    'draft'   => ['label' => 'Draft', 'bg' => '#fef7e0', 'color' => '#b06000'],
                                    'future'  => ['label' => 'Scheduled', 'bg' => '#e8f0fe', 'color' => '#1a73e8'],
                                    'trash'   => ['label' => 'Trash', 'bg' => '#fce8e6', 'color' => '#c5221f'],
                                ];
                                $sb = $status_badge[$p->post_status] ?? ['label' => ucfirst($p->post_status), 'bg' => '#eee', 'color' => '#333'];
                                ?>
                                <span style="background:<?php echo esc_attr($sb['bg']); ?>; color:<?php echo esc_attr($sb['color']); ?>; padding:2px 8px; border-radius:4px; font-weight:600;">
                                    <?php echo esc_html($sb['label']); ?>
                                </span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-event-edit&event_id=' . $p->ID)); ?>" class="button button-small">Edit</a>
                                
                                <?php if (current_user_can(LFA_Events::CAP_PUBLISH_EVENTS)): ?>
                                    <?php if ($p->post_status === 'publish'): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                            <input type="hidden" name="action" value="lfa_set_event_status">
                                            <input type="hidden" name="event_id" value="<?php echo esc_attr($p->ID); ?>">
                                            <input type="hidden" name="new_status" value="draft">
                                            <?php wp_nonce_field('lfa_set_event_status_' . $p->ID); ?>
                                            <button type="submit" class="button button-small" onclick="return confirm('Unpublish this event? It will be removed from public calendar immediately.');">Unpublish</button>
                                        </form>
                                    <?php elseif ($p->post_status === 'draft'): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                            <input type="hidden" name="action" value="lfa_set_event_status">
                                            <input type="hidden" name="event_id" value="<?php echo esc_attr($p->ID); ?>">
                                            <input type="hidden" name="new_status" value="publish">
                                            <?php wp_nonce_field('lfa_set_event_status_' . $p->ID); ?>
                                            <button type="submit" class="button button-small button-primary">Publish</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($p->post_status !== 'trash' && current_user_can(LFA_Events::CAP_DELETE_EVENTS)): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                        <input type="hidden" name="action" value="lfa_delete_event">
                                        <input type="hidden" name="event_id" value="<?php echo esc_attr($p->ID); ?>">
                                        <?php wp_nonce_field('lfa_delete_event_' . $p->ID); ?>
                                        <button type="submit" class="button button-small button-link-delete" onclick="return confirm('Move this event to trash?');">Trash</button>
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
     * Event Create / Edit Screen
     */
    public static function render_event_edit_page(): void {
        if (!current_user_can(LFA_Events::CAP_EDIT_EVENTS)) {
            wp_die('Unauthorized: Insufficient permissions to edit events.', 'Forbidden', ['response' => 403]);
        }

        $event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
        $post = $event_id > 0 ? get_post($event_id) : null;

        $title   = $post ? $post->post_title : '';
        $slug    = $post ? $post->post_name : '';
        $excerpt = $post ? $post->post_excerpt : '';
        $content = $post ? $post->post_content : '';
        $status  = $post ? $post->post_status : 'draft';

        $start_raw  = $post ? (string)get_post_meta($post->ID, '_lfa_event_start', true) : '';
        $end_raw    = $post ? (string)get_post_meta($post->ID, '_lfa_event_end', true) : '';
        $timezone   = $post ? (string)get_post_meta($post->ID, '_lfa_event_timezone', true) : 'America/New_York';
        $date_only  = $post ? ((string)get_post_meta($post->ID, '_lfa_event_date_only', true) === '1') : false;
        $venue_name = $post ? (string)get_post_meta($post->ID, '_lfa_event_venue_name', true) : '';
        $venue_addr = $post ? (string)get_post_meta($post->ID, '_lfa_event_venue_address', true) : '';
        $is_online  = $post ? ((string)get_post_meta($post->ID, '_lfa_event_is_online', true) === '1') : false;
        $online_url = $post ? (string)get_post_meta($post->ID, '_lfa_event_online_url', true) : '';
        $online_app = $post ? ((string)get_post_meta($post->ID, '_lfa_event_online_approved', true) === '1') : false;
        $linked_conf= $post ? (int)get_post_meta($post->ID, '_lfa_event_linked_conf_id', true) : 0;

        $terms = $post ? wp_get_post_terms($post->ID, LFA_Events::TAXONOMY) : [];
        $current_cat = !empty($terms) ? $terms[0]->slug : 'community';

        $all_categories = get_terms(['taxonomy' => LFA_Events::TAXONOMY, 'hide_empty' => false]);

        // Get conferences for linking dropdown
        global $wpdb;
        $confs = [];
        if (class_exists('LFA_Conference')) {
            $confs = LFA_Conference::get_all_conferences(false);
        }

        $timezones = [
            'America/New_York'    => 'Eastern Time (US / Canada) — America/New_York',
            'America/Chicago'     => 'Central Time (US / Canada) — America/Chicago',
            'America/Denver'      => 'Mountain Time (US / Canada) — America/Denver',
            'America/Los_Angeles' => 'Pacific Time (US / Canada) — America/Los_Angeles',
            'America/Toronto'     => 'Toronto — America/Toronto',
            'Europe/London'       => 'London (GMT/BST) — Europe/London',
            'Africa/Lagos'        => 'West Africa Time (Lagos) — Africa/Lagos',
            'UTC'                 => 'Coordinated Universal Time — UTC',
        ];

        // Format dates for HTML datetime-local inputs
        $start_input = $start_raw ? date('Y-m-d\TH:i', strtotime($start_raw)) : '';
        $end_input   = $end_raw ? date('Y-m-d\TH:i', strtotime($end_raw)) : '';
        ?>
        <div class="wrap">
            <h1><?php echo $post ? 'Edit Event: ' . esc_html($post->post_title) : 'Create New Event'; ?></h1>
            <p class="description">Configure event timing, IANA timezone, confirmed physical/virtual venue details, and cross-linking.</p>
            <hr class="wp-header-end">

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width: 900px; margin-top: 20px;">
                <input type="hidden" name="action" value="lfa_save_event">
                <input type="hidden" name="event_id" value="<?php echo esc_attr($event_id); ?>">
                <?php wp_nonce_field('lfa_save_event_action', 'lfa_event_nonce'); ?>

                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <h2>Basic Information</h2>
                    <p>
                        <label for="event_title"><strong>Event Title *</strong></label><br>
                        <input type="text" id="event_title" name="title" class="widefat" required value="<?php echo esc_attr($title); ?>" placeholder="e.g. 2027 Alumni National Conference">
                    </p>

                    <p>
                        <label for="event_slug"><strong>Slug (Permanent Identifier)</strong></label><br>
                        <input type="text" id="event_slug" name="slug" class="widefat" value="<?php echo esc_attr($slug); ?>" placeholder="Leave blank to auto-generate from title">
                    </p>

                    <p>
                        <label for="event_category"><strong>Event Category</strong></label><br>
                        <select id="event_category" name="category" class="widefat">
                            <?php foreach ($all_categories as $c): ?>
                                <option value="<?php echo esc_attr($c->slug); ?>" <?php selected($current_cat, $c->slug); ?>><?php echo esc_html($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label for="event_summary"><strong>Short Summary / Excerpt</strong></label><br>
                        <textarea id="event_summary" name="summary" rows="3" class="widefat" placeholder="A concise 1-2 sentence overview for the event cards"><?php echo esc_textarea($excerpt); ?></textarea>
                    </p>

                    <p>
                        <label for="event_content"><strong>Event Full Description & Details</strong></label><br>
                        <?php
                        wp_editor($content, 'event_content', [
                            'textarea_name' => 'content',
                            'textarea_rows' => 8,
                            'media_buttons' => true,
                        ]);
                        ?>
                    </p>
                </div>

                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <h2>Date, Timing & Timezone (EVE-008, EVE-009, EVE-010)</h2>
                    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 250px;">
                            <label for="event_start"><strong>Start Date & Time *</strong></label><br>
                            <input type="datetime-local" id="event_start" name="starts_at" class="widefat" required value="<?php echo esc_attr($start_input); ?>">
                        </div>
                        <div style="flex: 1; min-width: 250px;">
                            <label for="event_end"><strong>End Date & Time *</strong></label><br>
                            <input type="datetime-local" id="event_end" name="ends_at" class="widefat" required value="<?php echo esc_attr($end_input); ?>">
                            <small style="color: #666;">End date must be equal to or later than start date.</small>
                        </div>
                    </div>

                    <p style="margin-top: 15px;">
                        <label for="event_timezone"><strong>Official IANA Timezone</strong></label><br>
                        <select id="event_timezone" name="timezone" class="widefat">
                            <?php foreach ($timezones as $tz_key => $tz_label): ?>
                                <option value="<?php echo esc_attr($tz_key); ?>" <?php selected($timezone, $tz_key); ?>><?php echo esc_html($tz_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label>
                            <input type="checkbox" name="date_only" value="1" <?php checked($date_only); ?>>
                            <strong>All-Day / Date-Only Event</strong> (Hides artificial hours/minutes from public display)
                        </label>
                    </p>
                </div>

                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <h2>Location & Online Joining Details (EVE-016, EVE-017)</h2>
                    <p>
                        <label for="venue_name"><strong>Confirmed Physical Venue Name</strong></label><br>
                        <input type="text" id="venue_name" name="venue_name" class="widefat" value="<?php echo esc_attr($venue_name); ?>" placeholder="e.g. North America Convention Center, Omaha, NE">
                        <small style="color:#666;">Leave empty if venue is not yet confirmed; will display as 'North America Zone TBA'.</small>
                    </p>

                    <p>
                        <label for="venue_address"><strong>Venue Address</strong></label><br>
                        <input type="text" id="venue_address" name="venue_address" class="widefat" value="<?php echo esc_attr($venue_addr); ?>" placeholder="e.g. 123 Fellowship Way, Omaha, NE">
                    </p>

                    <hr style="margin: 15px 0;">

                    <p>
                        <label>
                            <input type="checkbox" name="is_online" value="1" <?php checked($is_online); ?>>
                            <strong>Virtual / Online Fellowship Event</strong>
                        </label>
                    </p>

                    <p>
                        <label for="online_url"><strong>Online Joining / Streaming Link</strong></label><br>
                        <input type="url" id="online_url" name="online_url" class="widefat" value="<?php echo esc_attr($online_url); ?>" placeholder="https://zoom.us/j/... or streaming link">
                        <small style="color:#c5221f;">Private meeting passwords or security tokens must NEVER be placed in public URLs.</small>
                    </p>

                    <p>
                        <label>
                            <input type="checkbox" name="online_approved" value="1" <?php checked($online_app); ?>>
                            <strong>Approved for Public Display</strong> (Only approved links will be shown on public event pages)
                        </label>
                    </p>
                </div>

                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <h2>Conference Cross-Linking (EVE-018)</h2>
                    <p class="description">If this event represents a biennial conference or summit, select the matching conference below. The public card will route directly to the conference portal rather than creating duplicate registration or checkout workflows.</p>
                    <p>
                        <label for="linked_conf"><strong>Link to Existing Conference</strong></label><br>
                        <select id="linked_conf" name="linked_conf_id" class="widefat">
                            <option value="0">&mdash; None (Independent General Event) &mdash;</option>
                            <?php foreach ($confs as $c): ?>
                                <option value="<?php echo esc_attr($c->id); ?>" <?php selected($linked_conf, (int)$c->id); ?>>
                                    #<?php echo esc_html($c->id); ?> &mdash; <?php echo esc_html($c->title); ?> (Status: <?php echo esc_html($c->published_status); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </div>

                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <h2>Publishing Controls (EVE-002, EVE-003, EVE-006)</h2>
                    <p>
                        <label for="event_status"><strong>Publication Status</strong></label><br>
                        <select id="event_status" name="post_status" class="widefat">
                            <option value="draft" <?php selected($status, 'draft'); ?>>Draft (Internal &mdash; Not Public)</option>
                            <?php if (current_user_can(LFA_Events::CAP_PUBLISH_EVENTS)): ?>
                                <option value="publish" <?php selected($status, 'publish'); ?>>Published (Live on Public Calendar)</option>
                                <option value="future" <?php selected($status, 'future'); ?>>Scheduled (Future Auto-Publish)</option>
                            <?php endif; ?>
                        </select>
                        <?php if (!current_user_can(LFA_Events::CAP_PUBLISH_EVENTS)): ?>
                            <small style="color:#b06000;">Your role (Editor) can save drafts. Publishing requires Communications Publisher / Administrator authority.</small>
                        <?php endif; ?>
                    </p>

                    <p style="margin-top: 20px;">
                        <button type="submit" class="button button-primary button-large">
                            <?php echo $post ? 'Save & Update Event' : 'Create Event Record'; ?>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-events-admin')); ?>" class="button button-large">Cancel</a>
                    </p>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle Save Event POST
     */
    public static function handle_save_event(): void {
        if (!current_user_can(LFA_Events::CAP_EDIT_EVENTS)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }
        check_admin_referer('lfa_save_event_action', 'lfa_event_nonce');

        $user_id   = get_current_user_id();
        $event_id  = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
        $title     = sanitize_text_field($_POST['title'] ?? '');
        $slug      = sanitize_title($_POST['slug'] ?? $title);
        $summary   = sanitize_textarea_field($_POST['summary'] ?? '');
        $content   = wp_kses_post($_POST['content'] ?? '');
        $category  = sanitize_title($_POST['category'] ?? 'community');

        $starts_at = sanitize_text_field($_POST['starts_at'] ?? '');
        $ends_at   = sanitize_text_field($_POST['ends_at'] ?? '');
        $timezone  = sanitize_text_field($_POST['timezone'] ?? 'America/New_York');
        $date_only = !empty($_POST['date_only']) ? '1' : '0';

        $venue_name  = sanitize_text_field($_POST['venue_name'] ?? '');
        $venue_addr  = sanitize_text_field($_POST['venue_address'] ?? '');
        $is_online   = !empty($_POST['is_online']) ? '1' : '0';
        $online_url  = esc_url_raw($_POST['online_url'] ?? '');
        $online_appr = !empty($_POST['online_approved']) ? '1' : '0';
        $linked_conf = isset($_POST['linked_conf_id']) ? (int)$_POST['linked_conf_id'] : 0;

        $target_status = sanitize_key($_POST['post_status'] ?? 'draft');

        // Permissions check: Only CAP_PUBLISH_EVENTS can set 'publish' or 'future' (EVE-003)
        if (in_array($target_status, ['publish', 'future'], true) && !current_user_can(LFA_Events::CAP_PUBLISH_EVENTS)) {
            $target_status = 'draft';
        }

        // Chronology Validation (EVE-008: Reject end earlier than start)
        if (!empty($starts_at) && !empty($ends_at)) {
            if (!LFA_Events::validate_dates($starts_at, $ends_at)) {
                $redirect = add_query_arg(['notice' => 'error_dates', 'event_id' => $event_id], admin_url('admin.php?page=lfa-event-edit'));
                wp_safe_redirect($redirect);
                exit;
            }
        }

        // Format datetimes to MySQL format
        $mysql_start = !empty($starts_at) ? date('Y-m-d H:i:s', strtotime($starts_at)) : '';
        $mysql_end   = !empty($ends_at) ? date('Y-m-d H:i:s', strtotime($ends_at)) : '';

        $post_data = [
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_excerpt' => $summary,
            'post_content' => $content,
            'post_status'  => $target_status,
            'post_type'    => LFA_Events::POST_TYPE,
        ];

        if ($event_id > 0) {
            $post_data['ID'] = $event_id;
            wp_update_post($post_data);
            $action = 'event_updated';
        } else {
            $post_data['post_author'] = $user_id;
            $event_id = wp_insert_post($post_data);
            $action = 'event_created';
        }

        if ($event_id && !is_wp_error($event_id)) {
            // Set category taxonomy
            wp_set_object_terms($event_id, $category, LFA_Events::TAXONOMY);

            // Set metadata
            update_post_meta($event_id, '_lfa_event_start', $mysql_start);
            update_post_meta($event_id, '_lfa_event_end', $mysql_end);
            update_post_meta($event_id, '_lfa_event_timezone', $timezone);
            update_post_meta($event_id, '_lfa_event_date_only', $date_only);
            update_post_meta($event_id, '_lfa_event_venue_name', $venue_name);
            update_post_meta($event_id, '_lfa_event_venue_address', $venue_addr);
            update_post_meta($event_id, '_lfa_event_is_online', $is_online);
            update_post_meta($event_id, '_lfa_event_online_url', $online_url);
            update_post_meta($event_id, '_lfa_event_online_approved', $online_appr);
            update_post_meta($event_id, '_lfa_event_linked_conf_id', $linked_conf);
            update_post_meta($event_id, '_lfa_event_status', $target_status);

            // Audit logging
            if (class_exists('LFA_Content_Audit')) {
                LFA_Content_Audit::log('staff', $user_id, $action, 'event', (string)$event_id, [
                    'title'  => $title,
                    'status' => $target_status,
                ]);
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=lfa-events-admin&notice=saved'));
        exit;
    }

    /**
     * Handle Status Transition (Publish / Unpublish)
     */
    public static function handle_set_event_status(): void {
        $event_id   = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
        $new_status = isset($_POST['new_status']) ? sanitize_key($_POST['new_status']) : '';
        check_admin_referer('lfa_set_event_status_' . $event_id);

        if (!current_user_can(LFA_Events::CAP_PUBLISH_EVENTS)) {
            wp_die('Unauthorized: Publishing authority required to alter event state.', 'Forbidden', ['response' => 403]);
        }

        if ($event_id > 0 && in_array($new_status, ['publish', 'draft', 'trash'], true)) {
            wp_update_post([
                'ID'          => $event_id,
                'post_status' => $new_status,
            ]);
            update_post_meta($event_id, '_lfa_event_status', $new_status);

            if (class_exists('LFA_Content_Audit')) {
                LFA_Content_Audit::log('staff', get_current_user_id(), 'event_status_changed', 'event', (string)$event_id, [
                    'to_status' => $new_status,
                ]);
            }

            $notice = ($new_status === 'publish') ? 'published' : 'unpublished';
            wp_safe_redirect(admin_url('admin.php?page=lfa-events-admin&notice=' . $notice));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=lfa-events-admin'));
        exit;
    }

    /**
     * Handle Delete / Trash Event (EVE-020)
     */
    public static function handle_delete_event(): void {
        $event_id = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
        check_admin_referer('lfa_delete_event_' . $event_id);

        if (!current_user_can(LFA_Events::CAP_DELETE_EVENTS)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        if ($event_id > 0) {
            wp_trash_post($event_id); // Safe trash, no permanent accidental data loss
            if (class_exists('LFA_Content_Audit')) {
                LFA_Content_Audit::log('staff', get_current_user_id(), 'event_trashed', 'event', (string)$event_id);
            }
            wp_safe_redirect(admin_url('admin.php?page=lfa-events-admin&notice=deleted'));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=lfa-events-admin'));
        exit;
    }
}
