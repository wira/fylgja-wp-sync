<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_Wpml_Mapper {

    private $trid_map;
    private Fylgja_Lookup_Interface $lookup;

    public function __construct($trid_map, Fylgja_Lookup_Interface $lookup) {
        $this->trid_map = $trid_map;
        $this->lookup   = $lookup;
    }

    /**
     * Returns a structured plan describing the trid action to take for an element.
     * Pure — no writes.
     *
     * @param string   $element_type WPML element_type (e.g. 'post_post', 'tax_category').
     * @param array    $wpml_block   The payload's wpml subarray.
     * @param int|null $local_id     Slave-side ID if already known (currently unused; reserved).
     */
    public function plan(string $element_type, array $wpml_block, ?int $local_id): array {
        $plan = [
            'trid_action'   => 'none',
            'language_code' => null,
            'trid'          => null,
            'warnings'      => [],
        ];

        if (!$this->is_wpml_active()) {
            return $plan;
        }
        if (empty($wpml_block) || empty($wpml_block['language_code'])) {
            return $plan;
        }

        $language_code            = $wpml_block['language_code'];
        $source_trid              = (int) ($wpml_block['source_trid'] ?? 0);
        $source_default_id        = (int) ($wpml_block['source_default_element_id'] ?? 0);

        $active = $this->active_languages();
        if (!isset($active[$language_code])) {
            $plan['warnings'][] = "Language code '{$language_code}' not enabled on slave";
            return $plan;
        }

        // Step 1: trid map cache hit.
        $cached = $this->trid_map->lookup($element_type, $source_trid);
        if ($cached !== null) {
            return [
                'trid_action'   => 'attach_to_trid',
                'trid'          => $cached,
                'language_code' => $language_code,
                'warnings'      => [],
            ];
        }

        // Step 2: resolve via default-language sibling.
        if ($source_default_id > 0) {
            $local_default_id = $this->find_local_default($element_type, $source_default_id);
            if ($local_default_id !== null) {
                $local_trid = (int) apply_filters('wpml_element_trid', null, $local_default_id, $element_type);
                if ($local_trid > 0) {
                    $this->trid_map->record($element_type, $source_trid, $local_trid);
                    return [
                        'trid_action'              => 'attach_to_trid',
                        'trid'                     => $local_trid,
                        'language_code'            => $language_code,
                        'matched_sibling_local_id' => $local_default_id,
                        'warnings'                 => [],
                    ];
                }
            }
        }

        // Step 3: create a fresh trid.
        return [
            'trid_action'   => 'create_new_trid',
            'trid'          => null,
            'language_code' => $language_code,
            'warnings'      => [],
        ];
    }

    private function is_wpml_active(): bool {
        return function_exists('wpml_get_active_languages_filter');
    }

    private function active_languages(): array {
        $langs = apply_filters('wpml_active_languages', []);
        return is_array($langs) ? $langs : [];
    }

    private function find_local_default(string $element_type, int $source_default_id): ?int {
        if (strpos($element_type, 'post_') === 0) {
            return $this->lookup->find_post_by_source_id($source_default_id);
        }
        if (strpos($element_type, 'tax_') === 0) {
            $taxonomy = substr($element_type, 4);
            return $this->lookup->find_term_by_source_id($source_default_id, $taxonomy);
        }
        return null;
    }

    /**
     * Apply the plan: call WPML to set element language details, and record
     * a fresh trid in the trid_map if one was just allocated.
     *
     * Branch 0.2 spike result: if wpml_set_element_language_details accepts trid=null,
     * this method passes null and reads back the assigned trid via wpml_element_trid.
     * If the spike showed null is rejected, pre-allocate via SELECT MAX(trid)+1 first.
     */
    public function attach(int $local_id, string $element_type, array $plan): void {
        if ($plan['trid_action'] === 'none' || !$this->is_wpml_active()) {
            return;
        }

        $source_trid = null;
        if (!empty($plan['source_trid'])) {
            $source_trid = (int) $plan['source_trid'];
        }

        if ($plan['trid_action'] === 'attach_to_trid') {
            do_action('wpml_set_element_language_details', [
                'element_id'           => $local_id,
                'element_type'         => $element_type,
                'trid'                 => (int) $plan['trid'],
                'language_code'        => $plan['language_code'],
                'source_language_code' => null,
            ]);
            return;
        }

        if ($plan['trid_action'] === 'create_new_trid') {
            do_action('wpml_set_element_language_details', [
                'element_id'           => $local_id,
                'element_type'         => $element_type,
                'trid'                 => null,
                'language_code'        => $plan['language_code'],
                'source_language_code' => null,
            ]);
            $new_trid = (int) apply_filters('wpml_element_trid', null, $local_id, $element_type);
            if ($new_trid > 0 && $source_trid !== null) {
                $this->trid_map->record($element_type, $source_trid, $new_trid);
            }
            return;
        }
    }
}
