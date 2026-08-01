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
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor" ' +
    'width="11" height="12" aria-hidden="true">' +
    '<path d="M225.8 468.2l-2.5-2.3L48.1 303.2C17.4 274.7 0 234.7 0 192.8l0-3.3c0-70.4 50-130.9 119.2-144.3c46.2-9 93.7 7.7 123.9 43.7l12.9 15.4 12.9-15.4c30.2-36 77.7-52.7 123.9-43.7C462 58.6 512 119.1 512 189.5l0 3.3c0 41.9-17.4 81.9-48.1 110.4L288.7 465.9l-2.5 2.3c-8.2 7.6-19 11.9-30.2 11.9s-22-4.3-30.2-11.9z"/>' +
    "</svg>";

  function ensureToggleIcon(tile, productId) {
    if (tile.querySelector(".wc-plz-mk-toggle")) return;
    var wrapper = tiles.getImageWrapper(tile);
    wrapper.classList.add("wc-plz-tile-positioned");
    var btn = document.createElement("button");
    btn.className = "wc-plz-mk-toggle";
    btn.type = "button";
    btn.setAttribute("aria-label", "Zur Merkliste hinzufügen");
    btn.dataset.productId = productId;
    btn.innerHTML = TOGGLE_SVG;
    wrapper.appendChild(btn);
  }

  function applyTileIcons() {
    var list = getMerkliste();
    var allTiles = getAllTiles();
    allTiles.forEach(function (tile) {
      var id = getProductIdFromEl(tile);
      if (!id) return;
      ensureToggleIcon(tile, id);
      var btn = tile.querySelector(".wc-plz-mk-toggle");
      if (!btn) return;
      var active = list.indexOf(id) !== -1;
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
      // Skip background-refresh write if the popover is already closed
      if (!popoverOpen && !fromCache) return;
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
        var price = (prod.prices && prod.prices.price_html) ? sanitizePriceHtml(prod.prices.price_html) : "";
        var permalink = prod.permalink || "";
        return (
          '<div class="wc-plz-merkliste-item" data-product-id="' + id + '">' +
            (img ? '<img class="wc-plz-merkliste-item__img" src="' + escHtml(img) + '" alt="" loading="lazy">' : '') +
            '<div class="wc-plz-merkliste-item__details">' +
              '<span class="wc-plz-merkliste-item__name">' + escHtml(name) + "</span>" +
              (price ? '<span class="wc-plz-merkliste-item__price">' + price + "</span>" : "") +
            "</div>" +
            '<div class="wc-plz-merkliste-item__actions">' +
              (permalink ?
                '<button type="button" class="wc-plz-merkliste-item__btn wc-plz-merkliste-item__btn--goto" ' +
                  'data-permalink="' + escHtml(permalink) + '" aria-label="Zum Produkt">' +
                  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M4 4l7.07 17 2.51-7.39L21 11.07z"/></svg>' +
                "</button>" : ""
              ) +
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

  // Sanitizes WC price_html: keeps formatting tags, strips everything else.
  // price_html is WC-generated HTML (del/ins/span for sale prices) but may be
  // filtered by third-party plugins that echo user-controlled content.
  var PRICE_HTML_ALLOWED = { SPAN: 1, DEL: 1, INS: 1, STRONG: 1, EM: 1, BDI: 1, ABBR: 1, WBR: 1 };
  function sanitizePriceHtml(html) {
    var tmp = document.createElement("div");
    tmp.innerHTML = String(html);
    var nodes = tmp.querySelectorAll("*");
    for (var i = nodes.length - 1; i >= 0; i--) {
      var el = nodes[i];
      if (!PRICE_HTML_ALLOWED[el.tagName]) {
        el.parentNode.replaceChild(document.createTextNode(el.textContent), el);
      } else {
        var attrs = Array.prototype.slice.call(el.attributes);
        for (var j = 0; j < attrs.length; j++) {
          var attrName = attrs[j].name.toLowerCase();
          if (attrName !== "class" && attrName !== "title") {
            el.removeAttribute(attrs[j].name);
          }
        }
      }
    }
    return tmp.innerHTML;
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

    // Zum-Produkt-Button im Popover
    var gotoBtn = e.target.closest(".wc-plz-merkliste-item__btn--goto");
    if (gotoBtn) {
      e.preventDefault();
      var permalink = gotoBtn.dataset.permalink;
      if (permalink) window.location.href = permalink;
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
