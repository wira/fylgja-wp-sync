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
     * Covers the site-local placement rule in sync_post_meta(): `position` and
     * `meta-empty` are seeded only when the slave copy is first created ($is_create =
     * true) and preserved on every later sync ($is_create = false), so operator
     * curation of the slave homepage grid survives. All other meta syncs as before,
     * and the reserved-prefix skips (_fylgja_/_wp_/_edit_/_pingme) still hold.
     */
    class Site_Local_Meta_Test extends TestCase {

        /** @var array<int, array> Recorded update_post_meta() calls: [id, key, value]. */
        private array $post_meta;

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();
            $this->post_meta = [];
            $GLOBALS['wpdb'] = new class { public string $prefix = 'wp_'; };
            Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
                $this->post_meta[] = [$id, $key, $value];
                return true;
            });
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            Monkey\tearDown();
            parent::tearDown();
        }

        private function invoke_sync(int $post_id, array $meta, bool $is_create): void {
            $receiver = new \Fylgja_Receiver(new \Fylgja_Auth(), null, null);
            $m = new \ReflectionMethod(\Fylgja_Receiver::class, 'sync_post_meta');
            $m->setAccessible(true);
            $m->invoke($receiver, $post_id, $meta, $is_create);
        }

        public function test_update_preserves_site_local_meta(): void {
            $this->invoke_sync(555, [
                'position'      => 5,
                'meta-empty'    => 'empty',
                'chinese_title' => 'ZH',
            ], false);

            $keys = array_column($this->post_meta, 1);
            $this->assertNotContains('position', $keys, 'position preserved on update');
            $this->assertNotContains('meta-empty', $keys, 'meta-empty preserved on update');
            $this->assertContains([555, 'chinese_title', 'ZH'], $this->post_meta);
        }

        public function test_create_seeds_site_local_meta(): void {
            $this->invoke_sync(555, [
                'position'      => 5,
                'meta-empty'    => 'empty',
                'chinese_title' => 'ZH',
            ], true);

            $this->assertContains([555, 'position', 5], $this->post_meta);
            $this->assertContains([555, 'meta-empty', 'empty'], $this->post_meta);
            $this->assertContains([555, 'chinese_title', 'ZH'], $this->post_meta);
        }

        public function test_reserved_prefixes_always_skipped(): void {
            $this->invoke_sync(555, [
                '_fylgja_source_id' => 1,
                '_wp_thing'         => 1,
                '_edit_lock'        => 1,
                '_pingme'           => 1,
                'position'          => 5,
            ], true); // even on create, reserved prefixes are skipped

            $keys = array_column($this->post_meta, 1);
            $this->assertSame(['position'], $keys, 'only the non-reserved key is written');
        }
    }
}
