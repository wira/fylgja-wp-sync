<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_Sync_Log {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fylgja_sync_log';
    }

    public function insert(string $action, string $object_type, array $payload, array $preview): int {
        global $wpdb;

        $wpdb->insert($this->table, [
            // Stamp UTC explicitly. The column DEFAULTs to MySQL CURRENT_TIMESTAMP, which
            // uses the DB server timezone (not UTC) — so on a non-UTC server it stores
            // local time, and the admin viewer (get_date_from_gmt) then double-applies the
            // offset, showing wrong times. gmdate() is UTC regardless of the MySQL/WP tz.
            'received_at' => gmdate('Y-m-d H:i:s'),
            'action'      => $action,
            'object_type' => $object_type,
            'source_id'   => (int) ($payload['source_id'] ?? 0),
            'payload'     => wp_json_encode($payload),
            'preview'     => wp_json_encode($preview),
            'applied'     => 0,
        ]);

        $id = (int) $wpdb->insert_id;
        $this->trim_to(1000);
        return $id;
    }

    public function mark_applied(int $id, array $result): void {
        global $wpdb;
        $wpdb->update(
            $this->table,
            [
                'applied' => $result['success'] ? 1 : 0,
                'error'   => $result['success'] ? null : ($result['error'] ?? 'unknown error'),
            ],
            ['id' => $id]
        );
    }

    public function query(array $filters = [], int $page = 1, int $per_page = 50): array {
        global $wpdb;

        $where = ['1=1'];
        $args  = [];

        if (!empty($filters['object_type'])) {
            $where[] = 'object_type = %s';
            $args[]  = $filters['object_type'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'action = %s';
            $args[]  = $filters['action'];
        }
        if (isset($filters['applied'])) {
            $where[] = 'applied = %d';
            $args[]  = (int) (bool) $filters['applied'];
        }

        $offset = max(0, ($page - 1) * $per_page);
        $args[] = $per_page;
        $args[] = $offset;

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT %d OFFSET %d";
        return $wpdb->get_results($wpdb->prepare($sql, ...$args));
    }

    public function clear(): void {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table}");
    }

    public function trim_to(int $max_rows): void {
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        if ($count <= $max_rows) {
            return;
        }
        $cutoff_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} ORDER BY id DESC LIMIT 1 OFFSET %d",
            $max_rows - 1
        ));
        if ($cutoff_id > 0) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->table} WHERE id < %d",
                $cutoff_id
            ));
        }
    }

    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'fylgja_sync_log';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            action VARCHAR(20) NOT NULL,
            object_type VARCHAR(20) NOT NULL,
            source_id BIGINT NOT NULL,
            payload LONGTEXT NOT NULL,
            preview LONGTEXT NOT NULL,
            applied TINYINT(1) NOT NULL DEFAULT 0,
            error TEXT NULL,
            PRIMARY KEY (id),
            KEY received_idx (received_at),
            KEY object_idx (object_type, source_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
