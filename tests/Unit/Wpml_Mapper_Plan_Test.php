<?php

namespace Fylgja\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-lookup.php';
require_once dirname(__DIR__, 2) . '/includes/class-trid-map.php';
require_once dirname(__DIR__, 2) . '/includes/class-wpml-mapper.php';

/**
 * In-memory test double for the trid map (avoids wpdb).
 */
class FakeTridMap {
    public array $records = [];
    public function lookup(string $element_type, int $source_trid): ?int {
        return $this->records[$element_type . ':' . $source_trid] ?? null;
    }
    public function record(string $element_type, int $source_trid, int $local_trid): void {
        $this->records[$element_type . ':' . $source_trid] = $local_trid;
    }
}

class FakeLookup implements \Fylgja_Lookup_Interface {
    public array $post_map = [];   // source_id => local_id
    public array $term_map = [];   // "{source_id}:{taxonomy}" => local_id
    public function find_post_by_source_id(int $source_id): ?int {
        return $this->post_map[$source_id] ?? null;
    }
    public function find_term_by_source_id(int $source_id, string $taxonomy): ?int {
        return $this->term_map[$source_id . ':' . $taxonomy] ?? null;
    }
}

class Wpml_Mapper_Plan_Test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_mapper(FakeTridMap $tridmap, FakeLookup $lookup, array $active_langs = ['en', 'es', 'de']): \Fylgja_Wpml_Mapper {
        Functions\when('apply_filters')->alias(function ($hook, $value, ...$rest) use ($active_langs) {
            if ($hook === 'wpml_active_languages') {
                $out = [];
                foreach ($active_langs as $code) {
                    $out[$code] = ['code' => $code];
                }
                return $out;
            }
            return $value;
        });
        Functions\when('function_exists')->alias(fn ($f) => $f === 'wpml_get_active_languages_filter');
        return new \Fylgja_Wpml_Mapper($tridmap, $lookup);
    }

    public function test_wpml_inactive_returns_none(): void {
        Functions\when('function_exists')->alias(fn ($f) => false);
        $mapper = new \Fylgja_Wpml_Mapper(new FakeTridMap(), new FakeLookup());

        $plan = $mapper->plan('post_post', [
            'language_code' => 'es',
            'source_trid' => 42,
            'source_default_element_id' => 1,
        ], null);

        $this->assertSame('none', $plan['trid_action']);
        $this->assertNull($plan['language_code']);
    }

    public function test_empty_wpml_block_returns_none(): void {
        $mapper = $this->make_mapper(new FakeTridMap(), new FakeLookup());

        $plan = $mapper->plan('post_post', [], null);

        $this->assertSame('none', $plan['trid_action']);
    }

    public function test_language_code_not_active_returns_none_with_warning(): void {
        $mapper = $this->make_mapper(new FakeTridMap(), new FakeLookup(), ['en']);

        $plan = $mapper->plan('post_post', [
            'language_code' => 'fr',
            'source_trid' => 42,
            'source_default_element_id' => 1,
        ], null);

        $this->assertSame('none', $plan['trid_action']);
        $this->assertNotEmpty($plan['warnings']);
        $this->assertStringContainsString("'fr'", $plan['warnings'][0]);
    }

    public function test_cache_hit_returns_attach_to_trid(): void {
        $tridmap = new FakeTridMap();
        $tridmap->record('post_post', 42, 999);
        $mapper = $this->make_mapper($tridmap, new FakeLookup());

        $plan = $mapper->plan('post_post', [
            'language_code' => 'es',
            'source_trid' => 42,
            'source_default_element_id' => 1,
        ], null);

        $this->assertSame('attach_to_trid', $plan['trid_action']);
        $this->assertSame(999, $plan['trid']);
        $this->assertSame('es', $plan['language_code']);
    }

    public function test_resolves_via_local_default_sibling(): void {
        $tridmap = new FakeTridMap();
        $lookup = new FakeLookup();
        $lookup->post_map[1] = 555;
        Functions\when('apply_filters')->alias(function ($hook, $value, ...$rest) {
            if ($hook === 'wpml_active_languages') {
                return ['en' => [], 'es' => []];
            }
            if ($hook === 'wpml_element_trid') {
                return 777;
            }
            return $value;
        });
        Functions\when('function_exists')->alias(fn ($f) => $f === 'wpml_get_active_languages_filter');
        $mapper = new \Fylgja_Wpml_Mapper($tridmap, $lookup);

        $plan = $mapper->plan('post_post', [
            'language_code' => 'es',
            'source_trid' => 42,
            'source_default_element_id' => 1,
        ], null);

        $this->assertSame('attach_to_trid', $plan['trid_action']);
        $this->assertSame(777, $plan['trid']);
        $this->assertSame(555, $plan['matched_sibling_local_id']);
        // Mapping should be recorded for future siblings.
        $this->assertSame(777, $tridmap->lookup('post_post', 42));
    }

    public function test_no_cache_no_sibling_returns_create_new_trid(): void {
        $mapper = $this->make_mapper(new FakeTridMap(), new FakeLookup());

        $plan = $mapper->plan('post_post', [
            'language_code' => 'en',
            'source_trid' => 42,
            'source_default_element_id' => 1,
        ], null);

        $this->assertSame('create_new_trid', $plan['trid_action']);
        $this->assertSame('en', $plan['language_code']);
    }
}
