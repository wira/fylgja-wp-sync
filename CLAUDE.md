# fylgja-wp-sync

WordPress plugin for master/slave content synchronization via REST API, with WPML
translation support.

## Structure

- `fylgja-wp-sync.php` — entry point; loads classes based on the configured role.
- `uninstall.php` — drops plugin tables/options and unschedules cron on delete.
- `includes/`
  - `class-queue.php` — custom DB table for pending sync items.
  - `class-queue-collapser.php` — de-dups queued items before flush.
  - `class-auth.php` — API key auth for master↔slave communication.
  - `class-admin.php` — settings page (role, remote URL, API key, manual sync, Resync All).
  - `class-pusher.php` — master-mode hooks and push/flush logic.
  - `class-resync.php` — master-mode full-resync engine (cron-driven, batched).
  - `class-receiver.php` — slave-mode REST endpoints; applies posts/terms/strings.
  - `class-string-detector.php` — detects translated strings to enqueue.
  - `class-wpml-collector.php` / `class-wpml-mapper.php` — WPML language/trid handling.
  - `class-trid-map.php`, `class-lookup.php`, `class-deferred-refs.php`, `class-sync-log.php` — supporting stores.
  - `class-flush-guard.php` — `GET_LOCK`-based flush concurrency guard.
  - `class-health-poller.php` — master-side cache of the slave's health endpoint.
- `assets/` — admin JS/CSS.

## Conventions

- Class names prefixed with `Fylgja_`.
- Options prefixed with `fylgja_`.
- REST namespace: `fylgja-wp-sync/v1`.
- DB tables: `{$wpdb->prefix}` + `fylgja_sync_queue`, `fylgja_sync_log`, `fylgja_trid_map`,
  `fylgja_deferred_refs`.
- Nonce: `fylgja-sync-nonce`.
- Text domain: `fylgja-wp-sync`.
- Payload version: `2` (the receiver rejects older versions).

## Development

There is no WordPress in this repo. For local dev, symlink the plugin into a staging
WordPress install's plugins directory:

```bash
ln -s /path/to/fylgja-wp-sync /path/to/wordpress/wp-content/plugins/fylgja-wp-sync
```

### Tests

`composer test` runs PHPUnit unit tests under `tests/Unit/`. Brain Monkey stubs WordPress
functions — there is no MySQL or WordPress in the test environment, so use the staging
symlink for in-browser testing. `patchwork.json` whitelists PHP internals (e.g.
`function_exists`) that tests need to redefine.

## Devcontainer

PHP toolchain for `composer` + `phpunit` behind a network egress firewall. No MySQL or
WordPress runs inside the container. See `.devcontainer/`:

- `Dockerfile` — PHP base image plus Node.js and the firewall tooling.
- `devcontainer.json` — build args (`PHP_VERSION`, `NODE_MAJOR`), capabilities, and the
  `postCreateCommand` that raises the firewall and runs `composer install`.
- `init-firewall.sh` — proxy-aware egress allowlist.

To change the PHP version, edit `build.args.PHP_VERSION` in `devcontainer.json` and rebuild
the container (same pattern for `NODE_MAJOR`).
