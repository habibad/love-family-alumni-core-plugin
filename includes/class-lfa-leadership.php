<?php
/**
 * Love Family Alumni — Leadership & Committee Governance Service
 *
 * Implements dynamic management of leadership positions, executive rosters,
 * organizational terms, Global vs North America hierarchy, and verification safeguards.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Leadership {

    public const SCHEMA_VERSION = '1.0.0';

    public const LEVEL_GLOBAL        = 'global';
    public const LEVEL_NORTH_AMERICA = 'north_america';
    public const LEVEL_CHAPTER       = 'chapter';

    public const STATUS_VERIFIED = 'verified';
    public const STATUS_PENDING  = 'pending_ratification';

    public static function init(): void {
        self::install_schema();
        add_action('admin_post_lfa_save_leadership_officer', [__CLASS__, 'handle_save_officer']);
        add_action('admin_post_lfa_delete_leadership_officer', [__CLASS__, 'handle_delete_officer']);
    }

    public static function table_officers(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_leadership_officers';
    }

    public static function install_schema(): void {
        global $wpdb;
        $table = self::table_officers();
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(255) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `biography` TEXT NOT NULL,
            `photo_url` VARCHAR(500) NULL,
            `org_level` VARCHAR(50) NOT NULL DEFAULT 'north_america',
            `chapter_name` VARCHAR(255) NULL,
            `committee` VARCHAR(255) NULL,
            `term_start_year` INT NOT NULL,
            `term_end_year` INT NULL,
            `is_current` TINYINT(1) NOT NULL DEFAULT 0,
            `verification_status` VARCHAR(50) NOT NULL DEFAULT 'verified',
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `level_idx` (`org_level`),
            KEY `status_idx` (`verification_status`),
            KEY `current_idx` (`is_current`)
        ) {$charset_collate};";

        dbDelta($sql);

        self::seed_verified_officers();
    }

    public static function seed_verified_officers(): void {
        global $wpdb;
        $table = self::table_officers();
        $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($count > 0) {
            return;
        }

        $seeds = [
            [
                'title'               => 'Founding President (North America)',
                'name'                => 'Dr. Christian Oje Oarhe',
                'biography'           => 'Founding President of the Love Family Alumni — North America Chapter, serving from inauguration in 2018 until the 2021 executive leadership transition. Established formal alumni fellowship charters across the United States and Canada.',
                'photo_url'           => '',
                'org_level'           => self::LEVEL_NORTH_AMERICA,
                'chapter_name'        => 'North America Chapter',
                'committee'           => 'Executive Council',
                'term_start_year'     => 2018,
                'term_end_year'       => 2021,
                'is_current'          => 0,
                'verification_status' => self::STATUS_VERIFIED,
                'sort_order'          => 1,
            ],
            [
                'title'               => 'North America Zonal President',
                'name'                => 'Dr. Evans Igho Akpo',
                'biography'           => 'North America Zonal President serving from 2021 to the present. Assumed office following the foundational presidency of Dr. Christian Oje Oarhe to spearhead expanded digital infrastructure, professional networking directories, and institutional endowment funds.',
                'photo_url'           => '',
                'org_level'           => self::LEVEL_NORTH_AMERICA,
                'chapter_name'        => 'North America Zone',
                'committee'           => 'Executive Council',
                'term_start_year'     => 2021,
                'term_end_year'       => null,
                'is_current'          => 1,
                'verification_status' => self::STATUS_VERIFIED,
                'sort_order'          => 2,
            ],
        ];

        foreach ($seeds as $s) {
            $wpdb->insert($table, array_merge($s, ['created_at' => current_time('mysql')]));
        }
    }

    public static function get_officers($org_level = null, bool $only_verified = true): array {
        global $wpdb;
        $table = self::table_officers();
        $where = [];
        $params = [];
        $committee = null;

        if (is_array($org_level)) {
            $args = $org_level;
            $org_level = $args['org_level'] ?? null;
            if (isset($args['only_verified'])) {
                $only_verified = (bool)$args['only_verified'];
            }
            if (!empty($args['committee'])) {
                $committee = sanitize_text_field($args['committee']);
            }
        }

        if ($only_verified) {
            $where[] = "`verification_status` = %s";
            $params[] = self::STATUS_VERIFIED;
        }

        if ($org_level !== null) {
            $where[] = "`org_level` = %s";
            $params[] = $org_level;
        }

        if ($committee !== null) {
            $where[] = "`committee` = %s";
            $params[] = $committee;
        }

        $where_sql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
        $sql = "SELECT * FROM `{$table}` {$where_sql} ORDER BY `is_current` DESC, `sort_order` ASC, `term_start_year` DESC";

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function save_officer(array $data, int $actor_id): array {
        if (!user_can($actor_id, LFA_Roles::CAP_MANAGE_LEADERSHIP) && !user_can($actor_id, 'administrator')) {
            return ['success' => false, 'error' => 'Unauthorized'];
        }

        global $wpdb;
        $table = self::table_officers();

        $title = sanitize_text_field($data['title'] ?? '');
        $name = sanitize_text_field($data['name'] ?? '');
        $biography = sanitize_textarea_field($data['biography'] ?? '');
        $photo_url = esc_url_raw($data['photo_url'] ?? '');
        $org_level = sanitize_key($data['org_level'] ?? self::LEVEL_NORTH_AMERICA);
        $chapter_name = sanitize_text_field($data['chapter_name'] ?? '');
        $committee = sanitize_text_field($data['committee'] ?? '');
        $term_start = (int)($data['term_start_year'] ?? date('Y'));
        $term_end = !empty($data['term_end_year']) ? (int)$data['term_end_year'] : null;
        $is_current = !empty($data['is_current']) ? 1 : 0;
        $verification = sanitize_key($data['verification_status'] ?? self::STATUS_VERIFIED);
        $sort_order = (int)($data['sort_order'] ?? 0);

        if (empty($title) || empty($name)) {
            return ['success' => false, 'error' => 'Title and Name are required.'];
        }

        $id = !empty($data['id']) ? (int)$data['id'] : 0;
        if ($id > 0) {
            $wpdb->update($table, [
                'title'               => $title,
                'name'                => $name,
                'biography'           => $biography,
                'photo_url'           => $photo_url,
                'org_level'           => $org_level,
                'chapter_name'        => $chapter_name,
                'committee'           => $committee,
                'term_start_year'     => $term_start,
                'term_end_year'       => $term_end,
                'is_current'          => $is_current,
                'verification_status' => $verification,
                'sort_order'          => $sort_order,
                'updated_at'          => current_time('mysql'),
            ], ['id' => $id]);
            return ['success' => true, 'id' => $id, 'action' => 'updated'];
        } else {
            $wpdb->insert($table, [
                'title'               => $title,
                'name'                => $name,
                'biography'           => $biography,
                'photo_url'           => $photo_url,
                'org_level'           => $org_level,
                'chapter_name'        => $chapter_name,
                'committee'           => $committee,
                'term_start_year'     => $term_start,
                'term_end_year'       => $term_end,
                'is_current'          => $is_current,
                'verification_status' => $verification,
                'sort_order'          => $sort_order,
                'created_at'          => current_time('mysql'),
                'updated_at'          => current_time('mysql'),
            ]);
            return ['success' => true, 'id' => $wpdb->insert_id, 'action' => 'created'];
        }
    }

    public static function delete_officer(int $id, int $actor_id): array {
        if (!user_can($actor_id, LFA_Roles::CAP_MANAGE_LEADERSHIP) && !user_can($actor_id, 'administrator')) {
            return ['success' => false, 'error' => 'Unauthorized'];
        }

        global $wpdb;
        $table = self::table_officers();
        $wpdb->delete($table, ['id' => $id]);
        return ['success' => true];
    }

    public static function handle_save_officer(): void {
        check_admin_referer('lfa_save_leadership_action', 'lfa_nonce');
        $actor_id = get_current_user_id();
        $res = self::save_officer($_POST, $actor_id);
        $redirect = add_query_arg([
            'lfa_notice' => $res['success'] ? 'officer_saved' : 'officer_error',
            'msg' => $res['success'] ? urlencode('Leadership officer record saved successfully') : urlencode($res['error']),
        ], wp_get_referer() ?: home_url('/admin-portal/#leadership'));
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_delete_officer(): void {
        check_admin_referer('lfa_delete_leadership_action', 'lfa_nonce');
        $actor_id = get_current_user_id();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $res = self::delete_officer($id, $actor_id);
        $redirect = add_query_arg([
            'lfa_notice' => $res['success'] ? 'officer_deleted' : 'officer_error',
            'msg' => $res['success'] ? urlencode('Officer record deleted.') : urlencode($res['error']),
        ], wp_get_referer() ?: home_url('/admin-portal/#leadership'));
        wp_safe_redirect($redirect);
        exit;
    }
}
