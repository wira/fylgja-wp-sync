<?php
/**
 * Plugin Name: Fylgja WP Sync
 * Description: Master/slave content synchronization between WordPress sites via REST API.
 * Version: 1.0.0
 * Author: Wira Ciputra
 * Text Domain: fylgja-wp-sync
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FYLGJA_VERSION', '1.0.0');
define('FYLGJA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FYLGJA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FYLGJA_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once FYLGJA_PLUGIN_DIR . 'includes/class-queue.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-queue-collapser.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-flush-guard.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-auth.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-admin.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-receiver.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-pusher.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-trid-map.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-sync-log.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-deferred-refs.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-lookup.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-wpml-mapper.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-wpml-collector.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-string-detector.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-health-poller.php';
require_once FYLGJA_PLUGIN_DIR . 'includes/class-resync.php';

register_activation_hook(__FILE__, 'fylgja_activate');
register_deactivation_hook(__FILE__, 'fylgja_deactivate');

function fylgja_activate(): void {
    Fylgja_Queue::create_table();
    Fylgja_Trid_Map::create_table();
    Fylgja_Sync_Log::create_table();
    Fylgja_Deferred_Refs::create_table();
}

function fylgja_deactivate(): void {
    // No-op. Tables persist across deactivation; drop only on uninstall.
}

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['fylgja_five_minutes'])) {
        $schedules['fylgja_five_minutes'] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
    }
    return $schedules;
});

add_action('fylgja_push_strings', function () {
    $queue    = new Fylgja_Queue();
    $detector = new Fylgja_String_Detector($queue);
    $detector->detect_and_enqueue();
});

add_action('fylgja_resync_tick', function () {
    $queue  = new Fylgja_Queue();
    $pusher = new Fylgja_Pusher($queue, new Fylgja_Auth());
    (new Fylgja_Resync($queue, $pusher))->tick();
});

add_action('init', function () {
    $role = get_option('fylgja_role', 'disabled');

    if ($role === 'master') {
        $queue = new Fylgja_Queue();
        $auth = new Fylgja_Auth();
        $pusher = new Fylgja_Pusher($queue, $auth);
        $pusher->register_hooks();

        if (!wp_next_scheduled('fylgja_push_strings')) {
            wp_schedule_event(time() + 300, 'fylgja_five_minutes', 'fylgja_push_strings');
        }
    }

    if ($role === 'slave') {
        $auth = new Fylgja_Auth();
        $receiver = new Fylgja_Receiver($auth);
        $receiver->register_routes();

        add_action('fylgja_sweep_deferred', function () {
            (new Fylgja_Receiver(new Fylgja_Auth()))->run_deferred_sweep();
        });
        if (!wp_next_scheduled('fylgja_sweep_deferred')) {
            wp_schedule_event(time() + 300, 'fylgja_five_minutes', 'fylgja_sweep_deferred');
        }
    }
});

if (is_admin()) {
    $admin = new Fylgja_Admin();
    $admin->register_hooks();
}
