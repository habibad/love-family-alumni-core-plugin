<?php
/**
 * Love Family Alumni — Events Calendar Subsystem
 *
 * Provides real WordPress database management, CPT registration,
 * chronological filtering, category taxonomies, conference linking,
 * REST endpoints, and timezone conversion.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Events {

    const POST_TYPE = 'lfa_event';
    const TAXONOMY  = 'lfa_event_category';

    const CAP_EDIT_EVENTS    = 'lfa_edit_events';
    const CAP_PUBLISH_EVENTS = 'lfa_publish_events';
    const CAP_DELETE_EVENTS  = 'lfa_delete_events';

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_post_type_and_taxonomies'], 5);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('template_redirect', [__CLASS__, 'handle_template_redirect']);
    }

    public static function handle_template_redirect(): void {
        if (is_page('events') && !empty($_GET['event'])) {
            $slug = sanitize_title(wp_unslash($_GET['event']));
            $event = self::get_event_by_slug($slug);
            if (!$event) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                nocache_headers();
            } elseif (!empty($event['linked_conf_url'])) {
                wp_safe_redirect($event['linked_conf_url'], 302);
                exit;
            }
        }
    }

    public static function install_schema(): void {
        self::register_post_type_and_taxonomies();
        self::seed_default_categories();
        self::seed_initial_events();
    }

    public static function register_post_type_and_taxonomies(): void {
        // 1. Taxonomy: Event Category
        register_taxonomy(self::TAXONOMY, self::POST_TYPE, [
            'labels' => [
                'name'          => 'Event Categories',
                'singular_name' => 'Event Category',
                'menu_name'     => 'Event Categories',
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'event-category'],
            'capabilities'      => [
                'manage_terms' => self::CAP_PUBLISH_EVENTS,
                'edit_terms'   => self::CAP_PUBLISH_EVENTS,
                'delete_terms' => self::CAP_PUBLISH_EVENTS,
                'assign_terms' => self::CAP_EDIT_EVENTS,
            ],
        ]);

        // 2. Custom Post Type: Event
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => 'Events',
                'singular_name'      => 'Event',
                'menu_name'          => 'Events',
                'add_new'            => 'Add New Event',
                'add_new_item'       => 'Add New Event',
                'edit_item'          => 'Edit Event',
                'new_item'           => 'New Event',
                'view_item'          => 'View Event',
                'search_items'       => 'Search Events',
                'not_found'          => 'No events found',
                'not_found_in_trash' => 'No events found in Trash',
            ],
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => false, // Managed via custom top-level/sub-menu in admin
            'show_in_rest'        => true,
            'rest_base'           => 'lfa_events_cpt',
            'has_archive'         => false,
            'rewrite'             => ['slug' => 'events', 'with_front' => false],
            'supports'            => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
            'capability_type'     => ['lfa_event', 'lfa_events'],
            'map_meta_cap'        => false,
            'capabilities'        => [
                'edit_post'          => self::CAP_EDIT_EVENTS,
                'read_post'          => 'read',
                'delete_post'        => self::CAP_DELETE_EVENTS,
                'edit_posts'         => self::CAP_EDIT_EVENTS,
                'edit_others_posts'  => self::CAP_PUBLISH_EVENTS,
                'publish_posts'      => self::CAP_PUBLISH_EVENTS,
                'read_private_posts' => self::CAP_EDIT_EVENTS,
            ],
        ]);
    }

    public static function seed_default_categories(): void {
        $categories = [
            'conference' => 'Conferences',
            'reunion'    => 'Reunions',
            'community'  => 'Community',
            'service'    => 'Service & Impact',
            'virtual'    => 'Virtual Fellowship',
        ];

        foreach ($categories as $slug => $name) {
            if (!term_exists($slug, self::TAXONOMY)) {
                wp_insert_term($name, self::TAXONOMY, ['slug' => $slug]);
            }
        }
    }

    /**
     * Seed initial authoritative events (e.g. 2027 Conference & Omaha 2025 Reunion)
     */
    public static function seed_initial_events(): void {
        $existing = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => 1,
        ]);
        if (!empty($existing)) {
            return;
        }

        // 1. 2027 National Conference (Upcoming, linked to conference #1)
        $conf1 = null;
        if (class_exists('LFA_Conference')) {
            $conf1 = LFA_Conference::get_conference('2027-biennial-conference');
        }

        $event1_id = wp_insert_post([
            'post_title'   => '2027 Alumni National Conference',
            'post_name'    => '2027-alumni-national-conference',
            'post_content' => 'The flagship continental gathering uniting The Love Family Alumni for fellowship, inspirational sessions, leadership renewal, and strategic community impact.',
            'post_excerpt' => 'The flagship continental gathering uniting The Love Family Alumni for fellowship, inspirational sessions, leadership renewal, and strategic community impact.',
            'post_status'  => 'publish',
            'post_type'    => self::POST_TYPE,
            'post_author'  => 1,
        ]);

        if ($event1_id && !is_wp_error($event1_id)) {
            wp_set_object_terms($event1_id, 'conference', self::TAXONOMY);
            update_post_meta($event1_id, '_lfa_event_start', '2027-09-16 09:00:00');
            update_post_meta($event1_id, '_lfa_event_end', '2027-09-19 17:00:00');
            update_post_meta($event1_id, '_lfa_event_timezone', 'America/New_York');
            update_post_meta($event1_id, '_lfa_event_date_only', '0');
            update_post_meta($event1_id, '_lfa_event_venue_name', 'North America Zone (Keynote: Dr. Uche Igbokwe, U.K.)');
            update_post_meta($event1_id, '_lfa_event_venue_address', 'North America Zone TBA');
            update_post_meta($event1_id, '_lfa_event_is_online', '0');
            update_post_meta($event1_id, '_lfa_event_status', 'published');
            if ($conf1) {
                update_post_meta($event1_id, '_lfa_event_linked_conf_id', (int)$conf1->id);
            }
        }

        // 2. 2025 Omaha Alumni Reunion (Past archive event)
        $event2_id = wp_insert_post([
            'post_title'   => '2025 Omaha Alumni Reunion & Assembly',
            'post_name'    => '2025-omaha-alumni-reunion',
            'post_content' => 'A milestone regional gathering celebrating our heritage, reconnecting alumni across decades, and laying the groundwork for continental mission expansion.',
            'post_excerpt' => 'A milestone regional gathering celebrating our heritage, reconnecting alumni across decades, and laying the groundwork for continental mission expansion.',
            'post_status'  => 'publish',
            'post_type'    => self::POST_TYPE,
            'post_author'  => 1,
        ]);

        if ($event2_id && !is_wp_error($event2_id)) {
            wp_set_object_terms($event2_id, 'reunion', self::TAXONOMY);
            update_post_meta($event2_id, '_lfa_event_start', '2025-07-18 10:00:00');
            update_post_meta($event2_id, '_lfa_event_end', '2025-07-20 16:00:00');
            update_post_meta($event2_id, '_lfa_event_timezone', 'America/Chicago');
            update_post_meta($event2_id, '_lfa_event_date_only', '1');
            update_post_meta($event2_id, '_lfa_event_venue_name', 'Omaha, Nebraska');
            update_post_meta($event2_id, '_lfa_event_venue_address', 'Omaha, Nebraska, USA');
            update_post_meta($event2_id, '_lfa_event_is_online', '0');
            update_post_meta($event2_id, '_lfa_event_status', 'published');
        }
    }

    /**
     * Validate event chronology (EVE-008)
     */
    public static function validate_dates(string $start, string $end): bool {
        $ts_start = strtotime($start);
        $ts_end   = strtotime($end);
        if ($ts_start === false || $ts_end === false) {
            return false;
        }
        return $ts_end >= $ts_start;
    }

    /**
     * Query events for public and frontend consumption
     */
    public static function get_events(array $args = []): array {
        $defaults = [
            'period'    => 'all', // 'all', 'upcoming', 'past'
            'category'  => '',
            'month'     => '',    // 'YYYY-MM'
            'search'    => '',
            'page'      => 1,
            'per_page'  => 12,
            'status'    => 'publish',
        ];
        $params = wp_parse_args($args, $defaults);

        $now_mysql = current_time('mysql', false); // Local or UTC reference

        $meta_query = [];
        $tax_query  = [];

        if (!empty($params['category']) && $params['category'] !== 'all') {
            $tax_query[] = [
                'taxonomy' => self::TAXONOMY,
                'field'    => 'slug',
                'terms'    => sanitize_title($params['category']),
            ];
        }

        // Period filter (upcoming vs past)
        if ($params['period'] === 'upcoming') {
            $meta_query[] = [
                'key'     => '_lfa_event_start',
                'value'   => $now_mysql,
                'compare' => '>=',
                'type'    => 'DATETIME',
            ];
            $orderby = 'meta_value';
            $meta_key = '_lfa_event_start';
            $order = 'ASC';
        } elseif ($params['period'] === 'past') {
            $meta_query[] = [
                'key'     => '_lfa_event_start',
                'value'   => $now_mysql,
                'compare' => '<',
                'type'    => 'DATETIME',
            ];
            $orderby = 'meta_value';
            $meta_key = '_lfa_event_start';
            $order = 'DESC';
        } else {
            // All: order by start date descending
            $orderby = 'meta_value';
            $meta_key = '_lfa_event_start';
            $order = 'ASC';
        }

        // Specific Month filter (EVE-011)
        if (!empty($params['month']) && preg_match('/^\d{4}-\d{2}$/', $params['month'])) {
            $month_start = $params['month'] . '-01 00:00:00';
            $days_in_month = (int)date('t', strtotime($month_start));
            $month_end   = $params['month'] . '-' . $days_in_month . ' 23:59:59';

            $meta_query[] = [
                'key'     => '_lfa_event_start',
                'value'   => [$month_start, $month_end],
                'compare' => 'BETWEEN',
                'type'    => 'DATETIME',
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
        ];

        if (isset($orderby)) {
            $query_args['orderby']  = $orderby;
            $query_args['meta_key'] = $meta_key;
            $query_args['order']    = $order;
        }

        $query = new WP_Query($query_args);
        $items = [];

        foreach ($query->posts as $post) {
            $items[] = self::format_event_item($post);
        }

        return [
            'items'       => $items,
            'total'       => (int)$query->found_posts,
            'total_pages' => (int)$query->max_num_pages,
            'page'        => max(1, (int)$params['page']),
        ];
    }

    /**
     * Get single event by slug
     */
    public static function get_event_by_slug(string $slug): ?array {
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
        // Enforce permissions for draft/future
        if ($post->post_status !== 'publish' && !current_user_can(self::CAP_EDIT_EVENTS)) {
            return null;
        }

        return self::format_event_item($post);
    }

    public static function format_event(WP_Post $post): array {
        return self::format_event_item($post);
    }

    /**
     * Format a post object into standard Event representation
     */
    public static function format_event_item(WP_Post $post): array {
        $id = $post->ID;

        $start_raw = (string)get_post_meta($id, '_lfa_event_start', true);
        $end_raw   = (string)get_post_meta($id, '_lfa_event_end', true);
        $tz_str    = (string)get_post_meta($id, '_lfa_event_timezone', true) ?: 'America/New_York';
        $date_only = (string)get_post_meta($id, '_lfa_event_date_only', true) === '1';

        $venue_name    = (string)get_post_meta($id, '_lfa_event_venue_name', true);
        $venue_address = (string)get_post_meta($id, '_lfa_event_venue_address', true);
        $is_online     = (string)get_post_meta($id, '_lfa_event_is_online', true) === '1';
        $online_url    = (string)get_post_meta($id, '_lfa_event_online_url', true);
        $online_appr   = (string)get_post_meta($id, '_lfa_event_online_approved', true) === '1';
        $linked_conf   = (int)get_post_meta($id, '_lfa_event_linked_conf_id', true);

        // Safe category
        $terms = wp_get_post_terms($id, self::TAXONOMY);
        $cat_slug  = !empty($terms) ? $terms[0]->slug : 'community';
        $cat_label = !empty($terms) ? $terms[0]->name : 'Community Event';

        // Timezone conversion & formatting (EVE-009, EVE-010)
        $formatted_dates = self::format_dates($start_raw, $end_raw, $tz_str, $date_only);

        // Featured image
        $thumb_id  = get_post_thumbnail_id($id);
        $image_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : '';
        $image_alt = $thumb_id ? (string)get_post_meta($thumb_id, '_wp_attachment_image_alt', true) : esc_attr($post->post_title);

        // Fallback contextual authentic image if none attached
        if (empty($image_url)) {
            $cat_images = [
                'reunion'    => 'omaha-podium-2025.jpg',
                'virtual'    => 'original-five-person-community.jpg',
                'community'  => 'original-alumni-group.jpg',
                'conference' => 'conference-cinematic-bg.jpg',
            ];
            $chosen = $cat_images[$cat_slug] ?? 'conference-cinematic-bg.jpg';
            $image_url = get_template_directory_uri() . '/assets/images/' . $chosen;
        }

        // Action URL (EVE-018: Conference linking avoids duplicate checkout)
        $action_label = 'View Event Details';
        $action_url   = home_url('/events/?event=' . $post->post_name);

        if ($linked_conf > 0 && class_exists('LFA_Conference')) {
            $conf_obj = LFA_Conference::get_conference_by_id($linked_conf);
            if ($conf_obj) {
                $action_label = 'View ' . ($conf_obj->title ?: 'Conference');
                $action_url   = LFA_Conference::get_conference_url($conf_obj);
            }
        }

        // Period check
        $now_mysql = current_time('mysql', false);
        $period = ($start_raw && $start_raw >= $now_mysql) ? 'upcoming' : 'past';

        return [
            'id'             => (int)$id,
            'title'          => $post->post_title,
            'slug'           => $post->post_name,
            'permalink'      => home_url('/events/?event=' . $post->post_name),
            'summary'        => $post->post_excerpt ?: wp_trim_words($post->post_content, 25),
            'content'        => wp_kses_post($post->post_content),
            'status'         => $post->post_status,
            'category'       => $cat_slug,
            'category_label' => $cat_label,
            'period'         => $period,
            'starts_at'      => $start_raw,
            'ends_at'        => $end_raw,
            'timezone'       => $tz_str,
            'date_only'      => $date_only,
            'date_label'     => $formatted_dates['label'],
            'status_badge'   => $formatted_dates['badge'],
            'location'       => !empty($venue_name) ? $venue_name : ($is_online ? 'Virtual Fellowship' : 'North America Zone TBA'),
            'venue_address'  => $venue_address,
            'is_online'      => $is_online,
            'online_url'     => ($is_online && $online_appr) ? esc_url($online_url) : '',
            'linked_conf_id' => $linked_conf,
            'linked_conf_url'=> ($linked_conf > 0 && $action_url !== home_url('/events/?event=' . $post->post_name)) ? $action_url : '',
            'image_url'      => $image_url,
            'image_alt'      => $image_alt ?: $post->post_title,
            'action_label'   => $action_label,
            'action_url'     => $action_url,
            'is_featured'    => ($cat_slug === 'conference'),
        ];
    }

    /**
     * Format start/end datetimes with timezone and granularity (EVE-009, EVE-010)
     */
    public static function format_dates(string $start, string $end, string $timezone, bool $date_only): array {
        if (empty($start)) {
            return ['label' => 'Dates TBA', 'badge' => 'Dates TBA'];
        }

        try {
            $tz = new DateTimeZone($timezone);
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
        }

        $dt_start = new DateTime($start);
        $dt_start->setTimezone($tz);

        $dt_end = null;
        if (!empty($end)) {
            $dt_end = new DateTime($end);
            $dt_end->setTimezone($tz);
        }

        if ($date_only) {
            // EVE-010: Date only, no artificial time
            if ($dt_end && $dt_start->format('Y-m-d') !== $dt_end->format('Y-m-d')) {
                if ($dt_start->format('F Y') === $dt_end->format('F Y')) {
                    $label = $dt_start->format('F j') . '–' . $dt_end->format('j, Y');
                } else {
                    $label = $dt_start->format('M j, Y') . ' – ' . $dt_end->format('M j, Y');
                }
            } else {
                $label = $dt_start->format('F j, Y');
            }
            $badge = $label;
        } else {
            // Full datetime with timezone
            $tz_abbr = $dt_start->format('T');
            if ($dt_end && $dt_start->format('Y-m-d') === $dt_end->format('Y-m-d')) {
                $label = $dt_start->format('F j, Y · g:i A') . ' – ' . $dt_end->format('g:i A ') . $tz_abbr;
            } elseif ($dt_end) {
                $label = $dt_start->format('M j, g:i A') . ' – ' . $dt_end->format('M j, Y, g:i A ') . $tz_abbr;
            } else {
                $label = $dt_start->format('F j, Y · g:i A ') . $tz_abbr;
            }
            $badge = $dt_start->format('M j, Y');
        }

        return ['label' => $label, 'badge' => $badge];
    }

    /**
     * REST Routes (EVE-021: Exclude drafts/private data for guests)
     */
    public static function register_rest_routes(): void {
        register_rest_route('lfa/v1', '/events', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_get_events'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lfa/v1', '/events/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_get_single_event'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function rest_get_events(WP_REST_Request $request): WP_REST_Response {
        $period   = sanitize_key($request->get_param('period') ?: 'all');
        $category = sanitize_title($request->get_param('category') ?: '');
        $month    = sanitize_text_field($request->get_param('month') ?: '');
        $search   = sanitize_text_field($request->get_param('search') ?: '');
        $page     = max(1, (int)$request->get_param('page'));
        $per_page = min(50, max(1, (int)($request->get_param('per_page') ?: 12)));

        // Strict privacy: Guest/unauthorized only see published
        $status = 'publish';
        if (current_user_can(self::CAP_EDIT_EVENTS) && $request->get_param('status')) {
            $status = sanitize_key($request->get_param('status'));
        }

        $res = self::get_events([
            'period'   => $period,
            'category' => $category,
            'month'    => $month,
            'search'   => $search,
            'page'     => $page,
            'per_page' => $per_page,
            'status'   => $status,
        ]);

        return new WP_REST_Response($res, 200);
    }

    public static function rest_get_single_event(WP_REST_Request $request): WP_REST_Response {
        $slug  = sanitize_title($request->get_param('slug'));
        $event = self::get_event_by_slug($slug);

        if (!$event) {
            return new WP_REST_Response(['error' => 'Event not found or not published.'], 404);
        }

        return new WP_REST_Response($event, 200);
    }
}
