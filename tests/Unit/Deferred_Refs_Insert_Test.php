<?php

namespace Fylgja\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-deferred-refs.php';

class Deferred_Refs_Insert_Test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_json_encode')->alias(fn ($v) => json_encode($v));
    }

    protected function tearDown(): void {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * wpdb double that stores inserted rows in memory and answers the guard
     * SELECT by matching the unique tuple baked into the prepared query args.
     */
    private function make_wpdb(): object {
        return new class {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            public int $insert_calls = 0;
            /** @var array<int, array> */
            public array $rows = [];
            private int $next_id = 1;
            /** @var array Captured prepare() args for the pending get_var lookup. */
            private array $pending = [];

            public function prepare($query, ...$args) {
                // Guard query is the only prepared statement here; capture its
                // tuple (ref_type, dependent_local_id, ref_object_type, ref_source_id).
                $this->pending = $args;
                return $query;
            }

            public function get_var($query) {
                [$ref_type, $dep, $ref_obj, $src] = $this->pending;
                foreach ($this->rows as $id => $row) {
                    if ($row['ref_type'] === $ref_type
                        && (int) $row['dependent_local_id'] === (int) $dep
                        && $row['ref_object_type'] === $ref_obj
                        && (int) $row['ref_source_id'] === (int) $src) {
                        return (string) $id;
                    }
                }
                return null;
            }

            public function insert($table, $data) {
                $this->insert_calls++;
                $id = $this->next_id++;
                $this->rows[$id] = $data;
                $this->insert_id = $id;
                return 1;
            }
        };
    }

    public function test_first_insert_writes_a_row(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb();
        $refs = new \Fylgja_Deferred_Refs();

        $id = $refs->insert('post_term_assignment', 100, 'term:category', 7);

        $this->assertSame(1, $id);
        $this->assertSame(1, $GLOBALS['wpdb']->insert_calls);
    }

    public function test_duplicate_insert_returns_existing_id_without_writing(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb();
        $refs = new \Fylgja_Deferred_Refs();

        $first  = $refs->insert('post_term_assignment', 100, 'term:category', 7);
        $second = $refs->insert('post_term_assignment', 100, 'term:category', 7);

        $this->assertSame($first, $second);
        $this->assertSame(1, $GLOBALS['wpdb']->insert_calls, 'duplicate tuple must not re-insert');
    }

    public function test_distinct_tuple_inserts_again(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb();
        $refs = new \Fylgja_Deferred_Refs();

        $refs->insert('post_term_assignment', 100, 'term:category', 7);
        $other = $refs->insert('post_term_assignment', 100, 'term:category', 8);

        $this->assertSame(2, $other);
        $this->assertSame(2, $GLOBALS['wpdb']->insert_calls);
    }
}
