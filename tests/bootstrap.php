<?php
/**
 * PHPUnit bootstrap.
 *
 * Brain Monkey stubs WordPress functions so unit tests run without booting WP.
 * Patchwork must load BEFORE composer's autoloader so it can intercept PHP
 * internals (e.g. `function_exists`) listed in `patchwork.json`.
 */

require_once __DIR__ . '/../vendor/antecedent/patchwork/Patchwork.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Define ABSPATH so plugin files don't exit when included in tests.
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wp-fake/');
}

// Plugin constants typically defined by WP.
if (!defined('FYLGJA_VERSION')) {
    define('FYLGJA_VERSION', '0.2.0-dev');
}
if (!defined('FYLGJA_PLUGIN_DIR')) {
    define('FYLGJA_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('FYLGJA_PLUGIN_URL')) {
    define('FYLGJA_PLUGIN_URL', 'https://example.com/wp-content/plugins/fylgja-wp-sync/');
}
