# Produktübersicht (Packliste)

Native, in-plugin replacement for the two "DT konsolidierte Produktliste Lokal/Postversand" n8n workflows. Consolidates open WooCommerce orders' line items by product, for warehouse staff to use as a packing list.

Relevant source:

- [`includes/class-woohoo-product-overview.php`](../includes/class-woohoo-product-overview.php) — page provisioning, password gate, REST endpoint
- [`includes/class-woohoo-po-aggregator.php`](../includes/class-woohoo-po-aggregator.php) — pure order-aggregation logic (no WordPress output)
- [`assets/js/product-overview.js`](../assets/js/product-overview.js) — reference client (fetch + DOM rendering)
- [`includes/admin/class-woohoo-module-widgets.php`](../includes/admin/class-woohoo-module-widgets.php) — admin settings UI

## Enabling & configuring

**WooCommerce → DT Woohoo → Zusatz-Features** tab:

| Field | Option | Notes |
|-------|--------|-------|
| Enable | `woohoo_product_overview_enabled` | Toggles the page between `publish` and `draft` (draft 404s on the front-end). |
| Passwort | `woohoo_product_overview_settings[password]` | Write-only field — blank submission keeps the existing password. Stored as a `wp_hash_password()` hash, never in plaintext. |
| Sitzungsdauer (Tage) | `woohoo_product_overview_settings[session_days]` | Clamped 1–90. Controls how long the unlock cookie stays valid. |

The page itself is auto-provisioned at a fixed path, **`/woohoo-product-overview/`** (`Woohoo_Product_Overview::DEFAULT_PATH`) — not admin-configurable. It is created, renamed back to that slug, and published/drafted automatically whenever the settings above change (`sync_page()`).

## Access model

The page and the REST endpoint share one authorization check, with two ways to satisfy it:

1. **WP staff accounts** — any user with `WC_PLZ_Filter::MANAGE_CAP` (administrators and shop managers) is authorized immediately, no password needed, same as the rest of the plugin's admin surfaces.
2. **Shared password** — for staff without a WP account. Submitting the correct password on the gate form sets a signed, stateless cookie (`woohoo_po_auth`): `"<expiry>:<hmac>"`, where the HMAC covers the current password hash + expiry, keyed by `wp_salt('auth')`. There's no server-side session store, and changing the password invalidates every previously-unlocked browser instantly. Failed attempts are throttled per IP (8 attempts / 15 min lockout).

The page renders standalone — its own `<!doctype html>`, no theme header/footer/nav, no WooCommerce chrome. It's an internal tool, not a shop-facing page. `DONOTCACHEPAGE` is forced so WP Rocket's full-page cache never serves one visitor's authenticated view (or the lockout state) to the next.

## REST endpoint

```
GET /wp-json/woohoo/v1/product-overview
```

### Query parameters

| Param | Required | Format | Description |
|-------|----------|--------|--------------|
| `mode` | yes | `local` \| `post` | `local` = orders whose delivery-date meta matches `date`. `post` = all open orders shipped via "Postversand", date-agnostic. |
| `date` | only if `mode=local` | `YYYY-MM-DD` | Matched against the `_willii_delivery_date` order meta. |
| `exclude_postcodes` | no | comma-separated string | Orders whose shipping postcode exactly matches one of these are dropped. Non-digit characters are stripped from each entry before matching. |

Only orders in WooCommerce status `processing` with no completion date are included in either mode.

### Authentication

The `permission_callback` accepts either:

- **Capability check**: current user has `WC_PLZ_Filter::MANAGE_CAP`. This is what a WordPress **Application Password** (HTTP Basic Auth) grants once WordPress resolves the current user from the request — no `X-WP-Nonce` header is required on this path, since nonce verification in this handler only guards the cookie-based branch below.
- **Password-cookie + nonce**: a valid `woohoo_po_auth` cookie (see above) *and* an `X-WP-Nonce` header verified against WordPress's own `wp_rest` action. The nonce is only ever handed out server-side, embedded in the authenticated HTML page (`woohooPO.nonce`) — there is no way to obtain it without first loading that page as an authorized browser session. This path is for the plugin's own bundled client, not for external integrations.

