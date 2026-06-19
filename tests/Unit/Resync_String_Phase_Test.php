<?php

namespace Fylgja\Tests\Unit {

    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__, 2) . '/includes/class-queue.php';
    require_once dirname(__DIR__, 2) . '/includes/class-string-detector.php';
    require_once dirname(__DIR__, 2) . '/includes/class-pusher.php';
    require_once dirname(__DIR__, 2) . '/includes/class-resync.php';

    /** Records enqueue() calls instead of writing to the queue table. */
    class RecordingQueue extends \Fylgja_Queue {
        /** @var array<int, array> */
        public array $enqueued = [];
        public function __construct() {}
        public function enqueue(string $action, string $object_type, int $object_id, array $data, string $source_kind = 'hook'): int {
            $this->enqueued[] = compact('action', 'object_type', 'object_id', 'source_kind') + ['data' => $data];
            return count($this->enqueued);
        }
    }

    /** No-op pusher so the resync tick needs no real flush wiring. */
    class NoopPusher extends \Fylgja_Pusher {
        public function __construct() {}
        public function ensure_flush_scheduled(): void {}
    }

    /**
     * The string resync phase must be scoped to *translatable* strings only — the ones
     * push_string_batch actually pushes. A regression that scoped it to all icl_strings
     * rows made the progress read e.g. 24/1965 (looking stuck at 24, the true count of
     * pushable strings) and made the phase grind the whole table over ~78 cron ticks.
     */
    class Resync_String_Phase_Test extends TestCase {

        /** @var array<int, string> SQL passed to $wpdb->get_results(). */
        private array $resultsSql = [];
        /** @var array<int, string> SQL passed to $wpdb->get_var(). */
        private array $varSql = [];
        /** Captured fylgja_resync_state from the last update_option() call. */
        private ?array $savedState = null;

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            Functions\when('apply_filters')->alias(fn ($hook, $value = null) => $value);
            Functions\when('wp_next_scheduled')->justReturn(false);
            Functions\when('wp_schedule_single_event')->justReturn(true);
            Functions\when('update_option')->alias(function ($name, $value) {
                if ($name === 'fylgja_resync_state') {
                    $this->savedState = $value;
                }
                return true;
            });
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            Monkey\tearDown();
            parent::tearDown();
        }

        private function string_row(int $id): object {
            return (object) [
                'id' => $id, 'context' => 'theme', 'name' => "s{$id}",
                'gettext_context' => '', 'value' => "v{$id}", 'status' => 2,
                'string_type' => 0, 'wrap_tag' => '',
            ];
        }

        public function test_string_phase_scopes_to_translatable_and_reaches_done(): void {
            $test = $this;
            $GLOBALS['wpdb'] = new class($test) {
                public string $prefix = 'wp_';
                private $test;
                public function __construct($test) { $this->test = $test; }
                public function prepare($query, ...$args) { return $query; }
                public function get_var($query) { return 0; }
                public function get_results($query) {
                    $this->test->recordResults($query);
                    // The batch query selects FROM wp_icl_strings; the per-row query
                    // selects FROM wp_icl_string_translations (no bare "icl_strings").
                    if (strpos($query, 'icl_strings ') !== false) {
                        return [$this->test->row(101), $this->test->row(102)];
                    }
                    return [(object) ['language' => 'de', 'value' => 'x', 'status' => 10]];
                }
            };

            $state = [
                'in_progress' => true,
                'phase'       => 'strings',
                'cursor'      => 0,
                'totals'      => ['terms' => 0, 'posts' => 0, 'strings' => 2],
                'pushed'      => ['terms' => 0, 'posts' => 0, 'strings' => 0],
                'started_at'  => '2026-01-01T00:00:00+00:00',
            ];
            Functions\when('get_option')->alias(fn ($name, $default = false) =>
                $name === 'fylgja_resync_state' ? $state : 25
            );

            $queue = new RecordingQueue();
            (new \Fylgja_Resync($queue, new NoopPusher()))->tick();

            // The fix: the batch query is scoped to strings that have a translation.
            $batchSql = $this->firstMatching($this->resultsSql, 'icl_strings ');
            $this->assertNotNull($batchSql, 'a batch query against icl_strings must run');
            $this->assertStringContainsStringIgnoringCase('EXISTS', $batchSql);
            $this->assertStringContainsString('icl_string_translations', $batchSql);

            // Behaviour: both translatable strings were pushed...
            $this->assertCount(2, $queue->enqueued);
            $this->assertSame('string', $queue->enqueued[0]['object_type']);
            // ...and the phase exhausted (2 examined < batch 25) → done.
            $this->assertNotNull($this->savedState);
            $this->assertSame('done', $this->savedState['phase']);
            $this->assertFalse($this->savedState['in_progress']);
            $this->assertSame(2, $this->savedState['pushed']['strings']);
        }

        public function test_count_strings_is_scoped_to_translatable(): void {
            $test = $this;
            $GLOBALS['wpdb'] = new class($test) {
                public string $prefix = 'wp_';
                public string $posts = 'wp_posts';
                public string $terms = 'wp_terms';
                private $test;
                public function __construct($test) { $this->test = $test; }
                public function prepare($query, ...$args) { return $query; }
                public function get_col($query) { return []; }
                public function get_results($query) { return []; }
                public function get_var($query) {
                    $this->test->recordVar($query);
                    if (strpos($query, 'icl_strings') !== false) { return 3; }
                    if (strpos($query, 'wp_posts') !== false)    { return 10; }
                    return 5; // terms
                }
            };

            Functions\when('get_option')->alias(fn ($name, $default = false) =>
                $name === 'fylgja_resync_state' ? [] : 25
            );

            (new \Fylgja_Resync(new RecordingQueue(), new NoopPusher()))->start();

            $countSql = $this->firstMatching($this->varSql, 'icl_strings');
            $this->assertNotNull($countSql, 'a count over icl_strings must run');
            $this->assertStringContainsStringIgnoringCase('EXISTS', $countSql);
            $this->assertStringContainsString('icl_string_translations', $countSql);

            $this->assertNotNull($this->savedState);
            $this->assertSame(3, $this->savedState['totals']['strings']);
        }

        // --- hooks the anonymous $wpdb classes call back into ---
        public function recordResults(string $sql): void { $this->resultsSql[] = $sql; }
        public function recordVar(string $sql): void { $this->varSql[] = $sql; }
        public function row(int $id): object { return $this->string_row($id); }

        private function firstMatching(array $haystacks, string $needle): ?string {
            foreach ($haystacks as $sql) {
                if (strpos($sql, $needle) !== false) { return $sql; }
            }
            return null;
        }
    }
}
