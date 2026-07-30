# Naming Convention

This plugin is branded **"DT Woohoo"** ("Digitale Theke" + "Woohoo") in its
user-facing display name and admin UI. That rebrand is deliberately
**cosmetic only** — it does not touch any internal identifier.

## What changed vs. what didn't

| Layer | Status |
|---|---|
| Plugin Name header, admin menu label, tab labels, README | Renamed to "DT Woohoo" |
| Plugin folder / main file (`wc-plz-filter/wc-plz-filter.php`) | **Unchanged** |
| PHP classes `WC_PLZ_Filter`, `WC_PLZ_Stats`, `WC_PLZ_Updater`, `WC_PLZ_Reminder` | **Unchanged** |
| Constants (`MANAGE_CAP`, `OPT`, `CACHE`, `COOKIE`, ...) | **Unchanged** |
| WordPress capability (`manage_plz_filter`) | **Unchanged** |
| Option keys / DB table (`wc_plz_filter_v2`, `wp_wc_plz_events`, ...) | **Unchanged** |
| AJAX actions (`wc_plz_check`, `wc_plz_save`, `wc_plz_hidden_ids`) | **Unchanged** |
| REST namespace (`wc-plz/v1/*`) | **Unchanged** |
| GitHub repo / auto-updater zip-folder matching | **Unchanged** |

## Why leave the legacy naming alone

This plugin runs in production. Renaming any of the "unchanged" row above is
not free:

- **Folder/file rename** — WordPress identifies active plugins by their
  folder+file path. Renaming it deactivates the plugin until someone manually
  reactivates it on the live site (front-end popup/filtering goes dark until
  then).
- **Capability/option/DB rename** — requires a migration routine (copy old →
  new, re-grant the capability to roles) or existing settings/stats/reminder
  data silently stop being read.
- **AJAX action / REST namespace rename** — breaks the already-enqueued
  front-end JS for any visitor with a cached page until they reload, and
  breaks the GitHub webhook URL and any external REST API consumer (e.g. a
  saved WooCommerce API key script hitting `/wc-plz/v1/stats`).

None of that buys anything functionally — it would just make the code say a
different word. So it stays as-is until there's a concrete reason to pay that
cost (see below).

## Convention for new code

Everything **new** — the module system this rebrand introduced
(`interface-woohoo-module.php`, `class-woohoo-admin-page.php`, the
`Woohoo_Module_*` adapters) and any future module (mailing service, product
commissioning, delivery-area marketing, etc.) — is written under the
**`Woohoo_*`** naming convention from the start, since it has no legacy
references to break.

Rule of thumb going forward:

- Writing something brand new? Use `Woohoo_*`.
- Touching an existing `WC_PLZ_*` class for its own sake (bug fix, small
  feature)? Leave its name as `WC_PLZ_*` — don't rename it as a drive-by.
- Doing a full rewrite of an existing module anyway (all its logic is being
  replaced, not just extended)? That's the point where renaming it to
  `Woohoo_*` alongside the rewrite is nearly free — fold it in then.

## What a full internal rename would require, if it's ever justified

Only worth doing if this plugin is ever open-sourced / distributed to other
shops under the Woohoo name, or a real class-name collision shows up. If that
day comes, the full rename touches:

1. **Plugin folder + main file** — `wc-plz-filter/wc-plz-filter.php` →
   `dt-woohoo/dt-woohoo.php` (or similar). Requires a deploy runbook: the site
   will show the plugin as deactivated the moment the folder disappears, so
   plan a maintenance window and reactivate immediately after.
2. **Capability migration** — on `plugins_loaded` (before the old plugin's
   deactivation removes it), grant the new capability to any role/user that
   currently holds `manage_plz_filter`, then remove the old one.
3. **Option key migration** — copy `wc_plz_filter_v2`, `wc_plz_reminder`,
   `wc_plz_updater_repo`, `wc_plz_updater_secret`, `wc_plz_hidden_version`,
   etc. to their new key names on first load of the renamed plugin, and only
   then delete the old keys.
4. **DB table** — either `RENAME TABLE wp_wc_plz_events TO wp_woohoo_events`
   or keep the physical table name and only change the class-level constant
   (cheaper, no data migration needed — recommended).
5. **AJAX actions + REST namespace** — rename `wc_plz_check`/`wc_plz_save`/
   `wc_plz_hidden_ids` and `wc-plz/v1/*`, and bump the enqueued JS/CSS
   versions so caches don't serve stale action names against a renamed
   server-side handler.
6. **GitHub webhook + updater** — if the GitHub repo itself is renamed,
   `WC_PLZ_Updater::fix_source_dir()`'s `wc-plz-filter-main/` folder-matching
   logic and the webhook URL registered on GitHub both need updating in
   lockstep, or auto-update silently breaks.
7. **Class/constant rename** — mechanical `WC_PLZ_*` → `Woohoo_*` pass across
   every file, including filenames (`class-wc-plz-*.php` → `class-woohoo-*.php`)
   for consistency.

Do this as one atomic release with a rollback plan, not incrementally — a
half-migrated state (some option keys renamed, some not) is worse than either
end state.
