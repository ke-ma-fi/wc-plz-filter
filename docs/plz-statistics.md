# PLZ Statistics

Anonymous, GDPR-compliant logging of which postal codes and delivery modes customers pick, plus the REST API for retrieving aggregates.

Relevant source: [`includes/class-wc-plz-stats.php`](../includes/class-wc-plz-stats.php).

## Data model

Every saved delivery-mode choice (`wc_plz_save` AJAX call: a popup choice, including "Überspringen"/dismiss as `skipped`, and a postcode change in the checkout form while a mode is set) is logged as one row in a dedicated table, `{$wpdb->prefix}wc_plz_events`:

| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Primary key |
| `plz` | `VARCHAR(10)` | Postal code; empty for modes without one (`abholung`, `skipped`) |
| `mode` | `VARCHAR(20)` | `local`, `post`, `abholung`, or `skipped` |
| `created` | `DATETIME` | Site-local time of the event (`current_time( 'mysql' )`) |

Indexed on `created` (for range queries/cleanup) and `(mode, plz)` (for aggregation).

**No personal data is stored** — no IP, no session ID, no user ID. `WC_PLZ_Stats::log_event()` skips logging entirely when the current visitor is logged in and holds `WC_PLZ_Filter::MANAGE_CAP`, so staff testing the popup never pollutes the numbers.

## Caching

`get_aggregated()` caches its grouped query result in a transient keyed by `wplzs_<epoch>_<md5(from|to)>` (`CACHE_TTL` = 5 minutes). Rather than deleting/matching individual transient keys on write, every insert or cleanup run bumps a `wc_plz_stats_epoch` option — since the epoch is part of the cache key, this instantly invalidates every previously-cached query without needing to enumerate them.

## Retention & cleanup

A daily WP-Cron event (`wc_plz_stats_cleanup`, scheduled on activation by `WC_PLZ_Filter::install()` and re-ensured once per plugin version after an update by `WC_PLZ_Stats::ensure_installed()`) runs `run_cleanup()`, which enforces two independent limits (configurable in the admin dashboard, stored in the `wc_plz_stats_cleanup` option):

- **TTL** (default: 180 days, minimum 1) — rows older than this are deleted outright.
- **Max rows** (default: 100,000, minimum 100) — if the table still exceeds this after the TTL pass, the oldest remaining rows are deleted until it doesn't.

## Admin dashboard

The **Statistik** tab under **WooCommerce → DT Woohoo** shows an aggregated table (PLZ · Zone/Mode · Selections · Last seen), a date-range filter, the retention settings above, and a one-click "Alle Statistiken löschen" reset (nonce-protected, requires `MANAGE_CAP`). The same tab also shows the Merkliste usage counters (see [widgets.md](widgets.md#usage-counters-temporary)), which are stored separately and have their own reset button.

## REST API

Retrieve aggregated statistics remotely using a WooCommerce API key.

```
GET /wp-json/wc-plz/v1/stats
GET /wp-json/wc-plz/v1/stats?from=2026-01-01&to=2026-04-30
```

**Authentication:** WooCommerce API key (Consumer Key + Consumer Secret via HTTP Basic Auth), which WordPress resolves to a user; the endpoint's `permission_callback` then requires that user to hold `WC_PLZ_Filter::MANAGE_CAP`. Create a key under WooCommerce → Settings → Advanced → REST API (Read permission is sufficient).

```bash
curl -u ck_xxx:cs_xxx \
  "https://yourshop.de/wp-json/wc-plz/v1/stats?from=2026-04-01"
```

`from` / `to` are optional and must be `YYYY-MM-DD` (inclusive, whole days); an invalid value is rejected with `400 rest_invalid_param`. (The admin dashboard's date filter, by contrast, silently ignores an invalid value.)

WooCommerce API keys work here because WooCommerce applies its key authentication to every REST route whose namespace starts with `wc-` — `wc-plz/v1` qualifies. Results are cached for up to 5 minutes (see [Caching](#caching)).

**Response:**

```json
{
  "period": { "from": "2026-04-01", "to": "" },
  "total_events": 412,
  "data": [
    { "plz": "63667", "mode": "local",    "count": 42, "last_seen": "2026-04-26 14:23:00" },
    { "plz": "60313", "mode": "post",     "count": 15, "last_seen": "2026-04-25 09:11:00" },
    { "plz": "",      "mode": "abholung", "count":  8, "last_seen": "2026-04-26 10:05:00" },
    { "plz": "",      "mode": "skipped",  "count":  3, "last_seen": "2026-04-25 17:44:00" }
  ]
}
```

`data` is one row per distinct `(plz, mode)` combination within the requested period, sorted by `count` descending; `last_seen` is the site-local `DATETIME` of the latest event in that group.

`total_events` is the number of rows in the whole table, **not** limited to the requested period — sum the `count` values for the period total.