**Practical implication for other apps:** integrate via Application Passwords against a user holding `MANAGE_CAP`, not via the cookie/nonce path.

```bash
curl -u "staff-user:xxxx xxxx xxxx xxxx xxxx xxxx" \
  "https://shop.example.com/wp-json/woohoo/v1/product-overview?mode=post&exclude_postcodes=63679,37170"
```

The response always sends `Cache-Control: no-store, max-age=0` — it is never behind a page cache and reflects live order state on every call, so it's safe to poll but should not be cached by the caller either.

### Success response — `200`

```jsonc
{
  "groups": [
    {
      "name": "Bio-Rinderhack 500g",     // product (parent) name
      "sku": "RIND-500",                 // product SKU, "" if unset
      "total_label": "3,5 kg gesamt",    // pre-formatted total across all matched orders
      "orders": [
        {
          "customer_name": "Erika Musterfrau",
          "shipping_method": "Postversand",
          "variant": "Gewürzt, Portion A", // joined attribute meta, "—" if none
          "qty_label": "2 × 500 g = 1 kg"  // pre-formatted quantity line
        }
      ]
    }
  ]
}
```

Notes on the shape:

- `groups` is sorted descending by total quantity (heaviest/most-ordered product first).
- Every row within a group's `orders` array is already de-duplicated/merged: multiple line items across different orders collapse into one row when customer name, variant, shipping method, and per-unit weight all match.
- All numeric aggregation and unit conversion (kg/g/l/ml/cl/mg, "Xer"/"X Paar" pack sizes) happens server-side. Clients only ever receive the two pre-formatted display strings (`total_label`, `qty_label`) — there is no raw numeric quantity field to re-derive.
- Every string field is caller-controlled data (customer names, product names) that has **not** been HTML-escaped — it is plain text meant for `textContent`/JSON consumption, not for `innerHTML` concatenation. This is deliberate (see the aggregator's class docblock): the original n8n version built HTML strings directly and was vulnerable to stored XSS via order billing names. Any renderer for this endpoint's data must treat every string as untrusted text, not markup.

### Error responses

All errors are standard `WP_Error`-shaped REST responses:

```json
{ "code": "woohoo_po_bad_mode", "message": "Ungültiger Modus.", "data": { "status": 400 } }
```

| HTTP | `code` | Cause |
|------|--------|-------|
| 400 | `woohoo_po_bad_mode` | `mode` missing or not `local`/`post` |
| 400 | `woohoo_po_bad_date` | `mode=local` with a missing/malformed `date` |
| 401 | `woohoo_po_unauthorized` | Feature enabled, but no valid auth (cookie or capability) |
| 403 | `woohoo_po_bad_nonce` | Cookie-auth path taken, but `X-WP-Nonce` missing/invalid |
| 404 | `woohoo_po_disabled` | Feature toggle is off and the caller isn't `MANAGE_CAP` |
| 500 | `woohoo_po_query_failed` | Exception during aggregation; `message` includes the underlying exception message for diagnosis from the browser network tab alone |

### Minimal client example

```js
const params = new URLSearchParams({ mode: 'post', exclude_postcodes: '63679' });

const res = await fetch(`https://shop.example.com/wp-json/woohoo/v1/product-overview?${params}`, {
  headers: { Authorization: 'Basic ' + btoa('staff-user:xxxx xxxx xxxx xxxx xxxx xxxx') },
});

const body = await res.json();
if (!res.ok) {
  throw new Error(body.message ?? `HTTP ${res.status}`);
}

for (const group of body.groups) {
  console.log(group.name, group.total_label);
  for (const order of group.orders) {
    console.log(' ', order.customer_name, order.qty_label);
  }
}
```

For browser-based (not server-to-server) integrations from a different origin, note that no CORS headers are added by this endpoint — WordPress's default REST CORS behavior (same-origin only, unless a separate CORS-enabling plugin/filter is active) applies. Cross-origin browser calls will need that configured separately; server-to-server calls via Application Passwords are unaffected.
