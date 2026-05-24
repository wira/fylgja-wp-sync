<?php

if (!defined('ABSPATH')) {
    exit;
}

interface Fylgja_Lookup_Interface {
    public function find_post_by_source_id(int $source_id): ?int;
    public function find_term_by_source_id(int $source_id, string $taxonomy): ?int;
}

class Fylgja_Lookup implements Fylgja_Lookup_Interface {

    public function find_post_by_source_id(int $source_id): ?int {
        global $wpdb;
        if (!$source_id) {
            return null;
        }
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_fylgja_source_id' AND meta_value = %d LIMIT 1",
            $source_id
        ));
        return $result === null ? null : (int) $result;
    }

    public function find_term_by_source_id(int $source_id, string $taxonomy): ?int {
        global $wpdb;
        if (!$source_id) {
            return null;
        }
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT tm.term_id FROM {$wpdb->termmeta} tm
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
             WHERE tm.meta_key = '_fylgja_source_id'
               AND tm.meta_value = %d
               AND tt.taxonomy = %s
             LIMIT 1",
            $source_id,
            $taxonomy
        ));
        return $result === null ? null : (int) $result;
    }
}
