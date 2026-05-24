<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_Wpml_Collector {

    public function is_wpml_active(): bool {
        return function_exists('wpml_get_active_languages_filter');
    }

    /**
     * Returns the wpml payload block for a post, or an empty array if WPML is inactive.
     */
    public function collect_for_post(WP_Post $post): array {
        if (!$this->is_wpml_active()) {
            return [];
        }

        $element_type = 'post_' . $post->post_type;
        $details = $this->get_element_details((int) $post->ID, $element_type);
        if ($details === null) {
            return [];
        }

        $source_default_id = $this->resolve_default_element_id($element_type, (int) $details['trid']);

        return [
            'language_code'             => $details['language_code'],
            'source_trid'               => (int) $details['trid'],
            'source_default_element_id' => $source_default_id ?? (int) $post->ID,
            'element_type'              => $element_type,
        ];
    }

    // WPML stores term translations under element_id = term_taxonomy_id (NOT term_id).
    public function collect_for_term(WP_Term $term): array {
        if (!$this->is_wpml_active()) {
            return [];
        }

        $element_type = 'tax_' . $term->taxonomy;
        $details = $this->get_element_details((int) $term->term_taxonomy_id, $element_type);
        if ($details === null) {
            return [];
        }

        $source_default_id = $this->resolve_default_element_id($element_type, (int) $details['trid']);

        return [
            'language_code'             => $details['language_code'],
            'source_trid'               => (int) $details['trid'],
            'source_default_element_id' => $source_default_id ?? (int) $term->term_taxonomy_id,
            'element_type'              => $element_type,
        ];
    }

    /**
     * Returns ['trid' => int, 'language_code' => string, 'source_language_code' => ?string] or null.
     */
    private function get_element_details(int $element_id, string $element_type): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT trid, language_code, source_language_code
             FROM {$wpdb->prefix}icl_translations
             WHERE element_type = %s AND element_id = %d
             LIMIT 1",
            $element_type,
            $element_id
        ), ARRAY_A);
        return $row ?: null;
    }

    private function resolve_default_element_id(string $element_type, int $trid): ?int {
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT element_id
             FROM {$wpdb->prefix}icl_translations
             WHERE element_type = %s
               AND trid = %d
               AND (source_language_code IS NULL OR source_language_code = '')
             LIMIT 1",
            $element_type,
            $trid
        ));
        return $row === null ? null : (int) $row;
    }
}
