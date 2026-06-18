<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_Resync {

    private const OPTION = 'fylgja_resync_state';
    private const BATCH  = 25;
    private const PHASES = ['terms', 'posts', 'strings'];

    private Fylgja_Queue $queue;
    private Fylgja_Pusher $pusher;

    public function __construct(Fylgja_Queue $queue, Fylgja_Pusher $pusher) {
        $this->queue  = $queue;
        $this->pusher = $pusher;
    }

    public function start(): array {
        $state = $this->get_state();
        if (!empty($state['in_progress'])) {
            return ['ok' => false, 'error' => 'already_running'];
        }
        $totals = [
            'terms'   => $this->count_terms(),
            'posts'   => $this->count_posts(),
            'strings' => $this->count_strings(),
        ];
        $state = [
            'in_progress' => true,
            'phase'       => 'terms',
            'cursor'      => 0,
            'totals'      => $totals,
            'pushed'      => ['terms' => 0, 'posts' => 0, 'strings' => 0],
            'started_at'  => gmdate('c'),
        ];
        $this->set_state($state);
        $this->schedule_next_tick();
        return ['ok' => true, 'state' => $state];
    }

    public function cancel(): void {
        $state = $this->get_state();
        $state['in_progress'] = false;
        $this->set_state($state);
        wp_clear_scheduled_hook('fylgja_resync_tick');
    }

    public function resume(): array {
        $state = $this->get_state();
        if (empty($state) || empty($state['phase'])) {
            return ['ok' => false, 'error' => 'no_state_to_resume'];
        }
        if (!empty($state['in_progress'])) {
            return ['ok' => false, 'error' => 'already_running'];
        }
        $state['in_progress'] = true;
        $this->set_state($state);
        $this->schedule_next_tick();
        return ['ok' => true, 'state' => $state];
    }

    public function tick(): array {
        $state = $this->get_state();
        if (empty($state) || empty($state['in_progress'])) {
            return ['ok' => false, 'error' => 'not_running'];
        }

        $batch_default = (int) get_option('fylgja_resync_batch_size', self::BATCH);
        $batch_size    = (int) apply_filters('fylgja_resync_batch_size', $batch_default);

        switch ($state['phase']) {
            case 'terms':
                $result = $this->push_term_batch($state, $batch_size);
                break;
            case 'posts':
                $result = $this->push_post_batch($state, $batch_size);
                break;
            case 'strings':
                $result = $this->push_string_batch($state, $batch_size);
                break;
            default:
                $result = ['pushed' => 0, 'examined' => 0];
        }

        $state['pushed'][$state['phase']] += $result['pushed'];

        // Drain what this tick enqueued without waiting for a manual Sync Now.
        if ($result['pushed'] > 0) {
            $this->pusher->ensure_flush_scheduled();
        }

        // Exhaustion is keyed on rows *examined*, not pushed: the string phase
        // skips untranslated rows (the common case), so counting pushes would
        // end the phase prematurely on a batch that's mostly skips.
        if ($result['examined'] < $batch_size) {
            $state = $this->advance_phase($state);
        }

        $this->set_state($state);

        if ($state['in_progress']) {
            $this->schedule_next_tick();
        }

        return ['ok' => true, 'state' => $state, 'pushed_this_tick' => $result['pushed']];
    }

    private function advance_phase(array $state): array {
        $idx = array_search($state['phase'], self::PHASES, true);
        if ($idx === false || $idx === count(self::PHASES) - 1) {
            $state['in_progress'] = false;
            $state['phase']       = 'done';
            return $state;
        }
        $state['phase']  = self::PHASES[$idx + 1];
        $state['cursor'] = 0;
        return $state;
    }

    private function schedule_next_tick(): void {
        if (!wp_next_scheduled('fylgja_resync_tick')) {
            wp_schedule_single_event(time() + 60, 'fylgja_resync_tick');
        }
    }

    public function get_state(): array {
        $state = get_option(self::OPTION, []);
        return is_array($state) ? $state : [];
    }

    private function set_state(array $state): void {
        update_option(self::OPTION, $state, false /* autoload=no */);
    }

    private function count_terms(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->terms}");
    }

    private function count_posts(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','future','pending')"
        );
    }

    private function count_strings(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'icl_strings';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE string_package_id IS NULL");
    }

    /** @return array{pushed:int,examined:int} */
    private function push_term_batch(array &$state, int $batch_size): array {
        global $wpdb;
        $cursor = (int) ($state['cursor'] ?? 0);
        $term_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->terms} WHERE term_id > %d ORDER BY term_id ASC LIMIT %d",
            $cursor, $batch_size
        ));

        $pushed = 0;
        foreach ($term_ids as $term_id) {
            $term_id = (int) $term_id;
            // get_term() is WPML-language-sensitive: under a single ambient language
            // it redirects a translation's id to its current-language sibling, so a
            // resync would silently drop every non-default-language term. get_instance
            // bypasses the get_term filter and returns the exact term requested.
            $term = WP_Term::get_instance($term_id);
            if (is_wp_error($term) || !$term) {
                $state['cursor'] = $term_id;
                continue;
            }
            $payload = $this->pusher->serialize_term($term);
            $this->queue->enqueue('upsert', 'term', $term_id, $payload, 'resync');
            $state['cursor'] = $term_id;
            $pushed++;
        }
        return ['pushed' => $pushed, 'examined' => count($term_ids)];
    }

    /** @return array{pushed:int,examined:int} */
    private function push_post_batch(array &$state, int $batch_size): array {
        global $wpdb;
        $cursor = (int) ($state['cursor'] ?? 0);
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE ID > %d
               AND post_status IN ('publish','draft','private','future','pending')
             ORDER BY ID ASC LIMIT %d",
            $cursor, $batch_size
        ));

        $pushed = 0;
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $post = get_post($post_id);
            if (!$post) {
                $state['cursor'] = $post_id;
                continue;
            }
            $payload = $this->pusher->serialize_post($post);
            $this->queue->enqueue('upsert', 'post', $post_id, $payload, 'resync');
            $state['cursor'] = $post_id;
            $pushed++;
        }
        return ['pushed' => $pushed, 'examined' => count($post_ids)];
    }

    /** @return array{pushed:int,examined:int} */
    private function push_string_batch(array &$state, int $batch_size): array {
        global $wpdb;
        $cursor = (int) ($state['cursor'] ?? 0);
        $strings_table = $wpdb->prefix . 'icl_strings';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, context, name, gettext_context, value, status, string_type, wrap_tag
             FROM {$strings_table}
             WHERE id > %d AND string_package_id IS NULL
             ORDER BY id ASC LIMIT %d",
            $cursor, $batch_size
        ));

        $detector = new Fylgja_String_Detector($this->queue);
        $pushed = 0;
        foreach ($rows as $s) {
            $translations_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT language, value, status
                 FROM {$wpdb->prefix}icl_string_translations
                 WHERE string_id = %d",
                $s->id
            ));
            $translations = [];
            foreach ($translations_rows as $t) {
                $translations[$t->language] = ['value' => $t->value, 'status' => (int) $t->status];
            }

            $state['cursor'] = (int) $s->id;

            $payload = $detector->build_payload($s, $translations);
            if ($payload === null) {
                continue;
            }
            $this->queue->enqueue('upsert', 'string', (int) $s->id, $payload, 'resync');
            $pushed++;
        }
        return ['pushed' => $pushed, 'examined' => count($rows)];
    }
}
