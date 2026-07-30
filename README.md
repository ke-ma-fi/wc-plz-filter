# DT Woohoo

A lightweight WooCommerce plugin for German online shops that presents customers with a **postal-code (PLZ) popup** on their first visit and lets them pick a delivery mode. Based on their choice, the shop dynamically filters products and pre-fills the checkout.

> Internally the plugin folder, classes, options, capability, AJAX actions, and REST namespace still use the legacy `wc-plz-filter` / `WC_PLZ_*` naming — only the plugin's display name and admin UI are branded "DT Woohoo". See [docs/NAMING.md](docs/NAMING.md) for why, and what a full rename would require.

---

## Features

- **Four delivery modes** selectable via popup:
  - 🏪 **Abholung** – in-store pickup (all products available)
  - 🚚 **Lokale Lieferung** – local delivery (postal code checked against WooCommerce shipping zones)
  - 📦 **Postversand** – postal shipping (configurable product classes hidden, e.g. fresh goods)
  - 📍 **Kein Filter** – customer dismissed the popup; no filtering applied, badge shown as reminder
- **Dynamic zone detection** – reads postcode ranges and wildcards directly from WooCommerce shipping zones (no manual list maintenance)
- **Product filtering** – hides products with excluded shipping classes in postal-shipping mode
- **Checkout pre-fill** – automatically fills the billing postcode from the stored cookie and WooCommerce customer session
- **Floating badge** – shows current delivery mode with hover tooltip; click to reopen the popup
- **Persistent state** – choice is stored in a cookie and synced to the WooCommerce customer session; survives page navigation and browser back/forward (bfcache)
- **PLZ statistics** – anonymous, GDPR-compliant per-event log of which postcodes and modes are selected; filterable by date range; accessible via REST API
- **Payment reminder** – automatically sends a payment-link email to customers whose orders remain in `pending` status for too long; configurable interval, threshold, subject, body, reply-to address, and dev mode with test-email target
- **Admin settings page** – configure excluded shipping classes, popup texts, accent colour, badge position, tooltips, and cookie lifetime
- **Admin PLZ tester** – check any postcode against detected zones right in the dashboard
- **Developer reset** – one-click cookie and session reset for testing

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | ≥ 8.0 |
| WordPress | ≥ 6.0 |
| WooCommerce | ≥ 7.0 |

## Installation

1. Download or clone this repository.
2. Copy the plugin folder (or upload `wc-plz-filter.zip`) to `wp-content/plugins/`.
3. Activate **DT Woohoo** in *Plugins → Installed Plugins*.
4. Go to **WooCommerce → DT Woohoo → Liefermodus** to configure the plugin.

## Configuration

Navigate to **WooCommerce → DT Woohoo → Liefermodus** in the WordPress admin:

| Setting | Description |
|---------|-------------|
| Excluded shipping classes | WooCommerce shipping classes hidden in postal-shipping mode (e.g. "Frische") |
| Cookie lifetime (days) | How long the customer's delivery-mode choice is remembered (default: 30) |
| Popup title / text | Customise the headline and body copy of the selection popup |
| Postal-shipping notice | Message shown when the entered postcode is outside the local delivery area |
| Accent colour | Colour used for the popup header and primary button |
| Badge position | Where the floating status badge appears: `bottom-right`, `bottom-left`, `top-right`, `top-left`, `bottom-center`, `left-center`, `right-center` |
| Badge rotate | Rotates the badge 90° – useful for `left-center` / `right-center` positions |
| Badge offset (X / Y) | Fine-tunes the badge position in pixels |
| Badge tooltips | Hover text shown on the badge for each of the four modes (including "Kein Filter") |

### Shipping zone setup

The plugin reads postcodes **directly from your WooCommerce shipping zones** (WooCommerce → Settings → Shipping). Supported postcode formats:

- Exact: `63667`
- Wildcard: `636*`
- Range (WooCommerce): `63600...63699`
- Range (dash): `63600-63699`

No additional configuration is needed – changes to shipping zones are picked up automatically (cached for 1 hour).

## How it works

