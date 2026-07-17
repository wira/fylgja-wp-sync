<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_Receiver {

    private Fylgja_Auth $auth;
    private Fylgja_Sync_Log $log;
    private Fylgja_Wpml_Mapper $mapper;

    /** Meta keys the slave owns locally: seeded once on create, never overwritten after. */
    private const SITE_LOCAL_META = ['position', 'meta-empty'];

    public function __construct(Fylgja_Auth $auth, ?Fylgja_Sync_Log $log = null, ?Fylgja_Wpml_Mapper $mapper = null) {
        $this->auth = $auth;
        $this->log  = $log ?? new Fylgja_Sync_Log();
        $this->mapper = $mapper ?? new Fylgja_Wpml_Mapper(
            new Fylgja_Trid_Map(),
            new Fylgja_Lookup()
        );
    }

    public function register_routes(): void {
        add_action('rest_api_init', function () {
            register_rest_route('fylgja-wp-sync/v1', '/receive', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_receive'],
                'permission_callback' => [$this->auth, 'permission_check'],
            ]);

            register_rest_route('fylgja-wp-sync/v1', '/receive-batch', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_receive_batch'],
                'permission_callback' => [$this->auth, 'permission_check'],
            ]);

            register_rest_route('fylgja-wp-sync/v1', '/health', [
                'methods'             => 'GET',
                'callback'            => [$this, 'handle_health'],
                'permission_callback' => [$this->auth, 'permission_check'],
            ]);
        });
    }

    public function handle_health(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'mode'        => get_option('fylgja_slave_mode', 'active'),
            'version'     => defined('FYLGJA_VERSION') ? FYLGJA_VERSION : 'unknown',
            'wpml_active' => $this->is_wpml_active(),
        ], 200);
    }

    private function is_wpml_active(): bool {
        return defined('ICL_SITEPRESS_VERSION') || function_exists('wpml_get_active_languages_filter');
    }

    public function handle_receive(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();

        $action      = sanitize_text_field($body['action'] ?? '');
        $object_type = sanitize_text_field($body['object_type'] ?? '');
        $payload     = $body['payload'] ?? [];

        $version_check = $this->check_payload_version($payload);
        if ($version_check !== null) {
            return $version_check;
        }

        if (empty($action) || empty($object_type) || empty($payload)) {
            return new WP_REST_Response(['error' => 'Missing required fields'], 400);
        }

        $preview = $this->compute_preview($action, $object_type, $payload);
        $log_id  = $this->log->insert($action, $object_type, $payload, $preview);

        if (get_option('fylgja_slave_mode', 'active') === 'inspect') {
            return new WP_REST_Response(
                ['mode' => 'inspect', 'log_id' => $log_id, 'preview' => $preview],
                200
            );
        }

        $result = $this->apply_preview($preview);
        $this->log->mark_applied($log_id, $result);
        return new WP_REST_Response($result, $result['success'] ? 200 : 500);
    }

    public function handle_receive_batch(WP_REST_Request $request): WP_REST_Response {
        $items   = $request->get_json_params()['items'] ?? [];
        $results = [];

        foreach ($items as $item) {
            $action      = sanitize_text_field($item['action'] ?? '');
            $object_type = sanitize_text_field($item['object_type'] ?? '');
            $payload     = $item['payload'] ?? [];

            $version_check = $this->check_payload_version($payload);
            if ($version_check !== null) {
                $results[] = ['success' => false, 'error' => 'Unsupported payload_version'];
                continue;
            }

            $preview = $this->compute_preview($action, $object_type, $payload);
            $log_id  = $this->log->insert($action, $object_type, $payload, $preview);

            if (get_option('fylgja_slave_mode', 'active') === 'inspect') {
                $results[] = ['mode' => 'inspect', 'log_id' => $log_id];
                continue;
            }

            $result = $this->apply_preview($preview);
            $this->log->mark_applied($log_id, $result);
            $results[] = $result;
        }

        return new WP_REST_Response(['results' => $results], 200);
    }

    private function check_payload_version(array $payload): ?WP_REST_Response {
        $payload_version = (int) ($payload['payload_version'] ?? 0);
        if ($payload_version < 2) {
            return new WP_REST_Response(
                ['error' => "Unsupported payload_version: {$payload_version}. Slave requires >= 2."],
                400
            );
        }
        return null;
    }

    /**
     * Pure: builds a preview describing what would happen, without writes.
     */
    public function compute_preview(string $action, string $object_type, array $payload): array {
        $base = [
            'object_type' => $object_type,
            'action'      => $action,
            'source_id'   => (int) ($payload['source_id'] ?? 0),
            'payload'     => $payload,
            'warnings'    => [],
        ];

        // Tripwire (non-destructive): incoming meta should already be real data — arrays
        // and scalars. A value that arrives as a still-serialized STRING means the sender
        // didn't unserialize it (e.g. an unpatched master), and applying it as-is makes
        // update_*_meta double-serialize it, surfacing the blob as e.g. a literal CSS class.
        // We never rewrite it — a legitimately serialized-looking string is indistinguishable
        // from this bug — we only flag it so the mismatch shows in the log's Warnings column.
        if (!empty($payload['meta']) && is_array($payload['meta'])) {
            foreach ($payload['meta'] as $key => $value) {
                if (is_string($value) && is_serialized($value, false)) {
                    $base['warnings'][] = "Meta '{$key}' arrived as a serialized string; sender may be unpatched. Stored as-is.";
                }
            }
        }

        switch ($object_type) {
            case 'post':
                return $this->preview_post($action, $payload, $base);
            case 'attachment':
                return $this->preview_attachment($action, $payload, $base);
            case 'term':
                return $this->preview_term($action, $payload, $base);
            case 'string':
                return $this->preview_string($action, $payload, $base);
            default:
                $base['warnings'][] = "Unknown object_type: {$object_type}";
                $base['would_apply'] = false;
                return $base;
        }
    }

    /**
     * Performs the writes described by a preview.
     */
    public function apply_preview(array $preview): array {
        switch ($preview['object_type']) {
            case 'post':
                $result = $this->apply_post_preview($preview);
                break;
            case 'attachment':
                $result = $this->apply_attachment_preview($preview);
                break;
            case 'term':
                $result = $this->apply_term_preview($preview);
                break;
            case 'string':
                $result = $this->apply_string_preview($preview);
                break;
            default:
                return ['success' => false, 'error' => "Cannot apply unknown object_type: {$preview['object_type']}"];
        }

        if (!empty($result['success'])) {
            $this->maybe_sweep_deferred_refs();
        }

        return $result;
    }

    /** Seconds the inline post-apply sweep is suppressed after it runs. */
    private const INLINE_SWEEP_THROTTLE = 10;

    /**
     * Inline sweep after a successful apply, throttled so a bulk burst (resync pushing
     * ~1500 items) collapses the per-apply O(deferred-rows) sweep into an occasional pass
     * instead of running it on every single request — a major slave-side cost that helped
     * saturate it. A lone interactive edit still sweeps immediately (the throttle window
     * has long since expired), and the fylgja_sweep_deferred cron always does a full
     * unthrottled pass as the safety net.
     */
    private function maybe_sweep_deferred_refs(): void {
        if (get_transient('fylgja_inline_sweep_throttle')) {
            return;
        }
        set_transient('fylgja_inline_sweep_throttle', 1, self::INLINE_SWEEP_THROTTLE);
        $this->sweep_deferred_refs();
    }

    public function run_deferred_sweep(): void {
        $this->sweep_deferred_refs();
    }

    private function sweep_deferred_refs(): void {
        $storage = new Fylgja_Deferred_Refs();
        $lookup  = new Fylgja_Lookup();
        $rows    = $storage->pending_for_types_like(['term:%', 'post:%'], 500);

        foreach ($rows as $row) {
            $local_id = $this->resolve_deferred_target($row, $lookup);
            if ($local_id === null) {
                continue;
            }
            if ($this->apply_deferred_resolution($row, $local_id)) {
                $storage->delete((int) $row->id);
            }
        }
    }

    private function resolve_deferred_target(object $row, Fylgja_Lookup $lookup): ?int {
        if (str_starts_with($row->ref_object_type, 'term:')) {
            $taxonomy = substr($row->ref_object_type, 5);
            return $lookup->find_term_by_source_id((int) $row->ref_source_id, $taxonomy);
        }
        if (str_starts_with($row->ref_object_type, 'post:')) {
            return $lookup->find_post_by_source_id((int) $row->ref_source_id);
        }
        return null;
    }

    private function apply_deferred_resolution(object $row, int $resolved_local_id): bool {
        if ($row->ref_type === 'post_term_assignment') {
            $taxonomy = substr($row->ref_object_type, 5);
            wp_set_object_terms((int) $row->dependent_local_id, [$resolved_local_id], $taxonomy, true);
            return true;
        }
        if ($row->ref_type === 'menu_item_object') {
            update_post_meta((int) $row->dependent_local_id, '_menu_item_object_id', $resolved_local_id);
            wp_update_post(['ID' => (int) $row->dependent_local_id, 'post_status' => 'publish']);
            return true;
        }
        if ($row->ref_type === 'term_parent') {
            $hint     = $row->payload_hint ? json_decode($row->payload_hint, true) : [];
            $taxonomy = $hint['taxonomy'] ?? null;
            if (!$taxonomy) return false;
            wp_update_term((int) $row->dependent_local_id, $taxonomy, ['parent' => $resolved_local_id]);
            return true;
        }
        return false;
    }

    private function preview_post(string $action, array $payload, array $base): array {
        $source_id = $base['source_id'];

        if ($action === 'delete') {
            $local_id = $this->find_local_post($source_id);
            $base['would_delete']   = $local_id !== null;
            $base['local_id']       = $local_id;
            return $base;
        }

        $local_id = $this->find_local_post($source_id);
        $base['local_id']        = $local_id;
        $base['would_create']    = $local_id === null;

        $wpml_block = $payload['wpml'] ?? [];
        $element_type = $wpml_block['element_type'] ?? ('post_' . ($payload['post_type'] ?? 'post'));
        $base['wpml_plan']    = $this->mapper->plan($element_type, $wpml_block, $local_id);
        $base['element_type'] = $element_type;
        if (!empty($base['wpml_plan']['warnings'])) {
            $base['warnings'] = array_merge($base['warnings'], $base['wpml_plan']['warnings']);
        }

        return $base;
    }

    private function preview_attachment(string $action, array $payload, array $base): array {
        $source_id  = $base['source_id'];
        $source_url = $payload['source_url'] ?? '';

        if ($action === 'delete') {
            $local_id = $this->find_local_post($source_id);
            $base['would_delete'] = $local_id !== null;
            $base['local_id']     = $local_id;
            return $base;
        }

        $local_id = $this->find_local_post($source_id);
        $base['local_id']     = $local_id;
        $base['would_create'] = $local_id === null;
        $base['source_url']   = $source_url;
        if (empty($source_url)) {
            $base['warnings'][] = 'Missing source_url for attachment';
        }
        $wpml_block = $payload['wpml'] ?? [];
        $element_type = $wpml_block['element_type'] ?? ('post_' . ($payload['post_type'] ?? 'attachment'));
        $base['wpml_plan']    = $this->mapper->plan($element_type, $wpml_block, $local_id);
        $base['element_type'] = $element_type;
        if (!empty($base['wpml_plan']['warnings'])) {
            $base['warnings'] = array_merge($base['warnings'], $base['wpml_plan']['warnings']);
        }
        return $base;
    }

    private function preview_term(string $action, array $payload, array $base): array {
        $source_id = $base['source_id'];
        $taxonomy  = $payload['taxonomy'] ?? '';
        $lookup    = new Fylgja_Lookup();

        $local_id = $taxonomy ? $lookup->find_term_by_source_id($source_id, $taxonomy) : null;

        if ($action === 'delete') {
            $base['would_delete'] = $local_id !== null;
            $base['local_id']     = $local_id;
            return $base;
        }

        $base['local_id']     = $local_id;
        $base['would_create'] = $local_id === null;

        $wpml_block = $payload['wpml'] ?? [];
        $element_type = $wpml_block['element_type'] ?? ('tax_' . $taxonomy);
        $base['wpml_plan']    = $this->mapper->plan($element_type, $wpml_block, $local_id);
        $base['element_type'] = $element_type;
        if (!empty($base['wpml_plan']['warnings'])) {
            $base['warnings'] = array_merge($base['warnings'], $base['wpml_plan']['warnings']);
        }

        return $base;
    }

    private function apply_term_preview(array $preview): array {
        $action    = $preview['action'];
        $payload   = $preview['payload'];
        $source_id = $preview['source_id'];
        $taxonomy  = $payload['taxonomy'] ?? '';

        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            return ['success' => false, 'error' => "Unknown taxonomy: {$taxonomy}"];
        }

        $lookup = new Fylgja_Lookup();

        if ($action === 'delete') {
            $local_term_id = $lookup->find_term_by_source_id($source_id, $taxonomy);
            if (!$local_term_id) {
                return ['success' => true, 'message' => 'Already deleted or not found'];
            }
            $result = wp_delete_term($local_term_id, $taxonomy);
            if (is_wp_error($result) || $result === false) {
                return ['success' => false, 'error' => 'wp_delete_term failed'];
            }
            return ['success' => true, 'deleted_local_id' => $local_term_id];
        }

        $local_id = $preview['local_id'] ?? $lookup->find_term_by_source_id($source_id, $taxonomy);

        // Clone-slave adoption: a slave cloned from the master already holds these
        // terms but without a _fylgja_source_id stamp, so the source-id lookup
        // misses and resync would insert a duplicate. Match the pre-existing term
        // by WPML language + slug and adopt it (the stamp below claims it). The
        // language scoping is what makes this safe — a slug-only match was reverted
        // earlier because it could adopt the wrong-language sibling and corrupt it.
        if (!$local_id) {
            $local_id = $this->adopt_unstamped_term(
                $taxonomy,
                $payload['wpml']['language_code'] ?? null,
                sanitize_title($payload['slug'] ?? '')
            );
        }

        $parent_local_id = 0;
        $parent_source_id = (int) ($payload['parent_source_id'] ?? 0);
        $needs_deferred_parent = false;
        if ($parent_source_id > 0) {
            $resolved_parent = $lookup->find_term_by_source_id($parent_source_id, $taxonomy);
            if ($resolved_parent) {
                $parent_local_id = $resolved_parent;
            } else {
                $needs_deferred_parent = true;
            }
        }

        $target_lang = $preview['wpml_plan']['language_code'] ?? null;
        // Mirror the master verbatim: the slave replicates the master's slug as-is
        // rather than re-deriving a "unique" one. The master is the source of truth and
        // already guarantees a valid slug; WPML legitimately lets a term and its
        // translations share one slug (disambiguated by language), so re-uniquifying
        // here only drifts the slave away from the master (the old <slug>-2 / <slug>-{lang}).
        $slug        = sanitize_title(($payload['slug'] ?? '') !== '' ? $payload['slug'] : ($payload['name'] ?? ''));
        $description = wp_kses_post($payload['description'] ?? '');

        // Run inserts AND updates under the term's own language. WPML's get_term
        // filter (get_term_adjust_id) rewrites the id returned by get_term_by to
        // the *current-language* sibling of the trid group. wp_update_term's
        // duplicate-slug check (get_term_by('slug',...) !== term_id) then compares
        // against the wrong sibling and raises a phantom duplicate_term_slug. WPML
        // disables that adjustment while wp_update_term is on the stack, but the
        // guard only scans ~19 backtrace frames and our REST/resync stack is deeper,
        // so the guard misses. Switching to the target language makes the adjustment
        // a no-op (the term resolves to itself), which is exactly what lets the shared
        // master slug be accepted verbatim. It must therefore run for ANY target
        // language, not only when there is trid work to do — an already-mapped clone
        // term reports trid_action 'none' yet still needs the switch to keep its slug.
        $switch_lang = (bool) $target_lang;
        if ($switch_lang) {
            $prev_lang = apply_filters('wpml_current_language', null);
            do_action('wpml_switch_language', $target_lang);
        }
        try {
            $args = [
                'slug'        => $slug,
                'description' => $description,
                'parent'      => $parent_local_id,
            ];
            if ($local_id) {
                $result = wp_update_term($local_id, $taxonomy, ['name' => $payload['name']] + $args);
            } else {
                $result = wp_insert_term($payload['name'], $taxonomy, $args);
                if (is_wp_error($result)) {
                    // Adopt an existing same-identity term instead of failing. An install
                    // synced before source-id stamping was reliable can already hold this
                    // term under a different/absent _fylgja_source_id, so the insert
                    // collides (term_exists). Update it in place with the same verbatim
                    // slug; the source-id stamp below then claims it for future lookups.
                    $existing_id = $this->existing_term_id_from_error($result);
                    if ($existing_id > 0) {
                        $local_id = $existing_id;
                        $result = wp_update_term($local_id, $taxonomy, ['name' => $payload['name']] + $args);
                    }
                }
            }
        } finally {
            if ($switch_lang) {
                do_action('wpml_switch_language', $prev_lang);
            }
        }

        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        $local_id = (int) ($result['term_id'] ?? $local_id);
        update_term_meta($local_id, '_fylgja_source_id', $source_id);

        if (!empty($payload['meta'])) {
            foreach ($payload['meta'] as $key => $value) {
                if (str_starts_with($key, '_fylgja_') || str_starts_with($key, '_wpml_') || $key === '_icl_translation_id') {
                    continue;
                }
                update_term_meta($local_id, $key, $value);
            }
        }

        if (!empty($preview['wpml_plan']) && $preview['wpml_plan']['trid_action'] !== 'none') {
            $plan_with_source = $preview['wpml_plan'];
            $plan_with_source['source_trid'] = (int) ($payload['wpml']['source_trid'] ?? 0);
            // WPML keys term translations on term_taxonomy_id, not term_id.
            $local_term = get_term($local_id, $taxonomy);
            if ($local_term && !is_wp_error($local_term)) {
                $this->mapper->attach((int) $local_term->term_taxonomy_id, $preview['element_type'], $plan_with_source);
            }
        }

        // Mirror the master term slug verbatim now that the language/trid is attached.
        $this->enforce_master_term_slug($local_id, $taxonomy, $slug);

        if ($needs_deferred_parent) {
            (new Fylgja_Deferred_Refs())->insert(
                'term_parent',
                $local_id,
                "term:{$taxonomy}",
                $parent_source_id,
                ['taxonomy' => $taxonomy]
            );
        }

        return ['success' => true, 'local_id' => $local_id, 'source_id' => $source_id];
    }

    /**
     * Resolves the existing local term id from a wp_insert_term collision so the
     * caller can adopt it. Only WP's `term_exists` error is safe to act on — it
     * carries the colliding term id as its error data, and that term is the same
     * one we meant to write. Slug-conflict errors are NOT handled here: they fire
     * for cross-language translation slug clashes, where the slug-matching term is
     * the wrong-language sibling and adopting it would corrupt it (see the open
     * translation-slug follow-up).
     */
    private function existing_term_id_from_error(WP_Error $error): int {
        $data = $error->get_error_data();
        if (is_numeric($data) && (int) $data > 0) {
            return (int) $data;
        }
        if (is_array($data) && !empty($data['term_id'])) {
            return (int) $data['term_id'];
        }
        return 0;
    }

    /**
     * Finds a pre-existing, unstamped slave term to adopt during sync, matched by
     * taxonomy + WPML language + slug. Used when the _fylgja_source_id lookup
     * misses on a slave cloned from the master. Language-scoped so it never picks
     * a wrong-language sibling; the meta_id IS NULL guard prevents hijacking a term
     * already mapped to another source id.
     */
    private function adopt_unstamped_term(string $taxonomy, ?string $language_code, string $slug): ?int {
        if (!$language_code || $slug === '' || !$this->is_wpml_active()) {
            return null;
        }
        global $wpdb;
        $term_id = $wpdb->get_var($wpdb->prepare(
            "SELECT t.term_id
             FROM {$wpdb->terms} t
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             JOIN {$wpdb->prefix}icl_translations tr
                  ON tr.element_id = tt.term_taxonomy_id
                 AND tr.element_type = %s
             LEFT JOIN {$wpdb->termmeta} tm
                  ON tm.term_id = t.term_id AND tm.meta_key = '_fylgja_source_id'
             WHERE tt.taxonomy = %s
               AND tr.language_code = %s
               AND t.slug = %s
               AND tm.meta_id IS NULL
             LIMIT 1",
            'tax_' . $taxonomy,
            $taxonomy,
            $language_code,
            $slug
        ));
        return $term_id === null ? null : (int) $term_id;
    }

    private function preview_string(string $action, array $payload, array $base): array {
        $identity_hash = md5(
            ($payload['context'] ?? '')
            . ($payload['name'] ?? '')
            . ($payload['gettext_context'] ?? '')
        );
        $base['identity_md5']       = $identity_hash;
        $base['local_string_id']    = $this->find_local_string_id($identity_hash);
        $base['would_create']       = $base['local_string_id'] === null;
        $base['translations_count'] = count($payload['translations'] ?? []);
        return $base;
    }

    private function find_local_string_id(string $md5): ?int {
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}icl_strings WHERE domain_name_context_md5 = %s LIMIT 1",
            $md5
        ));
        return $row === null ? null : (int) $row;
    }

    private function apply_string_preview(array $preview): array {
        global $wpdb;
        $payload = $preview['payload'];
        $local_string_id = $preview['local_string_id'] ?? null;

        $string_data = [
            'value'       => $payload['value'] ?? '',
            'status'      => (int) ($payload['status'] ?? 0),
            'string_type' => (int) ($payload['string_type'] ?? 0),
            'wrap_tag'    => $payload['wrap_tag'] ?? '',
        ];

        if ($local_string_id) {
            $wpdb->update("{$wpdb->prefix}icl_strings", $string_data, ['id' => $local_string_id]);
        } else {
            $wpdb->insert("{$wpdb->prefix}icl_strings", $string_data + [
                'language'                => 'en',
                'context'                 => $payload['context'] ?? '',
                'name'                    => $payload['name'] ?? '',
                'gettext_context'         => $payload['gettext_context'] ?? '',
                'domain_name_context_md5' => $preview['identity_md5'],
                'translation_priority'    => '',
            ]);
            $local_string_id = (int) $wpdb->insert_id;
        }

        foreach (($payload['translations'] ?? []) as $lang => $t) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}icl_string_translations (string_id, language, status, value)
                 VALUES (%d, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), value = VALUES(value)",
                $local_string_id,
                $lang,
                (int) ($t['status'] ?? 10),
                $t['value'] ?? ''
            ));
        }

        return ['success' => true, 'local_id' => $local_string_id, 'source_id' => $preview['source_id']];
    }

    private function apply_post_preview(array $preview): array {
        $action  = $preview['action'];
        $payload = $preview['payload'];
        $source_id = $preview['source_id'];

        if ($action === 'delete') {
            return $this->delete_synced_post($source_id);
        }

        $local_id = $preview['local_id'];

        // Clone-slave adoption (mirrors the term path in apply_term_preview): a slave
        // cloned from the master already holds this post but without a _fylgja_source_id
        // stamp, so the source-id lookup misses and resync would insert a duplicate.
        // Match the pre-existing post by WPML language + slug and adopt it (the stamp
        // below claims it). Language scoping is what makes this safe — it never adopts a
        // wrong-language sibling.
        if (!$local_id) {
            $local_id = $this->adopt_unstamped_post(
                sanitize_text_field($payload['post_type'] ?? 'post'),
                $payload['wpml']['language_code'] ?? null,
                sanitize_title($payload['post_name'] ?? '')
            );
        }

        $post_data = [
            'post_title'   => sanitize_text_field($payload['post_title'] ?? ''),
            'post_content' => wp_kses_post($payload['post_content'] ?? ''),
            'post_status'  => sanitize_text_field($payload['post_status'] ?? 'publish'),
            'post_type'    => sanitize_text_field($payload['post_type'] ?? 'post'),
            'post_name'    => sanitize_title($payload['post_name'] ?? ''),
            'post_excerpt' => wp_kses_post($payload['post_excerpt'] ?? ''),
            'menu_order'   => (int) ($payload['menu_order'] ?? 0),
        ];

        if ($local_id) {
            $post_data['ID'] = $local_id;
            $result_id = wp_update_post($post_data, true);
        } else {
            $result_id = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result_id)) {
            return ['success' => false, 'error' => $result_id->get_error_message()];
        }

        update_post_meta($result_id, '_fylgja_source_id', $source_id);

        // Attach WPML language/trid if WPML is active and payload includes the block.
        if (!empty($preview['wpml_plan']) && $preview['wpml_plan']['trid_action'] !== 'none') {
            $plan_with_source = $preview['wpml_plan'];
            $plan_with_source['source_trid'] = (int) ($payload['wpml']['source_trid'] ?? 0);
            $this->mapper->attach($result_id, $preview['element_type'], $plan_with_source);
        }

        // Mirror the master post_name verbatim now that the language is attached.
        $this->enforce_master_post_name($result_id, $payload['post_name'] ?? '');

        if (!empty($payload['meta'])) {
            $this->sync_post_meta($result_id, $payload['meta']);
        }

        if (!empty($payload['terms'])) {
            $this->sync_post_terms($result_id, $payload['terms']);
        }

        if (($payload['post_type'] ?? '') === 'nav_menu_item') {
            $this->rewrite_menu_item_object($result_id);
        }

        return ['success' => true, 'local_id' => $result_id, 'source_id' => $source_id];
    }

    private function rewrite_menu_item_object(int $local_id): void {
        $menu_item_object = get_post_meta($local_id, '_menu_item_object', true);
        $source_object_id = (int) get_post_meta($local_id, '_menu_item_object_id', true);

        // 'custom' resolves by URL; 'post_type_archive' resolves by post-type slug and
        // carries a 0/negative sentinel object id. Neither has a real object to remap —
        // leave them published, never defer. (A real post/term ref is always > 0.)
        if ($menu_item_object === 'custom' || $source_object_id <= 0) {
            return;
        }

        $lookup = new Fylgja_Lookup();
        $type   = get_post_meta($local_id, '_menu_item_type', true);
        $local_object_id = null;

        if ($type === 'taxonomy') {
            $local_object_id = $lookup->find_term_by_source_id($source_object_id, $menu_item_object);
        } elseif ($type === 'post_type' || $type === '') {
            $local_object_id = $lookup->find_post_by_source_id($source_object_id);
        }

        if ($local_object_id) {
            update_post_meta($local_id, '_menu_item_object_id', $local_object_id);
            if (get_post_status($local_id) === 'draft') {
                wp_update_post(['ID' => $local_id, 'post_status' => 'publish']);
            }
            return;
        }

        wp_update_post(['ID' => $local_id, 'post_status' => 'draft']);
        $ref_object_type = $type === 'taxonomy'
            ? "term:{$menu_item_object}"
            : "post:{$menu_item_object}";
        (new Fylgja_Deferred_Refs())->insert(
            'menu_item_object',
            $local_id,
            $ref_object_type,
            $source_object_id
        );
    }

    private function apply_attachment_preview(array $preview): array {
        $action  = $preview['action'];
        $payload = $preview['payload'];
        $source_id  = $preview['source_id'];
        $source_url = $preview['source_url'] ?? '';

        if ($action === 'delete') {
            return $this->delete_synced_post($source_id);
        }

        if (empty($source_url)) {
            return ['success' => false, 'error' => 'Missing source_url for attachment'];
        }

        $local_id = $preview['local_id'];

        // Clone-slave adoption (same logic as posts/terms — an attachment is a post):
        // a slave cloned from the master already holds this attachment without a
        // _fylgja_source_id stamp, so the source-id lookup misses. Adopt the pre-existing
        // attachment by WPML language + slug instead of re-downloading the file as a
        // duplicate. Language scoping is required: the same filename slug exists once per
        // language.
        if (!$local_id) {
            $local_id = $this->adopt_unstamped_post(
                'attachment',
                $payload['wpml']['language_code'] ?? null,
                sanitize_title($payload['post_name'] ?? '')
            );
        }

        if (!$local_id) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $tmp = download_url($source_url);
            if (is_wp_error($tmp)) {
                return ['success' => false, 'error' => $tmp->get_error_message()];
            }

            $file_array = [
                'name'     => basename(wp_parse_url($source_url, PHP_URL_PATH)),
                'tmp_name' => $tmp,
            ];

            $attachment_id = media_handle_sideload($file_array, 0);
            if (is_wp_error($attachment_id)) {
                @unlink($tmp);
                return ['success' => false, 'error' => $attachment_id->get_error_message()];
            }

            $local_id = $attachment_id;
        }

        // Stamp the source id on whichever attachment we ended up with — sideloaded
        // OR adopted — so future source-id lookups resolve directly (mirrors the post path).
        update_post_meta($local_id, '_fylgja_source_id', $source_id);

        // Attach WPML language/trid if WPML is active and payload includes the block.
        if (!empty($preview['wpml_plan']) && $preview['wpml_plan']['trid_action'] !== 'none') {
            $plan_with_source = $preview['wpml_plan'];
            $plan_with_source['source_trid'] = (int) ($payload['wpml']['source_trid'] ?? 0);
            $this->mapper->attach($local_id, $preview['element_type'], $plan_with_source);
        }

        // Mirror the master post_name verbatim now that the language is attached.
        $this->enforce_master_post_name($local_id, $payload['post_name'] ?? '');

        if (!empty($payload['meta'])) {
            $this->sync_post_meta($local_id, $payload['meta']);
        }

        return ['success' => true, 'local_id' => $local_id, 'source_id' => $source_id];
    }

    /**
     * Mirrors the master's post_name verbatim. wp_insert_post()/wp_update_post() route the
     * slug through wp_unique_post_slug(), which WPML only language-scopes once the post HAS
     * a language — so a freshly-inserted translation lands as "<slug>-2" (its bare master
     * slug collides with a sibling translation in another language). Once the WPML language
     * is attached, re-applying the master post_name resolves under the correct language and
     * keeps it verbatim. A no-op on the adopt/update path, where it already matches.
     */
    private function enforce_master_post_name(int $post_id, string $master_name): void {
        $master_name = sanitize_title($master_name);
        if ($master_name === '' || get_post_field('post_name', $post_id) === $master_name) {
            return;
        }
        wp_update_post(['ID' => $post_id, 'post_name' => $master_name]);
    }

    /**
     * Mirrors the master term slug verbatim. wp_insert_term() routes a new term's slug
     * through wp_unique_term_slug(), which WPML can only language-scope once the term HAS
     * a language — but the WPML language/trid is attached only AFTER the insert, so a
     * fresh translation lands as "<slug>-2" (its bare master slug collides with a sibling
     * translation). Re-applying the slug here writes it directly: wp_update_term's
     * provided-slug duplicate check still resolves the not-yet-renamed term against its
     * wrong-language sibling, and the master is the source of truth, so the verbatim slug
     * is authoritative. A no-op on the update/adopt path, where the slug already matches.
     */
    private function enforce_master_term_slug(int $term_id, string $taxonomy, string $master_slug): void {
        global $wpdb;
        $master_slug = sanitize_title($master_slug);
        if ($master_slug === '') {
            return;
        }
        $current = $wpdb->get_var($wpdb->prepare("SELECT slug FROM {$wpdb->terms} WHERE term_id = %d", $term_id));
        if ((string) $current === $master_slug) {
            return;
        }
        $wpdb->update($wpdb->terms, ['slug' => $master_slug], ['term_id' => $term_id]);
        clean_term_cache($term_id, $taxonomy);
    }

    private function find_local_post(int $source_id): ?int {
        // Direct postmeta lookup — must match ALL post types. get_posts('any')
        // silently excludes attachments and any CPT registered with
        // exclude_from_search=true, which made re-syncs of those types insert
        // duplicates instead of updating in place.
        return (new Fylgja_Lookup())->find_post_by_source_id($source_id);
    }

    /**
     * Finds a pre-existing, unstamped slave post to adopt during sync, matched by
     * post_type + WPML language + slug. The post-side analogue of
     * adopt_unstamped_term(): used when the _fylgja_source_id lookup misses on a slave
     * cloned from the master. Language-scoped so it never picks a wrong-language
     * sibling; the meta_id IS NULL guard prevents hijacking a post already mapped to
     * another source id.
     */
    private function adopt_unstamped_post(string $post_type, ?string $language_code, string $slug): ?int {
        if (!$language_code || $slug === '' || !$this->is_wpml_active()) {
            return null;
        }
        global $wpdb;
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}icl_translations tr
                  ON tr.element_id = p.ID
                 AND tr.element_type = %s
             LEFT JOIN {$wpdb->postmeta} pm
                  ON pm.post_id = p.ID AND pm.meta_key = '_fylgja_source_id'
             WHERE p.post_type = %s
               AND tr.language_code = %s
               AND p.post_name = %s
               AND pm.meta_id IS NULL
             LIMIT 1",
            'post_' . $post_type,
            $post_type,
            $language_code,
            $slug
        ));
        return $post_id === null ? null : (int) $post_id;
    }

    private function delete_synced_post(int $source_id): array {
        $local_id = $this->find_local_post($source_id);
        if (!$local_id) {
            return ['success' => true, 'message' => 'Already deleted or not found'];
        }
        $deleted = wp_delete_post($local_id, true);
        if (!$deleted) {
            return ['success' => false, 'error' => "Failed to delete local post {$local_id}"];
        }
        return ['success' => true, 'deleted_local_id' => $local_id];
    }

    private function sync_post_meta(int $post_id, array $meta, bool $is_create = false): void {
        foreach ($meta as $key => $value) {
            if (str_starts_with($key, '_fylgja_')
                || str_starts_with($key, '_wp_')
                || str_starts_with($key, '_edit_')
                || $key === '_pingme'
            ) {
                continue;
            }
            // Site-local placement: seed only when the slave copy is first created;
            // never overwrite it on later syncs, so operator curation of the slave
            // homepage grid survives edits and Resync All.
            if (in_array($key, self::SITE_LOCAL_META, true) && !$is_create) {
                continue;
            }
            update_post_meta($post_id, $key, $value);
        }
    }

    private function sync_post_terms(int $post_id, array $terms_by_taxonomy): void {
        $lookup   = new Fylgja_Lookup();
        $deferred = new Fylgja_Deferred_Refs();

        foreach ($terms_by_taxonomy as $taxonomy => $source_term_ids) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $local_term_ids = [];
            $unresolved     = [];

            foreach ($source_term_ids as $source_term_id) {
                $local_term_id = $lookup->find_term_by_source_id((int) $source_term_id, $taxonomy);
                if ($local_term_id) {
                    $local_term_ids[] = $local_term_id;
                } else {
                    $unresolved[] = (int) $source_term_id;
                }
            }

            wp_set_object_terms($post_id, $local_term_ids, $taxonomy);

            foreach ($unresolved as $source_term_id) {
                $deferred->insert(
                    'post_term_assignment',
                    $post_id,
                    "term:{$taxonomy}",
                    $source_term_id
                );
            }
        }
    }
}
