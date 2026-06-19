<?php

namespace Fylgja\Tests\Unit {

    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__, 2) . '/includes/class-lookup.php';
    require_once dirname(__DIR__, 2) . '/includes/class-trid-map.php';
    require_once dirname(__DIR__, 2) . '/includes/class-wpml-mapper.php';
    require_once dirname(__DIR__, 2) . '/includes/class-deferred-refs.php';
    require_once dirname(__DIR__, 2) . '/includes/class-sync-log.php';
    require_once dirname(__DIR__, 2) . '/includes/class-auth.php';
    require_once dirname(__DIR__, 2) . '/includes/class-receiver.php';

    /**
     * The inline post-apply deferred sweep is throttled so a resync burst doesn't run the
     * full sweep on every one of ~1500 requests (a slave-saturating cost). When the
     * throttle window is active the sweep is skipped entirely; when clear it runs and arms
     * the window. The fylgja_sweep_deferred cron remains the unthrottled safety net.
     */
    class Inline_Sweep_Throttle_Test extends TestCase {

        private bool $swept = false;
        /** @var array<int, array> */
        private array $setTransients = [];

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();
            $this->swept = false;
            $this->setTransients = [];

            $test = $this;
            $GLOBALS['wpdb'] = new class($test) {
                public string $prefix = 'wp_';
                private $test;
                public function __construct($test) { $this->test = $test; }
                public function prepare($q, ...$a) { return $q; }
                public function get_var($q) { return null; }
                public function get_results($q) {
                    $this->test->markSwept(); // only the deferred-refs query reaches here
                    return [];
                }
            };

            Functions\when('set_transient')->alias(function ($key, $value, $exp) {
                $this->setTransients[] = [$key, $value, $exp];
                return true;
            });
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            Monkey\tearDown();
            parent::tearDown();
        }

        public function markSwept(): void { $this->swept = true; }

        private function invoke_maybe_sweep(): void {
            $receiver = (new \ReflectionClass(\Fylgja_Receiver::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod(\Fylgja_Receiver::class, 'maybe_sweep_deferred_refs');
            $method->setAccessible(true);
            $method->invoke($receiver);
        }

        public function test_skips_sweep_while_throttled(): void {
            Functions\when('get_transient')->justReturn(1);

            $this->invoke_maybe_sweep();

            $this->assertFalse($this->swept, 'sweep must not run while throttled');
            $this->assertSame([], $this->setTransients, 'throttle must not be re-armed while active');
        }

        public function test_sweeps_and_arms_throttle_when_clear(): void {
            Functions\when('get_transient')->justReturn(false);

            $this->invoke_maybe_sweep();

            $this->assertTrue($this->swept, 'sweep must run when the throttle window is clear');
            $this->assertCount(1, $this->setTransients);
            $this->assertSame('fylgja_inline_sweep_throttle', $this->setTransients[0][0]);
        }
    }
}
