<?php

namespace Fylgja\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-flush-guard.php';

class Flush_Guard_Test extends TestCase {

    public function test_runs_body_when_lock_acquired(): void {
        $guard = new \Fylgja_Flush_Guard(
            fn () => true,
            fn () => null
        );

        $ran = false;
        $result = $guard->run(function () use (&$ran) {
            $ran = true;
            return ['pushed' => 3];
        });

        $this->assertTrue($ran);
        $this->assertSame(['pushed' => 3], $result);
    }

    public function test_skips_body_when_lock_unavailable(): void {
        $guard = new \Fylgja_Flush_Guard(
            fn () => false,
            fn () => null
        );

        $ran = false;
        $result = $guard->run(function () use (&$ran) {
            $ran = true;
            return ['pushed' => 99];
        });

        $this->assertFalse($ran);
        $this->assertSame(['skipped' => 'already_running'], $result);
    }

    public function test_release_runs_even_on_exception(): void {
        $released = false;
        $guard = new \Fylgja_Flush_Guard(
            fn () => true,
            function () use (&$released) { $released = true; }
        );

        try {
            $guard->run(function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertTrue($released, 'lock must be released on exception');
    }

    public function test_release_not_called_when_lock_unavailable(): void {
        $released = false;
        $guard = new \Fylgja_Flush_Guard(
            fn () => false,
            function () use (&$released) { $released = true; }
        );

        $guard->run(fn () => null);

        $this->assertFalse($released, 'release must not be called when lock was never acquired');
    }
}
