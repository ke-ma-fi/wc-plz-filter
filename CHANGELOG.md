# Changelog

All notable changes to DT Woohoo (`wc-plz-filter`) are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Versioning policy

- The version lives in two places that must match: the `Version:` header in
  `wc-plz-filter.php` and `WC_PLZ_Filter::VERSION`. `bin/check-version.sh`
  (CI job `version`) enforces this, and that a PR into `main` raises it.
- Versions only go **up** — also for a revert/rollback, which ships as a new,
  higher version. Never reuse or lower a version: the per-version routines
  (`maybe_upgrade()`, WP Rocket purge, asset cache busting) key on it.
- **One version per release**, i.e. per merge into `main` (= production
  deploy). Integration happens on `base`; the version is bumped once for the
  whole release.
- Every release gets an entry here and a git tag `vX.Y.Z` on the merge commit
  (see [docs/RUNBOOK.md](docs/RUNBOOK.md), release checklist).
- Patch (`x.y.Z`) for fixes, minor (`x.Y.0`) for new features or behaviour
  changes, major only for breaking changes such as an internal rename (see
  [docs/NAMING.md](docs/NAMING.md)).

## [2.13.1] – 2026-11-11

### Fixed

- Packliste (Produktübersicht): the "Postversand" view was empty because it
  matched the exact shipping-method title "Postversand", while the shop's
  method is now called "Versand per DHL". Postal orders are now recognised by
  the keywords `dhl` / `post` in the method title or ID (pickup methods are
  never included); keywords are filterable via
  `woohoo_po_post_method_keywords`.

## [2.13.0] – unreleased

Deploy-safety and hardening release following the October 2026 review
(roadmap items A1–A6, B1–B5, C1–C5 in [docs/ROADMAP.md](docs/ROADMAP.md)).

### Added

