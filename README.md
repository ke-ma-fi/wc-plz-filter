# WC PLZ-Filter

A lightweight WooCommerce plugin for German online shops that presents customers with a **postal-code (PLZ) popup** on their first visit and lets them pick a delivery mode. Based on their choice, the shop dynamically filters products and pre-fills the checkout.

---

## Features

- **Three delivery modes** selectable via popup:
  - 🏪 **Abholung** – in-store pickup (all products available)
  - 🚚 **Lokale Lieferung** – local delivery (postal code checked against WooCommerce shipping zones)
  - 📦 **Postversand** – postal shipping (configurable product classes hidden, e.g. fresh goods)
- **Dynamic zone detection** – reads postcode ranges and wildcards directly from WooCommerce shipping zones (no manual list maintenance)
- **Product filtering** – hides products with excluded shipping classes in postal-shipping mode
- **Checkout pre-fill** – automatically fills the billing postcode from the stored cookie
- **Floating badge** – shows current delivery mode; click to reopen the popup
- **Admin settings page** – configure excluded shipping classes, popup texts, accent colour, badge position, and cookie lifetime
- **Admin PLZ tester** – check any postcode against detected zones right in the dashboard

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | ≥ 8.0 |
| WordPress | ≥ 6.0 |
| WooCommerce | ≥ 7.0 |

## Installation

1. Download or clone this repository.
2. Copy the plugin folder (or upload `wc-plz-filter.zip`) to `wp-content/plugins/`.
3. Activate **WC PLZ-Filter** in *Plugins → Installed Plugins*.
4. Go to **WooCommerce → PLZ-Filter** to configure the plugin.

## Configuration

Navigate to **WooCommerce → PLZ-Filter** in the WordPress admin:

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
| Badge tooltips | Hover text shown on the badge for each of the three modes |

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
   ┌──┴──────────┬──────────────┐
   ▼             ▼              ▼
Abholung    Enter PLZ      (skip)
(pickup)        │
           In local zone?
           ┌────┴────┐
          Yes        No
           ▼          ▼
       Local       Postal
      delivery    shipping
                  (products filtered)
```

## Uninstall

Deactivating and deleting the plugin via WordPress removes all stored options and transients automatically (`uninstall.php`).

