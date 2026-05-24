<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_Queue {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fylgja_sync_queue';
    }

    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'fylgja_sync_queue';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(20) NOT NULL,
            object_type VARCHAR(20) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            payload LONGTEXT NOT NULL,
            source_kind VARCHAR(20) NOT NULL DEFAULT 'hook',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status_idx (status),
            KEY object_idx (object_type, object_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function drop_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'fylgja_sync_queue';
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    public function enqueue(string $action, string $object_type, int $object_id, array $data, string $source_kind = 'hook'): int {
        global $wpdb;

        $wpdb->insert($this->table, [
            'action'      => $action,
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'payload'     => wp_json_encode($data),
            'source_kind' => $source_kind,
            'status'      => 'pending',
        ]);

        return (int) $wpdb->insert_id;
    }

    public function get_pending(int $limit = 50): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = 'pending' ORDER BY id ASC LIMIT %d",
                $limit
            )
        );
    }

    public function mark_completed(int $id): void {
        global $wpdb;
        $wpdb->update($this->table, ['status' => 'completed'], ['id' => $id]);
    }

    public function mark_failed(int $id, string $error): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET status = 'failed', last_error = %s, attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP WHERE id = %d",
            $error,
            $id
        ));
    }

    public function retry_failed(): int {
        global $wpdb;
        return (int) $wpdb->update(
            $this->table,
            ['status' => 'pending'],
            ['status' => 'failed']
        );
    }

    public function flush_completed(): int {
        global $wpdb;
        return (int) $wpdb->query(
            "DELETE FROM {$this->table} WHERE status = 'completed'"
        );
    }

    public function delete_older_same(string $action, string $object_type, int $object_id, int $current_id): int {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table}
             WHERE status = 'pending'
               AND action = %s
               AND object_type = %s
               AND object_id = %d
               AND id < %d",
            $action, $object_type, $object_id, $current_id
        ));
    }

    public function delete_older_upserts_for_delete(string $object_type, int $object_id, int $current_id): int {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table}
             WHERE status = 'pending'
               AND action = 'upsert'
               AND object_type = %s
               AND object_id = %d
               AND id < %d",
            $object_type, $object_id, $current_id
        ));
    }

    public function count_pending(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status = 'pending'");
    }

    public function get_counts(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status"
        );

        $counts = ['pending' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->count;
        }
        return $counts;
    }
}
