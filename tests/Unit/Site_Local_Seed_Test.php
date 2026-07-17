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
     * End-to-end wiring of $is_create through apply_post_preview(): a brand-new slave
     * post (insert path) seeds `position`/`meta-empty` from the master; an existing or
     * clone-adopted post (update path) preserves the slave's own placement. Mirrors the
     * harness in Apply_Post_Preview_Test.
     */
    class Site_Local_Seed_Test extends TestCase {

        /** @var array<int, array> Recorded update_post_meta() calls: [id, key, value]. */
        private array $post_meta;
        private string $stored_post_name;

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            $this->post_meta = [];
            $this->stored_post_name = 'aito-flagship-store';

            Functions\when('sanitize_text_field')->returnArg();
            Functions\when('sanitize_title')->returnArg();
            Functions\when('wp_kses_post')->returnArg();
            Functions\when('is_wp_error')->alias(fn ($thing) => $thing instanceof \WP_Error);
            Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
                $this->post_meta[] = [$id, $key, $value];
                return true;
            });
            Functions\when('get_post_field')->alias(fn ($field, $id) => $this->stored_post_name);
            Functions\when('wpml_get_active_languages_filter')->justReturn([]);
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            Monkey\tearDown();
            parent::tearDown();
        }

        /** $wpdb whose clone-adoption query returns $adopt_id (or null = no pre-existing post). */
        private function wpdb_returning(?int $adopt_id): object {
            return new class($adopt_id) {
                public string $prefix = 'wp_';
                public string $posts = 'wp_posts';
                public string $postmeta = 'wp_postmeta';
                private ?int $adopt_id;
                public function __construct(?int $adopt_id) { $this->adopt_id = $adopt_id; }
                public function prepare($query, ...$args) { return $query; }
                public function get_var($query) {
                    if (strpos($query, 'icl_translations') !== false && strpos($query, 'IS NULL') !== false) {
                        return $this->adopt_id;
                    }
                    return null;
                }
            };
        }

        private function make_preview_with_meta(array $meta): array {
            return [
                'object_type'  => 'post',
                'action'       => 'upsert',
                'source_id'    => 70,
                'payload'      => [
                    'post_title'  => 'AITO Flagship Store',
                    'post_content'=> '...',
                    'post_status' => 'publish',
                    'post_type'   => 'project',
                    'post_name'   => 'aito-flagship-store',
                    'meta'        => $meta,
                    'wpml'        => [
                        'source_trid'   => 6789,
                        'language_code' => 'en',
                    ],
                ],
                'warnings'     => [],
                'local_id'     => null,
                'would_create' => true,
                'wpml_plan'    => ['trid_action' => 'none', 'language_code' => 'en', 'warnings' => []],
                'element_type' => 'post_project',
            ];
        }

        private function invoke_apply_post_preview(array $preview): array {
            $receiver = new \Fylgja_Receiver(new \Fylgja_Auth(), null, null);
            $method = new \ReflectionMethod(\Fylgja_Receiver::class, 'apply_post_preview');
            $method->setAccessible(true);
            return $method->invoke($receiver, $preview);
        }

        public function test_new_post_seeds_placement_from_master(): void {
            $GLOBALS['wpdb'] = $this->wpdb_returning(null); // no clone match -> insert -> create
            Functions\when('wp_insert_post')->justReturn(9001);
            Functions\when('wp_update_post')->alias(function () {
                throw new \RuntimeException('must insert a genuinely new post, not update');
            });

            $this->invoke_apply_post_preview($this->make_preview_with_meta([
                'position'      => 7,
                'meta-empty'    => '',
                'chinese_title' => 'ZH',
            ]));

            $this->assertContains([9001, 'position', 7], $this->post_meta, 'new post seeds position');
            $this->assertContains([9001, 'meta-empty', ''], $this->post_meta, 'new post seeds meta-empty');
            $this->assertContains([9001, 'chinese_title', 'ZH'], $this->post_meta);
        }

        public function test_existing_post_preserves_placement(): void {
            $GLOBALS['wpdb'] = $this->wpdb_returning(4321); // clone adopted -> update
            Functions\when('wp_update_post')->justReturn(4321);
            Functions\when('wp_insert_post')->alias(function () {
                throw new \RuntimeException('must update the adopted post, not insert');
            });

            $this->invoke_apply_post_preview($this->make_preview_with_meta([
                'position'      => 7,
                'meta-empty'    => 'empty',
                'chinese_title' => 'ZH',
            ]));

            $keys = array_column($this->post_meta, 1);
            $this->assertNotContains('position', $keys, 'update preserves slave position');
            $this->assertNotContains('meta-empty', $keys, 'update preserves slave meta-empty');
            $this->assertContains([4321, 'chinese_title', 'ZH'], $this->post_meta);
        }
    }
}
