<?php

namespace {
    // Minimal WP_Post / WP_Term stubs (Brain Monkey doesn't define them). Guarded so
    // they don't clash if another test in the same process defines them.
    if (!class_exists('WP_Post')) {
        #[\AllowDynamicProperties]
        class WP_Post {
            public function __construct(array $props) {
                foreach ($props as $k => $v) {
                    $this->$k = $v;
                }
            }
        }
    }
    if (!class_exists('WP_Term')) {
        #[\AllowDynamicProperties]
        class WP_Term {
            public function __construct(array $props) {
                foreach ($props as $k => $v) {
                    $this->$k = $v;
                }
            }
        }
    }
}

namespace Fylgja\Tests\Unit {

    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__, 2) . '/includes/class-queue.php';
    require_once dirname(__DIR__, 2) . '/includes/class-auth.php';
    require_once dirname(__DIR__, 2) . '/includes/class-wpml-collector.php';
    require_once dirname(__DIR__, 2) . '/includes/class-pusher.php';

    /** Collector stub so serialize_post/term need no WPML wiring. */
    class NoopCollector extends \Fylgja_Wpml_Collector {
        public function collect_for_post(\WP_Post $post): array { return []; }
        public function collect_for_term(\WP_Term $term): array { return []; }
    }

    /**
     * serialize_post()/serialize_term() read meta with the keyless get_post_meta() /
     * get_term_meta(), which returns RAW (still-serialized) values. They must unserialize
     * array/object meta before it enters the payload — otherwise the slave's
     * update_post_meta() re-serializes the already-serialized string (WP's documented
     * double-serialization in maybe_serialize()), and e.g. _menu_item_classes surfaces on
     * the slave as the literal blob a:2:{...} rendered as a CSS class.
     */
    class Serialize_Meta_Test extends TestCase {

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
            };

            // Faithful reproduction of WP core maybe_unserialize() semantics.
            Functions\when('maybe_unserialize')->alias(function ($value) {
                if (!is_string($value)) {
                    return $value;
                }
                $trimmed = trim($value);
                if ($trimmed === 'b:0;') {
                    return false;
                }
                $result = @unserialize($trimmed);
                return $result === false ? $value : $result;
            });
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            Monkey\tearDown();
            parent::tearDown();
        }

        private function make_pusher(): \Fylgja_Pusher {
            return new \Fylgja_Pusher(new \Fylgja_Queue(), new \Fylgja_Auth(), new NoopCollector());
        }

        public function test_serialize_post_unserializes_array_meta(): void {
            // Raw DB value for a menu item with classes ['projects-menu-item', 'with-sub-menu'].
            $raw = 'a:2:{i:0;s:18:"projects-menu-item";i:1;s:13:"with-sub-menu";}';

            Functions\when('get_post_meta')->alias(fn ($id) => [
                '_menu_item_classes' => [$raw],
                '_menu_item_type'    => ['post_type'], // plain scalar, must pass through unchanged
            ]);
            Functions\when('get_object_taxonomies')->justReturn([]);

            $post = new \WP_Post([
                'ID'           => 4321,
                'post_title'   => 'Projects',
                'post_content' => '',
                'post_status'  => 'publish',
                'post_type'    => 'nav_menu_item',
                'post_name'    => 'projects',
                'post_excerpt' => '',
                'menu_order'   => 3,
            ]);

            $payload = $this->make_pusher()->serialize_post($post);

            $this->assertSame(
                ['projects-menu-item', 'with-sub-menu'],
                $payload['meta']['_menu_item_classes'],
                'array meta must arrive as a real array, not a raw serialized string'
            );
            $this->assertSame('post_type', $payload['meta']['_menu_item_type']);
        }

        public function test_serialize_term_unserializes_array_meta(): void {
            $raw = 'a:1:{i:0;s:7:"feature";}';

            Functions\when('get_term_meta')->alias(fn ($id) => [
                'some_array_meta' => [$raw],
                'plain_meta'      => ['hello'],
            ]);

            $term = new \WP_Term([
                'term_id'     => 99,
                'taxonomy'    => 'category',
                'name'        => 'News',
                'slug'        => 'news',
                'description' => '',
                'parent'      => 0,
            ]);

            $payload = $this->make_pusher()->serialize_term($term);

            $this->assertSame(['feature'], $payload['meta']['some_array_meta']);
            $this->assertSame('hello', $payload['meta']['plain_meta']);
        }
    }
}
