<?php

if (!defined('ABSPATH')) {
    exit;
}

interface Deferred_Refs_Storage_Interface {
    public function pending_for_types(array $ref_object_types, int $limit): array;
    public function delete(int $id): void;
}

class Fylgja_Deferred_Refs implements Deferred_Refs_Storage_Interface {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fylgja_deferred_refs';
    }

    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'fylgja_deferred_refs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ref_type VARCHAR(40) NOT NULL,
            dependent_local_id BIGINT NOT NULL,
            ref_object_type VARCHAR(40) NOT NULL,
            ref_source_id BIGINT NOT NULL,
            payload_hint LONGTEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ref_lookup_idx (ref_object_type, ref_source_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Existing installs may hold duplicate rows from before insert() became
        // idempotent; collapse them keeping the lowest id per unique tuple.
        $wpdb->query(
            "DELETE d1 FROM {$table} d1
             JOIN {$table} d2
               ON d1.ref_type = d2.ref_type
              AND d1.dependent_local_id = d2.dependent_local_id
              AND d1.ref_object_type = d2.ref_object_type
              AND d1.ref_source_id = d2.ref_source_id
              AND d1.id > d2.id"
        );
    }

    public function insert(string $ref_type, int $dependent_local_id, string $ref_object_type, int $ref_source_id, ?array $payload_hint = null): int {
        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE ref_type = %s AND dependent_local_id = %d
               AND ref_object_type = %s AND ref_source_id = %d
             LIMIT 1",
            $ref_type, $dependent_local_id, $ref_object_type, $ref_source_id
        ));
        if ($existing !== null) {
            return (int) $existing;
        }

        $wpdb->insert($this->table, [
            'ref_type'           => $ref_type,
            'dependent_local_id' => $dependent_local_id,
            'ref_object_type'    => $ref_object_type,
            'ref_source_id'      => $ref_source_id,
            'payload_hint'       => $payload_hint ? wp_json_encode($payload_hint) : null,
        ]);
        return (int) $wpdb->insert_id;
    }

    public function pending_for_types(array $ref_object_types, int $limit): array {
        global $wpdb;
        if (empty($ref_object_types)) return [];
        $placeholders = implode(',', array_fill(0, count($ref_object_types), '%s'));
        $args = array_merge($ref_object_types, [$limit]);
        $sql = "SELECT * FROM {$this->table} WHERE ref_object_type IN ({$placeholders}) ORDER BY id ASC LIMIT %d";
        return $wpdb->get_results($wpdb->prepare($sql, ...$args));
    }

    public function delete(int $id): void {
        global $wpdb;
        $wpdb->delete($this->table, ['id' => $id]);
    }

    public function pending_for_types_like(array $patterns, int $limit): array {
        global $wpdb;
        if (empty($patterns)) return [];
        $where_parts = [];
        $args        = [];
        foreach ($patterns as $pat) {
            $where_parts[] = 'ref_object_type LIKE %s';
            $args[]        = $pat;
        }
        $args[] = $limit;
        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' OR ', $where_parts) . " ORDER BY id ASC LIMIT %d";
        return $wpdb->get_results($wpdb->prepare($sql, ...$args));
    }
}

class Fylgja_Deferred_Refs_Sweeper {

    private Deferred_Refs_Storage_Interface $storage;
    /** @var callable */
    private $resolver;
    /** @var callable */
    private $applier;
    private int $stale_seconds;

    /**
     * @param Deferred_Refs_Storage_Interface $storage
     * @param callable                        $resolver fn(string $ref_object_type, int $ref_source_id): ?int
     * @param callable|null                   $applier  fn(object $row, int $resolved_local_id): bool — defaults to no-op
     */
    public function __construct(Deferred_Refs_Storage_Interface $storage, callable $resolver, ?callable $applier = null, int $stale_seconds = 7 * 86400) {
        $this->storage  = $storage;
        $this->resolver = $resolver;
        $this->applier  = $applier ?? fn ($row, $id) => true;
        $this->stale_seconds = $stale_seconds;
    }

    public function sweep(array $ref_object_types, int $limit = 500): array {
        $rows = $this->storage->pending_for_types($ref_object_types, $limit);
        $resolved_count = 0;
        $stale_warnings = [];
        $now = time();

        foreach ($rows as $row) {
            $local_id = ($this->resolver)($row->ref_object_type, (int) $row->ref_source_id);
            if ($local_id !== null) {
                $applied = ($this->applier)($row, $local_id);
                if ($applied) {
                    $this->storage->delete((int) $row->id);
                    $resolved_count++;
                }
                continue;
            }
            $created_ts = strtotime($row->created_at . ' UTC');
            if ($created_ts && ($now - $created_ts) > $this->stale_seconds) {
                $stale_warnings[] = [
                    'id'              => (int) $row->id,
                    'ref_type'        => $row->ref_type,
                    'ref_object_type' => $row->ref_object_type,
                    'ref_source_id'   => (int) $row->ref_source_id,
                    'age_seconds'     => $now - $created_ts,
                ];
            }
        }

        return [
            'resolved'        => $resolved_count,
            'remaining'       => count($rows) - $resolved_count,
            'stale_warnings'  => $stale_warnings,
        ];
    }
}
