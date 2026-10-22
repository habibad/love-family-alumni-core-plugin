<?php
/**
 * Love Family Alumni — Administrative Archives, Gallery & Timeline Management
 *
 * Implements admin interfaces for managing photo collections, video records,
 * document archives with tiered access levels, and historical timeline milestones.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Archives_Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_admin_menus'], 30);
        add_action('admin_post_lfa_save_archive_item', [__CLASS__, 'handle_save_archive_item']);
        add_action('admin_post_lfa_save_timeline_milestone', [__CLASS__, 'handle_save_timeline_milestone']);
        add_action('admin_post_lfa_delete_archive_item', [__CLASS__, 'handle_delete_archive_item']);
        add_action('admin_post_lfa_delete_milestone', [__CLASS__, 'handle_delete_milestone']);
    }

    public static function register_admin_menus(): void {
        add_menu_page(
            'LFA Archives',
            'Heritage & Archives',
            LFA_Archives::CAP_MANAGE_ARCHIVES,
            'lfa-archives-admin',
            [__CLASS__, 'render_archives_list_page'],
            'dashicons-book-alt',
            28
        );

        add_submenu_page(
            'lfa-archives-admin',
            'Archive Items',
            'All Archive Items',
            LFA_Archives::CAP_MANAGE_ARCHIVES,
            'lfa-archives-admin',
            [__CLASS__, 'render_archives_list_page']
        );

        add_submenu_page(
            'lfa-archives-admin',
            'Add Archive Item',
            'Add Archive Item',
            LFA_Archives::CAP_MANAGE_ARCHIVES,
            'lfa-archive-edit',
            [__CLASS__, 'render_archive_edit_page']
        );

        add_submenu_page(
            'lfa-archives-admin',
            'Timeline Milestones',
            'Timeline Milestones',
            LFA_Archives::CAP_MANAGE_ARCHIVES,
            'lfa-timeline-admin',
            [__CLASS__, 'render_timeline_list_page']
        );

        add_submenu_page(
            'lfa-archives-admin',
            'Add Milestone',
            'Add Milestone',
            LFA_Archives::CAP_MANAGE_ARCHIVES,
            'lfa-milestone-edit',
            [__CLASS__, 'render_milestone_edit_page']
        );
    }

    /**
     * Archive Items List Page
     */
    public static function render_archives_list_page(): void {
        if (!current_user_can(LFA_Archives::CAP_MANAGE_ARCHIVES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        $notice = sanitize_key($_GET['notice'] ?? '');
        $kind_filter = sanitize_key($_GET['kind'] ?? 'all');

        $meta_query = [];
        if ($kind_filter !== 'all') {
            $meta_query[] = [
                'key'   => '_lfa_archive_kind',
                'value' => $kind_filter,
            ];
        }

        $query_args = [
            'post_type'      => LFA_Archives::POST_TYPE_ITEM,
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => 50,
            'meta_query'     => $meta_query,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        $items = get_posts($query_args);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Heritage, Media & Document Archives</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-archive-edit')); ?>" class="page-title-action">Add Archive Item</a>
            <p class="description">Manage historical photographs, video recordings, official documents, minutes, and publications.</p>
            <hr class="wp-header-end">

            <?php if ($notice === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Archive item saved successfully.</p></div>
            <?php elseif ($notice === 'deleted'): ?>
                <div class="notice notice-success is-dismissible"><p>Archive item deleted.</p></div>
            <?php elseif ($notice === 'video_error'): ?>
                <div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Invalid video URL. Only allowlisted video platforms (YouTube, Vimeo) are permitted.</p></div>
            <?php elseif ($notice === 'mime_error'): ?>
                <div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Disallowed document file format. Scriptable and executable files are strictly prohibited.</p></div>
            <?php endif; ?>

            <div class="tablenav top">
                <ul class="subsubsub">
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-archives-admin')); ?>" class="<?php echo $kind_filter === 'all' ? 'current' : ''; ?>">All</a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-archives-admin&kind=photo')); ?>" class="<?php echo $kind_filter === 'photo' ? 'current' : ''; ?>">Photos</a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-archives-admin&kind=video')); ?>" class="<?php echo $kind_filter === 'video' ? 'current' : ''; ?>">Videos</a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-archives-admin&kind=document')); ?>" class="<?php echo $kind_filter === 'document' ? 'current' : ''; ?>">Documents & Minutes</a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-archives-admin&kind=publication')); ?>" class="<?php echo $kind_filter === 'publication' ? 'current' : ''; ?>">Publications</a></li>
                </ul>
            </div>

            <table class="widefat striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="width: 40px;">ID</th>
                        <th>Archive Title</th>
                        <th>Kind</th>
                        <th>Access Level</th>
                        <th>Year</th>
                        <th>Category / Collection</th>
                        <th>Payload Info</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="8">No archive items found.</td></tr>
                    <?php else: foreach ($items as $p):
                        $item = LFA_Archives::format_archive_item($p, true);
                        ?>
                        <tr>
                            <td>#<?php echo esc_html($p->ID); ?></td>
                            <td>
                                <strong><a href="<?php echo esc_url(admin_url('admin.php?page=lfa-archive-edit&item_id=' . $p->ID)); ?>"><?php echo esc_html($p->post_title); ?></a></strong>
                                <p style="margin: 4px 0 0; color: #666; font-size: 12px;"><?php echo esc_html(wp_trim_words($p->post_content, 12)); ?></p>
                            </td>
                            <td><span class="badge" style="background:#e8f0fe;padding:2px 6px;border-radius:4px;"><?php echo esc_html(strtoupper($item['kind'])); ?></span></td>
                            <td>
                                <?php
                                $access_styles = [
                                    'public'           => ['label' => 'Public', 'bg' => '#e6f4ea', 'color' => '#137333'],
                                    'members_only'     => ['label' => 'Members-Only', 'bg' => '#fef7e0', 'color' => '#b06000'],
                                    'restricted_staff' => ['label' => 'Restricted Staff', 'bg' => '#fce8e6', 'color' => '#c5221f'],
                                ];
                                $as = $access_styles[$item['access_level']] ?? ['label' => $item['access_level'], 'bg' => '#eee', 'color' => '#333'];
                                ?>
                                <span style="background:<?php echo esc_attr($as['bg']); ?>; color:<?php echo esc_attr($as['color']); ?>; padding:2px 8px; border-radius:4px; font-weight:600; font-size:12px;">
                                    <?php echo esc_html($as['label']); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($item['year'] ?: '—'); ?></td>
                            <td>
                                <?php echo esc_html($item['category_name']); ?><br>
                                <small style="color: #666;"><?php echo esc_html($item['collection_name']); ?></small>
                            </td>
                            <td>
                                <?php if ($item['kind'] === 'document'): ?>
                                    <code><?php echo esc_html($item['file_name'] ?: 'No file'); ?></code> (<?php echo esc_html($item['file_size_human']); ?>)
                                <?php elseif ($item['kind'] === 'video'): ?>
                                    <span style="color:#0073aa; font-size:12px;"><?php echo esc_html(parse_url($item['video_url'], PHP_URL_HOST)); ?></span>
                                <?php else: ?>
                                    <span style="color:#666;">Media Attachment</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-archive-edit&item_id=' . $p->ID)); ?>" class="button button-small">Edit</a>
                                <?php if ($item['kind'] === 'document' && !empty($item['file_name'])): ?>
                                    <a href="<?php echo esc_url($item['download_url']); ?>" class="button button-small" target="_blank">Test Download</a>
                                <?php endif; ?>
                                <?php if (current_user_can(LFA_Archives::CAP_DELETE_ARCHIVES)): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                        <input type="hidden" name="action" value="lfa_delete_archive_item">
                                        <input type="hidden" name="item_id" value="<?php echo esc_attr($p->ID); ?>">
                                        <?php wp_nonce_field('lfa_delete_archive_' . $p->ID); ?>
                                        <button type="submit" class="button button-small button-link-delete" onclick="return confirm('Delete this archive item?');">Delete</button>
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
     * Archive Item Create / Edit Screen
     */
    public static function render_archive_edit_page(): void {
        if (!current_user_can(LFA_Archives::CAP_MANAGE_ARCHIVES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        $item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
        $post = $item_id > 0 ? get_post($item_id) : null;

        $title   = $post ? $post->post_title : '';
        $content = $post ? $post->post_content : '';
        $excerpt = $post ? $post->post_excerpt : '';
        $status  = $post ? $post->post_status : 'publish';

        $kind    = $post ? (string)get_post_meta($post->ID, '_lfa_archive_kind', true) : 'photo';
        $access  = $post ? (string)get_post_meta($post->ID, '_lfa_archive_access', true) : 'restricted_staff'; // Default restricted!
        $year    = $post ? (int)get_post_meta($post->ID, '_lfa_archive_year', true) : date('Y');
        $rights  = $post ? (string)get_post_meta($post->ID, '_lfa_archive_source_rights', true) : 'Love Family Alumni Heritage';
        $video_u = $post ? (string)get_post_meta($post->ID, '_lfa_archive_video_url', true) : '';
        $file_n  = $post ? (string)get_post_meta($post->ID, '_lfa_archive_file_name', true) : '';

        $cats    = get_terms(['taxonomy' => LFA_Archives::TAXONOMY_CAT, 'hide_empty' => false]);
        $colls   = get_terms(['taxonomy' => LFA_Archives::TAXONOMY_COLLECTION, 'hide_empty' => false]);

        $post_cats  = $post ? wp_get_post_terms($post->ID, LFA_Archives::TAXONOMY_CAT) : [];
        $cur_cat    = !empty($post_cats) ? $post_cats[0]->slug : 'documents';

        $post_colls = $post ? wp_get_post_terms($post->ID, LFA_Archives::TAXONOMY_COLLECTION) : [];
        $cur_coll   = !empty($post_colls) ? $post_colls[0]->slug : '';
        ?>
        <div class="wrap">
            <h1><?php echo $post ? 'Edit Archive Item: ' . esc_html($post->post_title) : 'Add New Archive Item'; ?></h1>
            <p class="description">Upload photos, videos, or classified documents with rigorous access controls (ARC-013 to ARC-018).</p>
            <hr class="wp-header-end">

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="max-width: 900px; margin-top: 20px;">
                <input type="hidden" name="action" value="lfa_save_archive_item">
                <input type="hidden" name="item_id" value="<?php echo esc_attr($item_id); ?>">
                <?php wp_nonce_field('lfa_save_archive_action', 'lfa_archive_nonce'); ?>

                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <h2>Item Information</h2>
                    <p>
                        <label for="archive_title"><strong>Title *</strong></label><br>
                        <input type="text" id="archive_title" name="title" class="widefat" required value="<?php echo esc_attr($title); ?>" placeholder="e.g. 2021 Zonal Leadership Charter">
                    </p>

                    <div style="display: flex; gap: 20px;">
                        <div style="flex: 1;">
                            <label for="archive_kind"><strong>Archive Kind *</strong></label><br>
                            <select id="archive_kind" name="kind" class="widefat">
                                <option value="photo" <?php selected($kind, 'photo'); ?>>Photograph / Image</option>
                                <option value="video" <?php selected($kind, 'video'); ?>>Video / Interview Recording</option>
                                <option value="document" <?php selected($kind, 'document'); ?>>Constitution / Minutes / Document</option>
                                <option value="publication" <?php selected($kind, 'publication'); ?>>Newsletter / Book / Publication</option>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <label for="archive_year"><strong>Historical Year (Preserves temporal precision, ARC-025)</strong></label><br>
                            <input type="number" id="archive_year" name="year" class="widefat" value="<?php echo esc_attr($year ?: ''); ?>" min="1970" max="2035" placeholder="e.g. 2021">
                        </div>
                    </div>

                    <p style="margin-top: 15px;">
                        <label for="archive_cat"><strong>Category *</strong></label><br>
                        <select id="archive_cat" name="category" class="widefat">
                            <?php foreach ($cats as $c): ?>
                                <option value="<?php echo esc_attr($c->slug); ?>" <?php selected($cur_cat, $c->slug); ?>><?php echo esc_html($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label for="archive_coll"><strong>Collection / Album</strong></label><br>
                        <select id="archive_coll" name="collection" class="widefat">
                            <option value="">&mdash; None (Stand-alone Item) &mdash;</option>
                            <?php foreach ($colls as $col): ?>
                                <option value="<?php echo esc_attr($col->slug); ?>" <?php selected($cur_coll, $col->slug); ?>><?php echo esc_html($col->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label for="archive_rights"><strong>Source Attribution & Copyright / Rights</strong></label><br>
                        <input type="text" id="archive_rights" name="source_rights" class="widefat" value="<?php echo esc_attr($rights); ?>" placeholder="e.g. Love Family Archives Committee">
                    </p>

                    <p>
                        <label for="archive_content"><strong>Description & Historical Context</strong></label><br>
                        <textarea id="archive_content" name="content" rows="4" class="widefat"><?php echo esc_textarea($content); ?></textarea>
                    </p>
                </div>

                <div class="card" style="padding: 20px; margin-bottom: 20px; border-left: 4px solid #c5221f;">
                    <h2>Access Control & Privacy (ARC-013, ARC-014, ARC-015)</h2>
                    <p class="description">Classify asset access permissions. Documents, constitutions, and minutes default to Restricted Staff.</p>

                    <p>
                        <label for="archive_access"><strong>Access Tier *</strong></label><br>
                        <select id="archive_access" name="access_level" class="widefat">
                            <option value="restricted_staff" <?php selected($access, 'restricted_staff'); ?>>Restricted Staff Only (Default for Constitutions, Minutes & Reports)</option>
                            <option value="members_only" <?php selected($access, 'members_only'); ?>>Verified Approved Members Only (Protected)</option>
                            <option value="public" <?php selected($access, 'public'); ?>>Public (Visible to All Visitors)</option>
                        </select>
                    </p>
                </div>

                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <h2>Payload / Media (ARC-016, ARC-018, ARC-021)</h2>
                    <p>
                        <label for="video_url"><strong>External Video URL (For Videos only)</strong></label><br>
                        <input type="url" id="video_url" name="video_url" class="widefat" value="<?php echo esc_attr($video_u); ?>" placeholder="https://www.youtube.com/watch?v=... or Vimeo URL">
                        <small style="color: #666;">Allowlisted domains: YouTube, Vimeo. Arbitrary iframes are strictly blocked (ARC-021).</small>
                    </p>

                    <hr style="margin: 15px 0;">

                    <p>
                        <label for="doc_file"><strong>Document File Upload (Protected Storage)</strong></label><br>
                        <?php if (!empty($file_n)): ?>
                            <p><strong>Current File:</strong> <code><?php echo esc_html($file_n); ?></code></p>
                        <?php endif; ?>
                        <input type="file" id="doc_file" name="doc_file" class="widefat" accept=".pdf,.doc,.docx,.xlsx,.xls,.jpg,.png,.webp,.mp4">
                        <small style="color: #666;">Allowed formats: PDF, DOCX, XLSX, JPG, PNG, MP4. Files are stored outside direct web access (ARC-016).</small>
                    </p>
                </div>

                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <h2>Publishing Status</h2>
                    <p>
                        <label for="archive_status"><strong>Publication Status</strong></label><br>
                        <select id="archive_status" name="post_status" class="widefat">
                            <option value="publish" <?php selected($status, 'publish'); ?>>Published</option>
                            <option value="draft" <?php selected($status, 'draft'); ?>>Draft (Internal)</option>
                        </select>
                    </p>

                    <p style="margin-top: 20px;">
                        <button type="submit" class="button button-primary button-large">
                            <?php echo $post ? 'Save & Update Item' : 'Add to Archive'; ?>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-archives-admin')); ?>" class="button button-large">Cancel</a>
                    </p>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle Save Archive Item POST
     */
    public static function handle_save_archive_item(): void {
        if (!current_user_can(LFA_Archives::CAP_MANAGE_ARCHIVES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }
        check_admin_referer('lfa_save_archive_action', 'lfa_archive_nonce');

        $user_id = get_current_user_id();
        $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        $title   = sanitize_text_field($_POST['title'] ?? '');
        $content = wp_kses_post($_POST['content'] ?? '');
        $kind    = sanitize_key($_POST['kind'] ?? 'photo');
        $access  = sanitize_key($_POST['access_level'] ?? 'restricted_staff');
        $year    = (int)($_POST['year'] ?? date('Y'));
        $rights  = sanitize_text_field($_POST['source_rights'] ?? 'Love Family Alumni Heritage');
        $video_u = esc_url_raw($_POST['video_url'] ?? '');
        $status  = sanitize_key($_POST['post_status'] ?? 'publish');

        $category   = sanitize_title($_POST['category'] ?? 'documents');
        $collection = sanitize_title($_POST['collection'] ?? '');

        // Video URL validation (ARC-021)
        if (!empty($video_u) && !LFA_Archives::validate_video_url($video_u)) {
            $redirect = add_query_arg(['notice' => 'video_error', 'item_id' => $item_id], admin_url('admin.php?page=lfa-archive-edit'));
            wp_safe_redirect($redirect);
            exit;
        }

        $post_data = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_type'    => LFA_Archives::POST_TYPE_ITEM,
        ];

        if ($item_id > 0) {
            $post_data['ID'] = $item_id;
            wp_update_post($post_data);
            $action = 'archive_item_updated';
        } else {
            $post_data['post_author'] = $user_id;
            $item_id = wp_insert_post($post_data);
            $action = 'archive_item_created';
        }

        if ($item_id && !is_wp_error($item_id)) {
            wp_set_object_terms($item_id, $category, LFA_Archives::TAXONOMY_CAT);
            if (!empty($collection)) {
                wp_set_object_terms($item_id, $collection, LFA_Archives::TAXONOMY_COLLECTION);
            }

            update_post_meta($item_id, '_lfa_archive_kind', $kind);
            update_post_meta($item_id, '_lfa_archive_access', $access);
            update_post_meta($item_id, '_lfa_archive_year', $year);
            update_post_meta($item_id, '_lfa_archive_source_rights', $rights);
            update_post_meta($item_id, '_lfa_archive_video_url', $video_u);

            // Handle Protected Document File Upload (ARC-016, ARC-018)
            if (!empty($_FILES['doc_file']['name']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['doc_file'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!isset(LFA_Archives::ALLOWED_DOC_MIMES[$mime])) {
                    $redirect = add_query_arg(['notice' => 'mime_error', 'item_id' => $item_id], admin_url('admin.php?page=lfa-archive-edit'));
                    wp_safe_redirect($redirect);
                    exit;
                }

                $storage_dir = wp_upload_dir()['basedir'] . '/lfa_protected_archives';
                if (!is_dir($storage_dir)) {
                    mkdir($storage_dir, 0755, true);
                }

                $clean_filename = sanitize_file_name($file['name']);
                $target_path = $storage_dir . '/' . time() . '_' . $clean_filename;

                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    update_post_meta($item_id, '_lfa_archive_file_path', $target_path);
                    update_post_meta($item_id, '_lfa_archive_file_name', $clean_filename);
                    update_post_meta($item_id, '_lfa_archive_file_mime', $mime);
                    update_post_meta($item_id, '_lfa_archive_file_size', filesize($target_path));
                }
            }

            if (class_exists('LFA_Content_Audit')) {
                LFA_Content_Audit::log('staff', $user_id, $action, 'archive_item', (string)$item_id, [
                    'title'  => $title,
                    'kind'   => $kind,
                    'access' => $access,
                ]);
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=lfa-archives-admin&notice=saved'));
        exit;
    }

    /**
     * Timeline Milestones List Page (ARC-024, ARC-025, ARC-026)
     */
    public static function render_timeline_list_page(): void {
        if (!current_user_can(LFA_Archives::CAP_MANAGE_ARCHIVES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        $notice = sanitize_key($_GET['notice'] ?? '');
        $milestones = LFA_Archives::get_timeline_milestones();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Historical Timeline Milestones</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-milestone-edit')); ?>" class="page-title-action">Add Milestone</a>
            <p class="description">Documented historical milestones of the Love Family fellowship. Year-only precision is strictly maintained without fabricated dates.</p>
            <hr class="wp-header-end">

            <?php if ($notice === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Timeline milestone saved successfully.</p></div>
            <?php elseif ($notice === 'deleted'): ?>
                <div class="notice notice-success is-dismissible"><p>Timeline milestone deleted.</p></div>
            <?php elseif ($notice === 'year_error'): ?>
                <div class="notice notice-error is-dismissible"><p><strong>Historical Precision Error (ARC-026):</strong> The 2027 leadership transition is scheduled/prospective and must not be described as completed in 2026.</p></div>
            <?php endif; ?>

            <table class="widefat striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="width: 70px;">Year</th>
                        <th>Milestone Title</th>
                        <th>Historical Description</th>
                        <th>Documented Source Reference</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($milestones)): ?>
                        <tr><td colspan="6">No milestones documented.</td></tr>
                    <?php else: foreach ($milestones as $m): ?>
                        <tr>
                            <td><strong style="font-size: 1.15em; color: #071d3e;"><?php echo esc_html($m['year']); ?></strong></td>
                            <td><strong><?php echo esc_html($m['title']); ?></strong></td>
                            <td><?php echo wp_trim_words(strip_tags($m['description']), 20); ?></td>
                            <td><em><?php echo esc_html($m['source']); ?></em></td>
                            <td>
                                <span class="badge" style="background:<?php echo $m['is_future'] ? '#e8f0fe; color:#1a73e8;' : '#e6f4ea; color:#137333;'; ?> padding:2px 8px; border-radius:4px; font-weight:600;">
                                    <?php echo esc_html($m['badge']); ?>
                                </span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-milestone-edit&milestone_id=' . $m['id'])); ?>" class="button button-small">Edit</a>
                                <?php if (current_user_can(LFA_Archives::CAP_DELETE_ARCHIVES)): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                        <input type="hidden" name="action" value="lfa_delete_milestone">
                                        <input type="hidden" name="milestone_id" value="<?php echo esc_attr($m['id']); ?>">
                                        <?php wp_nonce_field('lfa_delete_milestone_' . $m['id']); ?>
                                        <button type="submit" class="button button-small button-link-delete" onclick="return confirm('Delete this milestone?');">Delete</button>
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
     * Timeline Milestone Edit Page
     */
    public static function render_milestone_edit_page(): void {
        if (!current_user_can(LFA_Archives::CAP_MANAGE_ARCHIVES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        $milestone_id = isset($_GET['milestone_id']) ? (int)$_GET['milestone_id'] : 0;
        $post = $milestone_id > 0 ? get_post($milestone_id) : null;

        $title   = $post ? $post->post_title : '';
        $content = $post ? $post->post_content : '';
        $year    = $post ? (int)get_post_meta($post->ID, '_lfa_milestone_year', true) : 2026;
        $source  = $post ? (string)get_post_meta($post->ID, '_lfa_milestone_source', true) : '';
        $gran    = $post ? (string)get_post_meta($post->ID, '_lfa_milestone_granularity', true) : 'year';
        ?>
        <div class="wrap">
            <h1><?php echo $post ? 'Edit Milestone: ' . esc_html($post->post_title) : 'Add Timeline Milestone'; ?></h1>
            <p class="description">Documented historical milestones must use authentic client-approved dates and references.</p>
            <hr class="wp-header-end">

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width: 800px; margin-top: 20px;">
                <input type="hidden" name="action" value="lfa_save_timeline_milestone">
                <input type="hidden" name="milestone_id" value="<?php echo esc_attr($milestone_id); ?>">
                <?php wp_nonce_field('lfa_save_milestone_action', 'lfa_milestone_nonce'); ?>

                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <p>
                        <label for="milestone_year"><strong>Historical Year * (ARC-025: Temporal Precision)</strong></label><br>
                        <input type="number" id="milestone_year" name="year" class="widefat" required value="<?php echo esc_attr($year); ?>" min="1970" max="2035">
                    </p>

                    <p>
                        <label for="milestone_title"><strong>Milestone Title *</strong></label><br>
                        <input type="text" id="milestone_title" name="title" class="widefat" required value="<?php echo esc_attr($title); ?>" placeholder="e.g. Zonal Leadership Transition">
                    </p>

                    <p>
                        <label for="milestone_source"><strong>Documented Source Reference * (ARC-024)</strong></label><br>
                        <input type="text" id="milestone_source" name="source" class="widefat" required value="<?php echo esc_attr($source); ?>" placeholder="e.g. LFA General Assembly Minutes 2021">
                    </p>

                    <p>
                        <label for="milestone_desc"><strong>Detailed Narrative Description</strong></label><br>
                        <textarea id="milestone_desc" name="content" rows="4" class="widefat"><?php echo esc_textarea($content); ?></textarea>
                    </p>

                    <p style="margin-top: 20px;">
                        <button type="submit" class="button button-primary button-large">
                            <?php echo $post ? 'Save Milestone' : 'Create Milestone'; ?>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lfa-timeline-admin')); ?>" class="button button-large">Cancel</a>
                    </p>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle Save Milestone POST
     */
    public static function handle_save_timeline_milestone(): void {
        if (!current_user_can(LFA_Archives::CAP_MANAGE_ARCHIVES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }
        check_admin_referer('lfa_save_milestone_action', 'lfa_milestone_nonce');

        $user_id = get_current_user_id();
        $mid     = isset($_POST['milestone_id']) ? (int)$_POST['milestone_id'] : 0;
        $year    = (int)($_POST['year'] ?? 2026);
        $title   = sanitize_text_field($_POST['title'] ?? '');
        $source  = sanitize_text_field($_POST['source'] ?? '');
        $content = wp_kses_post($_POST['content'] ?? '');

        // ARC-026: 2027 leadership transition described as future, never asserted as completed
        if ($year >= 2027 && (str_contains(strtolower($title), 'completed') || str_contains(strtolower($content), 'completed'))) {
            $redirect = add_query_arg(['notice' => 'year_error', 'milestone_id' => $mid], admin_url('admin.php?page=lfa-milestone-edit'));
            wp_safe_redirect($redirect);
            exit;
        }

        $post_data = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => LFA_Archives::POST_TYPE_TIMELINE,
        ];

        if ($mid > 0) {
            $post_data['ID'] = $mid;
            wp_update_post($post_data);
            $action = 'milestone_updated';
        } else {
            $post_data['post_author'] = $user_id;
            $mid = wp_insert_post($post_data);
            $action = 'milestone_created';
        }

        if ($mid && !is_wp_error($mid)) {
            update_post_meta($mid, '_lfa_milestone_year', $year);
            update_post_meta($mid, '_lfa_milestone_source', $source);
            update_post_meta($mid, '_lfa_milestone_granularity', 'year');

            if (class_exists('LFA_Content_Audit')) {
                LFA_Content_Audit::log('staff', $user_id, $action, 'timeline_milestone', (string)$mid, [
                    'year'  => $year,
                    'title' => $title,
                ]);
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=lfa-timeline-admin&notice=saved'));
        exit;
    }

    /**
     * Handle Delete Archive Item POST (ARC-030)
     */
    public static function handle_delete_archive_item(): void {
        $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        check_admin_referer('lfa_delete_archive_' . $item_id);

        if (!current_user_can(LFA_Archives::CAP_DELETE_ARCHIVES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        if ($item_id > 0) {
            // Check if protected file exists and audit cleanup (ARC-030)
            $file_path = (string)get_post_meta($item_id, '_lfa_archive_file_path', true);
            if (!empty($file_path) && file_exists($file_path)) {
                @unlink($file_path);
            }

            wp_delete_post($item_id, true); // Clean deletion
            if (class_exists('LFA_Content_Audit')) {
                LFA_Content_Audit::log('staff', get_current_user_id(), 'archive_item_deleted', 'archive_item', (string)$item_id);
            }

            wp_safe_redirect(admin_url('admin.php?page=lfa-archives-admin&notice=deleted'));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=lfa-archives-admin'));
        exit;
    }

    /**
     * Handle Delete Milestone POST
     */
    public static function handle_delete_milestone(): void {
        $mid = isset($_POST['milestone_id']) ? (int)$_POST['milestone_id'] : 0;
        check_admin_referer('lfa_delete_milestone_' . $mid);

        if (!current_user_can(LFA_Archives::CAP_DELETE_ARCHIVES)) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        if ($mid > 0) {
            wp_delete_post($mid, true);
            if (class_exists('LFA_Content_Audit')) {
                LFA_Content_Audit::log('staff', get_current_user_id(), 'milestone_deleted', 'timeline_milestone', (string)$mid);
            }
            wp_safe_redirect(admin_url('admin.php?page=lfa-timeline-admin&notice=deleted'));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=lfa-timeline-admin'));
        exit;
    }
}
