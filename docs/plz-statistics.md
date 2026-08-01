# PLZ Statistics

Anonymous, GDPR-compliant logging of which postal codes and delivery modes customers pick, plus the REST API for retrieving aggregates.

Relevant source: [`includes/class-wc-plz-stats.php`](../includes/class-wc-plz-stats.php).

## Data model

Each confirmed popup selection is logged as one row in a dedicated table, `{$wpdb->prefix}wc_plz_events`:

| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Primary key |
| `plz` | `VARCHAR(10)` | Postal code; empty for modes without one (`abholung`, `skipped`) |
| `mode` | `VARCHAR(20)` | `local`, `post`, `abholung`, or `skipped` |
| `created` | `DATETIME` | Server time of the event |

Indexed on `created` (for range queries/cleanup) and `(mode, plz)` (for aggregation).

**No personal data is stored** — no IP, no session ID, no user ID. `WC_PLZ_Stats::log_event()` skips logging entirely when the current visitor is logged in and holds `WC_PLZ_Filter::MANAGE_CAP`, so staff testing the popup never pollutes the numbers.

## Caching

`get_aggregated()` caches its grouped query result in a transient keyed by `wplzs_<epoch>_<md5(from|to)>` (`CACHE_TTL` = 5 minutes). Rather than deleting/matching individual transient keys on write, every insert or cleanup run bumps a `wc_plz_stats_epoch` option — since the epoch is part of the cache key, this instantly invalidates every previously-cached query without needing to enumerate them.

## Retention & cleanup

A daily WP-Cron event (`wc_plz_stats_cleanup`, registered on activation) runs `run_cleanup()`, which enforces two independent limits (configurable in the admin dashboard, stored in the `wc_plz_stats_cleanup` option):

- **TTL** (default: 180 days) — rows older than this are deleted outright.
- **Max rows** (default: 100,000) — if the table still exceeds this after the TTL pass, the oldest remaining rows are deleted until it doesn't.

## Admin dashboard

The **Statistik** tab under **WooCommerce → DT Woohoo** shows an aggregated table (PLZ · Zone/Mode · Selections · Last seen), a date-range filter, the retention settings above, and a one-click "Alle Statistiken löschen" reset (nonce-protected, requires `MANAGE_CAP`).

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

`from` / `to` are optional and must be `YYYY-MM-DD`; an invalid value is silently treated as empty (no filter) rather than erroring.

**Response:**

```json
{
  "period": { "from": "2026-04-01", "to": "" },
  "total_events": 65,
  "data": [
    { "plz": "63667", "mode": "local",    "count": 42, "last_seen": "2026-04-26T14:23:00" },
    { "plz": "60313", "mode": "post",     "count": 15, "last_seen": "2026-04-25T09:11:00" },
    { "plz": "",      "mode": "abholung", "count":  8, "last_seen": "2026-04-26T10:05:00" },
    { "plz": "",      "mode": "skipped",  "count":  3, "last_seen": "2026-04-25T17:44:00" }
  ]
}
```

`data` is one row per distinct `(plz, mode)` combination within the requested period, sorted by `count` descending.
