<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_Admin {

    public function register_hooks(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'maybe_render_inspect_notice']);
        add_action('wp_ajax_fylgja_sync_now', [$this, 'ajax_sync_now']);
        add_action('wp_ajax_fylgja_generate_key', [$this, 'ajax_generate_key']);
        add_action('wp_ajax_fylgja_queue_status', [$this, 'ajax_queue_status']);
        add_action('wp_ajax_fylgja_log_query', [$this, 'ajax_log_query']);
        add_action('wp_ajax_fylgja_log_clear', [$this, 'ajax_log_clear']);
        add_action('wp_ajax_fylgja_resync_start', [$this, 'ajax_resync_start']);
        add_action('wp_ajax_fylgja_resync_status', [$this, 'ajax_resync_status']);
        add_action('wp_ajax_fylgja_resync_cancel', [$this, 'ajax_resync_cancel']);
        add_action('wp_ajax_fylgja_resync_resume', [$this, 'ajax_resync_resume']);
    }

    private function make_resync(): Fylgja_Resync {
        $queue  = new Fylgja_Queue();
        $pusher = new Fylgja_Pusher($queue, new Fylgja_Auth());
        return new Fylgja_Resync($queue, $pusher);
    }

    public function add_menu_page(): void {
        $hook = add_options_page(
            'WP Sync Hub',
            'WP Sync Hub',
            'manage_options',
            'fylgja-sync',
            [$this, 'render_page']
        );
        add_action("admin_print_styles-{$hook}", [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(): void {
        $css = FYLGJA_PLUGIN_DIR . 'assets/admin.css';
        $js  = FYLGJA_PLUGIN_DIR . 'assets/admin.js';

        wp_enqueue_style(
            'fylgja-admin',
            FYLGJA_PLUGIN_URL . 'assets/admin.css',
            [],
            file_exists($css) ? (string) filemtime($css) : FYLGJA_VERSION
        );
        wp_enqueue_script(
            'fylgja-admin',
            FYLGJA_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            file_exists($js) ? (string) filemtime($js) : FYLGJA_VERSION,
            true
        );
        wp_localize_script('fylgja-admin', 'fylgjaSync', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('fylgja-sync-nonce'),
        ]);
    }

    public function register_settings(): void {
        register_setting('fylgja_settings', 'fylgja_role', [
            'sanitize_callback' => [$this, 'sanitize_role'],
        ]);
        register_setting('fylgja_settings', 'fylgja_remote_url', [
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting('fylgja_settings', 'fylgja_api_key', [
            'autoload'          => false,
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('fylgja_settings', 'fylgja_slave_mode', [
            'sanitize_callback' => [$this, 'sanitize_slave_mode'],
        ]);
    }

    public function sanitize_role($value): string {
        return in_array($value, ['disabled', 'master', 'slave'], true) ? $value : 'disabled';
    }

    public function sanitize_slave_mode($value): string {
        return in_array($value, ['active', 'inspect'], true) ? $value : 'active';
    }

    public function render_page(): void {
        $role       = get_option('fylgja_role', 'disabled');
        $remote_url = get_option('fylgja_remote_url', '');
        $api_key    = get_option('fylgja_api_key', '');
        $queue      = new Fylgja_Queue();
        $counts     = $queue->get_counts();
        ?>
        <div class="wrap">
            <h1>WP Sync Hub</h1>

            <form method="post" action="options.php">
                <?php settings_fields('fylgja_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Role</th>
                        <td>
                            <select name="fylgja_role" id="fylgja_role">
                                <option value="disabled" <?php selected($role, 'disabled'); ?>>Disabled</option>
                                <option value="master" <?php selected($role, 'master'); ?>>Master</option>
                                <option value="slave" <?php selected($role, 'slave'); ?>>Slave</option>
                            </select>
                            <p class="description">Master pushes changes. Slave receives them.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Remote Site URL</th>
                        <td>
                            <input type="url" name="fylgja_remote_url" value="<?php echo esc_attr($remote_url); ?>"
                                   class="regular-text" placeholder="https://example.com" />
                            <p class="description">The WordPress site URL of the other instance.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="password" name="fylgja_api_key" id="fylgja_api_key"
                                   value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                            <button type="button" id="fylgja-toggle-key" class="button">
                                Show
                            </button>
                            <button type="button" id="fylgja-generate-key" class="button">
                                Generate New Key
                            </button>
                            <p class="description">Shared secret. Must match on both master and slave.</p>
                        </td>
                    </tr>
                    <?php $slave_mode = get_option('fylgja_slave_mode', 'active'); ?>
                    <tr>
                        <th scope="row">Slave Mode</th>
                        <td>
                            <select name="fylgja_slave_mode" id="fylgja_slave_mode">
                                <option value="active" <?php selected($slave_mode, 'active'); ?>>Active (apply changes)</option>
                                <option value="inspect" <?php selected($slave_mode, 'inspect'); ?>>Inspect (receive + log, no writes)</option>
                            </select>
                            <p class="description">
                                Only used when Role = Slave. Inspect mode logs incoming payloads without applying them
                                — useful for verifying WPML sync correctness before flipping live.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <?php if ($role === 'master') : ?>
            <hr />
            <h2>Queue Status</h2>
            <div id="fylgja-queue-status">
                <table class="widefat fylgja-queue-table">
                    <thead>
                        <tr>
                            <th>Pending</th>
                            <th>Completed</th>
                            <th>Failed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td id="fylgja-count-pending"><?php echo esc_html($counts['pending']); ?></td>
                            <td id="fylgja-count-completed"><?php echo esc_html($counts['completed']); ?></td>
                            <td id="fylgja-count-failed"><?php echo esc_html($counts['failed']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p>
                <button type="button" id="fylgja-sync-now" class="button button-primary">
                    Sync Now
                </button>
                <span id="fylgja-sync-status"></span>
            </p>

            <hr />
            <h2>Resync All</h2>
            <p class="description">
                Pushes every term, post, and translated string to the slave once
                (terms &rarr; posts &rarr; strings), in background batches. Safe to re-run:
                resync is idempotent.
            </p>
            <?php $resync_state = $this->make_resync()->get_state(); ?>
            <div id="fylgja-resync" data-state="<?php echo esc_attr(wp_json_encode($resync_state)); ?>">
                <p class="fylgja-resync-status">Loading&hellip;</p>
                <button type="button" class="button button-primary" data-action="start">Resync All</button>
                <button type="button" class="button" data-action="resume" hidden>Resume</button>
                <button type="button" class="button button-secondary" data-action="cancel" hidden>Cancel</button>
            </div>
            <?php endif; ?>

            <?php if ($role === 'slave') : ?>
            <hr />
            <h2>Sync Log <span class="description">(slave-side preview/apply history)</span></h2>
            <p>
                <label for="fylgja-log-filter-type">Object type:</label>
                <select id="fylgja-log-filter-type">
                    <option value="">All</option>
                    <option value="post">post</option>
                    <option value="attachment">attachment</option>
                    <option value="term">term</option>
                    <option value="string">string</option>
                </select>
                <label for="fylgja-log-filter-applied">Status:</label>
                <select id="fylgja-log-filter-applied">
                    <option value="">All</option>
                    <option value="0">Not applied</option>
                    <option value="1">Applied</option>
                </select>
                <button type="button" id="fylgja-log-refresh" class="button">Refresh</button>
                <button type="button" id="fylgja-log-clear" class="button">Clear log</button>
            </p>
            <table class="widefat striped" id="fylgja-log-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Received</th>
                        <th>Type</th>
                        <th>Action</th>
                        <th>Source ID</th>
                        <th>Applied</th>
                        <th>Warnings</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="8">Loading&hellip;</td></tr></tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function maybe_render_inspect_notice(): void {
        $role = get_option('fylgja_role', 'disabled');

        if ($role === 'slave') {
            if (get_option('fylgja_slave_mode', 'active') === 'inspect') {
                echo '<div class="notice notice-warning"><p><strong>Fylgja Sync:</strong> This site is in <code>inspect</code> mode. Incoming syncs are logged but not applied.</p></div>';
            }
            return;
        }

        if ($role === 'master') {
            $health = (new Fylgja_Health_Poller())->get();
            if (!is_array($health) || empty($health['reachable'])) {
                return;
            }
            if (($health['mode'] ?? 'active') !== 'inspect') {
                return;
            }
            echo '<div class="notice notice-warning"><p><strong>Fylgja Sync:</strong> Slave is in <code>inspect</code> mode &mdash; no changes are being applied. Flip to active on the slave when ready.</p></div>';
        }
    }

    public function ajax_generate_key(): void {
        check_ajax_referer('fylgja-sync-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        $auth = new Fylgja_Auth();
        $key = $auth->generate_api_key();
        wp_send_json_success(['key' => $key]);
    }

    public function ajax_sync_now(): void {
        check_ajax_referer('fylgja-sync-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        $queue  = new Fylgja_Queue();
        $auth   = new Fylgja_Auth();
        $pusher = new Fylgja_Pusher($queue, $auth);

        $results = $pusher->flush_queue();

        wp_send_json_success($results);
    }

    public function ajax_queue_status(): void {
        check_ajax_referer('fylgja-sync-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        $queue = new Fylgja_Queue();
        wp_send_json_success($queue->get_counts());
    }

    public function ajax_log_query(): void {
        check_ajax_referer('fylgja-sync-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        $filters = [];
        if (!empty($_POST['object_type'])) {
            $filters['object_type'] = sanitize_text_field(wp_unslash($_POST['object_type']));
        }
        if (isset($_POST['applied']) && $_POST['applied'] !== '') {
            $filters['applied'] = (int) $_POST['applied'];
        }

        $log  = new Fylgja_Sync_Log();
        $rows = $log->query($filters, 1, 50);

        // received_at is stored as a UTC DATETIME (MySQL CURRENT_TIMESTAMP). Emit an
        // ISO-8601 value carrying an offset so the browser can render it in the
        // viewer's local timezone unambiguously.
        foreach ($rows as $row) {
            $row->received_at_iso = !empty($row->received_at)
                ? get_date_from_gmt($row->received_at, 'c')
                : '';
        }

        wp_send_json_success(['rows' => $rows]);
    }

    public function ajax_log_clear(): void {
        check_ajax_referer('fylgja-sync-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        $log = new Fylgja_Sync_Log();
        $log->clear();
        wp_send_json_success();
    }

    public function ajax_resync_start(): void {
        check_ajax_referer('fylgja-sync-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        wp_send_json_success($this->make_resync()->start());
    }

    public function ajax_resync_status(): void {
        check_ajax_referer('fylgja-sync-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        wp_send_json_success(['state' => $this->make_resync()->get_state()]);
    }

    public function ajax_resync_cancel(): void {
        check_ajax_referer('fylgja-sync-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        $resync = $this->make_resync();
        $resync->cancel();
        wp_send_json_success(['state' => $resync->get_state()]);
    }

    public function ajax_resync_resume(): void {
        check_ajax_referer('fylgja-sync-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        wp_send_json_success($this->make_resync()->resume());
    }
}
