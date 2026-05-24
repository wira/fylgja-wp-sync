<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_Trid_Map {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fylgja_trid_map';
    }

    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'fylgja_trid_map';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            element_type VARCHAR(40) NOT NULL,
            source_trid BIGINT NOT NULL,
            local_trid BIGINT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY type_source_idx (element_type, source_trid),
            KEY local_trid_idx (element_type, local_trid)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function lookup(string $element_type, int $source_trid): ?int {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT local_trid FROM {$this->table} WHERE element_type = %s AND source_trid = %d",
            $element_type,
            $source_trid
        ));
        return $result === null ? null : (int) $result;
    }

    public function record(string $element_type, int $source_trid, int $local_trid): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table} (element_type, source_trid, local_trid) VALUES (%s, %d, %d)",
            $element_type,
            $source_trid,
            $local_trid
        ));
    }
}
