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
     * An attachment is a post, so it must use the same clone-slave adoption: a slave
     * cloned from the master already holds the attachment without a _fylgja_source_id
     * stamp, so the source-id lookup misses. The apply path must adopt the pre-existing
     * attachment by WPML language + slug instead of re-downloading the file as a
     * duplicate. (Live sync pushes attachments on save_post; Resync All does not.)
     */
    class Apply_Attachment_Preview_Test extends TestCase {

        /** @var array<int, array> Recorded update_post_meta() calls. */
        private array $post_meta;

        protected function setUp(): void {
            parent::setUp();
            Monkey\setUp();

            $this->post_meta = [];

            // apply_attachment_preview require_once's these wp-admin includes in its
            // sideload branch — provide empty stubs so entering that branch (the RED
            // state, before the fix) doesn't fatal on a missing file.
            $dir = ABSPATH . 'wp-admin/includes';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            foreach (['media', 'file', 'image'] as $f) {
                $p = "$dir/$f.php";
                if (!file_exists($p)) {
                    file_put_contents($p, "<?php\n");
                }
            }

            Functions\when('sanitize_text_field')->returnArg();
            Functions\when('sanitize_title')->returnArg();
            Functions\when('wp_kses_post')->returnArg();
            Functions\when('is_wp_error')->alias(fn ($thing) => $thing instanceof \WP_Error);
            Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
                $this->post_meta[] = [$id, $key, $value];
                return true;
            });
            // Stored post_name already matches the master, so the verbatim reconcile
            // (enforce_master_post_name) is a no-op here.
            Functions\when('get_post_field')->justReturn('soho-chaowai-06');
            // is_wpml_active() -> true
            Functions\when('wpml_get_active_languages_filter')->justReturn([]);
        }

        protected function tearDown(): void {
            unset($GLOBALS['wpdb']);
            Monkey\tearDown();
            parent::tearDown();
        }

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

        private function make_preview(): array {
            return [
                'object_type'  => 'attachment',
                'action'       => 'upsert',
                'source_id'    => 70,
                'payload'      => [
                    'post_type' => 'attachment',
                    'post_name' => 'soho-chaowai-06',
                    'wpml'      => ['source_trid' => 6789, 'language_code' => 'en'],
                ],
                'warnings'     => [],
                'local_id'     => null,   // source-id lookup missed
                'would_create' => true,
                'source_url'   => 'https://master.example/wp-content/uploads/soho-chaowai-06.jpg',
                'wpml_plan'    => ['trid_action' => 'none', 'language_code' => 'en', 'warnings' => []],
                'element_type' => 'post_attachment',
            ];
        }

        private function invoke_apply_attachment_preview(array $preview): array {
            $receiver = new \Fylgja_Receiver(new \Fylgja_Auth(), null, null);
            $method = new \ReflectionMethod(\Fylgja_Receiver::class, 'apply_attachment_preview');
            $method->setAccessible(true);
            return $method->invoke($receiver, $preview);
        }

        public function test_unstamped_clone_attachment_is_adopted_not_redownloaded(): void {
            $GLOBALS['wpdb'] = $this->wpdb_returning(4321); // pre-existing clone attachment
            Functions\when('download_url')->alias(function () {
                throw new \RuntimeException('must adopt the existing clone attachment, not re-download');
            });

            $result = $this->invoke_apply_attachment_preview($this->make_preview());

            $this->assertTrue($result['success']);
            $this->assertSame(4321, $result['local_id'], 'the pre-existing clone attachment is adopted');
            $this->assertContains([4321, '_fylgja_source_id', 70], $this->post_meta, 'adopted attachment gets stamped');
        }

        public function test_new_attachment_with_no_clone_match_is_sideloaded(): void {
            $GLOBALS['wpdb'] = $this->wpdb_returning(null); // no pre-existing clone attachment
            Functions\when('download_url')->justReturn('/tmp/fake-download.jpg');
            Functions\when('wp_parse_url')->returnArg();
            Functions\when('media_handle_sideload')->justReturn(9001);

            $result = $this->invoke_apply_attachment_preview($this->make_preview());

            $this->assertTrue($result['success']);
            $this->assertSame(9001, $result['local_id'], 'a brand-new attachment is sideloaded when nothing matches');
            $this->assertContains([9001, '_fylgja_source_id', 70], $this->post_meta);
        }
    }
}
