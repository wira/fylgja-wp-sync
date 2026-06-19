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

    /** Mapper stub returning a benign no-op plan so compute_preview needs no WPML wiring. */
    class PlanStubMapper extends \Fylgja_Wpml_Mapper {
        public function __construct() {}
        public function plan(string $element_type, array $wpml_block, ?int $local_id): array {
            return ['trid_action' => 'none', 'language_code' => null, 'warnings' => []];
        }
    }

    /**
     * Non-destructive tripwire: compute_preview() flags any incoming meta value that arrives
     * as a still-serialized STRING. Post source fix, properly-synced meta arrives as real
     * data (arrays/scalars), so a serialized string signals a sender that didn't unserialize
     * (e.g. an unpatched master) — applying it as-is would make update_*_meta double-serialize
     * it. The value is never rewritten (a legitimately serialized-looking string is
     * indistinguishable); it's only surfaced in the log's Warnings column.
     */
    class Detect_Serialized_Meta_Test extends TestCase {

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                public string $posts = 'wp_posts';
                public string $postmeta = 'wp_postmeta';
                public function prepare($query, ...$args) { return $query; }
                public function get_var($query) { return null; } // no local post → would_create
            };

            // Faithful enough reproduction of WP core is_serialized() for the test inputs.
            Functions\when('is_serialized')->alias(function ($data, $strict = true) {
                if (!is_string($data)) {
                    return false;
                }
                $trimmed = trim($data);
                if ($trimmed === 'b:0;') {
                    return true;
                }
                return @unserialize($trimmed) !== false;
            });
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            Monkey\tearDown();
            parent::tearDown();
        }

        private function preview_with_meta(array $meta): array {
            $receiver = new \Fylgja_Receiver(new \Fylgja_Auth(), null, new PlanStubMapper());
            return $receiver->compute_preview('upsert', 'post', [
                'source_id' => 70,
                'post_type' => 'nav_menu_item',
                'meta'      => $meta,
            ]);
        }

        public function test_serialized_string_meta_is_flagged(): void {
            $preview = $this->preview_with_meta([
                '_menu_item_classes' => 'a:2:{i:0;s:18:"projects-menu-item";i:1;s:13:"with-sub-menu";}',
            ]);

            $hit = array_filter(
                $preview['warnings'],
                fn ($w) => strpos($w, '_menu_item_classes') !== false
            );
            $this->assertNotEmpty($hit, 'a serialized-string meta value must be flagged in warnings');
        }

        public function test_real_array_and_plain_string_meta_are_not_flagged(): void {
            $preview = $this->preview_with_meta([
                '_menu_item_classes' => ['projects-menu-item', 'with-sub-menu'], // properly synced array
                '_menu_item_type'    => 'post_type',                             // plain scalar
            ]);

            $this->assertSame([], $preview['warnings'], 'real arrays and plain scalars must not be flagged');
        }
    }
}
