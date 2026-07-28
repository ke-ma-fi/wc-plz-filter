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

  /* ── Kachel-Umrandung anwenden ──────────────── */

  function applyIndicators(inCartIds) {
    var allTiles = getAllTiles();
    allTiles.forEach(function (tile) {
      var id = getProductIdFromEl(tile);
      if (!id) return;
      tile.classList.toggle("wc-plz-in-cart", inCartIds.indexOf(id) !== -1);
    });
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
  // Für Varianten ist parent_id die übergeordnete ID; für simple products ist id selbst korrekt.
  function extractParentIds(cart) {
    if (!cart || !Array.isArray(cart.items)) return [];
    var seen = {};
    var ids = [];
    cart.items.forEach(function (item) {
      var id = item.parent_id || item.id;
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
