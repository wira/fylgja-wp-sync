<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Master-side poller: fetches slave's GET /health and caches in a transient.
 *
 * Without caching, every admin page load that needs the banner would block on
 * a remote HTTP call. The 60s TTL bounds that to once-per-minute worst case;
 * the 5s timeout caps a single fetch when the slave is unreachable.
 */
class Fylgja_Health_Poller {

    private const TRANSIENT = 'fylgja_slave_health';
    private const TTL       = 60;

    public function get(bool $force_refresh = false): ?array {
        if (!$force_refresh) {
            $cached = get_transient(self::TRANSIENT);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $fresh = $this->fetch();
        if ($fresh !== null) {
            set_transient(self::TRANSIENT, $fresh, self::TTL);
        }
        return $fresh;
    }

    private function fetch(): ?array {
        $remote_url = get_option('fylgja_remote_url');
        $api_key    = get_option('fylgja_api_key');
        if (!$remote_url || !$api_key) {
            return null;
        }

        $response = wp_remote_get(
            trailingslashit($remote_url) . 'wp-json/fylgja-wp-sync/v1/health',
            [
                'timeout' => 5,
                'headers' => ['X-Fylgja-Api-Key' => $api_key],
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return ['reachable' => false];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return ['reachable' => false];
        }
        $body['reachable'] = true;
        return $body;
    }
}
