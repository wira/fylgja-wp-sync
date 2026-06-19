<?php

namespace Fylgja\Tests\Unit {

    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__, 2) . '/includes/class-sync-log.php';

    /**
     * The sync-log viewer treats received_at as UTC (get_date_from_gmt). The column's
     * MySQL CURRENT_TIMESTAMP default writes the DB server's *local* time, so on a non-UTC
     * server the viewer double-applies the offset and shows wrong times. insert() must
     * therefore stamp received_at in UTC itself, regardless of the PHP/MySQL timezone.
     */
    class Sync_Log_Received_At_Test extends TestCase {

        /** @var array<string, mixed> */
        private array $inserted = [];

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();
            $this->inserted = [];

            $test = $this;
            $GLOBALS['wpdb'] = new class($test) {
                public string $prefix = 'wp_';
                public int $insert_id = 123;
                private $test;
                public function __construct($test) { $this->test = $test; }
                public function insert($table, $data) { $this->test->recordInsert($data); return 1; }
                public function get_var($q) { return 0; }   // trim_to() count → nothing to trim
                public function prepare($q, ...$a) { return $q; }
                public function query($q) { return 0; }
            };

            Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            Monkey\tearDown();
            parent::tearDown();
        }

        public function recordInsert(array $data): void { $this->inserted = $data; }

        public function test_received_at_is_stamped_in_utc_not_local(): void {
            // Force a non-UTC PHP timezone: if insert() used local date() the stored value
            // would be +8 and fall outside the UTC window below.
            $orig_tz = date_default_timezone_get();
            date_default_timezone_set('Asia/Shanghai');
            try {
                $utc_before = gmdate('Y-m-d H:i:s');
                (new \Fylgja_Sync_Log())->insert('upsert', 'post', ['source_id' => 5], ['ok' => 1]);
                $utc_after = gmdate('Y-m-d H:i:s');
            } finally {
                date_default_timezone_set($orig_tz);
            }

            $this->assertArrayHasKey('received_at', $this->inserted, 'insert() must stamp received_at');
            $ts = $this->inserted['received_at'];
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts);
            // Must be the UTC clock, not the +8 local clock.
            $this->assertGreaterThanOrEqual($utc_before, $ts);
            $this->assertLessThanOrEqual($utc_after, $ts);
        }
    }
}
