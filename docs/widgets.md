# Zusatz-Features: Merkliste & Cart-Indicator

Two independent, client-side-only product tile widgets. Both are self-contained (own option, own enqueue, own markup) and never referenced directly by the core `WC_PLZ_Filter` class — they hook into it only through the `wc_plz_widget_group_extra` action and `wc_plz_nowprocket_handles` filter, so the core never needs to know either exists.

Toggle both under **WooCommerce → DT Woohoo → Zusatz-Features**. Enabled by default; disabling either leaves customers' existing browser data untouched.

Relevant source:

- [`includes/class-wc-plz-merkliste.php`](../includes/class-wc-plz-merkliste.php) / [`assets/js/merkliste.js`](../assets/js/merkliste.js)
- [`includes/class-wc-plz-cart-indicator.php`](../includes/class-wc-plz-cart-indicator.php) / [`assets/js/cart-indicator.js`](../assets/js/cart-indicator.js)

## Merkliste (wishlist)

A LocalStorage-only product wishlist — no account binding, no server sync, distinct from any account-based "favourites" feature. Consists of:

- A toggle icon injected into each product tile's image wrapper
- A floating widget button (rendered inside the badge's fixed-position widget group) showing the saved-item count
- A popover listing saved products, opened from that button

### Storage

Product IDs are kept as a plain JSON array in `localStorage['plz_merkliste']`. A separate cache, `localStorage['plz_mk_pcache']`, stores the last-fetched WooCommerce Store API product data (`{ ts, map: { [id]: product } }`) with a 10-minute TTL, so reopening the popover shortly after doesn't re-fetch. On a cache hit the popover renders immediately from the stale cache and silently refreshes in the background.

### Product data fetching

Product details (name, image, price, permalink) come from the public WC Store API `/products?include[]=<id>&...` endpoint — no custom REST endpoint. Requests are chunked at 100 IDs per call (`STORE_API_PER_PAGE`) since that's the Store API's `per_page` ceiling.

`price_html` from the Store API is run through an allowlist sanitizer (`sanitizePriceHtml`) before being inserted into the popover — it keeps WooCommerce's own formatting tags (`span`, `del`, `ins`, `strong`, `em`, `bdi`, `abbr`, `wbr`) and their `class`/`title` attributes, and strips everything else. This guards against a third-party plugin filtering `price_html` into something unexpected before it reaches this widget.

### Accessibility

The popover is a `role="dialog"` with `aria-modal="true"`; opening it moves focus to the close button and restores focus to the previously-focused element on close. `Tab`/`Shift+Tab` are trapped within the popover's focusable elements, and `Escape` closes it.

## Cart-Indicator

Draws a green outline around a tile's add-to-cart control when that product is already in the cart. Reads cart state from the WC Store API cart endpoint client-side; there is no server-side "is in cart" computation beyond the extension below.

### Parent-ID Store API extension

The Store API cart response has no parent-product-id field — for a variation cart item, `id` is the variation's own post ID, not the parent product's. Since tiles are keyed by parent product ID, matching would silently fail for every variable product. `WC_PLZ_Cart_Indicator::register_store_api_extension()` (hooked on `woocommerce_blocks_loaded`) adds a `parent_id` field to the cart item schema under `extensions["wc-plz-filter"]` (`$product->get_parent_id()`, `0` for simple products), which the frontend (`extractParentIds()`) reads to resolve variation cart items back to their tile.

### Tile / add-to-cart button detection

`getCartButton()` tries selectors in order to support both the standard WooCommerce loop markup and the shop's custom grid (the "fgf" plugin, see [`docs/NAMING.md`](NAMING.md) context): `.add_to_cart_button` → `.pinputs` (the fgf grid's shared wrapper around its quantity input and button — `.ptocart` alone is ambiguous, it sits on both) → `button.ptocart` → a generic `a.button, button.button` fallback for variable/external products that link to the product page instead of adding directly.

### Cache-friendly empty-cart short-circuit

On page load, `cartIsKnownNonEmpty()` checks WooCommerce's own `woocommerce_items_in_cart` cookie before making any REST call. If it's absent (empty cart), the widget makes **no** request at all — avoiding both the API round trip and the WooCommerce session cookie it would otherwise force, which matters because a session cookie prevents a full-page cache (WP Rocket) from serving that visitor a cached page.

### Live-update strategy

Three independent mechanisms keep the indicator in sync, since the shop uses a mix of WooCommerce Blocks and classic AJAX add-to-cart depending on the page:

1. **WC Blocks events** — `wc-blocks_added_to_cart` / `_removed_from_cart` / `_set_cart_data` CustomEvents trigger an immediate re-fetch.
2. **Classic AJAX** — if jQuery is present, its `added_to_cart` / `removed_from_cart` events are used (authoritative, fires right after the AJAX call resolves). If jQuery is absent, a generic click listener on `.add_to_cart_button, .single_add_to_cart_button` falls back to a `setTimeout(fetchCart, 1200)` poll. The custom fgf grid's own `button.ptocart` always uses the poll (1.2s delay) since that plugin's AJAX handler doesn't dispatch any of the above events.
3. **MutationObserver fallback** — watches the theme's cart-count element (tries `.cart-contents-count`, `.cart-count`, `.header-cart-count`, `.woocommerce-cart-count`, or any `[class*="cart"][class*="count"]`) and re-fetches on any mutation, as a catch-all for cart changes triggered outside the paths above (e.g. a mini-cart widget).

The cart is also re-fetched on `pageshow` when `event.persisted` is true (bfcache back/forward navigation).
