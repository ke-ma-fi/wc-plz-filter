/**
 * WC PLZ-Filter – Cart-Indicator
 * Zeigt ein grünes Kreis-Icon auf Produktkacheln wenn das Produkt im Warenkorb liegt.
 * Vanilla JS, no jQuery. Nutzt WC Store API für Cart-Daten.
 */
(function () {
  "use strict";

  var C = window.wcPlzCartIndicator;
  if (!C) return;

  var storeApiBase = (C.storeApiUrl || "/wp-json/wc/store/v1").replace(/\/$/, "");

  var CART_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" ' +
    'stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" ' +
    'width="13" height="13" aria-hidden="true">' +
    '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>' +
    '<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>' +
    "</svg>";

  /* ── Produkt-IDs aus Kachel-DOM ─────────────── */

  function getProductIdFromEl(el) {
    if (!el) return null;
    var classes = el.className || "";
    var m = classes.match(/\bpdb(\d+)\b/);
    if (m) return parseInt(m[1], 10);
    m = classes.match(/\bpost-(\d+)\b/);
    if (m) return parseInt(m[1], 10);
    return null;
  }

  function getAllTiles() {
    var results = [];
    var pdbEls = document.querySelectorAll("[class*='pdb']");
    pdbEls.forEach(function (el) {
      if (/\bpdb\d+\b/.test(el.className)) results.push(el);
    });
    var wcEls = document.querySelectorAll(".products li[class*='post-']");
    wcEls.forEach(function (el) {
      if (/\bpost-\d+\b/.test(el.className) && results.indexOf(el) === -1) {
        results.push(el);
      }
    });
    return results;
  }

  /* ── Indicators auf Kacheln anwenden ────────── */

  function applyIndicators(inCartIds) {
    var tiles = getAllTiles();
    tiles.forEach(function (tile) {
      var id = getProductIdFromEl(tile);
      if (!id) return;

      var existing = tile.querySelector(".wc-plz-cart-indicator");
      var inCart = inCartIds.indexOf(id) !== -1;

      if (inCart) {
        if (!existing) {
          if (getComputedStyle(tile).position === "static") {
            tile.classList.add("wc-plz-tile-positioned");
          }
          var indicator = document.createElement("span");
          indicator.className = "wc-plz-cart-indicator";
          indicator.innerHTML = CART_SVG;
          tile.appendChild(indicator);
        }
      } else {
        if (existing) existing.remove();
      }
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
    xhr.ontimeout = function () { fetchInFlight = false; };
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

  /* ── Live-Update (jQuery-frei) ──────────────── */

  // WC Blocks: native CustomEvents
  document.addEventListener("wc-blocks_added_to_cart", fetchCart);
  document.addEventListener("wc-blocks_removed_from_cart", fetchCart);
  document.addEventListener("wc-blocks_set_cart_data", fetchCart);

  // WC Classic: MutationObserver auf Cart-Count-Element
  // Themes verwenden unterschiedliche Selektoren — alle gängigen abdecken
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
    cartCountObserver = new MutationObserver(function () {
      fetchCart();
    });
    cartCountObserver.observe(target, { childList: true, characterData: true, subtree: true });
  }

  /* ── Init ───────────────────────────────────── */

  function init() {
    fetchCart();
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
