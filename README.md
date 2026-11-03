# DT Woohoo

WooCommerce extensions for the online shop of Metzgerei Fischer. At its core is a **postal-code (PLZ) popup** that lets customers pick a delivery mode; based on that choice the shop filters products, enforces the matching cart rules and pre-fills the checkout. Around it sit a PLZ statistic, an automatic payment reminder with a central mail log, a Merkliste (wishlist), a cart indicator, a packing list for staff (Produktübersicht) and a GitHub webhook auto-updater.

The plugin runs in production in exactly one shop. It is deployed by merging into `main` (see [Deploy & operations](#deploy--operations)) — there is no release `.zip` and no wordpress.org listing.

> Internally the plugin folder, classes, options, capability, AJAX actions, and REST namespace still use the legacy `wc-plz-filter` / `WC_PLZ_*` naming — only the plugin's display name and admin UI are branded "DT Woohoo". See [docs/NAMING.md](docs/NAMING.md) for why, and what a full rename would require.

---

## Features

- **Four delivery modes** selectable via popup:
  - 🏪 **Abholung** – in-store pickup (all products available)
  - 🚚 **Lokale Lieferung** – local delivery (postal code checked against WooCommerce shipping zones)
  - 📦 **Postversand** – postal shipping (products with configurable shipping classes are hidden, e.g. fresh goods)
  - 📍 **Kein Filter** – customer dismissed the popup; no filtering applied, badge shown as reminder
- **Configurable popup trigger** – by default the popup opens only once the cart holds at least one item (on the next page load after the first add-to-cart); alternatively immediately on the first visit. It never opens automatically on the checkout page.
- **Dynamic zone detection** – reads postcode ranges and wildcards directly from WooCommerce shipping zones (no manual list maintenance)
- **Product filtering** – in postal-shipping mode, products with excluded shipping classes are removed from product queries server-side and additionally hidden client-side (so cached pages are filtered too)
- **Cart & checkout rules** – removes excluded products from the cart in postal-shipping mode, redirects away from their product pages, shows minimum-order-value notices and a checkout notice when the chosen shipping does not fit the cart (see [Cart & checkout rules](#cart--checkout-rules))
- **Checkout pre-fill** – fills an empty billing postcode from the stored cookie; the chosen PLZ is also synced into the WooCommerce customer session (billing + shipping postcode)
- **Floating badge** – shows current delivery mode with hover tooltip; click to reopen the popup
- **Persistent state** – choice is stored in a cookie (default 180 days) and synced to the WooCommerce customer session; survives page navigation and browser back/forward (bfcache)
- **PLZ statistics** – anonymous per-event log of which postcodes and modes are selected; filterable by date range; accessible via REST API
- **Payment reminder** – automatically sends a payment-link email to customers whose orders remain in `pending` status for too long; configurable interval, threshold, subject, body, reply-to address, and dev mode with test-email target
- **Mail-Log** – central log of every email the plugin sends (recipient, subject, source, status, failure reason), kept for 90 days
- **Merkliste (wishlist)** – LocalStorage-based product list with a heart icon on product tiles and a floating widget button + popover; no account required
- **Cart-Indicator** – outline in the accent colour around a tile's add-to-cart button when that product (or one of its variations) is already in the cart
- **Produktübersicht (Packliste)** – native, password-protected page that consolidates open orders' line items by product for warehouse staff, split into "Lokal" (by delivery date) and "Postversand" views
- **Auto-update** – signed GitHub push webhook installs the configured branch; manual "Jetzt aktualisieren" button and update log
- **Admin PLZ tester** – check any postcode against detected zones right in the dashboard
- **Developer reset** – one-click reset of your own browser's delivery-mode cookie for testing

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | ≥ 8.0 (CI lints 8.0–8.3) |
| WordPress | ≥ 6.0 |
| WooCommerce | ≥ 7.0, classic (shortcode) cart/checkout |

### Compatibility notes

- **HPOS:** the plugin declares compatibility with WooCommerce's High-Performance Order Storage (`custom_order_tables`); all order access goes through the WooCommerce CRUD API. The production shop still runs on the posts storage.
- **Cart/Checkout blocks:** *not* declared compatible. The cart rules and checkout notices rely on classic WooCommerce hooks.
- **WP Rocket:** see [Caching](#caching-wp-rocket).

## Installation

There is no packaged release. The plugin lives in `wp-content/plugins/wc-plz-filter/` (any folder name works, the updater keeps whatever folder is active).

- **Existing shop:** updates arrive automatically via the GitHub webhook when `main` changes — nothing to install by hand.
- **Fresh install / emergency reinstall:** build a zip and upload it under *Plugins → Add New Plugin → Upload Plugin* (German admin: *Plugins → Neues Plugin hinzufügen → Plugin hochladen*). The folder prefix must match the plugin folder **actually installed on the server** — not necessarily `wc-plz-filter`, since the updater keeps whatever folder name is active (ask the operator, or check `wp-content/plugins/` via FTP):

  ```sh
  git archive --prefix=<folder>/ -o <folder>.zip origin/main
  # usually: git archive --prefix=wc-plz-filter/ -o wc-plz-filter.zip origin/main
  ```

  With a matching folder name WordPress offers "Replace current with uploaded" ("Aktuelle Version mit der hochgeladenen ersetzen"); with a different one it installs a second copy. For the same reason don't use GitHub's "Download ZIP" — its folder is called `wc-plz-filter-main`. `git archive` honours `.gitattributes`, so `docs/`, `.github/`, `bin/` and `CLAUDE.md` are left out of the package.
- Activate **DT Woohoo**, then configure it under **WooCommerce → DT Woohoo**, and set up the webhook in the **Updates** tab (see the [Runbook](https://github.com/ke-ma-fi/wc-plz-filter/blob/main/docs/RUNBOOK.md)).

## Deploy & operations

Every push to `main` is a production deploy: GitHub sends a signed push webhook to `/wp-json/wc-plz/v1/webhook`, and the plugin downloads the branch archive and installs it over the active plugin folder. Changes therefore go through pull requests (integration branch `base` → `main`), CI (`php -l` on PHP 8.0–8.3, `node --check`, version check) must be green, and the version must be bumped.

The activation hook never fires for webhook updates. Instead, once per version, `WC_PLZ_Filter::maybe_upgrade()` runs a lightweight upgrade step: it updates the stats table only when its schema version changed (otherwise it just makes sure the daily cleanup cron exists) and removes a stray payment-reminder cron while dev mode is on. It never schedules the payment-reminder cron (going live stays an explicit settings save) and never changes role capabilities; the full install routine (`WC_PLZ_Filter::install()`) runs on activation only. Separately, the updater grants `manage_woohoo_updates` to administrators once per version, and the WP Rocket cache is purged once per version.

The **[Runbook](https://github.com/ke-ma-fi/wc-plz-filter/blob/main/docs/RUNBOOK.md)** (German; [local copy](docs/RUNBOOK.md) — `docs/` is not shipped to the shop, so give the operator the GitHub link or a printout in advance) covers the deploy flow, webhook responses, GitHub ruleset setup, release checklist, rollback, what to do on a fatal error, rotating the webhook secret and the update lock (HTTP 409).

Further project documents:

- [CHANGELOG.md](CHANGELOG.md) — release notes and versioning policy
- [docs/ROADMAP.md](docs/ROADMAP.md) — planned and completed work (German)
- [CLAUDE.md](CLAUDE.md) — working rules for (AI-assisted) development in this repo

## Configuration

Everything lives under **WooCommerce → DT Woohoo**. Most tabs require the plugin capability `manage_plz_filter` (administrators and shop managers); the **Updates** tab requires `manage_woohoo_updates`.

| Tab | Contents |
|-----|----------|
| **Liefermodus** | Popup, filter and badge settings (below), detected PLZ areas, PLZ tester, developer reset |
| **Statistik** | PLZ statistics, retention settings, Merkliste usage counters |
| **Updates** | Auto-update: version, webhook secret + URL, repo/branch, manual update, update log, granting update rights |
| **Zahlungs-Erinnerung** | Payment reminder settings, test run, reminder log with resend |
| **Mail-Log** | Every email sent through the plugin's mailer |
| **Zusatz-Features** | Merkliste / Cart-Indicator toggles, "WP Rocket-Cache leeren" button, Produktübersicht settings and status |

### Liefermodus settings

| Setting | Description |
|---------|-------------|
| Vom Postversand ausgeschlossen | WooCommerce shipping classes hidden in postal-shipping mode (e.g. "Frische") |
| Cookie-Laufzeit (Tage) | How long the customer's delivery-mode choice is remembered (default: 180) |
| Popup-Auslöser | When the popup opens automatically for visitors without a choice: after the first item is in the cart (default) or immediately on the first visit |
| Mindestbestellwert | Minimum order value (gross, incl. tax) for Lokallieferung and Postversand, in € (default: 30 each; 0 = off) |
| Popup-Titel / Popup-Text | Headline and body copy of the selection popup |
| Hinweis bei Postversand | Message shown in the popup when the entered postcode is outside the local delivery area |
| Popup-Farbe | Accent colour: popup header, buttons, badge, Merkliste and Cart-Indicator |
| Badge-Position | `bottom-right`, `bottom-left`, `top-right`, `top-left`, `bottom-center`, `left-center`, `right-center` |
| Badge vertikal drehen | Rotates the badge 90° – for `left-center` / `right-center` |
| Badge-Offset (X / Y) | Fine-tunes the badge position in pixels |
| Badge-Tooltip Texte | Hover text on the badge for each of the four modes (including "Kein Filter") |

### Shipping zone setup

The plugin reads postcodes **directly from your WooCommerce shipping zones** (WooCommerce → Settings → Shipping). Supported postcode formats:

- Exact: `63667`
- Wildcard: `636*`
- Range (WooCommerce): `63600...63699`
- Range (dash): `63600-63699`

No additional configuration is needed – the detected list is cached for 12 hours and the cache is cleared whenever a shipping zone is saved or deleted (and when the Liefermodus settings are saved).

## How it works

```
Customer visits shop
      │
      ▼
Cookie present? ──Yes──► Show badge, apply filters
      │
      No
      ▼
Popup trigger met? ──No──► Badge "PLZ eingeben" (click opens popup)
(default: cart not empty;
 never on checkout)
      │
      Yes
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

## Cart & checkout rules

All of these apply only to postal-shipping mode unless stated otherwise:

- **Cart clean-up** – when the cart is loaded, products with an excluded shipping class are removed and the customer sees a notice listing them. If one still slips through, cart validation shows an error per product.
- **Single-product redirect** – opening the product page of an excluded product redirects back to the previous page (or the home page) with `?plz_blocked=1`, which shows a browser alert. Cached product pages are covered by the same check client-side.
- **Minimum order value** (Lokale Lieferung and Postversand) – if the gross cart subtotal is below the configured value, a notice ("Es fehlen noch …") is shown in the cart and at checkout. It is informational and does not block the order.
- **Shipping notice at checkout** – for customers *without* a popup choice (no cookie or "Kein Filter"): if the checkout postcode is outside the local zones, the chosen shipping method is not (only) pickup and the cart contains products excluded from postal shipping, a notice lists them and offers a link that switches to postal-shipping mode (which then removes them).

## Caching (WP Rocket)

The shop runs WP Rocket's full-page cache, which knows nothing about the `wc_delivery_mode` cookie. The plugin therefore:

- keeps the frontend cache-friendly: localized script data contains no per-visitor state, and the browser hides excluded products itself (inline head script + `plz-popup.js`, using IDs/slugs cached in `localStorage`);
- sets `DONOTCACHEPAGE` whenever a response was actually filtered server-side for a postal-shipping visitor (and on the single-product redirect), so a filtered page never ends up in the cache for everyone;
- fetches a fresh nonce from `/wp-json/wc-plz/v1/nonce` when a cached page carries an expired one;
- purges the WP Rocket cache automatically once per plugin version, and offers a manual "WP Rocket-Cache leeren" button on the Zusatz-Features tab (needed after toggling Merkliste/Cart-Indicator).

## PLZ Statistics

The plugin logs each confirmed popup choice to a dedicated database table. No personal data is stored – only the postal code (a geographic area), the selected mode, and a timestamp. Accounts holding `manage_plz_filter` (shop managers, administrators) are excluded from tracking to keep data clean.

**GDPR note:** Aggregate geographic statistics without personal identifiers are not subject to the GDPR. No consent is required for this data.

The **Statistik** tab under **WooCommerce → DT Woohoo** shows an aggregated table with a date-range filter, configurable retention (TTL + max row count, cleaned up daily via WP-Cron), and a reset button. Aggregates are also available remotely via a WooCommerce-API-key-authenticated REST endpoint.

For the data model, caching, retention internals, and full REST API reference (auth, parameters, response shape), see [docs/plz-statistics.md](docs/plz-statistics.md).

## Payment Reminder & Mail-Log

Navigate to **WooCommerce → DT Woohoo → Zahlungs-Erinnerung** to configure the automatic payment reminder. When a WooCommerce order stays in `pending` (payment awaited) status longer than a configurable threshold, the plugin sends the customer a reminder email with a direct payment link. Each order receives **at most one reminder**. A dev mode (default: on) keeps the cron off, sends test runs only to a test address and never marks orders as reminded, so it's safe to test repeatedly before going live; a reminder log with per-order resend is also available.

All plugin emails go through a central mailer (`Woohoo_Mailer`). The **Mail-Log** tab lists the last 100 of them (at most 90 days old) with status *Erfolgreich*, *Fehler* (with the failure reason reported by `wp_mail`) or *Ungültiger Empfänger*.

For the full settings reference, placeholder list, dev-mode/cron behaviour, and log details, see [docs/payment-reminder.md](docs/payment-reminder.md).

---

## Zusatz-Features (Merkliste & Cart-Indicator)

Navigate to **WooCommerce → DT Woohoo → Zusatz-Features** to toggle these on/off. Both are enabled by default and independent of each other. After toggling, use the "WP Rocket-Cache leeren" button on the same tab so cached pages pick up the change.

| Feature | Behaviour |
|---------|-----------|
| **Merkliste** | Adds a heart icon to product tiles and a floating widget button (with item count) that opens a popover listing saved products. The list itself is kept in the browser's LocalStorage only – no account binding, no server sync, distinct from any "Meine Lieblingsprodukte" account feature. Disabling it leaves customers' existing browser data untouched. For a temporary introduction statistic the widget sends anonymous usage events (`init`, `add`, `remove`, `popover_open`) to the public endpoint `POST /wp-json/wc-plz/v1/merkliste-stats`, which only increments global counters (shown in the Statistik tab); this is planned to be removed again. |
| **Cart-Indicator** | Draws an outline in the accent colour around a tile's add-to-cart button (a bottom line on the shop's custom grid) when that product is already in the cart. Reads the WooCommerce Store API cart client-side; a variation counts as "in cart" for its parent product's tile, and multiple variations of the same product still show a single outline (no quantity shown). |

For LocalStorage schema, caching, live-update strategy, and theme/plugin compatibility details, see [docs/widgets.md](docs/widgets.md).

## Produktübersicht (Packliste)

Navigate to **WooCommerce → DT Woohoo → Zusatz-Features** to enable the **Produktübersicht** page and set its access password. This replaces the former "DT konsolidierte Produktliste" n8n workflows with a native, password-protected, self-hosted page that consolidates open orders' line items by product into a packing list for warehouse staff — split into a **Lokal** view (by delivery date) and a **Postversand** view. Refunded quantities are netted out, and products ordered in different units are listed as per-unit subtotals (e.g. "3 kg + 4 Stück").

For the full access model, page provisioning behaviour, and REST API reference (endpoint, request/response shape, auth model for integrating from other apps), see [docs/product-overview.md](docs/product-overview.md).

---

## Developer Reset

The **Entwickler-Reset** button under WooCommerce → DT Woohoo → Liefermodus deletes the delivery-mode cookie (`wc_delivery_mode`) in your own browser, so the popup can appear again. It does not touch the WooCommerce customer session (the postcode synced there stays) or the hidden-product lists in `localStorage`. With the default popup trigger, the popup only reopens automatically once the cart is not empty.

## Uninstall

**Deactivating** the plugin removes its cron events and takes its capabilities away from the roles (`manage_plz_filter` from administrators and shop managers, `manage_woohoo_updates` from administrators) but keeps all data. Capabilities granted to individual users (e.g. `manage_woohoo_updates` via "Update-Rechte vergeben") stay on those users until the plugin is deleted.

**Deleting** the plugin in WordPress (*Plugins → Delete*) runs `uninstall.php` and **irreversibly removes all plugin data**: every option (settings, webhook secret, logs incl. the Mail-Log), the `wp_wc_plz_events` statistics table, the plugin's transients, the `manage_plz_filter` / `manage_woohoo_updates` capabilities (from roles and individual users), its cron events and the auto-provisioned Produktübersicht page. Only the `reminded_pending_payment` order meta is kept as order history.

To take the plugin out of service temporarily — e.g. after a fatal error — deactivate it or rename its folder via FTP, never delete it (see the [Runbook](https://github.com/ke-ma-fi/wc-plz-filter/blob/main/docs/RUNBOOK.md)).
