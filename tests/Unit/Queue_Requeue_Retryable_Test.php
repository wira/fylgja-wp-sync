<?php

namespace Fylgja\Tests\Unit {

    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__, 2) . '/includes/class-queue.php';

    /**
     * Failed pushes must self-heal: requeue_retryable() promotes failed rows back to
     * pending, but only those under the attempt cap and past a cool-off window — so a
     * transient slave timeout/500 recovers without hot-looping, and a genuinely broken
     * item dead-letters at the cap instead of retrying forever.
     */
    class Queue_Requeue_Retryable_Test extends TestCase {

        protected function setUp(): void {
            parent::setUp();
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                public string $lastQuery = '';
                /** Substitute %d placeholders so the test can assert the concrete bounds. */
                public function prepare($query, ...$args) {
                    foreach ($args as $a) {
                        $query = preg_replace('/%d/', (string) (int) $a, $query, 1);
                    }
                    return $query;
                }
                public function query($sql) {
                    $this->lastQuery = $sql;
                    return 7;
                }
            };
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            parent::tearDown();
        }

        public function test_requeues_only_capped_and_cooled_off_failures(): void {
            $promoted = (new \Fylgja_Queue())->requeue_retryable(5, 300);

            $this->assertSame(7, $promoted);

            $sql = $GLOBALS['wpdb']->lastQuery;
            $this->assertStringContainsString("SET status = 'pending'", $sql);
            $this->assertStringContainsString("status = 'failed'", $sql);
            $this->assertStringContainsString('attempts < 5', $sql);
            $this->assertStringContainsString('INTERVAL 300 SECOND', $sql);
        }
    }
}
