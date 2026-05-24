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

    /** Mapper stub so the receiver constructor needs no trid_map/lookup wiring. */
    class NoopMapper extends \Fylgja_Wpml_Mapper {
        public function __construct() {}
    }

    /**
     * post_type_archive menu items resolve by post-type slug and carry a 0/negative
     * sentinel object id. They must never be drafted or parked as a deferred ref.
     */
    class Rewrite_Menu_Item_Test extends TestCase {

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
            };
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            Monkey\tearDown();
            parent::tearDown();
        }

        private function invoke_rewrite(array $meta): void {
            Functions\when('get_post_meta')->alias(
                fn ($id, $key, $single = false) => $meta[$key] ?? ''
            );

            $receiver = new \Fylgja_Receiver(new \Fylgja_Auth(), null, new NoopMapper());
            $method = new \ReflectionMethod(\Fylgja_Receiver::class, 'rewrite_menu_item_object');
            $method->setAccessible(true);
            $method->invoke($receiver, 13741);
        }

        public function test_negative_sentinel_archive_item_is_not_parked(): void {
            // wp_update_post is only ever called to draft (park) or re-publish the item.
            // A clean early return must touch neither.
            Functions\expect('wp_update_post')->never();

            $this->invoke_rewrite([
                '_menu_item_type'      => 'post_type_archive',
                '_menu_item_object'    => 'company',
                '_menu_item_object_id' => -12,
            ]);

            $this->assertTrue(true); // assertion is the never() expectation above
        }

        public function test_zero_sentinel_archive_item_is_not_parked(): void {
            Functions\expect('wp_update_post')->never();

            $this->invoke_rewrite([
                '_menu_item_type'      => 'post_type_archive',
                '_menu_item_object'    => 'company',
                '_menu_item_object_id' => 0,
            ]);

            $this->assertTrue(true);
        }
    }
}
