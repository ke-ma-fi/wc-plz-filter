/**
 * WC PLZ-Filter – Cart-Indicator
 * Umrandet Produktkacheln grün, wenn das Produkt bereits im Warenkorb liegt.
 * Vanilla JS, no jQuery. Nutzt WC Store API für Cart-Daten.
 */
(function () {
  "use strict";

  var C = window.wcPlzCartIndicator;
  if (!C) return;

  var storeApiBase = (C.storeApiUrl || "/wp-json/wc/store/v1").replace(/\/$/, "");

  /* ── Shared tile utils (from plz-popup.js) ─── */

  var tiles = window.wcPlzTiles;
  if (!tiles) return;
  var getProductIdFromEl = tiles.getProductIdFromEl;
  var getAllTiles = tiles.getAllTiles;

  /* ── Umrandung des Add-to-Cart-Buttons anwenden ─ */

  // AJAX-Button bei simple products; bei variablen/externen Produkten verlinkt
  // die Kachel stattdessen auf die Produktseite ("Optionen wählen" etc.) —
  // generisches .button als Fallback deckt diesen Fall mit ab.
  //
  // Custom-Shop-Grid (fgf-Plugin): .ptocart sitzt dort sowohl auf dem
  // Mengen-<input> als auch auf dem Add-to-Cart-<button> — ein .ptocart-Query
  // wäre mehrdeutig und träfe zufällig das erste Element (das Mengenfeld).
  // .pinputs ist der gemeinsame Wrapper beider Controls und eindeutig.
  function getCartButton(tile) {
    return tile.querySelector(".add_to_cart_button") ||
      tile.querySelector(".pinputs") ||
      tile.querySelector("button.ptocart") ||
      tile.querySelector("a.button, button.button");
  }

  function applyIndicators(inCartIds) {
    var allTiles = getAllTiles();
    allTiles.forEach(function (tile) {
      var id = getProductIdFromEl(tile);
      if (!id) return;
      var btn = getCartButton(tile);
      if (!btn) return;
      btn.classList.toggle("wc-plz-in-cart", inCartIds.indexOf(id) !== -1);
    });
  }

  function closestTile(el) {
    return el.closest("[class*='pdb'], .products li[class*='post-']");
  }

  // Shows the indicator the instant an add-to-cart click fires instead of
  // waiting on a network round trip - the Store API fetch below, or (worse)
  // the fixed setTimeout guess used for the fgf grid, which on a slow
  // request can fire before the server has actually persisted the cart (see
  // the click handler further down). fetchCart()'s subsequent
  // applyIndicators() call fully re-derives every tile's state from the real
  // cart shortly after, so a failed add (out of stock, etc.) self-corrects.
  function applyOptimisticIndicator(clickedEl) {
    var tile = closestTile(clickedEl);
    if (!tile) return;
    var btn = getCartButton(tile);
    if (btn) btn.classList.add("wc-plz-in-cart");
  }

  /* ── WC Store API: Cart abrufen ─────────────── */

  var fetchInFlight = false;

  function fetchCart() {
    if (fetchInFlight) return;
    fetchInFlight = true;
    var xhr = new XMLHttpRequest();
    xhr.open("GET", storeApiBase + "/cart", true);
    xhr.timeout = 8000;
    function onError() { fetchInFlight = false; applyIndicators([]); }
    xhr.ontimeout = onError;
    xhr.onerror   = onError;
    xhr.onabort  = function () { fetchInFlight = false; };
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      fetchInFlight = false;
      if (xhr.status < 200 || xhr.status >= 300) return;
      try {
        var cart = JSON.parse(xhr.responseText);
        var inCart = extractParentIds(cart);
        applyIndicators(inCart);
      } catch (e) {}
    };
    xhr.send();
  }

  // Liefert deduplizierte übergeordnete Produkt-IDs aus allen Cart-Items.
  // Die Store API liefert kein natives parent_id-Feld – bei Varianten ist
  // "id" die Varianten-ID, nicht die des übergeordneten Produkts. Das
  // Plugin erweitert die Cart-Response serverseitig (siehe
  // WC_PLZ_Cart_Indicator::register_store_api_extension) um genau dieses
  // Feld unter extensions["wc-plz-filter"].parent_id (0 bei simple products).
  function extractParentIds(cart) {
    if (!cart || !Array.isArray(cart.items)) return [];
    var seen = {};
    var ids = [];
    cart.items.forEach(function (item) {
      var ext = item.extensions && item.extensions["wc-plz-filter"];
      var id = (ext && ext.parent_id) || item.id;
      if (id && !seen[id]) {
        seen[id] = true;
        ids.push(id);
      }
    });
    return ids;
  }

  /* ── Live-Update ────────────────────────────── */

  // WC Blocks: native CustomEvents
  document.addEventListener("wc-blocks_added_to_cart", fetchCart);
  document.addEventListener("wc-blocks_removed_from_cart", fetchCart);
  document.addEventListener("wc-blocks_set_cart_data", fetchCart);

  // WC Classic AJAX: jQuery event is authoritative when available; setTimeout is the fallback.
  // Avoid both firing: the jQuery handler cancels the pending timeout.
  document.addEventListener("click", function (e) {
    // button.ptocart is the custom shop's own add-to-cart button (fgf-Plugin-Grid).
    // Tag-qualified to exclude the quantity <input class="ptocart"> in the same
    // wrapper. Its AJAX handler lives in that plugin, not here, so we can't
    // assume it dispatches added_to_cart or any other WC event — always poll.
    var ptocartBtn = e.target.closest("button.ptocart");
    if (ptocartBtn) {
      applyOptimisticIndicator(ptocartBtn);
      setTimeout(fetchCart, 1200);
      return;
    }

    // .ajax_add_to_cart is only present when the button actually triggers an
    // AJAX add — WooCommerce omits it for variable/grouped/external products,
    // where the same .add_to_cart_button class just links to the product
    // page instead of adding anything. Only real adds get the optimistic
    // indicator; the broader check below (unqualified) still covers those
    // other cases for the reconciling fetch.
    var ajaxBtn = e.target.closest(".add_to_cart_button.ajax_add_to_cart");
    if (ajaxBtn) applyOptimisticIndicator(ajaxBtn);

    if (!e.target.closest(".add_to_cart_button, .single_add_to_cart_button")) return;
    if (window.jQuery) return; // jQuery path handles this via added_to_cart event below
    setTimeout(fetchCart, 1200);
  });

  // jQuery compat: listen for added_to_cart / removed_from_cart events
  if (window.jQuery) {
    window.jQuery(document.body).on("added_to_cart removed_from_cart", fetchCart);
  }

  // WC Classic: MutationObserver on cart count element as fallback
  var cartCountObserver = null;

  function watchCartCount() {
    var selectors = [
      ".cart-contents-count",
      ".cart-count",
      ".header-cart-count",
      '[class*="cart"][class*="count"]',
      ".woocommerce-cart-count",
    ];
    var target = null;
    for (var i = 0; i < selectors.length; i++) {
      target = document.querySelector(selectors[i]);
      if (target) break;
    }
    if (!target) return;
    cartCountObserver = new MutationObserver(fetchCart);
    cartCountObserver.observe(target, { childList: true, characterData: true, subtree: true });
  }

  /* ── Init ───────────────────────────────────── */

  // WC sets woocommerce_items_in_cart=1 when the cart is not empty.
  // Reading a cookie costs nothing and avoids a REST call (+ session cookie) for
  // visitors with an empty cart — keeping WP Rocket page cache intact for them.
  function cartIsKnownNonEmpty() {
    return document.cookie.indexOf("woocommerce_items_in_cart=1") !== -1;
  }

  function init() {
    if (cartIsKnownNonEmpty()) fetchCart();
    watchCartCount();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  // bfcache: Cart-Status nach Back-Navigation neu laden
  window.addEventListener("pageshow", function (e) {
    if (e.persisted) fetchCart();
  });
})();
