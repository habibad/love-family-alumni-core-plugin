<?php
/**
 * Love Family Alumni — Administrative News Management
 *
 * Implements editorial authoring, explicit publishing authority enforcement (NEW-009),
 * strict privacy consent gate for personal announcements (NEW-010), retraction workflows,
 * and safe opt-in notifications.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_News_Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_admin_menus'], 29);
        add_action('admin_post_lfa_save_news', [__CLASS__, 'handle_save_news']);
        add_action('admin_post_lfa_set_news_status', [__CLASS__, 'handle_set_news_status']);
        add_action('admin_post_lfa_delete_news', [__CLASS__, 'handle_delete_news']);
    }

    public static function register_admin_menus(): void {
        add_menu_page(
            'LFA News',
            'News & Updates',
            LFA_News::CAP_EDIT_NEWS,
            'lfa-news-admin',
            [__CLASS__, 'render_news_list_page'],
            'dashicons-megaphone',
            27
        );

        add_submenu_page(
            'lfa-news-admin',
            'All Stories',
            'All Stories',
            LFA_News::CAP_EDIT_NEWS,
            'lfa-news-admin',
            [__CLASS__, 'render_news_list_page']
        );

        add_submenu_page(
            'lfa-news-admin',
            'Add News Article',
            'Add Article',
            LFA_News::CAP_EDIT_NEWS,
            'lfa-news-edit',
            [__CLASS__, 'render_news_edit_page']
        );
    }

    /**
     * News Listing Admin Screen
     */
    public static function render_news_list_page(): void {
        if (!current_user_can(LFA_News::CAP_EDIT_NEWS)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        $notice = sanitize_key($_GET['notice'] ?? '');
        $status_filter = sanitize_key($_GET['status'] ?? 'all');

        $query_args = [
            'post_type'      => LFA_News::POST_TYPE,
            'post_status'    => ($status_filter === 'all') ? ['publish', 'draft', 'future', 'trash'] : $status_filter,
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        $stories = get_posts($query_args);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">News & Announcements Management</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-news-edit')); ?>" class="page-title-action">Add Article</a>
            <p class="description">Administer official communications, alumni achievements, celebrations, awards, and bereavement notices.</p>
            <hr class="wp-header-end">

            <?php if ($notice === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Article saved successfully.</p></div>
            <?php elseif ($notice === 'published'): ?>
                <div class="notice notice-success is-dismissible"><p>Article published successfully.</p></div>
            <?php elseif ($notice === 'withdrawn'): ?>
                <div class="notice notice-warning is-dismissible"><p>Article withdrawn. It has been removed from the public website, search, and API immediately.</p></div>
            <?php elseif ($notice === 'deleted'): ?>
                <div class="notice notice-success is-dismissible"><p>Article moved to trash.</p></div>
            <?php elseif ($notice === 'consent_denied'): ?>
                <div class="notice notice-error is-dismissible"><p><strong>Publishing Blocked (NEW-010):</strong> Personal announcement categories (Birthdays, Weddings, Bereavements, Promotions, Awards) require documented member consent/authorization before publication. Saved as Draft.</p></div>
            <?php elseif ($notice === 'error_permission'): ?>
                <div class="notice notice-error is-dismissible"><p><strong>Unauthorized:</strong> Editor role cannot publish stories without Communications Publisher authority.</p></div>
            <?php endif; ?>

            <div class="tablenav top">
                <ul class="subsubsub">
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-news-admin')); ?>" class="<?php echo $status_filter === 'all' ? 'current' : ''; ?>">All</a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-news-admin&status=publish')); ?>" class="<?php echo $status_filter === 'publish' ? 'current' : ''; ?>">Published</a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-news-admin&status=draft')); ?>" class="<?php echo $status_filter === 'draft' ? 'current' : ''; ?>">Drafts</a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-news-admin&status=future')); ?>" class="<?php echo $status_filter === 'future' ? 'current' : ''; ?>">Scheduled</a></li>
                </ul>
            </div>

            <table class="widefat striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="width: 40px;">ID</th>
                        <th>Article Title / Slug</th>
                        <th>Category</th>
                        <th>Consent Status</th>
                        <th>Attribution</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stories)): ?>
                        <tr><td colspan="8">No articles found in this view.</td></tr>
                    <?php else: foreach ($stories as $p):
                        $item = LFA_News::format_news_item($p);
                        ?>
                        <tr>
                            <td>#<?php echo esc_html($p->ID); ?></td>
                            <td>
                                <strong><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-news-edit&story_id=' . $p->ID)); ?>"><?php echo esc_html($p->post_title); ?></a></strong><br>
                                <code><?php echo esc_html($p->post_name); ?></code>
                            </td>
                            <td><span class="badge" style="background:#e8f0fe;padding:2px 6px;border-radius:4px;"><?php echo esc_html($item['category_label']); ?></span></td>
                            <td>
                                <?php if (LFA_News::requires_consent($item['category'])): ?>
                                    <?php if ($item['consent_ok']): ?>
                                        <span style="color:#137333; font-weight:600;">Verified & Approved</span>
                                    <?php else: ?>
                                        <span style="color:#c5221f; font-weight:700; background:#fce8e6; padding:2px 6px; border-radius:4px;">Consent Pending</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#666;">N/A (General)</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($item['author']); ?></td>
                            <td><?php echo esc_html($item['date']); ?></td>
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
                                <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-news-edit&story_id=' . $p->ID)); ?>" class="button button-small">Edit</a>

                                <?php if (current_user_can(LFA_News::CAP_PUBLISH_NEWS)): ?>
                                    <?php if ($p->post_status === 'publish'): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                            <input type="hidden" name="action" value="lfa_set_news_status">
                                            <input type="hidden" name="story_id" value="<?php echo esc_attr($p->ID); ?>">
                                            <input type="hidden" name="new_status" value="draft">
                                            <?php wp_nonce_field('lfa_set_news_status_' . $p->ID); ?>
                                            <button type="submit" class="button button-small" onclick="return confirm('Withdraw article? It will disappear from public search and listings immediately.');">Withdraw</button>
                                        </form>
                                    <?php elseif ($p->post_status === 'draft'): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                            <input type="hidden" name="action" value="lfa_set_news_status">
                                            <input type="hidden" name="story_id" value="<?php echo esc_attr($p->ID); ?>">
                                            <input type="hidden" name="new_status" value="publish">
                                            <?php wp_nonce_field('lfa_set_news_status_' . $p->ID); ?>
                                            <button type="submit" class="button button-small button-primary">Publish</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($p->post_status !== 'trash' && current_user_can(LFA_News::CAP_DELETE_NEWS)): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                        <input type="hidden" name="action" value="lfa_delete_news">
                                        <input type="hidden" name="story_id" value="<?php echo esc_attr($p->ID); ?>">
                                        <?php wp_nonce_field('lfa_delete_news_' . $p->ID); ?>
                                        <button type="submit" class="button button-small button-link-delete" onclick="return confirm('Move article to trash?');">Trash</button>
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
     * News Create / Edit Screen
     */
    public static function render_news_edit_page(): void {
        if (!current_user_can(LFA_News::CAP_EDIT_NEWS)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        $story_id = isset($_GET['story_id']) ? (int)$_GET['story_id'] : 0;
        $post = $story_id > 0 ? get_post($story_id) : null;

        $title   = $post ? $post->post_title : '';
        $slug    = $post ? $post->post_name : '';
        $excerpt = $post ? $post->post_excerpt : '';
        $content = $post ? $post->post_content : '';
        $status  = $post ? $post->post_status : 'draft';

        $attribution = $post ? (string)get_post_meta($post->ID, '_lfa_news_author_attribution', true) : 'Love Family Communications';
        $consent_ok  = $post ? ((string)get_post_meta($post->ID, '_lfa_news_consent_confirmed', true) === '1') : false;
        $consent_ref = $post ? (string)get_post_meta($post->ID, '_lfa_news_consent_notes', true) : '';
        $is_featured = $post ? ((string)get_post_meta($post->ID, '_lfa_news_featured', true) === '1') : false;

        $terms = $post ? wp_get_post_terms($post->ID, LFA_News::TAXONOMY) : [];
        $current_cat = !empty($terms) ? $terms[0]->slug : 'general';

        $all_categories = get_terms(['taxonomy' => LFA_News::TAXONOMY, 'hide_empty' => false]);
        ?>
        <div class="wrap">
            <h1><?php echo $post ? 'Edit Story: ' . esc_html($post->post_title) : 'Write New Article / Announcement'; ?></h1>
            <p class="description">Publish official announcements and member milestones with verified consent controls.</p>
            <hr class="wp-header-end">

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width: 900px; margin-top: 20px;">
                <input type="hidden" name="action" value="lfa_save_news">
                <input type="hidden" name="story_id" value="<?php echo esc_attr($story_id); ?>">
                <?php wp_nonce_field('lfa_save_news_action', 'lfa_news_nonce'); ?>

                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <h2>Article Details</h2>
                    <p>
                        <label for="news_title"><strong>Headline / Title *</strong></label><br>
                        <input type="text" id="news_title" name="title" class="widefat" required value="<?php echo esc_attr($title); ?>" placeholder="e.g. Alumni Milestone Announcement">
                    </p>

                    <p>
                        <label for="news_slug"><strong>Slug (URL Identifier)</strong></label><br>
                        <input type="text" id="news_slug" name="slug" class="widefat" value="<?php echo esc_attr($slug); ?>" placeholder="Leave blank to auto-generate">
                    </p>

                    <p>
                        <label for="news_category"><strong>Category *</strong></label><br>
                        <select id="news_category" name="category" class="widefat">
                            <?php foreach ($all_categories as $c): ?>
                                <option value="<?php echo esc_attr($c->slug); ?>" <?php selected($current_cat, $c->slug); ?>><?php echo esc_html($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label for="news_author"><strong>Author / Editorial Attribution</strong></label><br>
                        <input type="text" id="news_author" name="author_attribution" class="widefat" value="<?php echo esc_attr($attribution); ?>">
                    </p>

                    <p>
                        <label for="news_summary"><strong>Short Excerpt / Lead Paragraph</strong></label><br>
                        <textarea id="news_summary" name="summary" rows="3" class="widefat"><?php echo esc_textarea($excerpt); ?></textarea>
                    </p>

                    <p>
                        <label for="news_content"><strong>Article Body Content</strong></label><br>
                        <?php
                        wp_editor($content, 'news_content', [
                            'textarea_name' => 'content',
                            'textarea_rows' => 10,
                            'media_buttons' => true,
                        ]);
                        ?>
                    </p>

                    <p>
                        <label>
                            <input type="checkbox" name="is_featured" value="1" <?php checked($is_featured); ?>>
                            <strong>Set as Featured News Story</strong> (Highlighted in the 55/45 editorial split banner)
                        </label>
                    </p>
                </div>

                <div class="card" style="padding: 20px; margin-bottom: 20px; border-left: 4px solid #c6953d;">
                    <h2>Privacy, Consent & Authorization Gate (NEW-010, NEW-011)</h2>
                    <p class="description">
                        Per strict privacy policy: Personal announcements (Birthdays, Weddings, Promotions, Awards, Achievements, Bereavements)
                        must <strong>never</strong> be published without documented authorization from the subject or next of kin.
                        Private member profile avatars must not be pulled automatically without explicit permission.
                    </p>

                    <p>
                        <label>
                            <input type="checkbox" name="consent_confirmed" value="1" <?php checked($consent_ok); ?>>
                            <strong style="color: #071d3e;">Documented Consent & Authorization Verified</strong>
                        </label>
                    </p>

                    <p>
                        <label for="consent_notes"><strong>Authorization Reference / Notes</strong></label><br>
                        <input type="text" id="consent_notes" name="consent_notes" class="widefat" value="<?php echo esc_attr($consent_ref); ?>" placeholder="e.g. Written release via email from Jane Doe on Oct 18, 2026">
                        <small style="color: #666;">Provide documented reference for governance audit.</small>
                    </p>
                </div>

                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <h2>Publishing Controls (NEW-009)</h2>
                    <p>
                        <label for="news_status"><strong>Publication Status</strong></label><br>
                        <select id="news_status" name="post_status" class="widefat">
                            <option value="draft" <?php selected($status, 'draft'); ?>>Draft (Internal &mdash; Not Public)</option>
                            <?php if (current_user_can(LFA_News::CAP_PUBLISH_NEWS)): ?>
                                <option value="publish" <?php selected($status, 'publish'); ?>>Published (Live Immediately)</option>
                                <option value="future" <?php selected($status, 'future'); ?>>Scheduled</option>
                            <?php endif; ?>
                        </select>
                        <?php if (!current_user_can(LFA_News::CAP_PUBLISH_NEWS)): ?>
                            <small style="color:#b06000;">Editors may save drafts. Publishing requires explicit Communications Publisher authority.</small>
                        <?php endif; ?>
                    </p>

                    <p style="margin-top: 20px;">
                        <button type="submit" class="button button-primary button-large">
                            <?php echo $post ? 'Save & Update Article' : 'Save Article'; ?>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-news-admin')); ?>" class="button button-large">Cancel</a>
                    </p>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle Save News POST
     */
    public static function handle_save_news(): void {
        if (!current_user_can(LFA_News::CAP_EDIT_NEWS)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }
        check_admin_referer('lfa_save_news_action', 'lfa_news_nonce');

        $user_id   = get_current_user_id();
        $story_id  = isset($_POST['story_id']) ? (int)$_POST['story_id'] : 0;
        $title     = sanitize_text_field($_POST['title'] ?? '');
        $slug      = sanitize_title($_POST['slug'] ?? $title);
        $summary   = sanitize_textarea_field($_POST['summary'] ?? '');
        $content   = wp_kses_post($_POST['content'] ?? '');
        $category  = sanitize_title($_POST['category'] ?? 'general');

        $attribution = sanitize_text_field($_POST['author_attribution'] ?? 'Love Family Communications');
        $consent_ok  = !empty($_POST['consent_confirmed']) ? '1' : '0';
        $consent_ref = sanitize_text_field($_POST['consent_notes'] ?? '');
        $is_featured = !empty($_POST['is_featured']) ? '1' : '0';

        $target_status = sanitize_key($_POST['post_status'] ?? 'draft');

        // Permission gate (NEW-009): Only CAP_PUBLISH_NEWS can publish
        if (in_array($target_status, ['publish', 'future'], true) && !current_user_can(LFA_News::CAP_PUBLISH_NEWS)) {
            $target_status = 'draft';
        }

        // Privacy & Consent Gate (NEW-010): Personal story without consent cannot publish!
        $consent_denied = false;
        if (in_array($target_status, ['publish', 'future'], true) && LFA_News::requires_consent($category)) {
            if ($consent_ok !== '1') {
                $target_status = 'draft'; // Fail-closed!
                $consent_denied = true;
            }
        }

        $post_data = [
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_excerpt' => $summary,
            'post_content' => $content,
            'post_status'  => $target_status,
            'post_type'    => LFA_News::POST_TYPE,
        ];

        $is_new_publish = false;
        if ($story_id > 0) {
            $existing = get_post($story_id);
            if ($existing && $existing->post_status !== 'publish' && $target_status === 'publish') {
                $is_new_publish = true;
            }
            $post_data['ID'] = $story_id;
            wp_update_post($post_data);
            $action = 'news_updated';
        } else {
            $post_data['post_author'] = $user_id;
            $story_id = wp_insert_post($post_data);
            $action = 'news_created';
            if ($target_status === 'publish') {
                $is_new_publish = true;
            }
        }

        if ($story_id && !is_wp_error($story_id)) {
            wp_set_object_terms($story_id, $category, LFA_News::TAXONOMY);

            update_post_meta($story_id, '_lfa_news_author_attribution', $attribution);
            update_post_meta($story_id, '_lfa_news_consent_confirmed', $consent_ok);
            update_post_meta($story_id, '_lfa_news_consent_notes', $consent_ref);
            update_post_meta($story_id, '_lfa_news_featured', $is_featured);
            update_post_meta($story_id, '_lfa_news_status', $target_status);

            if (class_exists('LFA_Content_Audit')) {
                LFA_Content_Audit::log('staff', $user_id, $action, 'news', (string)$story_id, [
                    'title'    => $title,
                    'category' => $category,
                    'status'   => $target_status,
                    'consent'  => $consent_ok,
                ]);
            }

            // Notification Trigger (NEW-020: only when published and opt-in)
            if ($is_new_publish && $target_status === 'publish') {
                self::dispatch_optin_notification($story_id, $title, $summary);
            }
        }

        $notice = $consent_denied ? 'consent_denied' : 'saved';
        wp_safe_redirect(admin_url('admin.php?page=lfa-news-admin&notice=' . $notice));
        exit;
    }

    /**
     * Dispatch notification to opted-in users only (NEW-020)
     */
    public static function dispatch_optin_notification(int $story_id, string $title, string $summary): void {
        // Find users who have explicitly opted in to news emails
        $users = get_users([
            'meta_key'   => 'lfa_email_optin_news',
            'meta_value' => '1',
            'number'     => 10, // Controlled batch
        ]);

        if (empty($users)) {
            return;
        }

        $subject = 'LFA Announcement: ' . $title;
        $body = "Dear Alumni Member,\n\nA new announcement has been published on the Love Family Alumni portal:\n\n"
              . $title . "\n\n"
              . $summary . "\n\n"
              . "Read the full story: " . home_url('/news-details/?story=' . get_post_field('post_name', $story_id)) . "\n\n"
              . "Love Family Alumni — North America Zone";

        foreach ($users as $u) {
            wp_mail($u->user_email, $subject, $body);
            if (class_exists('LFA_Core')) {
                LFA_Core::log_email_event('news_announcement', (int)$u->ID, $u->user_email, 'sent');
            }
        }
    }

    /**
     * Handle Status Transition (Publish / Withdraw)
     */
    public static function handle_set_news_status(): void {
        $story_id   = isset($_POST['story_id']) ? (int)$_POST['story_id'] : 0;
        $new_status = isset($_POST['new_status']) ? sanitize_key($_POST['new_status']) : '';
        check_admin_referer('lfa_set_news_status_' . $story_id);

        if (!current_user_can(LFA_News::CAP_PUBLISH_NEWS)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        if ($story_id > 0 && in_array($new_status, ['publish', 'draft', 'trash'], true)) {
            // Check consent if publishing
            if ($new_status === 'publish') {
                $terms = wp_get_post_terms($story_id, LFA_News::TAXONOMY);
                $cat = !empty($terms) ? $terms[0]->slug : 'general';
                $consent_ok = get_post_meta($story_id, '_lfa_news_consent_confirmed', true) === '1';
                if (LFA_News::requires_consent($cat) && !$consent_ok) {
                    wp_safe_redirect(admin_url('admin.php?page=lfa-news-admin&notice=consent_denied'));
                    exit;
                }
            }

            wp_update_post([
                'ID'          => $story_id,
                'post_status' => $new_status,
            ]);
            update_post_meta($story_id, '_lfa_news_status', $new_status);

            if (class_exists('LFA_Content_Audit')) {
                LFA_Content_Audit::log('staff', get_current_user_id(), 'news_status_changed', 'news', (string)$story_id, [
                    'to_status' => $new_status,
                ]);
            }

            $notice = ($new_status === 'publish') ? 'published' : 'withdrawn';
            wp_safe_redirect(admin_url('admin.php?page=lfa-news-admin&notice=' . $notice));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=lfa-news-admin'));
        exit;
    }

    /**
     * Handle Delete / Trash
     */
    public static function handle_delete_news(): void {
        $story_id = isset($_POST['story_id']) ? (int)$_POST['story_id'] : 0;
        check_admin_referer('lfa_delete_news_' . $story_id);

        if (!current_user_can(LFA_News::CAP_DELETE_NEWS)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        if ($story_id > 0) {
            wp_trash_post($story_id);
            if (class_exists('LFA_Content_Audit')) {
                LFA_Content_Audit::log('staff', get_current_user_id(), 'news_trashed', 'news', (string)$story_id);
            }
            wp_safe_redirect(admin_url('admin.php?page=lfa-news-admin&notice=deleted'));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=lfa-news-admin'));
        exit;
    }
}
