<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_Pusher {

    private Fylgja_Queue $queue;
    private Fylgja_Auth $auth;
    private Fylgja_Wpml_Collector $collector;
    private bool $is_syncing = false;

    public function __construct(Fylgja_Queue $queue, Fylgja_Auth $auth, ?Fylgja_Wpml_Collector $collector = null) {
        $this->queue     = $queue;
        $this->auth      = $auth;
        $this->collector = $collector ?? new Fylgja_Wpml_Collector();
    }

    public function register_hooks(): void {
        add_action('save_post', [$this, 'on_save_post'], 20, 2);
        add_action('before_delete_post', [$this, 'on_delete_post'], 10, 1);
        add_action('updated_post_meta', [$this, 'on_updated_post_meta'], 10, 4);
        add_action('added_post_meta', [$this, 'on_updated_post_meta'], 10, 4);
        add_action('set_object_terms', [$this, 'on_set_terms'], 10, 4);
        add_action('created_term', [$this, 'on_created_term'], 10, 3);
        add_action('edited_term', [$this, 'on_edited_term'], 10, 3);
        add_action('delete_term', [$this, 'on_delete_term'], 10, 5);
        add_action('fylgja_process_queue', [$this, 'flush_queue']);
        add_action('wpml_pro_translation_completed', [$this, 'on_translation_completed'], 10, 1);
        add_action('icl_make_duplicate', [$this, 'on_duplicate_created'], 10, 4);
        add_action('wpml_translation_update', [$this, 'on_translation_update'], 10, 1);
    }

    public function on_translation_completed($post_id): void {
        if (!$post_id) return;
        $post = get_post((int) $post_id);
        if ($post) {
            $this->on_save_post($post->ID, $post);
        }
    }

    public function on_duplicate_created($master_post_id, $lang, $post_array, $duplicate_id): void {
        if (!$duplicate_id) return;
        $post = get_post((int) $duplicate_id);
        if ($post) {
            $this->on_save_post($post->ID, $post);
        }
    }

    public function on_translation_update($info): void {
        if (!is_array($info)) return;
        $post_id = $info['post_id'] ?? $info['new_post_id'] ?? null;
        if (!$post_id) return;
        $post = get_post((int) $post_id);
        if ($post) {
            $this->on_save_post($post->ID, $post);
        }
    }

    public function on_save_post(int $post_id, WP_Post $post): void {
        if ($this->is_syncing) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!in_array($post->post_status, ['publish', 'draft', 'private'], true)) {
            return;
        }

        $payload = $this->serialize_post($post);

        if ($post->post_type === 'attachment') {
            $source_url = wp_get_attachment_url($post_id);
            if (!$source_url) {
                return;
            }
            $payload['source_url'] = $source_url;
            $object_type = 'attachment';
        } else {
            $object_type = 'post';
        }

        $this->queue->enqueue('upsert', $object_type, $post_id, $payload);
        $this->schedule_flush();
    }

    public function on_delete_post(int $post_id): void {
        if ($this->is_syncing) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $object_type = $post->post_type === 'attachment' ? 'attachment' : 'post';
        $payload = ['source_id' => $post_id];

        $this->queue->enqueue('delete', $object_type, $post_id, $payload);
        $this->schedule_flush();
    }

    public function on_updated_post_meta(int $meta_id, int $object_id, string $meta_key, $meta_value): void {
        if ($this->is_syncing) {
            return;
        }
        if (str_starts_with($meta_key, '_fylgja_') || str_starts_with($meta_key, '_edit_')) {
            return;
        }

        $post = get_post($object_id);
        if (!$post || wp_is_post_revision($object_id) || wp_is_post_autosave($object_id)) {
            return;
        }
        if (!in_array($post->post_status, ['publish', 'draft', 'private'], true)) {
            return;
        }

        $payload = $this->serialize_post($post);
        $object_type = $post->post_type === 'attachment' ? 'attachment' : 'post';
        if ($object_type === 'attachment') {
            $source_url = wp_get_attachment_url($object_id);
            if (!$source_url) {
                return;
            }
            $payload['source_url'] = $source_url;
        }

        $this->queue->enqueue('upsert', $object_type, $object_id, $payload);
        $this->schedule_flush();
    }

    public function on_set_terms(int $object_id, array $terms, array $tt_ids, string $taxonomy): void {
        if ($this->is_syncing) {
            return;
        }

        $post = get_post($object_id);
        if (!$post || !in_array($post->post_status, ['publish', 'draft', 'private'], true)) {
            return;
        }

        $payload = $this->serialize_post($post);
        $this->queue->enqueue('upsert', 'post', $object_id, $payload);
        $this->schedule_flush();
    }

    public function on_created_term(int $term_id, int $tt_id, string $taxonomy): void {
        if ($this->is_syncing) return;
        $this->enqueue_term($term_id, $taxonomy);
    }

    public function on_edited_term(int $term_id, int $tt_id, string $taxonomy): void {
        if ($this->is_syncing) return;
        $this->enqueue_term($term_id, $taxonomy);
    }

    public function on_delete_term(int $term_id, int $tt_id, string $taxonomy, $deleted_term, array $object_ids): void {
        if ($this->is_syncing) return;
        $this->queue->enqueue('delete', 'term', $term_id, [
            'payload_version' => 2,
            'source_id'       => $term_id,
            'taxonomy'        => $taxonomy,
        ]);
        $this->schedule_flush();
    }

    private function enqueue_term(int $term_id, string $taxonomy): void {
        $term = get_term($term_id, $taxonomy);
        if (is_wp_error($term) || !$term) return;
        $payload = $this->serialize_term($term);
        $this->queue->enqueue('upsert', 'term', $term_id, $payload);
        $this->schedule_flush();
    }

    /** @internal Exposed for Fylgja_Resync; not part of the public API. */
    public function serialize_term(WP_Term $term): array {
        $meta = get_term_meta($term->term_id);
        $flat_meta = [];
        foreach ($meta as $key => $values) {
            // Keyless get_term_meta() returns RAW (still-serialized) values. Unserialize
            // so array/object meta travels as real data — otherwise the slave's
            // update_term_meta() re-serializes the already-serialized string.
            $flat_meta[$key] = maybe_unserialize($values[0] ?? '');
        }
        return [
            'payload_version'  => 2,
            'source_id'        => $term->term_id,
            'taxonomy'         => $term->taxonomy,
            'name'             => $term->name,
            'slug'             => $term->slug,
            'description'      => $term->description,
            'parent_source_id' => (int) $term->parent,
            'meta'             => $flat_meta,
            'wpml'             => $this->collector->collect_for_term($term),
        ];
    }

    private function schedule_flush(): void {
        if (!wp_next_scheduled('fylgja_process_queue')) {
            wp_schedule_single_event(time() + 5, 'fylgja_process_queue');
        }
    }

    /** @internal Exposed for Fylgja_Resync; not part of the public API. */
    public function serialize_post(WP_Post $post): array {
        $meta = get_post_meta($post->ID);
        $flat_meta = [];
        foreach ($meta as $key => $values) {
            // Keyless get_post_meta() returns RAW (still-serialized) values. Unserialize
            // so array/object meta (e.g. _menu_item_classes) travels as real data —
            // otherwise the slave's update_post_meta() re-serializes the already-serialized
            // string and the blob surfaces as a literal CSS class.
            $flat_meta[$key] = maybe_unserialize($values[0] ?? '');
        }

        $taxonomies = get_object_taxonomies($post->post_type);
        $terms = [];
        foreach ($taxonomies as $taxonomy) {
            $post_terms = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'ids']);
            if (!is_wp_error($post_terms) && !empty($post_terms)) {
                $terms[$taxonomy] = array_map('intval', $post_terms);
            }
        }

        $wpml = $this->collector->collect_for_post($post);

        return [
            'payload_version' => 2,
            'source_id'    => $post->ID,
            'post_title'   => $post->post_title,
            'post_content' => $post->post_content,
            'post_status'  => $post->post_status,
            'post_type'    => $post->post_type,
            'post_name'    => $post->post_name,
            'post_excerpt' => $post->post_excerpt,
            'menu_order'   => $post->menu_order,
            'meta'         => $flat_meta,
            'terms'        => $terms,
            'wpml'         => $wpml,
        ];
    }

    /** Seconds a single flush run may spend pushing before yielding to the next cron tick. */
    private const FLUSH_BUDGET = 20;

    /** Arm the background drain if it isn't already scheduled. */
    public function ensure_flush_scheduled(): void {
        $this->schedule_flush();
    }

    public function flush_queue(): array {
        global $wpdb;

        $lock_name = 'fylgja_flush_' . $wpdb->prefix;
        $guard = new Fylgja_Flush_Guard(
            fn (): bool => (bool) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $lock_name)),
            fn () => $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name))
        );

        $result = $guard->run(fn () => $this->do_flush());

        // The flush ran (lock acquired). Clear completed rows and, if work
        // remains, keep draining in the background until the queue empties.
        if (empty($result['skipped'])) {
            $this->queue->flush_completed();
            $remaining = $this->queue->count_pending();
            $result['pending_remaining'] = $remaining;
            if ($remaining > 0 && !wp_next_scheduled('fylgja_process_queue')) {
                wp_schedule_single_event(time() + 5, 'fylgja_process_queue');
            }
        }

        return $result;
    }

    private function do_flush(): array {
        $deadline = microtime(true) + self::FLUSH_BUDGET;
        $pushed = 0;
        $failed = 0;
        $total = 0;
        $collapsed_same = 0;
        $collapsed_supersedes = 0;
        $collapser = new Fylgja_Queue_Collapser();

        while (microtime(true) < $deadline) {
            $items = $this->queue->get_pending(100);
            if (empty($items)) {
                break;
            }
            foreach ($items as $item) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }
                $collapse = $collapser->collapse_for_row($this->queue, $item);
                $collapsed_same       += $collapse['collapsed_same'];
                $collapsed_supersedes += $collapse['collapsed_supersedes'];

                if ($this->send_to_slave($item)) {
                    $pushed++;
                } else {
                    $failed++;
                }
                $total++;
            }
        }

        return [
            'pushed'               => $pushed,
            'failed'               => $failed,
            'total'                => $total,
            'collapsed_same'       => $collapsed_same,
            'collapsed_supersedes' => $collapsed_supersedes,
        ];
    }

    /**
     * HTTP timeout (seconds) for a single push. Resync floods the slave with heavy WPML
     * writes, and a single item that runs past the interactive 30s gets abandoned
     * mid-flight — which leaves the slave still processing it while the next item arrives,
     * piling on concurrency until it tips into timeouts/500s. Give resync-sourced items a
     * far longer ceiling so legitimately-slow items finish instead of cascading.
     * Filterable for site-specific tuning.
     */
    private function push_timeout(string $source_kind): int {
        $timeout = $source_kind === 'resync' ? 120 : 30;
        return max(1, (int) apply_filters('fylgja_push_timeout', $timeout, $source_kind));
    }

    private function send_to_slave(object $item): bool {
        $remote_url = get_option('fylgja_remote_url', '');
        if (empty($remote_url)) {
            $this->queue->mark_failed((int) $item->id, 'No remote URL configured');
            return false;
        }

        $endpoint = trailingslashit($remote_url) . 'wp-json/fylgja-wp-sync/v1/receive';
        $payload = json_decode($item->payload, true);

        $args = [
            'body'    => wp_json_encode([
                'action'      => $item->action,
                'object_type' => $item->object_type,
                'payload'     => $payload,
            ]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => $this->push_timeout((string) ($item->source_kind ?? 'hook')),
        ];

        $args = $this->auth->add_auth_headers($args);

        $this->is_syncing = true;
        $response = wp_remote_post($endpoint, $args);
        $this->is_syncing = false;

        if (is_wp_error($response)) {
            $this->queue->mark_failed((int) $item->id, $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $this->queue->mark_failed((int) $item->id, "HTTP {$code}: {$body}");
            return false;
        }

        $this->queue->mark_completed((int) $item->id);
        return true;
    }
}
