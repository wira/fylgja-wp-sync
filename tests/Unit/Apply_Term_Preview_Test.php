<?php

namespace {
    if (!class_exists('WP_Error')) {
        class WP_Error {
            public string $code;
            public string $message;
            /** @var mixed */
            public $data;
            public function __construct(string $code = '', string $message = '', $data = '') {
                $this->code = $code;
                $this->message = $message;
                $this->data = $data;
            }
            public function get_error_message(): string {
                return $this->message;
            }
            public function get_error_data() {
                return $this->data;
            }
        }
    }
}

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
     * Records attach() calls without touching WPML or wpdb.
     */
    class SpyMapper extends \Fylgja_Wpml_Mapper {
        public array $attached = [];
        public function __construct() {} // skip parent (no trid_map/lookup needed)
        public function attach(int $local_id, string $element_type, array $plan): void {
            $this->attached[] = compact('local_id', 'element_type', 'plan');
        }
    }

    /**
     * Reproduces the WPML translated-term sync bug: a zh-hans translation that
     * shares its name + parent with the English original must be created as a
     * distinct row, not rejected as term_exists.
     */
    class Apply_Term_Preview_Test extends TestCase {

        /** @var string Tracks WPML's "current language" across switch calls. */
        private string $current_lang;

        /** @var string|null Language active at the moment wp_insert_term ran. */
        private ?string $lang_at_insert;

        /** @var array<int, array> Recorded update_term_meta() calls. */
        private array $term_meta;

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            $this->current_lang   = 'en'; // request default before any switch
            $this->lang_at_insert = null;
            $this->term_meta      = [];

            // Minimal $wpdb so Fylgja_Lookup / Fylgja_Sync_Log construction works.
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                public string $terms = 'wp_terms';
                public string $termmeta = 'wp_termmeta';
                public string $term_taxonomy = 'wp_term_taxonomy';
                public function prepare($query, ...$args) { return $query; }
                public function get_var($query) { return null; } // term not found / slug free
            };

            Functions\when('taxonomy_exists')->justReturn(true);
            Functions\when('sanitize_title')->returnArg();
            Functions\when('wp_kses_post')->returnArg();
            Functions\when('is_wp_error')->alias(fn ($thing) => $thing instanceof \WP_Error);
            Functions\when('get_term')->alias(
                fn ($id, $tax) => (object) ['term_id' => $id, 'term_taxonomy_id' => 8001]
            );

            Functions\when('update_term_meta')->alias(function ($id, $key, $value) {
                $this->term_meta[] = [$id, $key, $value];
                return true;
            });

            // WPML language context: switching to the target language must hide
            // the English sibling from wp_insert_term's duplicate check.
            Functions\when('do_action')->alias(function ($hook, ...$args) {
                if ($hook === 'wpml_switch_language') {
                    $this->current_lang = (string) ($args[0] ?? 'en');
                }
            });
            Functions\when('apply_filters')->alias(function ($hook, $value, ...$rest) {
                if ($hook === 'wpml_current_language') {
                    return $this->current_lang;
                }
                return $value;
            });

            // Models WPML-aware core: a same-named sibling collides UNLESS the
            // current language is the target language at insert time.
            Functions\when('wp_insert_term')->alias(function ($name, $tax, $args = []) {
                $this->lang_at_insert = $this->current_lang;
                if ($this->current_lang !== 'zh-hans') {
                    return new \WP_Error(
                        'term_exists',
                        'A term with the name provided already exists with this parent.'
                    );
                }
                return ['term_id' => 7001, 'term_taxonomy_id' => 8001];
            });
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            Monkey\tearDown();
            parent::tearDown();
        }

        private function make_preview(): array {
            return [
                'object_type'  => 'term',
                'action'       => 'edited_term',
                'source_id'    => 70, // the zh-hans "Test1"
                'payload'      => [
                    'taxonomy'         => 'category',
                    'name'             => 'Test1',
                    'slug'             => 'test1-zh-hans',
                    'description'      => '',
                    'parent_source_id' => 0,
                    'wpml'             => [
                        'source_trid'   => 6789,
                        'language_code' => 'zh-hans',
                    ],
                ],
                'warnings'     => [],
                'local_id'     => null,
                'would_create' => true,
                'wpml_plan'    => [
                    'trid_action'   => 'attach_to_trid',
                    'trid'          => 555,
                    'language_code' => 'zh-hans',
                    'warnings'      => [],
                ],
                'element_type' => 'tax_category',
            ];
        }

        private function invoke_apply_term_preview(array $preview, ?SpyMapper $mapper = null): array {
            $receiver = new \Fylgja_Receiver(new \Fylgja_Auth(), null, $mapper ?? new SpyMapper());
            $method = new \ReflectionMethod(\Fylgja_Receiver::class, 'apply_term_preview');
            $method->setAccessible(true);
            return $method->invoke($receiver, $preview);
        }

        public function test_same_name_translation_is_created_as_distinct_row(): void {
            $result = $this->invoke_apply_term_preview($this->make_preview());

            $this->assertTrue($result['success'], 'apply should succeed for a same-name translation');
            $this->assertSame(7001, $result['local_id'], 'a fresh term row should be created');
            $this->assertSame(70, $result['source_id']);

            // The whole point: insert must run under the target language.
            $this->assertSame('zh-hans', $this->lang_at_insert, 'wp_insert_term must run under zh-hans');

            // _fylgja_source_id must be stamped so the deferred sweep can resolve it.
            $this->assertContains([7001, '_fylgja_source_id', 70], $this->term_meta);

            // Active language must be restored, not left switched.
            $this->assertSame('en', $this->current_lang, 'language should be restored after insert');
        }

        public function test_term_exists_collision_adopts_existing_term(): void {
            // A slave synced before source-id stamping was reliable already holds this
            // term (id 4242) but find_term_by_source_id misses it, so insert collides.
            // WP's term_exists error carries the existing id as its error data.
            Functions\when('wp_insert_term')->alias(
                fn ($name, $tax, $args = []) => new \WP_Error(
                    'term_exists',
                    'A term with the name provided already exists with this parent.',
                    4242
                )
            );
            $updated = [];
            Functions\when('wp_update_term')->alias(function ($id, $tax, $args = []) use (&$updated) {
                $updated[] = $id;
                return ['term_id' => $id, 'term_taxonomy_id' => 8001];
            });

            $result = $this->invoke_apply_term_preview($this->make_preview());

            $this->assertTrue($result['success'], 'apply should adopt the existing term, not fail');
            $this->assertSame(4242, $result['local_id'], 'should adopt the colliding term id');
            $this->assertContains(4242, $updated, 'adopted term must be updated in place');
            $this->assertContains([4242, '_fylgja_source_id', 70], $this->term_meta, 'source id must be stamped on the adopted term');
        }

        public function test_trid_attach_still_runs_on_term_taxonomy_id(): void {
            $spy = new SpyMapper();
            $this->invoke_apply_term_preview($this->make_preview(), $spy);

            $this->assertCount(1, $spy->attached);
            $this->assertSame(8001, $spy->attached[0]['local_id'], 'attach keys on term_taxonomy_id');
            $this->assertSame('tax_category', $spy->attached[0]['element_type']);
        }

        public function test_update_of_translation_resolves_colliding_slug_to_lang_suffix(): void {
            // The term already exists on the slave (update path), and its source
            // slug "best-of" is owned by the English original. wp_update_term would
            // reject "best-of" with duplicate_term_slug, so the slug must resolve to
            // the WPML-style "best-of-zh-hans".
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                public string $terms = 'wp_terms';
                public string $termmeta = 'wp_termmeta';
                public string $term_taxonomy = 'wp_term_taxonomy';
                public function prepare($query, ...$args) {
                    if (count($args) === 1 && is_array($args[0])) {
                        $args = $args[0];
                    }
                    foreach ($args as $a) {
                        $repl  = is_string($a) ? "'" . $a . "'" : (string) $a;
                        $query = preg_replace('/%[ds]/', $repl, $query, 1);
                    }
                    return $query;
                }
                public function get_var($query) {
                    if (strpos($query, 'SELECT slug FROM') !== false) {
                        return 'best-of'; // current slug squats the original's slug
                    }
                    if (strpos($query, "t.slug = 'best-of-zh-hans'") !== false) {
                        return null; // language-suffixed slug is free
                    }
                    if (strpos($query, "t.slug = 'best-of'") !== false) {
                        return 99; // English original owns the bare slug
                    }
                    return null;
                }
            };

            Functions\when('apply_filters')->alias(function ($hook, $value, ...$rest) {
                if ($hook === 'wpml_default_language') {
                    return 'en';
                }
                return $value;
            });

            $captured = ['slug' => null, 'id' => null];
            Functions\when('wp_update_term')->alias(function ($id, $tax, $args = []) use (&$captured) {
                $captured['id']   = $id;
                $captured['slug'] = $args['slug'] ?? null;
                return ['term_id' => $id, 'term_taxonomy_id' => 8001];
            });

            $preview = $this->make_preview();
            $preview['source_id']        = 70;
            $preview['local_id']         = 5151; // term already exists on the slave
            $preview['would_create']     = false;
            $preview['payload']['slug']  = 'best-of';
            $preview['payload']['name']  = 'Best of';

            $result = $this->invoke_apply_term_preview($preview);

            $this->assertTrue($result['success'], 'update must not fail with duplicate_term_slug');
            $this->assertSame(5151, $captured['id'], 'the existing local term is updated in place');
            $this->assertSame('best-of-zh-hans', $captured['slug'], 'colliding slug resolves to the WPML lang-suffixed slug');
        }

        public function test_update_of_translation_runs_under_target_language(): void {
            // wp_update_term must run with WPML's active language switched to the
            // term's language; otherwise WPML's get_term_adjust_id rewrites the
            // get_term_by result to a sibling and core raises a phantom
            // duplicate_term_slug. Language must also be restored afterwards.
            $lang_at_update = null;
            Functions\when('wp_update_term')->alias(function ($id, $tax, $args = []) use (&$lang_at_update) {
                $lang_at_update = $this->current_lang;
                return ['term_id' => $id, 'term_taxonomy_id' => 8001];
            });

            $preview = $this->make_preview();
            $preview['local_id'] = 5151; // existing term -> update path

            $result = $this->invoke_apply_term_preview($preview);

            $this->assertTrue($result['success']);
            $this->assertSame('zh-hans', $lang_at_update, 'wp_update_term must run under the target language');
            $this->assertSame('en', $this->current_lang, 'language must be restored after the update');
        }

        public function test_unstamped_clone_term_is_adopted_by_language_and_slug(): void {
            // Slave cloned from master: the term already exists but has no
            // _fylgja_source_id, so the source-id lookup misses. It must be adopted
            // by (taxonomy + language + slug) and updated in place, NOT inserted.
            Functions\when('wpml_get_active_languages_filter')->justReturn([]); // is_wpml_active() -> true

            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                public string $terms = 'wp_terms';
                public string $termmeta = 'wp_termmeta';
                public string $term_taxonomy = 'wp_term_taxonomy';
                public function prepare($query, ...$args) {
                    if (count($args) === 1 && is_array($args[0])) {
                        $args = $args[0];
                    }
                    foreach ($args as $a) {
                        $repl  = is_string($a) ? "'" . $a . "'" : (string) $a;
                        $query = preg_replace('/%[ds]/', $repl, $query, 1);
                    }
                    return $query;
                }
                public function get_var($query) {
                    // The adoption query joins icl_translations and filters unstamped rows.
                    if (strpos($query, 'icl_translations') !== false && strpos($query, 'tm.meta_id IS NULL') !== false) {
                        return 4321; // pre-existing clone term in this language+slug
                    }
                    return null; // source-id lookup misses; slug is free
                }
            };

            $updated = [];
            Functions\when('wp_update_term')->alias(function ($id, $tax, $args = []) use (&$updated) {
                $updated[] = $id;
                return ['term_id' => $id, 'term_taxonomy_id' => 8001];
            });
            Functions\when('wp_insert_term')->alias(function () {
                throw new \RuntimeException('must adopt, not insert');
            });

            $result = $this->invoke_apply_term_preview($this->make_preview());

            $this->assertTrue($result['success']);
            $this->assertSame(4321, $result['local_id'], 'the pre-existing clone term is adopted');
            $this->assertContains(4321, $updated, 'adopted term is updated in place');
            $this->assertContains([4321, '_fylgja_source_id', 70], $this->term_meta, 'adopted term gets stamped');
        }
    }
}
