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
- **Merkliste (wishlist)** – LocalStorage-based product wishlist with a toggle icon on product tiles and a floating widget button + popover; no account or server sync required
- **Cart-Indicator** – green outline around a tile's "In den Warenkorb" button when that product (or one of its variations) is already in the cart
- **Produktübersicht (Packliste)** – native, password-protected page that consolidates open orders' line items by product for warehouse staff, split into "Lokal" (by delivery date) and "Postversand" views
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

The plugin logs each confirmed mode selection to a dedicated database table. No personal data is stored – only the postal code (a geographic area), the selected mode, and a timestamp. Shop managers and administrators are excluded from tracking to keep data clean.

**GDPR note:** Aggregate geographic statistics without personal identifiers are not subject to the GDPR. No consent is required for this data.

The **Statistik** tab under **WooCommerce → DT Woohoo** shows an aggregated table with a date-range filter, configurable retention (TTL + max row count, cleaned up daily via WP-Cron), and a reset button. Aggregates are also available remotely via a WooCommerce-API-key-authenticated REST endpoint.

For the data model, caching, retention internals, and full REST API reference (auth, parameters, response shape), see [docs/plz-statistics.md](docs/plz-statistics.md).

## Payment Reminder

Navigate to **WooCommerce → DT Woohoo → Zahlungs-Erinnerung** to configure the automatic payment reminder. When a WooCommerce order stays in `pending` (payment awaited) status longer than a configurable threshold, the plugin sends the customer a reminder email with a direct payment link. Each order receives **at most one reminder**. A dev mode (default: on) redirects all emails to a test address and never marks orders as reminded, so it's safe to test repeatedly before going live; a mail log with per-order resend is also available.

For the full settings reference, placeholder list, dev-mode/cron behaviour, and mail-log details, see [docs/payment-reminder.md](docs/payment-reminder.md).

---

## Zusatz-Features (Merkliste & Cart-Indicator)

Navigate to **WooCommerce → DT Woohoo → Zusatz-Features** to toggle these on/off. Both are enabled by default, store no server-side state, and are independent of each other.

| Feature | Behaviour |
|---------|-----------|
| **Merkliste** | Adds a notepad icon to product tiles and a floating widget button (with item count) that opens a popover listing saved products. The list is kept in the browser's LocalStorage only – no account binding, no server sync, distinct from any "My favourites" account feature. Disabling it leaves customers' existing browser data untouched. |
| **Cart-Indicator** | Draws a green outline around a tile's add-to-cart button when that product is already in the cart. Reads the WooCommerce Store API cart client-side; a variation counts as "in cart" for its parent product's tile, and multiple variations of the same product still show a single outline (no quantity shown). |

For LocalStorage schema, caching, live-update strategy, and theme/plugin compatibility details, see [docs/widgets.md](docs/widgets.md).

## Produktübersicht (Packliste)

Navigate to **WooCommerce → DT Woohoo → Zusatz-Features** to enable the **Produktübersicht** page and set its access password. This replaces the former "DT konsolidierte Produktliste" n8n workflows with a native, password-protected, self-hosted page that consolidates open orders' line items by product into a packing list for warehouse staff — split into a **Lokal** view (by delivery date) and a **Postversand** view.

For the full access model, page provisioning behaviour, and REST API reference (endpoint, request/response shape, auth model for integrating from other apps), see [docs/product-overview.md](docs/product-overview.md).

---

## Developer Reset

The **Entwickler-Reset** button under WooCommerce → DT Woohoo → Liefermodus clears your browser's delivery-mode cookie and the WooCommerce customer session, so the popup reappears on the next page load. Useful for testing without manually clearing cookies.

## Uninstall

Deactivating and deleting the plugin via WordPress removes all stored options and transients automatically (`uninstall.php`). The `wp_wc_plz_events` statistics table is also dropped on uninstall.
