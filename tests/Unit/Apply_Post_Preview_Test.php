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
     * Covers the post apply path's clone-slave adoption — the post-side analogue of
     * Apply_Term_Preview_Test's term adoption. A slave cloned from the master already
     * holds the post without a _fylgja_source_id stamp, so the source-id lookup misses;
     * resync must adopt the pre-existing post by WPML language + slug instead of
     * inserting a duplicate.
     */
    class Apply_Post_Preview_Test extends TestCase {

        /** @var array<int, array> Recorded update_post_meta() calls. */
        private array $post_meta;

        /** @var string post_name the slave currently stores for the applied post (drives the reconcile). */
        private string $stored_post_name;

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            $this->post_meta = [];
            // Default: the stored name already matches the master, so the verbatim
            // reconcile is a no-op. Tests that exercise drift override this.
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
            // is_wpml_active() -> true
            Functions\when('wpml_get_active_languages_filter')->justReturn([]);
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            Monkey\tearDown();
            parent::tearDown();
        }

        /** $wpdb whose adoption query (joins icl_translations, filters unstamped) returns $adopt_id. */
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
                        return $this->adopt_id; // pre-existing unstamped clone post (or null = no match)
                    }
                    return null;
                }
            };
        }

        private function make_preview(): array {
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
                    'wpml'        => [
                        'source_trid'   => 6789,
                        'language_code' => 'en',
                    ],
                ],
                'warnings'     => [],
                'local_id'     => null,   // source-id lookup missed
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

        public function test_unstamped_clone_post_is_adopted_by_language_and_slug(): void {
            $GLOBALS['wpdb'] = $this->wpdb_returning(4321); // pre-existing clone post in en + this slug

            $updated = [];
            Functions\when('wp_update_post')->alias(function ($data, $wp_error = false) use (&$updated) {
                $updated[] = $data['ID'] ?? null;
                return $data['ID'];
            });
            Functions\when('wp_insert_post')->alias(function () {
                throw new \RuntimeException('must adopt the existing clone post, not insert');
            });

            $result = $this->invoke_apply_post_preview($this->make_preview());

            $this->assertTrue($result['success']);
            $this->assertSame(4321, $result['local_id'], 'the pre-existing clone post is adopted');
            $this->assertContains(4321, $updated, 'adopted post is updated in place');
            $this->assertContains([4321, '_fylgja_source_id', 70], $this->post_meta, 'adopted post gets stamped');
        }

        public function test_new_post_with_no_clone_match_is_inserted(): void {
            $GLOBALS['wpdb'] = $this->wpdb_returning(null); // no pre-existing clone post

            $inserted = [];
            Functions\when('wp_insert_post')->alias(function ($data, $wp_error = false) use (&$inserted) {
                $inserted[] = $data['ID'] ?? 'new';
                return 9001;
            });
            Functions\when('wp_update_post')->alias(function () {
                throw new \RuntimeException('must insert a genuinely new post, not update');
            });

            $result = $this->invoke_apply_post_preview($this->make_preview());

            $this->assertTrue($result['success']);
            $this->assertSame(9001, $result['local_id'], 'a brand-new post is inserted when nothing matches');
            $this->assertContains([9001, '_fylgja_source_id', 70], $this->post_meta);
        }

        public function test_insert_reconciles_drifted_post_name_to_master(): void {
            // A freshly-inserted translation can land as "<slug>-2": wp_insert_post runs
            // wp_unique_post_slug, which only language-scopes once the post HAS a language,
            // so the bare master slug collides with the sibling translation and gets
            // suffixed. After the WPML language is attached the slave must reconcile the
            // post_name back to the master's verbatim value.
            $GLOBALS['wpdb'] = $this->wpdb_returning(null); // no clone match -> insert path
            $this->stored_post_name = 'aito-flagship-store-2'; // what wp_insert_post left behind

            Functions\when('wp_insert_post')->justReturn(9001);
            $reconciled = [];
            Functions\when('wp_update_post')->alias(function ($data, $wp_error = false) use (&$reconciled) {
                $reconciled[] = $data;
                return $data['ID'];
            });

            $result = $this->invoke_apply_post_preview($this->make_preview());

            $this->assertTrue($result['success']);
            $this->assertContains(
                ['ID' => 9001, 'post_name' => 'aito-flagship-store'],
                $reconciled,
                'drifted post_name must be reconciled to the verbatim master slug'
            );
        }
    }
}
