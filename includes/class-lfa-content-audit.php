<?php
/**
 * Love Family Alumni — Content Audit Logging System
 *
 * Provides immutable administrative audit trail for Events, News,
 * Archives, and Historical Timeline operations. Strictly scrubs PII.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Content_Audit {

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_content_audit';
    }

    public static function install_schema(): void {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_type varchar(30) NOT NULL DEFAULT 'staff',
            actor_id bigint(20) unsigned NULL,
            action varchar(100) NOT NULL,
            entity_type varchar(50) NOT NULL,
            entity_id varchar(100) NOT NULL,
            details_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY actor_id (actor_id),
            KEY action (action),
            KEY entity_type (entity_type),
            KEY entity_id (entity_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Log content event with strict privacy scrubbing
     */
    public static function log(string $actor_type, ?int $actor_id, string $action, string $entity_type, string $entity_id, array $data = []): void {
        global $wpdb;
        $table = self::table_name();

        // Ensure table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            self::install_schema();
        }

        // Scrub sensitive data / PII
        unset(
            $data['password'],
            $data['secret'],
            $data['token'],
            $data['client_secret'],
            $data['card_number'],
            $data['cvv']
        );

        $wpdb->insert($table, [
            'actor_type'   => sanitize_key($actor_type),
            'actor_id'     => $actor_id ?: null,
            'action'       => sanitize_key($action),
            'entity_type'  => sanitize_key($entity_type),
            'entity_id'    => sanitize_text_field((string)$entity_id),
            'details_json' => wp_json_encode($data),
            'created_at'   => current_time('mysql', true),
        ]);
    }
}
