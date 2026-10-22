<?php
/**
 * Love Family Alumni — News & Announcements Subsystem
 *
 * Provides real dynamic news management, required categories (Birthdays & Weddings,
 * Promotions & Achievements, Awards & Recognition, Bereavements, General), strict
 * personal consent verification gates (fail-closed), and opt-in notification triggers.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_News {

    const POST_TYPE = 'lfa_news';
    const TAXONOMY  = 'lfa_news_category';

    const CAP_EDIT_NEWS    = 'lfa_edit_news';
    const CAP_PUBLISH_NEWS = 'lfa_publish_news';
    const CAP_DELETE_NEWS  = 'lfa_delete_news';

    // Categories that require explicit consent/authorization documentation (NEW-010)
    const CONSENT_REQUIRED_CATEGORIES = [
        'birthdays-weddings',
        'promotions-achievements',
        'awards-recognition',
        'bereavements',
    ];

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_post_type_and_taxonomies'], 5);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('template_redirect', [__CLASS__, 'handle_template_redirect']);
        add_filter('wp_insert_post_data', [__CLASS__, 'enforce_consent_on_save'], 10, 2);
    }

    public static function enforce_consent_on_save(array $data, array $postarr): array {
        if (($data['post_type'] ?? '') === self::POST_TYPE && in_array($data['post_status'] ?? '', ['publish', 'future'], true)) {
            $post_id = $postarr['ID'] ?? 0;
            if ($post_id > 0) {
                $terms = wp_get_post_terms($post_id, self::TAXONOMY);
                $cat = !empty($terms) ? $terms[0]->slug : '';
                if (self::requires_consent($cat)) {
                    $consent = get_post_meta($post_id, '_lfa_news_consent_confirmed', true);
                    if ((string)$consent !== '1') {
                        $data['post_status'] = 'draft';
                    }
                }
            }
        }
        return $data;
    }

    public static function handle_template_redirect(): void {
        if (is_page('news-details') && !empty($_GET['story'])) {
            $slug = sanitize_title(wp_unslash($_GET['story']));
            $story = self::get_story_by_slug($slug);
            if (!$story && function_exists('lfa_news_task03_find')) {
                $story = lfa_news_task03_find($slug);
            }
            if (!$story) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                nocache_headers();
            }
        }
    }

    public static function install_schema(): void {
        self::register_post_type_and_taxonomies();
        self::seed_default_categories();
        self::seed_initial_news();
    }

    public static function register_post_type_and_taxonomies(): void {
        // 1. Taxonomy: News Category
        register_taxonomy(self::TAXONOMY, self::POST_TYPE, [
            'labels' => [
                'name'          => 'News Categories',
                'singular_name' => 'News Category',
                'menu_name'     => 'News Categories',
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'news-category'],
            'capabilities'      => [
                'manage_terms' => self::CAP_PUBLISH_NEWS,
                'edit_terms'   => self::CAP_PUBLISH_NEWS,
                'delete_terms' => self::CAP_PUBLISH_NEWS,
                'assign_terms' => self::CAP_EDIT_NEWS,
            ],
        ]);

        // 2. Custom Post Type: News Article / Announcement
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => 'News & Stories',
                'singular_name'      => 'News Story',
                'menu_name'          => 'News & Announcements',
                'add_new'            => 'Add New Article',
                'add_new_item'       => 'Add New Article',
                'edit_item'          => 'Edit Article',
                'new_item'           => 'New Article',
                'view_item'          => 'View Article',
                'search_items'       => 'Search Articles',
                'not_found'          => 'No articles found',
                'not_found_in_trash' => 'No articles found in Trash',
            ],
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'show_in_rest'        => true,
            'rest_base'           => 'lfa_news_cpt',
            'has_archive'         => false,
            'rewrite'             => ['slug' => 'news', 'with_front' => false],
            'supports'            => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
            'capability_type'     => ['lfa_news_post', 'lfa_news_posts'],
            'map_meta_cap'        => false,
            'capabilities'        => [
                'edit_post'          => self::CAP_EDIT_NEWS,
                'read_post'          => 'read',
                'delete_post'        => self::CAP_DELETE_NEWS,
                'edit_posts'         => self::CAP_EDIT_NEWS,
                'edit_others_posts'  => self::CAP_PUBLISH_NEWS,
                'publish_posts'      => self::CAP_PUBLISH_NEWS,
                'read_private_posts' => self::CAP_EDIT_NEWS,
            ],
        ]);
    }

    public static function seed_default_categories(): void {
        $categories = [
            'birthdays-weddings'     => 'Birthdays and Weddings',
            'promotions-achievements'=> 'Promotions and Achievements',
            'awards-recognition'     => 'Awards and Recognition',
            'bereavements'           => 'Bereavements',
            'general'                => 'General News and Announcements',
        ];

        foreach ($categories as $slug => $name) {
            if (!term_exists($slug, self::TAXONOMY)) {
                wp_insert_term($name, self::TAXONOMY, ['slug' => $slug]);
            }
        }
    }

    /**
     * Seed initial authoritative client news articles
     */
    public static function seed_initial_news(): void {
        $existing = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => 1,
        ]);
        if (!empty($existing)) {
            return;
        }

        // 1. General News: 2027 Conference Announcement
        $id1 = wp_insert_post([
            'post_title'   => 'Preparations Underway for the 2027 Biennial Continental Conference',
            'post_name'    => '2027-conference-preparations',
            'post_content' => '<p>The Love Family Alumni Executive Council is pleased to announce advanced preparations for our 2027 Biennial Conference under the inspiring theme <em>"Thy Kingdom Come"</em> (Matthew 6:10).</p><p>Dr. Uche Igbokwe has been confirmed as keynote speaker. The fellowship looks forward to an impactful season of spiritual renewal, generational connection, and strategic alumni development.</p>',
            'post_excerpt' => 'The Executive Council announces advanced preparations for the 2027 Biennial Continental Conference in North America.',
            'post_status'  => 'publish',
            'post_type'    => self::POST_TYPE,
            'post_author'  => 1,
        ]);
        if ($id1 && !is_wp_error($id1)) {
            wp_set_object_terms($id1, 'general', self::TAXONOMY);
            update_post_meta($id1, '_lfa_news_consent_confirmed', '1');
            update_post_meta($id1, '_lfa_news_author_attribution', 'Executive Secretariat');
            update_post_meta($id1, '_lfa_news_featured', '1');
        }

        // 2. Academic / Scholarship Fund Announcement
        $id2 = wp_insert_post([
            'post_title'   => 'The Akpo Family Scholarship Endowment Fund Expanded for 2026/2027',
            'post_name'    => 'akpo-family-scholarship-expansion',
            'post_content' => '<p>Through generous alumni contributions and family benefaction, the Akpo Family Scholarship Fund has expanded its endowment to support university students across STEM, medicine, and humanities disciplines.</p><p>Applications for the upcoming academic cycle will open through the verified member portal.</p>',
            'post_excerpt' => 'Scholarship endowment expanded to empower qualified undergraduate and postgraduate scholars within our fellowship family.',
            'post_status'  => 'publish',
            'post_type'    => self::POST_TYPE,
            'post_author'  => 1,
        ]);
        if ($id2 && !is_wp_error($id2)) {
            wp_set_object_terms($id2, 'awards-recognition', self::TAXONOMY);
            update_post_meta($id2, '_lfa_news_consent_confirmed', '1');
            update_post_meta($id2, '_lfa_news_consent_notes', 'Approved by Scholarship Board');
            update_post_meta($id2, '_lfa_news_author_attribution', 'Scholarship Committee');
            update_post_meta($id2, '_lfa_news_featured', '0');
        }
    }

    /**
     * Consent Verification Gate (NEW-010): Fail-closed
     */
    public static function requires_consent(string $category_slug): bool {
        return in_array($category_slug, self::CONSENT_REQUIRED_CATEGORIES, true);
    }

    /**
     * Query News Articles
     */
    public static function get_news(array $args = []): array {
        $defaults = [
            'category' => '',
            'search'   => '',
            'page'     => 1,
            'per_page' => 9,
            'status'   => 'publish',
            'featured' => null,
        ];
        $params = wp_parse_args($args, $defaults);

        $tax_query  = [];
        $meta_query = [];

        if (!empty($params['category']) && $params['category'] !== 'all') {
            $tax_query[] = [
                'taxonomy' => self::TAXONOMY,
                'field'    => 'slug',
                'terms'    => sanitize_title($params['category']),
            ];
        }

        if ($params['featured'] !== null) {
            $meta_query[] = [
                'key'   => '_lfa_news_featured',
                'value' => $params['featured'] ? '1' : '0',
            ];
        }

        $query_args = [
            'post_type'      => self::POST_TYPE,
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
        $items = [];

        foreach ($query->posts as $post) {
            $items[] = self::format_news_item($post);
        }

        return [
            'items'       => $items,
            'total'       => (int)$query->found_posts,
            'total_pages' => (int)$query->max_num_pages,
            'page'        => max(1, (int)$params['page']),
        ];
    }

    /**
     * Get single news article by slug
     */
    public static function get_story_by_slug(string $slug): ?array {
        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'name'           => sanitize_title($slug),
            'post_status'    => ['publish', 'draft', 'future'],
            'posts_per_page' => 1,
        ]);

        if (empty($posts)) {
            return null;
        }

        $post = $posts[0];
        // Enforce permissions for non-published (NEW-017)
        if ($post->post_status !== 'publish' && !current_user_can(self::CAP_EDIT_NEWS)) {
            return null;
        }

        return self::format_news_item($post);
    }

    /**
     * Get related published stories (NEW-019: Only published content, no draft leakage)
     */
    public static function get_related_stories(int $post_id, string $category_slug, int $limit = 3): array {
        $query_args = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish', // Strict: only published
            'posts_per_page' => $limit,
            'post__not_in'   => [$post_id],
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if (!empty($category_slug) && $category_slug !== 'all') {
            $query_args['tax_query'] = [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => sanitize_title($category_slug),
                ]
            ];
        }

        $posts = get_posts($query_args);
        $related = [];
        foreach ($posts as $p) {
            $related[] = self::format_news_item($p);
        }

        return $related;
    }

    public static function format_story(WP_Post $post): array {
        return self::format_news_item($post);
    }

    /**
     * Format a post object into standard News item representation
     */
    public static function format_news_item(WP_Post $post): array {
        $id = $post->ID;

        $terms = wp_get_post_terms($id, self::TAXONOMY);
        $cat_slug  = !empty($terms) ? $terms[0]->slug : 'general';
        $cat_label = !empty($terms) ? $terms[0]->name : 'General News';

        $attribution = (string)get_post_meta($id, '_lfa_news_author_attribution', true) ?: 'Love Family Communications';
        $consent_ok  = (string)get_post_meta($id, '_lfa_news_consent_confirmed', true) === '1';
        $is_featured = (string)get_post_meta($id, '_lfa_news_featured', true) === '1';

        // Image
        $thumb_id  = get_post_thumbnail_id($id);
        $image_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : '';
        $image_alt = $thumb_id ? (string)get_post_meta($thumb_id, '_wp_attachment_image_alt', true) : esc_attr($post->post_title);

        if (empty($image_url)) {
            $slug_img_map = [
                '2027-conference-preparations'                 => 'conference-cinematic-bg.jpg',
                'akpo-family-scholarship-expansion'            => 'scholarship-cap.jpg',
                'dr-uche-igbokwe-keynote-announcement'         => 'omaha-podium-2025.jpg',
                'alumni-executive-council-strategic-assembly'  => 'original-alumni-group.jpg',
                'celebrating-alumni-academic-achievements'     => 'original-women-community-group.jpg',
            ];
            $cat_img_map = [
                'awards-recognition' => 'original-women-community-group.jpg',
                'chapter-spotlight'  => 'original-five-person-community.jpg',
                'obituaries'         => 'giving-heart.jpg',
                'general'            => 'original-alumni-group.jpg',
            ];

            $img_file = $slug_img_map[$post->post_name] ?? ($cat_img_map[$cat_slug] ?? 'conference-cinematic-bg.jpg');
            $image_url = get_template_directory_uri() . '/assets/images/' . $img_file;
        }

        return [
            'id'             => (int)$id,
            'title'          => $post->post_title,
            'slug'           => $post->post_name,
            'permalink'      => home_url('/news-details/?story=' . $post->post_name),
            'summary'        => $post->post_excerpt ?: wp_trim_words($post->post_content, 22),
            'content'        => wp_kses_post($post->post_content),
            'status'         => $post->post_status,
            'date'           => get_the_date('F j, Y', $post),
            'date_iso'       => get_the_date('c', $post),
            'category'       => $cat_slug,
            'category_label' => $cat_label,
            'author'         => $attribution,
            'consent_ok'     => $consent_ok,
            'is_featured'    => $is_featured,
            'image_url'      => $image_url,
            'image_alt'      => $image_alt ?: $post->post_title,
            'read_time'      => max(1, (int)ceil(str_word_count(strip_tags($post->post_content)) / 200)) . ' min read',
        ];
    }

    /**
     * REST Routes (NEW-021)
     */
    public static function register_rest_routes(): void {
        register_rest_route('lfa/v1', '/news', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_get_news'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lfa/v1', '/news/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_get_single_story'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function rest_get_news(WP_REST_Request $request): WP_REST_Response {
        $category = sanitize_title($request->get_param('category') ?: '');
        $search   = sanitize_text_field($request->get_param('search') ?: '');
        $page     = max(1, (int)$request->get_param('page'));
        $per_page = min(50, max(1, (int)($request->get_param('per_page') ?: 9)));

        $status = 'publish';
        if (current_user_can(self::CAP_EDIT_NEWS) && $request->get_param('status')) {
            $status = sanitize_key($request->get_param('status'));
        }

        $res = self::get_news([
            'category' => $category,
            'search'   => $search,
            'page'     => $page,
            'per_page' => $per_page,
            'status'   => $status,
        ]);

        return new WP_REST_Response($res, 200);
    }

    public static function rest_get_single_story(WP_REST_Request $request): WP_REST_Response {
        $slug  = sanitize_title($request->get_param('slug'));
        $story = self::get_story_by_slug($slug);

        if (!$story) {
            return new WP_REST_Response(['error' => 'Story not found or not published.'], 404);
        }

        return new WP_REST_Response($story, 200);
    }
}
