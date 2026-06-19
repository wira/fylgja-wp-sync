<?php

namespace Fylgja\Tests\Unit {

    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__, 2) . '/includes/class-pusher.php';

    /**
     * Resync floods the slave; an item that outruns the interactive 30s timeout gets
     * abandoned mid-flight and snowballs into the timeout/500 cascade. Resync-sourced
     * pushes therefore get a much longer ceiling, while interactive pushes keep 30s.
     */
    class Push_Timeout_Test extends TestCase {

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();
            Functions\when('apply_filters')->alias(fn ($hook, $value = null) => $value);
        }

        protected function tearDown(): void {
            Monkey\tearDown();
            parent::tearDown();
        }

        private function timeout_for(string $source_kind): int {
            $pusher = (new \ReflectionClass(\Fylgja_Pusher::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod(\Fylgja_Pusher::class, 'push_timeout');
            $method->setAccessible(true);
            return $method->invoke($pusher, $source_kind);
        }

        public function test_resync_items_get_a_longer_timeout(): void {
            $this->assertSame(120, $this->timeout_for('resync'));
        }

        public function test_interactive_items_keep_the_default_timeout(): void {
            $this->assertSame(30, $this->timeout_for('hook'));
        }

        public function test_filter_can_override_the_timeout(): void {
            Functions\when('apply_filters')->alias(
                fn ($hook, $value = null, $sk = null) => $hook === 'fylgja_push_timeout' ? 200 : $value
            );
            $this->assertSame(200, $this->timeout_for('resync'));
        }
    }
}
