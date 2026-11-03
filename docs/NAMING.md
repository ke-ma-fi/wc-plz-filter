# Naming Convention

This plugin is branded **"DT Woohoo"** ("Digitale Theke" + "Woohoo") in its
user-facing display name and admin UI. That rebrand is deliberately
**cosmetic only** — it does not touch any internal identifier.

## What changed vs. what didn't

| Layer | Status |
|---|---|
| Plugin Name header, admin menu label, tab labels, README | Renamed to "DT Woohoo" |
| Plugin folder / main file (`wc-plz-filter/wc-plz-filter.php`) | **Unchanged** |
| PHP classes `WC_PLZ_Filter`, `WC_PLZ_Stats`, `WC_PLZ_Updater`, `WC_PLZ_Reminder` (and the later `WC_PLZ_Merkliste`, `WC_PLZ_Cart_Indicator`, see below) | **Unchanged** |
| Constants (`MANAGE_CAP`, `OPT`, `CACHE`, `COOKIE`, ...) | **Unchanged** |
| WordPress capability (`manage_plz_filter`) | **Unchanged** |
| Option keys / DB table (`wc_plz_filter_v2`, `wp_wc_plz_events`, ...) | **Unchanged** |
| AJAX actions (`wc_plz_check`, `wc_plz_save`, `wc_plz_hidden_ids`) | **Unchanged** |
| REST namespace (`wc-plz/v1/*`: `nonce`, `stats`, `webhook`, `merkliste-stats`) | **Unchanged** |
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
  saved WooCommerce API key script hitting `/wc-plz/v1/stats`). Note that
  WooCommerce API-key authentication only applies to REST namespaces starting
  with `wc/` or `wc-`; `wc-plz/v1` gets it for free, a `woohoo/v1` namespace
  would not.

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

New code following this convention so far: `Woohoo_Admin_Page`,
`Woohoo_Module_*`, `Woohoo_Mailer` (option `woohoo_mail_log`),
`Woohoo_Product_Overview` and `Woohoo_PO_Aggregator` (options
`woohoo_product_overview_*`, REST namespace `woohoo/v1`, cookie
`woohoo_po_auth`).

### Accepted exception: Merkliste and Cart-Indicator

`WC_PLZ_Merkliste` and `WC_PLZ_Cart_Indicator` were added after the rebrand
but still use the legacy naming (`class-wc-plz-*.php`, options
`wc_plz_merkliste_enabled` / `wc_plz_merkliste_stats` /
`wc_plz_cart_indicator_enabled`, REST route `wc-plz/v1/merkliste-stats`, JS/CSS
handles `wc-plz-*`). They are tightly coupled to the legacy core (badge widget
group, `wcPlzTiles` helpers, `--wc-plz-color`), and they are live in
production with stored options, so renaming them now would cost a migration
for no functional gain. This is accepted as-is; don't use it as precedent
for new modules.

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
   then delete the old keys. `uninstall.php` lists every key as a literal
   string and must be updated too.
4. **DB table** — either `RENAME TABLE wp_wc_plz_events TO wp_woohoo_events`
   or keep the physical table name and only change the class-level constant
   (cheaper, no data migration needed — recommended).
5. **AJAX actions + REST namespace** — rename `wc_plz_check`/`wc_plz_save`/
   `wc_plz_hidden_ids` and `wc-plz/v1/*`, and bump the enqueued JS/CSS
   versions so caches don't serve stale action names against a renamed
   server-side handler.
6. **GitHub webhook + updater** — `WC_PLZ_Updater::fix_source_dir()` does not
   match folder names: it renames whatever folder the downloaded archive
   extracts to into the basename of the *currently active* plugin folder
   (`basename( WC_PLZ_FILTER_DIR )`), so the update always overwrites the
   running copy. A folder rename (step 1) therefore cannot be shipped through
   the updater at all — it needs a manual install. If the GitHub repo itself
   is renamed, the `wc_plz_updater_repo` option (Updates tab) and the webhook
   on GitHub must be updated in lockstep, and if the REST namespace changes
   (step 5), so must the webhook URL (`/wp-json/wc-plz/v1/webhook`), or
   auto-update silently breaks.
7. **Class/constant rename** — mechanical `WC_PLZ_*` → `Woohoo_*` pass across
   every file, including filenames (`class-wc-plz-*.php` → `class-woohoo-*.php`)
   for consistency.

Do this as one atomic release with a rollback plan, not incrementally — a
half-migrated state (some option keys renamed, some not) is worse than either
end state.
