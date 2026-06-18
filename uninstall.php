<?php
/**
 * Fylgja WP Sync — uninstall.
 *
 * Runs only when the user clicks "Delete" in Plugins; not on deactivation.
 * Drops every Fylgja-owned table and option so the install is clean.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'fylgja_sync_queue',
    $wpdb->prefix . 'fylgja_sync_log',
    $wpdb->prefix . 'fylgja_trid_map',
    $wpdb->prefix . 'fylgja_deferred_refs',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

$options = [
    'fylgja_role',
    'fylgja_remote_url',
    'fylgja_api_key',
    'fylgja_slave_mode',
    'fylgja_string_hashes',
    'fylgja_resync_state',
    'fylgja_resync_batch_size',
];
foreach ($options as $option) {
    delete_option($option);
}

delete_transient('fylgja_slave_health');

$events = ['fylgja_process_queue', 'fylgja_push_strings', 'fylgja_sweep_deferred', 'fylgja_resync_tick'];
foreach ($events as $event) {
    $timestamp = wp_next_scheduled($event);
    while ($timestamp) {
        wp_unschedule_event($timestamp, $event);
        $timestamp = wp_next_scheduled($event);
    }
}
