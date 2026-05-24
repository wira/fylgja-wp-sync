<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_Auth {

    private const HEADER_NAME = 'X-Fylgja-Api-Key';

    public function get_api_key(): string {
        return get_option('fylgja_api_key', '');
    }

    public function generate_api_key(): string {
        $key = wp_generate_password(40, false);
        update_option('fylgja_api_key', $key, false);
        return $key;
    }

    public function add_auth_headers(array $args): array {
        $args['headers'][self::HEADER_NAME] = $this->get_api_key();
        return $args;
    }

    public function validate_request(WP_REST_Request $request): bool {
        $provided = $request->get_header('x_fylgja_api_key');
        $stored = $this->get_api_key();

        if (empty($stored) || empty($provided)) {
            return false;
        }

        return hash_equals($stored, $provided);
    }

    public function permission_check(WP_REST_Request $request): bool|WP_Error {
        if (!$this->validate_request($request)) {
            return new WP_Error(
                'fylgja_unauthorized',
                'Invalid or missing API key.',
                ['status' => 401]
            );
        }
        return true;
    }
}