```
Customer visits shop
      │
      ▼
Cookie present? ──Yes──► Show badge, apply filters
      │
      No
      ▼
Show PLZ popup
      │
   ┌──┴──────────┬──────────────┬──────────────┐
   ▼             ▼              ▼              ▼
Abholung    Enter PLZ      Überspringen   Backdrop/Esc
(pickup)        │           (skip)         (dismiss)
           In local zone?       └──────────────┘
           ┌────┴────┐                   │
          Yes        No          Badge shown: "Kein Filter"
           ▼          ▼          Popup won't reappear
       Local       Postal
      delivery    shipping
                  (products filtered)
```

## PLZ Statistics

The plugin logs each confirmed mode selection to a dedicated database table (`wp_wc_plz_events`). No personal data is stored – only the postal code (a geographic area), the selected mode, and a timestamp.

**GDPR note:** Aggregate geographic statistics without personal identifiers are not subject to the GDPR. No consent is required for this data.

### Admin dashboard

The **Statistik** tab under **WooCommerce → DT Woohoo** shows:

- Aggregated table: PLZ · Zone · Selections · Last seen
- Date range filter (from / to)
- Configurable retention: TTL in days (default: 180) and maximum row count (default: 100,000)
- Daily automatic cleanup via WP-Cron
- Reset button to clear all statistics

Shop managers and administrators are excluded from tracking to keep data clean.

### REST API

Retrieve aggregated statistics remotely using a WooCommerce API key:

```
GET /wp-json/wc-plz/v1/stats
GET /wp-json/wc-plz/v1/stats?from=2026-01-01&to=2026-04-30
```

**Authentication:** WooCommerce API key (Consumer Key + Consumer Secret via HTTP Basic Auth).  
Create a key under WooCommerce → Settings → Advanced → REST API (Read permission is sufficient).

```bash
curl -u ck_xxx:cs_xxx \
  "https://yourshop.de/wp-json/wc-plz/v1/stats?from=2026-04-01"
```

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

## Payment Reminder

Navigate to **WooCommerce → DT Woohoo → Zahlungs-Erinnerung** to configure the automatic payment reminder.

### How it works

When a WooCommerce order stays in `pending` (payment awaited) status longer than the configured threshold, the plugin sends the customer a reminder email containing a direct payment link. Each order receives **at most one reminder** (tracked via order meta). In dev mode the email is redirected to a test address and the meta flag is never set, so the same order can be tested repeatedly.

### Settings

| Setting | Description |
|---------|-------------|
| Dev mode | When active: no automatic cron, all emails sent to test address only (default: on) |
| Test email address | Recipient for all emails while dev mode is active (default: WordPress admin email) |
| Reply-To address | `Reply-To` header set on every outgoing reminder email; leave empty to omit the header (default: WordPress admin email) |
| Cron interval (minutes) | How often the cron job scans for pending orders (default: 5) |
| Pending threshold (minutes) | Orders older than this value trigger a reminder (default: 5) |
| Email subject | Customisable subject line; supports placeholders |
| Email body | Customisable body text; supports placeholders |

### Placeholders

The following placeholders are available in subject and body:

| Placeholder | Replaced with |
|-------------|---------------|
| `{order_number}` | WooCommerce order number |
| `{order_date}` | Order date (WordPress date format) |
| `{customer_first_name}` | Billing first name |
| `{customer_last_name}` | Billing last name |
| `{customer_full_name}` | Billing first + last name |
| `{order_total}` | Order total incl. currency |
| `{payment_url}` | Direct payment link |
| `{shop_name}` | Shop name |

Order number and date are always appended to the email footer, even if the placeholders are removed from the body.

### Dev mode & manual test run

While dev mode is active, a **"Jetzt testen"** button is shown in the admin panel. It runs the exact same scan as the regular cron job but sends all emails to the configured test address and never sets the reminder meta flag on orders.

### Mail log

The last 50 sent reminders are shown in a log table under the settings. Each entry includes timestamp, recipient, order ID, mode (dev/live), and status. A **"Erneut senden"** button allows resending for any logged order, bypassing the one-reminder-per-order limit.

---

## Developer Reset

The **Entwickler-Reset** button under WooCommerce → DT Woohoo → Liefermodus clears your browser's delivery-mode cookie and the WooCommerce customer session, so the popup reappears on the next page load. Useful for testing without manually clearing cookies.

## Uninstall

Deactivating and deleting the plugin via WordPress removes all stored options and transients automatically (`uninstall.php`). The `wp_wc_plz_events` statistics table is also dropped on uninstall.
