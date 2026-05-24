<?php

namespace Fylgja\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase {
    public function test_bootstrap_loads(): void {
        $this->assertTrue(defined('ABSPATH'));
        $this->assertTrue(defined('FYLGJA_VERSION'));
    }
}
