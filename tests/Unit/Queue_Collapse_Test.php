<?php

namespace Fylgja\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-queue-collapser.php';

class FakeQueueRows {
    public array $rows = [];
    public int $next_id = 1;

    public function add(string $action, string $object_type, int $object_id, string $payload = '{}', string $status = 'pending'): int {
        $row = (object) [
            'id'          => $this->next_id++,
            'action'      => $action,
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'payload'     => $payload,
            'status'      => $status,
        ];
        $this->rows[$row->id] = $row;
        return $row->id;
    }

    public function delete_older_same(string $action, string $object_type, int $object_id, int $current_id): int {
        $deleted = 0;
        foreach ($this->rows as $id => $row) {
            if ($row->status === 'pending'
                && $row->action === $action
                && $row->object_type === $object_type
                && $row->object_id === $object_id
                && $id < $current_id
            ) {
                unset($this->rows[$id]);
                $deleted++;
            }
        }
        return $deleted;
    }

    public function delete_older_upserts_for_delete(string $object_type, int $object_id, int $current_id): int {
        $deleted = 0;
        foreach ($this->rows as $id => $row) {
            if ($row->status === 'pending'
                && $row->action === 'upsert'
                && $row->object_type === $object_type
                && $row->object_id === $object_id
                && $id < $current_id
            ) {
                unset($this->rows[$id]);
                $deleted++;
            }
        }
        return $deleted;
    }
}

class Queue_Collapse_Test extends TestCase {

    public function test_three_upserts_for_same_object_collapse_to_one(): void {
        $queue = new FakeQueueRows();
        $queue->add('upsert', 'post', 42);
        $queue->add('upsert', 'post', 42);
        $newest = $queue->add('upsert', 'post', 42);

        $collapser = new \Fylgja_Queue_Collapser();
        $collapser->collapse_for_row($queue, $queue->rows[$newest]);

        $this->assertCount(1, $queue->rows);
        $this->assertArrayHasKey($newest, $queue->rows);
    }

    public function test_delete_supersedes_prior_upserts(): void {
        $queue = new FakeQueueRows();
        $queue->add('upsert', 'post', 42);
        $queue->add('upsert', 'post', 42);
        $delete_id = $queue->add('delete', 'post', 42);

        $collapser = new \Fylgja_Queue_Collapser();
        $collapser->collapse_for_row($queue, $queue->rows[$delete_id]);

        $this->assertCount(1, $queue->rows);
        $this->assertSame('delete', $queue->rows[$delete_id]->action);
    }

    public function test_unrelated_rows_preserved(): void {
        $queue = new FakeQueueRows();
        $queue->add('upsert', 'post', 1);
        $queue->add('upsert', 'post', 2);
        $current = $queue->add('upsert', 'post', 1);

        $collapser = new \Fylgja_Queue_Collapser();
        $collapser->collapse_for_row($queue, $queue->rows[$current]);

        $this->assertCount(2, $queue->rows);
    }

    public function test_different_object_types_isolated(): void {
        $queue = new FakeQueueRows();
        $queue->add('upsert', 'post', 42);
        $current = $queue->add('upsert', 'term', 42);

        $collapser = new \Fylgja_Queue_Collapser();
        $collapser->collapse_for_row($queue, $queue->rows[$current]);

        $this->assertCount(2, $queue->rows);
    }
}