- CI workflow `.github/workflows/ci.yml` for PRs and pushes to `main`/`base`:
  `php-lint (PHP 8.0)` … `php-lint (PHP 8.3)`, `js-check` (`node --check`) and
  `version`; `bin/check-version.sh` for the same check locally. (#21)
- `docs/RUNBOOK.md` (German): deploy flow, GitHub ruleset, release checklist,
  rollback, fatal-error emergency, webhook secret rotation, update lock. (#21)
- Install/upgrade routines. `WC_PLZ_Filter::install()` runs on activation
  (stats table, cron events, capabilities). Webhook updates never fire the
  activation hook, so `maybe_upgrade()` runs the more conservative
  `upgrade()` once per plugin version (option `wc_plz_upgraded_version`):
  `dbDelta()` only on a schema-version change, stats cleanup cron ensured,
  a stray reminder cron removed in dev mode. It never schedules the
  payment-reminder cron and never touches role capabilities. (#20, review fixes)
- HPOS compatibility declaration (`custom_order_tables`). The shop still uses
  posts storage; cart/checkout blocks are deliberately not declared. (#20)
- Mail-Log: failure reason from `wp_mail_failed`, status
  `invalid_recipient` for empty/invalid addresses, 90-day retention. (#20)
- `CHANGELOG.md`, `CLAUDE.md` and `docs/ROADMAP.md`. (#22)

### Changed

- Updater: a lock prevents parallel upgrades (webhook answers HTTP 409
  `locked`, manual update shows a notice; a stale lock expires after
  10 minutes), the upgrade continues if the client disconnects, and a
  webhook delivery whose `X-GitHub-Delivery` ID already succeeded is skipped.
  A webhook rejected with 409 schedules a one-off retry via WP-Cron
  (`wc_plz_updater_retry`, ~1 minute later, rescheduled while the lock is
  held), so a push that arrives during a running update is not lost.
  New options `wc_plz_updater_lock`, `wc_plz_updater_deliveries`. (#21, review fixes)
- Settings save capabilities are defined in one registry,
  `WC_PLZ_Filter::settings_group_caps()`, instead of one filter per module. (#17)
- Plugin header: `Requires at least: 6.0`, new description. (#20)
- Reminder log links orders via `get_edit_order_url()` (HPOS-safe). (#20)
- Packliste (Produktübersicht): products whose lines use different units
  get per-unit subtotals ("3 kg + 4 Stück") instead of a meaningless sum;
  groups are sorted by weight, then by piece count; rows only merge when
  unit and pack size match. (#18)
- Internal files (`docs/`, `.github/`, `bin/`, `tests/`, `CLAUDE.md`,
  `.gitattributes`, `.gitignore`) are `export-ignore`d and no longer deployed
  to the shop. (#20)

### Fixed

- Postal-shipping product lists filtered server-side set `DONOTCACHEPAGE`, so
  WP Rocket can no longer cache a filtered page and serve it to pickup/local
  customers. (#20)
- `uninstall.php` now removes everything the plugin stores (Mail-Log, updater
  branch/lock/delivery options, `manage_woohoo_updates` from roles and users,
  remaining transients, …). (#20)
- Payment reminder: the cron is also scheduled when the settings are saved
  for the very first time (`add_option` hook), and a cron run while dev mode
  is on aborts and unschedules itself instead of mailing real customers. (#20)
- Packliste: refunded quantities are netted out; more weight formats are
  recognised (`gr`, `Gramm`, `Kilo`, `Liter`, `1.000 g` thousands separator
  for g/mg/ml/cl; with kg/l a dot stays a decimal point, so `0.500 kg` is
  500 g); decimal quantities are no longer truncated. (#18, review fixes)
- PLZ popup: Escape only acts while the popup is open (no more accidental
  "Kein Filter" from closing another overlay); the `:has()` hiding rule is
  separate so older browsers keep the ID rule; pasted postcodes like
  "63 667" are normalised instead of cut off by `maxlength`; double submits
  are blocked; the nonce refresh times out after 10 s. (#19)
- Documentation brought in line with the code (cookie lifetime, zone cache,
  popup trigger, Merkliste counters, uninstall, deploy). (#22)

### Removed

- Stale `docs/demos/`, `docs/notes/badge-toast.md`, `docs/notes/to-dos.md`
  (open points moved to the roadmap). (#22)

## Earlier versions

There is no changelog before 2.13.0 and no release tags; see `git log` for
details. Some milestones (dates of the commit that introduced them):

| Version | Date | Highlights |
|---|---|---|
| 2.12.9 | 2026-09-26 | Minimum order value compared against the gross (incl. tax) cart subtotal |
| 2.12.8 | 2026-08-21 | Uninstall removes versioned transients |
| 2.12.5–2.12.6 | 2026-08-01 | Merkliste, Cart-Indicator and Produktübersicht (#16); Merkliste usage counters |
| 2.9.0 | 2026-07-27 | "DT Woohoo" branding, tabbed admin page with modules, central mailer + Mail-Log (#14); `manage_woohoo_updates` |
| 2.8.4–2.8.5 | 2026-07-20 | Checkout notice for shipping that doesn't fit the cart |
| 2.8.1 | 2026-06-23 | Plugin capability `manage_plz_filter` |
| 2.8.0 | 2026-06-19 | Payment reminder (#2) with Reply-To (#4) |
| 2.7.12 | 2026-05-14 | Default cookie lifetime 180 days |
| 2.7.11 | 2026-05-04 | Popup trigger setting |
| 2.7.8–2.7.9 | 2026-04-30 | Server-side cart clean-up in postal-shipping mode, minimum order values |

The version history also went *down* twice (2.9.0 → 2.8.0 on 2026-06-22 and
2.8.2 → 2.8.1 on 2026-06-23, both through reverts) — the reason for the
"only up" rule above.

[2.13.0]: https://github.com/ke-ma-fi/wc-plz-filter/compare/main...base
