# CLAUDE.md – DT Woohoo (`wc-plz-filter`)

WordPress/WooCommerce plugin for the online shop of Metzgerei Fischer: PLZ popup with
delivery-mode product filter, cart/checkout rules, PLZ statistics, payment reminder +
Mail-Log, Merkliste, Cart-Indicator, Produktübersicht (Packliste), GitHub auto-updater.
Overview: [README.md](README.md). Planned work: [docs/ROADMAP.md](docs/ROADMAP.md).

## Constraints – read first

- Runs in production in **exactly one shop**. The shop is operated by a third party;
  the developer has **no admin account** there. There is no staging site.
- **Every push to `main` is a production deploy:** a GitHub push webhook makes the
  plugin download the branch archive and install it over itself. A fatal error takes
  the shop *and* the webhook down — recovery then needs the operator ([docs/RUNBOOK.md](docs/RUNBOOK.md)).
- The activation hook never runs on webhook updates. Activation runs
  `WC_PLZ_Filter::install()`; updates run the conservative `WC_PLZ_Filter::upgrade()`
  once per `VERSION` (via `maybe_upgrade()`), unattended on a live request. Put setup
  needed after an update there — but never anything that starts customer-facing side
  effects (e.g. scheduling the payment-reminder cron) or overrides operator decisions
  (role caps).

## Branches & releases

- Work on a feature branch → PR into `base` (integration branch of the current
  release) → PR `base` → `main`. Hotfixes may target `main` directly via PR.
- **Never push to `main` directly**, never force-push it.
- CI (`.github/workflows/ci.yml`) must be green: `php-lint (PHP 8.0..8.3)`, `js-check`, `version`.
- Release = bump the version in **both** places (`Version:` header in `wc-plz-filter.php`
  and `WC_PLZ_Filter::VERSION`), add a [CHANGELOG.md](CHANGELOG.md) entry, tag `vX.Y.Z`
  after the merge. Versions only go up, one per release (also for reverts).
- Bump the version whenever JS/CSS changes ship: asset URLs and caches key on it.

## Checks to run locally

```sh
for f in $(git ls-files '*.php'); do php -l "$f" >/dev/null || echo "FAIL $f"; done
for f in assets/js/*.js; do node --check "$f"; done
bin/check-version.sh               # header == const
bin/check-version.sh origin/main   # + higher than main (git fetch origin main first)
```

## Code rules

- **PHP 8.0** compatible (no enums, readonly props, `never`, first-class callables…).
- **German** for all UI texts (admin and frontend); code comments may be English or German.
- Naming ([docs/NAMING.md](docs/NAMING.md)): new code uses `Woohoo_*` classes and
  `woohoo_*` options/handles; existing `WC_PLZ_*` / `wc_plz_*` identifiers, the
  `manage_plz_filter` cap and the `wc-plz/v1` namespace stay as they are (renames break
  production). Merkliste/Cart-Indicator are an accepted legacy-named exception.
- Every `register_setting()` group must be listed in `WC_PLZ_Filter::settings_group_caps()`
  ([docs/notes/settings-capability-filter.md](docs/notes/settings-capability-filter.md));
  otherwise shop managers get a 403 on save.
- Admin classes load only under `is_admin() && ! wp_doing_ajax()` in `WC_PLZ_Filter::init()`.
  Don't reference `Woohoo_Admin_Page`/`Woohoo_Module_*` from frontend, AJAX, REST or cron paths.
- Capabilities: `manage_plz_filter` (admins + shop managers) for plugin screens,
  `manage_woohoo_updates` (admins + explicitly granted users) for the Updates tab.
- Order access only via the WooCommerce CRUD API (HPOS compatibility is declared).
- Classic cart/checkout hooks only; cart/checkout blocks are not supported.

## Caching (WP Rocket full-page cache)

- WP Rocket does not know the `wc_delivery_mode` cookie. Any HTML that depends on it
  must either set `DONOTCACHEPAGE` (as `filter_products()` does) or be applied
  client-side (head script / `plz-popup.js` hide products from `localStorage`).
- Keep localized script data free of per-visitor state; fetch it via AJAX/REST.
- Register frontend script handles via the `wc_plz_nowprocket_handles` filter so Rocket does not delay/minify them.
- Avoid creating WooCommerce sessions/cookies for anonymous visitors (breaks caching).

## Data & uninstall

- `uninstall.php` runs standalone and lists every key literally: **every new option,
  transient (prefix), cron hook, capability, table or page must be added there.**
- Deleting the plugin in WP wipes all data — never suggest it as a troubleshooting step.
- Personal data (mail addresses) only with a retention limit (see `Woohoo_Mailer`).

## Docs

- Keep README.md and `docs/*.md` in sync with behaviour changes, in the file's existing
  language. `docs/`, `.github/`, `bin/`, `CLAUDE.md` are export-ignored (not deployed);
  README.md and CHANGELOG.md ship with the plugin.
