<?php

namespace Fylgja\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-queue.php';
require_once dirname(__DIR__, 2) . '/includes/class-string-detector.php';

class String_Build_Payload_Test extends TestCase {

    private \Fylgja_String_Detector $detector;

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
        };
        $this->detector = new \Fylgja_String_Detector(new \Fylgja_Queue());
    }

    protected function tearDown(): void {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function string_row(): object {
        return (object) [
            'id'              => 42,
            'context'         => 'theme',
            'name'            => 'Hello',
            'gettext_context' => '',
            'value'           => 'Hello',
            'status'          => 2,
            'string_type'     => 0,
            'wrap_tag'        => '',
        ];
    }

    public function test_returns_null_when_no_translations(): void {
        $this->assertNull($this->detector->build_payload($this->string_row(), []));
    }

    public function test_builds_payload_when_translations_present(): void {
        $translations = ['zh-hans' => ['value' => '你好', 'status' => 10]];

        $payload = $this->detector->build_payload($this->string_row(), $translations);

        $this->assertNotNull($payload);
        $this->assertSame(2, $payload['payload_version']);
        $this->assertSame(42, $payload['source_id']);
        $this->assertSame('theme', $payload['context']);
        $this->assertSame('Hello', $payload['name']);
        $this->assertSame($translations, $payload['translations']);
    }
}
