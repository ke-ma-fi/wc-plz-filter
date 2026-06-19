/**
 * WC PLZ-Filter – Merkliste (LocalStorage, Tile-Toggle, Popover)
 * Vanilla JS, no jQuery.
 */
(function () {
  "use strict";

  var M = window.wcPlzMerkliste;
  if (!M) return;

  var STORAGE_KEY = "plz_merkliste";
  var CACHE_KEY   = "plz_mk_pcache";
  var CACHE_TTL   = 600000; // 10 minutes
  var storeApiBase = (M.storeApiUrl || "/wp-json/wc/store/v1").replace(/\/$/, "");

  /* ── LocalStorage helpers ───────────────────── */

  function getMerkliste() {
    try {
      var parsed = JSON.parse(localStorage.getItem(STORAGE_KEY));
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function setMerkliste(ids) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(ids)); } catch (e) {}
  }

  function addProduct(id) {
    var list = getMerkliste();
    if (list.indexOf(id) === -1) { list.push(id); setMerkliste(list); }
  }

  function removeProduct(id) {
    setMerkliste(getMerkliste().filter(function (x) { return x !== id; }));
  }

  function isInMerkliste(id) {
    return getMerkliste().indexOf(id) !== -1;
  }

  /* ── Product cache ──────────────────────────── */

  function loadProductCache() {
    try {
      var c = JSON.parse(localStorage.getItem(CACHE_KEY) || "null");
      if (c && c.ts && Date.now() - c.ts < CACHE_TTL) return c.map;
    } catch (e) {}
    return null;
  }

  function saveProductCache(productMap) {
    try {
      localStorage.setItem(CACHE_KEY, JSON.stringify({ ts: Date.now(), map: productMap }));
    } catch (e) {}
  }

  /* ── Shared tile utils (from plz-popup.js) ─── */

  var tiles = window.wcPlzTiles;
  if (!tiles) return;
  var getProductIdFromEl = tiles.getProductIdFromEl;
  var getAllTiles = tiles.getAllTiles;

  /* ── Toggle-Icon in Kacheln ─────────────────── */

  var TOGGLE_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" fill="currentColor" ' +
    'width="13" height="15" aria-hidden="true">' +
    '<path d="M240 432L64 432c-8.8 0-16-7.2-16-16L48 96c0-8.8 7.2-16 16-16l320 0c8.8 0 16 7.2 16 16l0 176-88 0c-39.8 0-72 32.2-72 72l0 88z' +
    'M380.1 320L288 412.1 288 344c0-13.3 10.7-24 24-24l68.1 0z' +
    'M0 416c0 35.3 28.7 64 64 64l197.5 0c17 0 33.3-6.7 45.3-18.7L429.3 338.7c12-12 18.7-28.3 18.7-45.3L448 96c0-35.3-28.7-64-64-64L64 32C28.7 32 0 60.7 0 96L0 416z"/>' +
    "</svg>";

  function ensureToggleIcon(tile, productId) {
    if (tile.querySelector(".wc-plz-mk-toggle")) return;
    if (getComputedStyle(tile).position === "static") tile.classList.add("wc-plz-tile-positioned");
    var btn = document.createElement("button");
    btn.className = "wc-plz-mk-toggle";
    btn.type = "button";
    btn.setAttribute("aria-label", "Zur Merkliste hinzufügen");
    btn.dataset.productId = productId;
    btn.innerHTML = TOGGLE_SVG;
    tile.appendChild(btn);
  }

  function applyTileIcons() {
    var tiles = getAllTiles();
    tiles.forEach(function (tile) {
      var id = getProductIdFromEl(tile);
      if (!id) return;
      ensureToggleIcon(tile, id);
      var btn = tile.querySelector(".wc-plz-mk-toggle");
      if (!btn) return;
      var active = isInMerkliste(id);
      btn.classList.toggle("wc-plz-mk-toggle--active", active);
      btn.setAttribute("aria-label", active ? "Von Merkliste entfernen" : "Zur Merkliste hinzufügen");
    });
  }

  /* ── Widget-Button (Zahl + Sichtbarkeit) ────── */

  function updateWidget() {
    var btn = document.getElementById("wc-plz-merkliste-btn");
    if (!btn) return;
    var count = getMerkliste().length;
    btn.classList.toggle("wc-plz-merkliste-btn--visible", count > 0);
    var countEl = document.getElementById("wc-plz-merkliste-count");
    if (countEl) {
      countEl.textContent = count > 99 ? "99+" : String(count);
      countEl.classList.toggle("wc-plz-merkliste-btn__count--visible", count > 0);
    }
  }

  /* ── Popover ────────────────────────────────── */

  var popover = null;
  var popoverOpen = false;
  var previouslyFocused = null;

  function getFocusableEls(container) {
    return Array.prototype.slice.call(
      container.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])')
    );
  }

  function getOrCreatePopover() {
    if (popover) return popover;
    popover = document.createElement("div");
    popover.id = "wc-plz-merkliste-popover";
    popover.setAttribute("role", "dialog");
    popover.setAttribute("aria-modal", "true");
    popover.setAttribute("aria-label", "Merkliste");
    popover.innerHTML =
      '<div class="wc-plz-merkliste-popover__header">' +
        '<h3 class="wc-plz-merkliste-popover__title">Merkliste</h3>' +
        '<button class="wc-plz-merkliste-popover__close" type="button" aria-label="Merkliste schliessen">' +
          '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
        "</button>" +
      "</div>" +
      '<div class="wc-plz-merkliste-popover__list" id="wc-plz-merkliste-list"></div>' +
      '<div class="wc-plz-merkliste-popover__footer">' +
        '<p class="wc-plz-merkliste-popover__note">Diese Merkliste wird nur in diesem Browser gespeichert und geht beim Löschen der Browserdaten verloren.</p>' +
        '<button type="button" class="wc-plz-merkliste-popover__clear">Liste leeren</button>' +
      "</div>";
    document.body.appendChild(popover);
    popover.querySelector(".wc-plz-merkliste-popover__close").addEventListener("click", closePopover);
    popover.querySelector(".wc-plz-merkliste-popover__clear").addEventListener("click", function () {
      setMerkliste([]);
      applyTileIcons();
      updateWidget();
      var listEl = popover.querySelector("#wc-plz-merkliste-list");
      if (listEl) listEl.innerHTML = '<p class="wc-plz-merkliste-popover__empty">Keine Produkte auf der Merkliste.</p>';
    });
    return popover;
  }

  function openPopover() {
    if (popoverOpen) { closePopover(); return; }
    previouslyFocused = document.activeElement;
    var p = getOrCreatePopover();
    p.style.display = "flex";
    popoverOpen = true;
    positionPopover(p);
    renderPopoverList(p);
    var closeBtn = p.querySelector(".wc-plz-merkliste-popover__close");
    if (closeBtn) closeBtn.focus();
  }

  function closePopover() {
    if (!popover || !popoverOpen) return;
    popoverOpen = false;
    if (previouslyFocused) { previouslyFocused.focus(); previouslyFocused = null; }
    popover.classList.add("wc-plz-merkliste-popover--closing");
    // Fallback for prefers-reduced-motion or browsers that suppress the animation
    var hideTimer = setTimeout(function () {
      popover.classList.remove("wc-plz-merkliste-popover--closing");
      popover.style.display = "none";
    }, 250);
    popover.addEventListener("animationend", function () {
      clearTimeout(hideTimer);
      popover.classList.remove("wc-plz-merkliste-popover--closing");
      popover.style.display = "none";
    }, { once: true });
  }

  function positionPopover(p) {
    var btn = document.getElementById("wc-plz-merkliste-btn");
    if (!btn) return;
    var rect = btn.getBoundingClientRect();
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var pw = p.offsetWidth || 320;

    // Vertical: open above button when in lower half of screen
    if (rect.top > vh / 2) {
      p.style.bottom = (vh - rect.top + 8) + "px";
      p.style.top = "auto";
    } else {
      p.style.top = (rect.bottom + 8) + "px";
      p.style.bottom = "auto";
    }

    // Horizontal: anchor to near side, clamp so popover stays within viewport
    if (rect.left < vw / 2) {
      p.style.left = Math.max(8, Math.min(rect.left, vw - pw - 8)) + "px";
      p.style.right = "auto";
    } else {
      p.style.right = Math.max(8, Math.min(vw - rect.right, vw - pw - 8)) + "px";
      p.style.left = "auto";
    }
  }

  /* ── Produkt-Daten laden (mit Cache) ────────── */

  function fetchProductData(ids, callback) {
    if (!ids.length) { callback([]); return; }

    // Check cache first
    var cached = loadProductCache();
    if (cached) {
      var allCached = ids.every(function (id) { return cached[id]; });
      if (allCached) {
        // Serve from cache immediately; refresh in background
        var cachedProducts = ids.map(function (id) { return cached[id]; }).filter(Boolean);
        callback(cachedProducts, true); // true = from cache
        fetchFromApi(ids, function (fresh) {
          if (fresh) callback(fresh, false);
        });
        return;
      }
    }

    fetchFromApi(ids, callback);
  }

  var STORE_API_PER_PAGE = 100;

  function fetchFromApi(ids, callback) {
    if (ids.length > STORE_API_PER_PAGE) {
      var chunks = [], all = [], left, done = false;
      for (var i = 0; i < ids.length; i += STORE_API_PER_PAGE) chunks.push(ids.slice(i, i + STORE_API_PER_PAGE));
      left = chunks.length;
      chunks.forEach(function (chunk) {
        fetchFromApi(chunk, function (products) {
          if (done) return;
          if (!products) { done = true; callback(null); return; }
          all = all.concat(products);
          if (--left === 0) { done = true; callback(all); }
        });
      });
      return;
    }

    var params = ids.map(function (id) { return "include[]=" + id; }).join("&");
    var xhr = new XMLHttpRequest();
    xhr.open("GET", storeApiBase + "/products?" + params + "&per_page=" + ids.length, true);
    xhr.timeout = 8000;
    xhr.ontimeout = function () { callback(null); };
    xhr.onerror  = function () { callback(null); };
    xhr.onabort  = function () { callback(null); };
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          var products = JSON.parse(xhr.responseText);
          // Update cache
          var map = loadProductCache() || {};
          products.forEach(function (p) { map[p.id] = p; });
          saveProductCache(map);
          callback(products);
        } catch (e) { callback(null); }
      } else {
        callback(null);
      }
    };
    xhr.send();
  }

  /* ── Popover-Liste rendern ──────────────────── */

  function renderPopoverList(p) {
    var listEl = p.querySelector("#wc-plz-merkliste-list");
    if (!listEl) return;
    var ids = getMerkliste();

    if (ids.length === 0) {
      listEl.innerHTML = '<p class="wc-plz-merkliste-popover__empty">Keine Produkte auf der Merkliste.</p>';
      return;
    }

    // Show "Lade…" only if no cache available
    var cached = loadProductCache();
    var allCached = cached && ids.every(function (id) { return cached[id]; });
    if (!allCached) {
      listEl.innerHTML = '<p class="wc-plz-merkliste-popover__loading">Lade…</p>';
    }

    fetchProductData(ids, function (products, fromCache) {
      if (!products) {
        if (!fromCache) {
          listEl.innerHTML = '<p class="wc-plz-merkliste-popover__empty">Produkte konnten nicht geladen werden.</p>';
        }
        return;
      }

      // Keep LocalStorage order; build id→product map
      var productMap = {};
      products.forEach(function (prod) { productMap[prod.id] = prod; });

      var html = ids.map(function (id) {
        var prod = productMap[id];
        if (!prod) return "";
        var img  = (prod.images && prod.images[0] && prod.images[0].thumbnail) || "";
        var name = prod.name || "";
        var price = (prod.prices && prod.prices.price_html) ? prod.prices.price_html : "";
        return (
          '<div class="wc-plz-merkliste-item" data-product-id="' + id + '">' +
            (img ? '<img class="wc-plz-merkliste-item__img" src="' + escHtml(img) + '" alt="" loading="lazy">' : '') +
            '<div class="wc-plz-merkliste-item__details">' +
              '<span class="wc-plz-merkliste-item__name">' + escHtml(name) + "</span>" +
              (price ? '<span class="wc-plz-merkliste-item__price">' + price + "</span>" : "") +
            "</div>" +
            '<div class="wc-plz-merkliste-item__actions">' +
              '<button type="button" class="wc-plz-merkliste-item__btn wc-plz-merkliste-item__btn--cart" ' +
                'data-product-id="' + id + '" aria-label="In den Warenkorb">' +
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>' +
              "</button>" +
              '<button type="button" class="wc-plz-merkliste-item__btn wc-plz-merkliste-item__btn--remove" ' +
                'data-product-id="' + id + '" aria-label="Von Merkliste entfernen">' +
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>' +
              "</button>" +
            "</div>" +
          "</div>"
        );
      }).join("");

      listEl.innerHTML = html || '<p class="wc-plz-merkliste-popover__empty">Keine Produkte gefunden.</p>';
    });
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;")
      .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }

  /* ── AJAX Add-to-Cart ──────────────────────── */

  var CART_SVG_OK =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">' +
    '<polyline points="20 6 9 17 4 12"/></svg>';

  var CART_SVG_ERR =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">' +
    '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

  function ajaxAddToCart(productId, btn) {
    if (btn.disabled) return;
    var origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.style.opacity = "0.5";

    var body = new URLSearchParams();
    body.append("product_id", productId);
    body.append("quantity", "1");

    var xhr = new XMLHttpRequest();
    xhr.open("POST", "/?wc-ajax=add_to_cart", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
    xhr.timeout = 8000;

    function reset(delay) {
      setTimeout(function () {
        btn.innerHTML = origHtml;
        btn.disabled = false;
        btn.style.opacity = "";
      }, delay);
    }

    xhr.ontimeout = xhr.onerror = function () {
      btn.innerHTML = CART_SVG_ERR;
      btn.style.opacity = "1";
      reset(1500);
    };

    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;

      if (xhr.status < 200 || xhr.status >= 300) {
        btn.innerHTML = CART_SVG_ERR;
        btn.style.opacity = "1";
        reset(1500);
        return;
      }

      var res;
      try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }

      if (res && res.error) {
        btn.innerHTML = CART_SVG_ERR;
        btn.style.opacity = "1";
        reset(1500);
        return;
      }

      // Success: remove from Merkliste
      removeProduct(productId);
      applyTileIcons();
      updateWidget();

      // Remove item row after brief checkmark feedback
      btn.innerHTML = CART_SVG_OK;
      btn.style.opacity = "1";
      setTimeout(function () {
        var item = btn.closest(".wc-plz-merkliste-item");
        if (item) item.remove();
        if (popover) {
          var listEl = popover.querySelector("#wc-plz-merkliste-list");
          if (listEl && !listEl.querySelector(".wc-plz-merkliste-item")) {
            listEl.innerHTML = '<p class="wc-plz-merkliste-popover__empty">Keine Produkte auf der Merkliste.</p>';
          }
        }
      }, 700);

      // Update WC cart fragments (mini-cart widget etc.)
      if (res && res.fragments && window.jQuery) {
        window.jQuery.each(res.fragments, function (key, value) {
          window.jQuery(key).replaceWith(value);
        });
        window.jQuery(document.body).trigger("wc_fragments_refreshed");
      }

      // Trigger cart indicator refresh
      document.dispatchEvent(new CustomEvent("wc-blocks_added_to_cart"));
    };

    xhr.send(body.toString());
  }

  /* ── Event-Delegation ───────────────────────── */

  document.addEventListener("click", function (e) {
    // Kachel-Toggle
    var toggleBtn = e.target.closest(".wc-plz-mk-toggle");
    if (toggleBtn) {
      e.preventDefault();
      e.stopPropagation();
      var id = parseInt(toggleBtn.dataset.productId, 10);
      if (isInMerkliste(id)) { removeProduct(id); } else { addProduct(id); }
      applyTileIcons();
      updateWidget();
      if (popoverOpen && popover) renderPopoverList(popover);
      return;
    }

    // Popover öffnen via Merkliste-Button
    var merklisteBtn = e.target.closest("#wc-plz-merkliste-btn");
    if (merklisteBtn) {
      e.preventDefault();
      openPopover();
      return;
    }

    // Entfernen-Button im Popover
    var removeBtn = e.target.closest(".wc-plz-merkliste-item__btn--remove");
    if (removeBtn) {
      var pid = parseInt(removeBtn.dataset.productId, 10);
      removeProduct(pid);
      var item = removeBtn.closest(".wc-plz-merkliste-item");
      if (item) item.remove();
      applyTileIcons();
      updateWidget();
      if (popover) {
        var listEl = popover.querySelector("#wc-plz-merkliste-list");
        if (listEl && !listEl.querySelector(".wc-plz-merkliste-item")) {
          listEl.innerHTML = '<p class="wc-plz-merkliste-popover__empty">Keine Produkte auf der Merkliste.</p>';
        }
      }
      return;
    }

    // In-den-Warenkorb-Button im Popover
    var cartBtn = e.target.closest(".wc-plz-merkliste-item__btn--cart");
    if (cartBtn) {
      e.preventDefault();
      var productId = parseInt(cartBtn.dataset.productId, 10);
      if (productId) ajaxAddToCart(productId, cartBtn);
      return;
    }

    // Klick außerhalb schließt Popover
    if (popoverOpen && popover && !popover.contains(e.target) && !e.target.closest("#wc-plz-merkliste-btn")) {
      closePopover();
    }
  });

  // Escape schließt; Tab bleibt im Popover
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && popoverOpen) { closePopover(); return; }
    if (e.key === "Tab" && popoverOpen && popover) {
      var focusable = getFocusableEls(popover);
      if (!focusable.length) { e.preventDefault(); return; }
      var first = focusable[0], last = focusable[focusable.length - 1];
      if (e.shiftKey) {
        if (document.activeElement === first) { e.preventDefault(); last.focus(); }
      } else {
        if (document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }
  });

  /* ── Init ───────────────────────────────────── */

  function init() {
    applyTileIcons();
    updateWidget();

    // Warm up cache if merkliste is not empty
    var ids = getMerkliste();
    if (ids.length && !loadProductCache()) {
      fetchFromApi(ids, function () {}); // background prefetch, no-op callback
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  window.addEventListener("pageshow", function (e) {
    if (e.persisted) { applyTileIcons(); updateWidget(); }
  });
})();
