<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_String_Detector {

    private Fylgja_Queue $queue;
    private string $option = 'fylgja_string_hashes';

    public function __construct(Fylgja_Queue $queue) {
        $this->queue = $queue;
    }

    public function detect_and_enqueue(): array {
        global $wpdb;

        $hashes = get_option($this->option, []);
        if (!is_array($hashes)) $hashes = [];

        $strings = $wpdb->get_results(
            "SELECT id, context, name, gettext_context, value, status, string_type, wrap_tag
             FROM {$wpdb->prefix}icl_strings
             WHERE string_package_id IS NULL"
        );

        if (!is_array($strings)) {
            return ['changed' => 0, 'total' => 0];
        }

        $changed = 0;
        foreach ($strings as $s) {
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

            $payload = $this->build_payload($s, $translations);
            if ($payload === null) {
                continue;
            }

            $hash = sha1($s->value . '|' . $s->status . '|' . serialize($translations));
            if (($hashes[$s->id] ?? null) === $hash) {
                continue;
            }

            $this->queue->enqueue('upsert', 'string', (int) $s->id, $payload);
            $hashes[$s->id] = $hash;
            $changed++;
        }

        update_option($this->option, $hashes, false);

        return ['changed' => $changed, 'total' => count($strings)];
    }

    /**
     * @internal Returns null when the string carries no translations (skip):
     * untranslated source strings self-register on the slave from the same
     * theme/plugin code, so syncing them only floods the queue with noise.
     */
    public function build_payload(object $s, array $translations): ?array {
        if (empty($translations)) {
            return null;
        }
        return [
            'payload_version'  => 2,
            'source_id'        => (int) $s->id,
            'context'          => $s->context,
            'name'             => $s->name,
            'gettext_context'  => $s->gettext_context,
            'value'            => $s->value,
            'status'           => (int) $s->status,
            'string_type'      => (int) $s->string_type,
            'wrap_tag'         => $s->wrap_tag,
            'translations'     => $translations,
        ];
    }
}
