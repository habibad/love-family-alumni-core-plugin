<?php
/**
 * Love Family Alumni — Archives, Gallery & Historical Timeline Subsystem
 *
 * Implements historical photo albums, video archives, controlled document repositories
 * with tiered access levels (Public, Members-Only, Restricted Staff), secure file streaming,
 * allowlisted video embeds, and temporal precision timeline milestones.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Archives {

    const POST_TYPE_ITEM      = 'lfa_archive_item';
    const POST_TYPE_TIMELINE  = 'lfa_timeline';

    const TAXONOMY_CAT        = 'lfa_archive_category';
    const TAXONOMY_COLLECTION = 'lfa_archive_collection';

    const CAP_MANAGE_ARCHIVES = 'lfa_manage_archives';
    const CAP_PUBLISH_ARCHIVES= 'lfa_publish_archives';
    const CAP_DELETE_ARCHIVES = 'lfa_delete_archives';
    const CAP_VIEW_RESTRICTED = 'lfa_view_restricted_archives';

    const ALLOWED_VIDEO_DOMAINS = [
        'youtube.com',
        'www.youtube.com',
        'youtu.be',
        'vimeo.com',
        'player.vimeo.com',
    ];

    const ALLOWED_DOC_MIMES = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'video/mp4'  => 'mp4',
    ];

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_post_types_and_taxonomies'], 5);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('admin_post_lfa_download_archive', [__CLASS__, 'handle_download_action']);
        add_action('admin_post_nopriv_lfa_download_archive', [__CLASS__, 'handle_download_action']);
    }

    public static function install_schema(): void {
        self::register_post_types_and_taxonomies();
        self::seed_default_taxonomies();
        self::seed_initial_archives_and_timeline();
    }

    public static function register_post_types_and_taxonomies(): void {
        // 1. Taxonomy: Archive Category
        register_taxonomy(self::TAXONOMY_CAT, self::POST_TYPE_ITEM, [
            'labels' => [
                'name'          => 'Archive Categories',
                'singular_name' => 'Archive Category',
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'archive-category'],
            'capabilities'      => [
                'manage_terms' => self::CAP_PUBLISH_ARCHIVES,
                'edit_terms'   => self::CAP_PUBLISH_ARCHIVES,
                'delete_terms' => self::CAP_PUBLISH_ARCHIVES,
                'assign_terms' => self::CAP_MANAGE_ARCHIVES,
            ],
        ]);

        // 2. Taxonomy: Collections / Albums
        register_taxonomy(self::TAXONOMY_COLLECTION, self::POST_TYPE_ITEM, [
            'labels' => [
                'name'          => 'Archive Collections',
                'singular_name' => 'Collection / Album',
                'menu_name'     => 'Collections & Albums',
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'collection'],
            'capabilities'      => [
                'manage_terms' => self::CAP_PUBLISH_ARCHIVES,
                'edit_terms'   => self::CAP_PUBLISH_ARCHIVES,
                'delete_terms' => self::CAP_PUBLISH_ARCHIVES,
                'assign_terms' => self::CAP_MANAGE_ARCHIVES,
            ],
        ]);

        // 3. CPT: Archive Item
        register_post_type(self::POST_TYPE_ITEM, [
            'labels' => [
                'name'          => 'Archive Items',
                'singular_name' => 'Archive Item',
                'menu_name'     => 'Archives & Gallery',
                'add_new_item'  => 'Add New Archive Item',
                'edit_item'     => 'Edit Archive Item',
            ],
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'show_in_rest'        => true,
            'rest_base'           => 'lfa_archives_cpt',
            'supports'            => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
            'capability_type'     => ['lfa_archive_item', 'lfa_archive_items'],
            'map_meta_cap'        => false,
            'capabilities'        => [
                'edit_post'          => self::CAP_MANAGE_ARCHIVES,
                'read_post'          => 'read',
                'delete_post'        => self::CAP_DELETE_ARCHIVES,
                'edit_posts'         => self::CAP_MANAGE_ARCHIVES,
                'edit_others_posts'  => self::CAP_PUBLISH_ARCHIVES,
                'publish_posts'      => self::CAP_PUBLISH_ARCHIVES,
                'read_private_posts' => self::CAP_VIEW_RESTRICTED,
            ],
        ]);

        // 4. CPT: Timeline Milestone
        register_post_type(self::POST_TYPE_TIMELINE, [
            'labels' => [
                'name'          => 'Timeline Milestones',
                'singular_name' => 'Timeline Milestone',
                'menu_name'     => 'Historical Timeline',
                'add_new_item'  => 'Add Milestone',
                'edit_item'     => 'Edit Milestone',
            ],
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'show_in_rest'        => true,
            'rest_base'           => 'lfa_timeline_cpt',
            'supports'            => ['title', 'editor', 'revisions'],
            'capability_type'     => ['lfa_archive_item', 'lfa_archive_items'],
            'map_meta_cap'        => false,
            'capabilities'        => [
                'edit_post'          => self::CAP_MANAGE_ARCHIVES,
                'read_post'          => 'read',
                'delete_post'        => self::CAP_DELETE_ARCHIVES,
                'edit_posts'         => self::CAP_MANAGE_ARCHIVES,
                'edit_others_posts'  => self::CAP_PUBLISH_ARCHIVES,
                'publish_posts'      => self::CAP_PUBLISH_ARCHIVES,
                'read_private_posts' => self::CAP_VIEW_RESTRICTED,
            ],
        ]);
    }

    public static function seed_default_taxonomies(): void {
        // Seed Archive Categories
        $categories = [
            'documents'         => 'Constitutions & Official Documents',
            'meeting-minutes'   => 'Meeting Minutes & Reports',
            'photos-reunions'   => 'Photos & Reunions',
            'videos-interviews' => 'Videos & Interviews',
            'publications'      => 'Publications, Newsletters & Books',
            'conference-history'=> 'Conference History',
        ];

        foreach ($categories as $slug => $name) {
            if (!term_exists($slug, self::TAXONOMY_CAT)) {
                wp_insert_term($name, self::TAXONOMY_CAT, ['slug' => $slug]);
            }
        }

        // Seed Collections / Albums
        $collections = [
            'omaha-2025'         => 'Omaha 2025 Reunion Gathering',
            'uniben-founding'    => 'UNIBEN Roots & Founding Era',
            'executive-minutes'  => 'Official Governance & Executive Records',
            'publications-digest'=> 'Alumni Digest & Heritage Publications',
        ];

        foreach ($collections as $slug => $name) {
            if (!term_exists($slug, self::TAXONOMY_COLLECTION)) {
                wp_insert_term($name, self::TAXONOMY_COLLECTION, ['slug' => $slug]);
            }
        }
    }

    /**
     * Seed initial authoritative archive items and timeline milestones
     */
    public static function seed_initial_archives_and_timeline(): void {
        $existing = get_posts([
            'post_type'      => self::POST_TYPE_ITEM,
            'post_status'    => 'any',
            'posts_per_page' => 1,
        ]);
        if (empty($existing)) {
            // 1. Photo item: Omaha 2025 Opening Assembly
            $p1 = wp_insert_post([
                'post_title'   => 'Opening Assembly Fellowship — Omaha 2025',
                'post_name'    => 'omaha-2025-opening-assembly',
                'post_content' => 'Alumni gathered for the opening fellowship session in Omaha, Nebraska, reuniting brethren across North America.',
                'post_status'  => 'publish',
                'post_type'    => self::POST_TYPE_ITEM,
                'post_author'  => 1,
            ]);
            if ($p1 && !is_wp_error($p1)) {
                wp_set_object_terms($p1, 'photos-reunions', self::TAXONOMY_CAT);
                wp_set_object_terms($p1, 'omaha-2025', self::TAXONOMY_COLLECTION);
                update_post_meta($p1, '_lfa_archive_kind', 'photo');
                update_post_meta($p1, '_lfa_archive_access', 'public');
                update_post_meta($p1, '_lfa_archive_year', 2025);
                update_post_meta($p1, '_lfa_archive_source_rights', 'Love Family Alumni Official Media Team');
            }

            // 2. Video item: Keynote Address Excerpt
            $p2 = wp_insert_post([
                'post_title'   => 'Alumni Leadership Reflection — Video Message',
                'post_name'    => 'leadership-reflection-video',
                'post_content' => 'Special alumni video greeting reflecting on the journey of The Love Family fellowship from campus origins to global mission.',
                'post_status'  => 'publish',
                'post_type'    => self::POST_TYPE_ITEM,
                'post_author'  => 1,
            ]);
            if ($p2 && !is_wp_error($p2)) {
                wp_set_object_terms($p2, 'videos-interviews', self::TAXONOMY_CAT);
                update_post_meta($p2, '_lfa_archive_kind', 'video');
                update_post_meta($p2, '_lfa_archive_access', 'public');
                update_post_meta($p2, '_lfa_archive_year', 2024);
                update_post_meta($p2, '_lfa_archive_video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
                update_post_meta($p2, '_lfa_archive_source_rights', 'Recorded with Alumni Committee Release');
            }

            // 3. Document item: Official Constitution (Restricted by default per ARC-013, ARC-014)
            $p3 = wp_insert_post([
                'post_title'   => 'Love Family Alumni — Official Constitution & Bylaws',
                'post_name'    => 'lfa-constitution-bylaws',
                'post_content' => 'The codified charter, operational framework, and governance rules of the Love Family Alumni association.',
                'post_status'  => 'publish',
                'post_type'    => self::POST_TYPE_ITEM,
                'post_author'  => 1,
            ]);
            if ($p3 && !is_wp_error($p3)) {
                wp_set_object_terms($p3, 'documents', self::TAXONOMY_CAT);
                wp_set_object_terms($p3, 'executive-minutes', self::TAXONOMY_COLLECTION);
                update_post_meta($p3, '_lfa_archive_kind', 'document');
                update_post_meta($p3, '_lfa_archive_access', 'members_only'); // Requires verified approved member!
                update_post_meta($p3, '_lfa_archive_year', 2021);
                update_post_meta($p3, '_lfa_archive_source_rights', 'Executive Council Document Archive');

                // Create a real sample PDF in protected storage for download testing
                $storage_dir = wp_upload_dir()['basedir'] . '/lfa_protected_archives';
                if (!is_dir($storage_dir)) {
                    mkdir($storage_dir, 0755, true);
                }
                $doc_file = $storage_dir . '/lfa_constitution_charter.pdf';
                if (!file_exists($doc_file)) {
                    file_put_contents($doc_file, "%PDF-1.4\n% Love Family Alumni Constitution Document Stub\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000018 00000 n \n0000000067 00000 n \n0000000116 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n193\n%%EOF");
                }
                update_post_meta($p3, '_lfa_archive_file_path', $doc_file);
                update_post_meta($p3, '_lfa_archive_file_name', 'LFA_Constitution_2021.pdf');
                update_post_meta($p3, '_lfa_archive_file_mime', 'application/pdf');
                update_post_meta($p3, '_lfa_archive_file_size', filesize($doc_file));
            }

            // 4. Confidential Executive Minutes (Restricted Staff only per ARC-015)
            $p4 = wp_insert_post([
                'post_title'   => 'Executive Council Internal Minutes & Resolutions',
                'post_name'    => 'executive-minutes-2024',
                'post_content' => 'Restricted minutes of the executive leadership assembly detailing committee assignments and internal governance.',
                'post_status'  => 'publish',
                'post_type'    => self::POST_TYPE_ITEM,
                'post_author'  => 1,
            ]);
            if ($p4 && !is_wp_error($p4)) {
                wp_set_object_terms($p4, 'meeting-minutes', self::TAXONOMY_CAT);
                wp_set_object_terms($p4, 'executive-minutes', self::TAXONOMY_COLLECTION);
                update_post_meta($p4, '_lfa_archive_kind', 'document');
                update_post_meta($p4, '_lfa_archive_access', 'restricted_staff'); // Staff only!
                update_post_meta($p4, '_lfa_archive_year', 2024);
                update_post_meta($p4, '_lfa_archive_source_rights', 'Confidential Secretariat Repository');

                $storage_dir = wp_upload_dir()['basedir'] . '/lfa_protected_archives';
                $sec_file = $storage_dir . '/lfa_exec_minutes_2024.pdf';
                if (!file_exists($sec_file)) {
                    file_put_contents($sec_file, "%PDF-1.4\n% LFA Executive Minutes Restricted Stub\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000018 00000 n \n0000000067 00000 n \n0000000116 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n193\n%%EOF");
                }
                update_post_meta($p4, '_lfa_archive_file_path', $sec_file);
                update_post_meta($p4, '_lfa_archive_file_name', 'Executive_Minutes_2024.pdf');
                update_post_meta($p4, '_lfa_archive_file_mime', 'application/pdf');
                update_post_meta($p4, '_lfa_archive_file_size', filesize($sec_file));
            }
        }

        // Seed Milestones (ARC-024, ARC-025, ARC-026)
        $existing_milestones = get_posts([
            'post_type'        => self::POST_TYPE_TIMELINE,
            'post_status'      => 'any',
            'posts_per_page'   => 1,
            'suppress_filters' => true,
        ]);
        if (empty($existing_milestones)) {
            $milestones = [
                [
                    'year'        => 2021,
                    'title'       => 'Executive Leadership Transition & Governance Codification',
                    'desc'        => 'Formal transition in zonal leadership, ratifying new organizational constitution and establishing unified alumni communications.',
                    'source'      => 'LFA General Assembly Records 2021',
                    'granularity' => 'year',
                ],
                [
                    'year'        => 2024,
                    'title'       => 'Continental Outreach & International Fellowship Expansion',
                    'desc'        => 'Expanding regional support networks across North American cities and establishing the Akpo Family academic endowment.',
                    'source'      => 'Secretariat Annual Review 2024',
                    'granularity' => 'year',
                ],
                [
                    'year'        => 2025,
                    'title'       => 'Omaha Alumni Assembly & Continental Fellowship Reunion',
                    'desc'        => 'Milestone continental assembly held in Omaha, Nebraska, uniting alumni delegates across North America for fellowship and vision.',
                    'source'      => 'Omaha Convention Official Program 2025',
                    'granularity' => 'year',
                ],
                [
                    'year'        => 2026,
                    'title'       => 'Institutional Platform Development & Chapter Expansion',
                    'desc'        => 'Development of the unified digital alumni infrastructure, membership verification systems, and regional chapter coordination.',
                    'source'      => 'Zonal Steering Committee 2026',
                    'granularity' => 'year',
                ],
                [
                    'year'        => 2027,
                    'title'       => 'Prospective Biennial Conference & Scheduled Leadership Transition',
                    'desc'        => 'Upcoming flagship gathering themed "Thy Kingdom Come" (Matthew 6:10) featuring Keynote Speaker Dr. Uche Igbokwe, with prospective quadrennial executive transition.',
                    'source'      => '2027 Conference Steering Committee Brief',
                    'granularity' => 'year',
                ],
            ];

            foreach ($milestones as $m) {
                $mid = wp_insert_post([
                    'post_title'   => $m['title'],
                    'post_content' => $m['desc'],
                    'post_status'  => 'publish',
                    'post_type'    => self::POST_TYPE_TIMELINE,
                    'post_author'  => 1,
                ]);
                if ($mid && !is_wp_error($mid)) {
                    update_post_meta($mid, '_lfa_milestone_year', (int)$m['year']);
                    update_post_meta($mid, '_lfa_milestone_source', $m['source']);
                    update_post_meta($mid, '_lfa_milestone_granularity', $m['granularity']);
                }
            }
        }
    }

    /**
     * Check if user can access an archive item based on its access level (ARC-013, ARC-014, ARC-015)
     */
    public static function can_access_item(int $item_id, ?int $user_id = null): bool {
        $access = (string)get_post_meta($item_id, '_lfa_archive_access', true) ?: 'restricted_staff';

        if ($access === 'public') {
            return true;
        }

        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if ($user_id <= 0) {
            return false; // Guest cannot access non-public
        }

        if (user_can($user_id, 'manage_options') || user_can($user_id, self::CAP_VIEW_RESTRICTED)) {
            return true;
        }

        if ($access === 'members_only') {
            // Must be approved member (INT-003)
            return class_exists('LFA_Core') && LFA_Core::is_approved($user_id);
        }

        // restricted_staff: only staff with CAP_VIEW_RESTRICTED
        return false;
    }

    /**
     * Secure Download Action Handler (ARC-016, ARC-017, ARC-018, ARC-020)
     */
    public static function handle_download_action(): void {
        $item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($item_id <= 0) {
            wp_die('Invalid archive request.', 'Bad Request', ['response' => 400]);
        }

        $post = get_post($item_id);
        if (!$post || $post->post_type !== self::POST_TYPE_ITEM || $post->post_status !== 'publish') {
            wp_die('Document not found.', 'Not Found', ['response' => 404]);
        }

        // Access check
        $user_id = get_current_user_id();
        if (!self::can_access_item($item_id, $user_id)) {
            if ($user_id <= 0) {
                wp_die('Authentication required to access this archive document.', 'Unauthorized', ['response' => 401]);
            } else {
                wp_die('Forbidden: Insufficient permissions for this classified archive document.', 'Forbidden', ['response' => 403]);
            }
        }

        $file_path = (string)get_post_meta($item_id, '_lfa_archive_file_path', true);
        $file_name = (string)get_post_meta($item_id, '_lfa_archive_file_name', true) ?: basename($file_path);
        $file_mime = (string)get_post_meta($item_id, '_lfa_archive_file_mime', true) ?: 'application/octet-stream';

        if (empty($file_path) || !file_exists($file_path)) {
            wp_die('Archived file payload is unavailable.', 'Not Found', ['response' => 404]);
        }

        // Validate MIME type (ARC-018: reject executables, php, html, js)
        if (!isset(self::ALLOWED_DOC_MIMES[$file_mime])) {
            wp_die('Security Error: Disallowed document file format.', 'Forbidden', ['response' => 403]);
        }

        // Log audit
        if (class_exists('LFA_Content_Audit')) {
            LFA_Content_Audit::log($user_id > 0 ? 'member' : 'guest', $user_id ?: null, 'archive_downloaded', 'archive_item', (string)$item_id, [
                'file_name' => $file_name,
            ]);
        }

        // Strict non-shared cache headers (ARC-020)
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: ' . $file_mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file_name) . '"');
        header('Content-Length: ' . filesize($file_path));

        // Flush output buffer and stream file
        if (ob_get_level()) {
            ob_end_clean();
        }
        readfile($file_path);
        exit;
    }

    /**
     * Validate video URL (ARC-021: Allowlisted domains only)
     */
    public static function validate_video_url(string $url): bool {
        if (empty($url)) {
            return false;
        }
        $parsed = parse_url($url);
        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
            return false;
        }
        if (!isset($parsed['host'])) {
            return false;
        }
        $host = strtolower($parsed['host']);
        foreach (self::ALLOWED_VIDEO_DOMAINS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get Collections / Albums
     */
    public static function get_collections(): array {
        $terms = get_terms([
            'taxonomy'   => self::TAXONOMY_COLLECTION,
            'hide_empty' => false,
        ]);

        $collections = [];
        foreach ($terms as $t) {
            // Count published items
            $count = (int)count(get_posts([
                'post_type'      => self::POST_TYPE_ITEM,
                'post_status'    => 'publish',
                'tax_query'      => [[
                    'taxonomy' => self::TAXONOMY_COLLECTION,
                    'field'    => 'term_id',
                    'terms'    => $t->term_id,
                ]],
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]));

            $collections[] = [
                'id'          => $t->term_id,
                'slug'        => $t->slug,
                'title'       => $t->name,
                'description' => $t->description ?: 'Love Family Alumni Heritage Collection',
                'item_count'  => $count,
                'permalink'   => home_url('/gallery/?album=' . $t->slug),
            ];
        }

        return $collections;
    }

    /**
     * Get Archive Items with filtering
     */
    public static function get_items(array $args = []): array {
        $defaults = [
            'category'   => '',
            'collection' => '',
            'kind'       => '',
            'year'       => 0,
            'search'     => '',
            'page'       => 1,
            'per_page'   => 20,
            'status'     => 'publish',
        ];
        $params = wp_parse_args($args, $defaults);

        $tax_query  = [];
        $meta_query = [];

        if (!empty($params['category']) && $params['category'] !== 'all') {
            $tax_query[] = [
                'taxonomy' => self::TAXONOMY_CAT,
                'field'    => 'slug',
                'terms'    => sanitize_title($params['category']),
            ];
        }

        if (!empty($params['collection']) && $params['collection'] !== 'all') {
            $tax_query[] = [
                'taxonomy' => self::TAXONOMY_COLLECTION,
                'field'    => 'slug',
                'terms'    => sanitize_title($params['collection']),
            ];
        }

        if (!empty($params['kind']) && $params['kind'] !== 'all') {
            $meta_query[] = [
                'key'   => '_lfa_archive_kind',
                'value' => sanitize_key($params['kind']),
            ];
        }

        if (!empty($params['year']) && (int)$params['year'] > 0) {
            $meta_query[] = [
                'key'   => '_lfa_archive_year',
                'value' => (int)$params['year'],
                'type'  => 'NUMERIC',
            ];
        }

        $query_args = [
            'post_type'      => self::POST_TYPE_ITEM,
            'post_status'    => $params['status'],
            'posts_per_page' => (int)$params['per_page'],
            'paged'          => max(1, (int)$params['page']),
            'meta_query'     => $meta_query,
            'tax_query'      => $tax_query,
            's'              => sanitize_text_field($params['search']),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $query = new WP_Query($query_args);
        $user_id = get_current_user_id();

        $items = [];
        foreach ($query->posts as $p) {
            $access_level = (string)get_post_meta($p->ID, '_lfa_archive_access', true) ?: 'restricted_staff';
            $can_access   = self::can_access_item($p->ID, $user_id);

            // ARC-013: Private/restricted items don't leak confidential metadata to guests
            if (!$can_access && $access_level === 'restricted_staff') {
                continue;
            }

            $items[] = self::format_archive_item($p, $can_access);
        }

        return [
            'items'       => $items,
            'total'       => count($items),
            'total_pages' => (int)$query->max_num_pages,
            'page'        => max(1, (int)$params['page']),
        ];
    }

    /**
     * Get Photos for Album Lightbox (ARC-008, ARC-010)
     */
    public static function get_album_photos(string $collection_slug): array {
        $items_res = self::get_items([
            'collection' => $collection_slug,
            'kind'       => 'photo',
            'per_page'   => 50,
        ]);
        return $items_res['items'];
    }

    /**
     * Format Archive Item
     */
    public static function format_archive_item(WP_Post $post, bool $can_access): array {
        $id = $post->ID;

        $kind   = (string)get_post_meta($id, '_lfa_archive_kind', true) ?: 'photo';
        $access = (string)get_post_meta($id, '_lfa_archive_access', true) ?: 'public';
        $year   = (int)get_post_meta($id, '_lfa_archive_year', true);
        $rights = (string)get_post_meta($id, '_lfa_archive_source_rights', true) ?: 'Love Family Alumni Heritage';

        $cats   = wp_get_post_terms($id, self::TAXONOMY_CAT);
        $cat_slug = !empty($cats) ? $cats[0]->slug : 'general';
        $cat_name = !empty($cats) ? $cats[0]->name : 'General Archive';

        $colls  = wp_get_post_terms($id, self::TAXONOMY_COLLECTION);
        $col_slug = !empty($colls) ? $colls[0]->slug : '';
        $col_name = !empty($colls) ? $colls[0]->name : '';

        $thumb_id  = get_post_thumbnail_id($id);
        $image_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : '';
        $image_alt = $thumb_id ? (string)get_post_meta($thumb_id, '_wp_attachment_image_alt', true) : esc_attr($post->post_title);

        if (empty($image_url)) {
            $image_url = get_template_directory_uri() . '/assets/images/conference-cinematic-bg.jpg';
        }

        $video_url = (string)get_post_meta($id, '_lfa_archive_video_url', true);
        $file_name = (string)get_post_meta($id, '_lfa_archive_file_name', true);
        $file_mime = (string)get_post_meta($id, '_lfa_archive_file_mime', true);
        $file_size = (int)get_post_meta($id, '_lfa_archive_file_size', true);

        $download_url = home_url('/wp-admin/admin-post.php?action=lfa_download_archive&id=' . $id);

        return [
            'id'              => (int)$id,
            'title'           => $post->post_title,
            'description'     => wp_kses_post($post->post_content),
            'summary'         => $post->post_excerpt ?: wp_trim_words($post->post_content, 18),
            'kind'            => $kind,
            'access_level'    => $access,
            'can_access'      => $can_access,
            'year'            => $year ?: null,
            'source_rights'   => $rights,
            'category_slug'   => $cat_slug,
            'category_name'   => $cat_name,
            'collection_slug' => $col_slug,
            'collection_name' => $col_name,
            'image_url'       => $image_url,
            'image_alt'       => $image_alt ?: $post->post_title,
            'video_url'       => $video_url,
            'file_name'       => $file_name,
            'file_mime'       => $file_mime,
            'file_size_human' => $file_size > 0 ? size_format($file_size) : '',
            'download_url'    => $download_url,
        ];
    }

    /**
     * Get Timeline Milestones (ARC-024, ARC-025, ARC-026)
     */
    public static function get_timeline_milestones(): array {
        $posts = get_posts([
            'post_type'        => self::POST_TYPE_TIMELINE,
            'post_status'      => 'publish',
            'posts_per_page'   => 50,
            'orderby'          => 'meta_value_num',
            'meta_key'         => '_lfa_milestone_year',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);

        $milestones = [];
        foreach ($posts as $p) {
            $year = (int)get_post_meta($p->ID, '_lfa_milestone_year', true);
            $source = (string)get_post_meta($p->ID, '_lfa_milestone_source', true);
            $granularity = (string)get_post_meta($p->ID, '_lfa_milestone_granularity', true) ?: 'year';

            // ARC-026: 2027 must be prospective, never asserted as completed
            $is_future = ($year >= 2027);

            $milestones[] = [
                'id'          => $p->ID,
                'year'        => $year,
                'title'       => $p->post_title,
                'description' => wp_kses_post($p->post_content),
                'source'      => $source,
                'granularity' => $granularity,
                'is_future'   => $is_future,
                'badge'       => $is_future ? 'Upcoming / Prospective' : 'Documented Milestone',
            ];
        }

        return $milestones;
    }

    /**
     * REST Routes (ARC-028)
     */
    public static function register_rest_routes(): void {
        register_rest_route('lfa/v1', '/archives', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_get_archives'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lfa/v1', '/archives/timeline', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_get_timeline'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function rest_get_archives(WP_REST_Request $request): WP_REST_Response {
        $category   = sanitize_title($request->get_param('category') ?: '');
        $collection = sanitize_title($request->get_param('collection') ?: '');
        $kind       = sanitize_key($request->get_param('kind') ?: '');
        $year       = (int)$request->get_param('year');

        $res = self::get_items([
            'category'   => $category,
            'collection' => $collection,
            'kind'       => $kind,
            'year'       => $year,
        ]);

        return new WP_REST_Response($res, 200);
    }

    public static function rest_get_timeline(WP_REST_Request $request): WP_REST_Response {
        $milestones = self::get_timeline_milestones();
        return new WP_REST_Response(['milestones' => $milestones], 200);
    }
}
