<?php

namespace Fylgja\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-lookup.php';
require_once dirname(__DIR__, 2) . '/includes/class-deferred-refs.php';

// In-memory test double; implements the global interface from class-deferred-refs.php.
class FakeDeferredRefsStorage implements \Deferred_Refs_Storage_Interface {
    public array $rows = [];
    public int $next_id = 1;

    public function add(string $ref_type, int $dependent_local_id, string $ref_object_type, int $ref_source_id, ?array $payload_hint = null, ?string $created_at = null): int {
        $row = (object) [
            'id'                 => $this->next_id++,
            'ref_type'           => $ref_type,
            'dependent_local_id' => $dependent_local_id,
            'ref_object_type'    => $ref_object_type,
            'ref_source_id'      => $ref_source_id,
            'payload_hint'       => $payload_hint ? json_encode($payload_hint) : null,
            'created_at'         => $created_at ?? gmdate('Y-m-d H:i:s'),
        ];
        $this->rows[$row->id] = $row;
        return $row->id;
    }

    public function pending_for_types(array $ref_object_types, int $limit): array {
        $out = [];
        foreach ($this->rows as $row) {
            if (in_array($row->ref_object_type, $ref_object_types, true)) {
                $out[] = $row;
                if (count($out) >= $limit) break;
            }
        }
        return $out;
    }

    public function delete(int $id): void {
        unset($this->rows[$id]);
    }
}

class Deferred_Refs_Sweep_Test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_no_rows_no_work(): void {
        $storage = new FakeDeferredRefsStorage();
        $sweeper = new \Fylgja_Deferred_Refs_Sweeper($storage, fn ($type, $id) => null);

        $result = $sweeper->sweep(['term:category']);

        $this->assertSame(0, $result['resolved']);
        $this->assertSame(0, $result['remaining']);
    }

    public function test_row_resolves_when_source_now_available(): void {
        $storage = new FakeDeferredRefsStorage();
        $storage->add('post_term_assignment', 100, 'term:category', 42);

        $resolver = function (string $type, int $source_id): ?int {
            if ($type === 'term:category' && $source_id === 42) return 999;
            return null;
        };

        $applied = [];
        $applier = function ($row, int $resolved_local_id) use (&$applied): bool {
            $applied[] = ['row_id' => $row->id, 'resolved' => $resolved_local_id];
            return true;
        };

        $sweeper = new \Fylgja_Deferred_Refs_Sweeper($storage, $resolver, $applier);
        $result = $sweeper->sweep(['term:category']);

        $this->assertSame(1, $result['resolved']);
        $this->assertEmpty($storage->rows);
        $this->assertCount(1, $applied);
        $this->assertSame(999, $applied[0]['resolved']);
    }

    public function test_unresolved_row_stays(): void {
        $storage = new FakeDeferredRefsStorage();
        $storage->add('post_term_assignment', 100, 'term:category', 42);

        $sweeper = new \Fylgja_Deferred_Refs_Sweeper(
            $storage,
            fn ($type, $id) => null,
            fn ($row, $resolved) => true
        );

        $result = $sweeper->sweep(['term:category']);

        $this->assertSame(0, $result['resolved']);
        $this->assertCount(1, $storage->rows);
    }

    public function test_stale_rows_are_flagged_as_warnings(): void {
        $storage = new FakeDeferredRefsStorage();
        $eight_days_ago = gmdate('Y-m-d H:i:s', time() - 8 * 86400);
        $storage->add('post_term_assignment', 100, 'term:category', 42, null, $eight_days_ago);

        $sweeper = new \Fylgja_Deferred_Refs_Sweeper(
            $storage,
            fn ($type, $id) => null,
            fn ($row, $resolved) => true
        );

        $result = $sweeper->sweep(['term:category']);

        $this->assertSame(0, $result['resolved']);
        $this->assertCount(1, $result['stale_warnings']);
    }
}
