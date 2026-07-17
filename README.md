# Fylgja WP Sync

Master/slave WordPress content synchronization with WPML translation support.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WPML 4.6+ on **both** master and slave (matched major.minor version)
- WPML String Translation enabled on both sites for string sync
- WPML's doc translation method may be ATE or Classic — both are supported

## Installation

1. Drop into `wp-content/plugins/fylgja-wp-sync/` on both master and slave (or symlink it).
2. Activate on both sites.
3. Configure each site under **Settings → Fylgja Sync**:
   - **Role:** `master` or `slave`.
   - **Remote URL:** the *other* site's URL.
   - **API key:** generate on slave first, paste into master.

## Configuration

### Slave mode

`fylgja_slave_mode` toggles the slave between two behaviors:

- `active` (default): incoming payloads are applied to local posts/terms/strings.
- `inspect`: payloads are received and logged, but no writes happen. Use this to verify
  correctness before flipping to `active`. Both master and slave admin pages display a
  yellow banner while inspect is on.

### Resync All

The master settings page includes a **Resync All** button. Clicking it pushes every
eligible object (terms → posts → strings) to the slave once. The work runs in the
background on a self-rescheduling 60-second cron tick (`fylgja_resync_tick`), batching
200 objects per tick. Progress is shown live; the operation can be cancelled and resumed.

Resync is idempotent — running it twice produces the same slave state, because every
object resolves through the same lookup paths used by live sync
(`_fylgja_source_id` for posts/terms, `MD5(context+name+gettext_context)` for strings).

Recommended backfill workflow:

1. Flip slave to `inspect`.
2. On master, click "Resync All".
3. Watch slave's **Sync Log** tab. Confirm language codes, sibling lookups, and deferred
   refs look right.
4. Flip slave to `active`.
5. Click "Resync All" on master again. Existing slave posts now get their WPML language
   details attached.

## Payload reference

All payloads include `"payload_version": 2`. The receiver rejects payloads of unknown
versions with `400`.

### Post / attachment

```json
{
  "payload_version": 2,
  "source_id": 6860,
  "post_title": "Sample Project",
  "post_content": "...",
  "post_status": "publish",
  "post_type": "project",
  "post_name": "sample-project",
  "post_excerpt": "",
  "menu_order": 0,
  "meta": { "_thumbnail_id": "123", "...": "..." },
  "terms": { "category": [12, 17], "post_tag": [55] },
  "wpml": {
    "language_code": "en",
    "source_trid": 6860,
    "source_default_element_id": 6860,
    "element_type": "post_project"
  }
}
```

The `terms` block sends source-side term IDs (integer arrays). The slave resolves them via
`_fylgja_source_id` term-meta. Unresolvable terms are parked in `fylgja_deferred_refs` and
resolved later by the deferred sweep (see below).

### Term

```json
{
  "payload_version": 2,
  "source_id": 16,
  "taxonomy": "category",
  "name": "Architecture",
  "slug": "architecture",
  "description": "",
  "parent_source_id": 0,
  "meta": { "...": "..." },
  "wpml": {
    "language_code": "de",
    "source_trid": 7668,
    "source_default_element_id": 1,
    "element_type": "tax_category"
  }
}
```

### String

```json
{
  "payload_version": 2,
  "source_id": 1490,
  "context": "theme_subtitle",
  "name": "70c4fe802624c6daef688eced9f59cbc",
  "gettext_context": "",
  "value": "Categoria",
  "status": 0,
  "string_type": 0,
  "wrap_tag": "",
  "translations": {
    "es": { "value": "Categoría", "status": 10 },
    "de": { "value": "Kategorie", "status": 10 }
  }
}
```

Identity match on the slave is `MD5(context + name + gettext_context)`, compared against
`wp_icl_strings.domain_name_context_md5`. Translations are written with
`ON DUPLICATE KEY UPDATE`.

## Deferred references

When a post arrives referencing a term (or a menu item referencing a post) that hasn't
synced yet, the slave parks the dependency in `wp_fylgja_deferred_refs` instead of dropping
it. These rows are resolved by:

- the sweep that runs at the end of every successful apply, and
- a standalone slave-side cron (`fylgja_sweep_deferred`, every 5 minutes).

Because of the standalone cron, deferred refs resolve within ~5 minutes even if no further
syncs arrive. Inserts are idempotent on the tuple
`(ref_type, dependent_local_id, ref_object_type, ref_source_id)`, so re-syncing the same
post never produces duplicate pending rows.

## Limitations

- **Untranslated source strings are intentionally not pushed.** Both live sync and Resync
  push only strings that carry at least one translation. Untranslated source strings
  self-register on the slave from the same theme/plugin code, so syncing them would only
  flood the queue with noise.
- **String packages** (`wp_icl_strings.string_package_id IS NOT NULL`) are not synced. Use
  WPML's package APIs separately.
- **WPML configuration** (active languages, translatable post types, custom field
  translation rules) is **not** synced. Both sites must be configured identically.
- **WPML Media Translation** is not supported. Attachments flow through as language-neutral
  and are tagged to the default language.
- **Bidirectional sync** is not supported. Master → slave only.
- **Orphan cleanup** is operator-driven. If an object is deleted on master before sync
  caught up, the slave may retain it.
- **Homepage grid placement is site-local.** The `position` and `meta-empty` post-meta
  keys (which control a post's anySCALE homepage grid slot) are seeded from the master
  only on a post's first sync, then never overwritten. This lets the slave curate its own
  homepage independently; the trade-off is that the slave will not track later placement
  changes made on the master.

## WPML setup checklist

Both sites must agree on:

- Active languages (Settings → WPML → Languages).
- Default language.
- Translatable post types (Settings → WPML → Translation Management → Multilingual
  Content Setup).
- Custom field translation rules per post type.

A mismatch on any of these will produce silent oddities (e.g., a translation arriving for a
language the slave doesn't know about). The sync log preview surfaces these as warnings;
check it after the first few backfill ticks.

## Upgrading

This plugin uses `payload_version` to gate compatibility. Upgrading across a major payload
version is a **flag-day operation**:

1. On master, click **Sync Now** and confirm `wp_fylgja_sync_queue` has no pending rows.
2. Upgrade the **slave** plugin first.
3. Upgrade the **master** plugin.
4. Flip slave to `inspect` and verify a few payloads before flipping back to `active`.

> Note: the queue's `source_kind` column and the `fylgja_resync_state` option are picked up
> by `dbDelta` on activation. After upgrading an existing install, re-activate the plugin so
> the schema change is applied.

## Troubleshooting

- **Yellow banner on admin pages:** slave is in inspect mode. Flip to active when ready.
- **Queue rows piling up:** check master's PHP error log for HTTP errors from
  `wp_remote_post`. Common cause: API key mismatch.
- **Deferred refs not draining:** a term or post the slave depends on hasn't arrived yet.
  They resolve automatically within ~5 minutes via `fylgja_sweep_deferred`; Resync All
  resolves the rest.
- **Sync log shows `payload_version` errors:** master and slave are on different plugin
  versions. Match versions.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for the full text.
