/**
 * WC PLZ-Filter – Merkliste (LocalStorage, Tile-Toggle, Popover)
 * Vanilla JS, no jQuery.
 */
(function () {
  "use strict";

  var M = window.wcPlzMerkliste;
  if (!M) return;

  var STORAGE_KEY = "plz_merkliste";
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
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
    } catch (e) {}
  }

  function addProduct(id) {
    var list = getMerkliste();
    if (list.indexOf(id) === -1) {
      list.push(id);
      setMerkliste(list);
    }
  }

  function removeProduct(id) {
    setMerkliste(getMerkliste().filter(function (x) { return x !== id; }));
  }

  function isInMerkliste(id) {
    return getMerkliste().indexOf(id) !== -1;
  }

  /* ── Product-ID aus Kachel-DOM ──────────────── */

  // Unterstützt .pdb{id} (fgf-Custom-Grid) und .post-{id} (WC-Standard-Loop)
  function getProductIdFromEl(el) {
    if (!el) return null;
    var classes = el.className || "";
    var m = classes.match(/\bpdb(\d+)\b/);
    if (m) return parseInt(m[1], 10);
    m = classes.match(/\bpost-(\d+)\b/);
    if (m) return parseInt(m[1], 10);
    // data-product-id auf dem Element selbst
    if (el.dataset && el.dataset.productId) return parseInt(el.dataset.productId, 10);
    return null;
  }

  // Findet alle Produktkacheln auf der aktuellen Seite
  function getAllTiles() {
    var results = [];
    // fgf-Custom-Grid: Elemente mit pdb{id}-Klasse
    var pdbEls = document.querySelectorAll("[class*='pdb']");
    pdbEls.forEach(function (el) {
      if (/\bpdb\d+\b/.test(el.className)) results.push(el);
    });
    // WC-Standard-Loop: .products li.post-{id}
    var wcEls = document.querySelectorAll(".products li[class*='post-']");
    wcEls.forEach(function (el) {
      if (/\bpost-\d+\b/.test(el.className) && results.indexOf(el) === -1) {
        results.push(el);
      }
    });
    return results;
  }

  /* ── Toggle-Icon in Kacheln injizieren ─────── */

  var TOGGLE_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" ' +
    'stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" ' +
    'width="15" height="15" aria-hidden="true">' +
    '<path d="M12 20h9"/>' +
    '<path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>' +
    "</svg>";

  function ensureToggleIcon(tile, productId) {
    if (tile.querySelector(".wc-plz-mk-toggle")) return;
    // Kachel braucht position:relative für absolute Icon-Positionierung
    if (getComputedStyle(tile).position === "static") {
      tile.classList.add("wc-plz-tile-positioned");
    }
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
      btn.setAttribute(
        "aria-label",
        active ? "Von Merkliste entfernen" : "Zur Merkliste hinzufügen"
      );
    });
  }

  /* ── Widget-Button (Zahl + Sichtbarkeit) ────── */

  function updateWidget() {
    var btn = document.getElementById("wc-plz-merkliste-btn");
    if (!btn) return;

    var list = getMerkliste();
    var count = list.length;

    btn.style.display = count > 0 ? "" : "none";

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
      container.querySelectorAll(
        'button:not([disabled]), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
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
        '<p class="wc-plz-merkliste-popover__note">' +
          "Diese Merkliste wird nur in diesem Browser gespeichert und geht beim Löschen der Browserdaten verloren." +
        "</p>" +
      "</div>";
    document.body.appendChild(popover);

    popover
      .querySelector(".wc-plz-merkliste-popover__close")
      .addEventListener("click", closePopover);

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
    if (!popover) return;
    popover.style.display = "none";
    popoverOpen = false;
    if (previouslyFocused) previouslyFocused.focus();
    previouslyFocused = null;
  }

  // Positioniert Popover relativ zur Widget-Group oder dem Merkliste-Button
  function positionPopover(p) {
    var btn = document.getElementById("wc-plz-merkliste-btn");
    if (!btn) return;
    var rect = btn.getBoundingClientRect();
    var vw = window.innerWidth;
    var vh = window.innerHeight;

    // Vertikal: oberhalb des Buttons wenn unten wenig Platz
    if (rect.top > vh / 2) {
      p.style.bottom = (vh - rect.top + 8) + "px";
      p.style.top = "auto";
    } else {
      p.style.top = (rect.bottom + 8) + "px";
      p.style.bottom = "auto";
    }

    // Horizontal: an Button-Seite ausrichten
    if (rect.left < vw / 2) {
      p.style.left = rect.left + "px";
      p.style.right = "auto";
    } else {
      p.style.right = (vw - rect.right) + "px";
      p.style.left = "auto";
    }
  }

  /* ── Produkt-Daten per WC Store API laden ───── */

  var STORE_API_PER_PAGE = 100;

  function fetchProductData(ids, callback) {
    if (!ids.length) { callback([]); return; }

    // WC Store API caps per_page at 100 — chunk larger lists
    if (ids.length > STORE_API_PER_PAGE) {
      var chunks = [];
      for (var i = 0; i < ids.length; i += STORE_API_PER_PAGE) {
        chunks.push(ids.slice(i, i + STORE_API_PER_PAGE));
      }
      var all = [];
      var left = chunks.length;
      var done = false;
      chunks.forEach(function (chunk) {
        fetchProductData(chunk, function (products) {
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
        try { callback(JSON.parse(xhr.responseText)); } catch (e) { callback(null); }
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

    listEl.innerHTML = '<p class="wc-plz-merkliste-popover__loading">Lade…</p>';

    fetchProductData(ids, function (products) {
      if (!products) {
        listEl.innerHTML = '<p class="wc-plz-merkliste-popover__empty">Produkte konnten nicht geladen werden.</p>';
        return;
      }
      // Reihenfolge aus LocalStorage beibehalten
      var productMap = {};
      products.forEach(function (p) { productMap[p.id] = p; });

      var html = ids.map(function (id) {
        var prod = productMap[id];
        if (!prod) return "";
        var img = (prod.images && prod.images[0] && prod.images[0].thumbnail) || "";
        var name = prod.name || "";
        var price = (prod.prices && prod.prices.price_html) ? prod.prices.price_html : "";
        var addToCartUrl = (prod.add_to_cart && prod.add_to_cart.url) ? prod.add_to_cart.url : "#";
        return (
          '<div class="wc-plz-merkliste-item" data-product-id="' + id + '">' +
            (img ? '<img class="wc-plz-merkliste-item__img" src="' + escHtml(img) + '" alt="">' : '') +
            '<div class="wc-plz-merkliste-item__details">' +
              '<span class="wc-plz-merkliste-item__name">' + escHtml(name) + "</span>" +
              // price_html from WC Store API is intentional HTML (formatted price markup from WC itself)
            (price ? '<span class="wc-plz-merkliste-item__price">' + price + "</span>" : "") +
            "</div>" +
            '<div class="wc-plz-merkliste-item__actions">' +
              '<button type="button" class="wc-plz-merkliste-item__btn wc-plz-merkliste-item__btn--cart" ' +
                'data-add-to-cart-url="' + escHtml(addToCartUrl) + '" data-product-id="' + id + '" ' +
                'aria-label="In den Warenkorb">' +
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
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  /* ── Event-Delegation ───────────────────────── */

  document.addEventListener("click", function (e) {
    // Kachel-Toggle
    var toggleBtn = e.target.closest(".wc-plz-mk-toggle");
    if (toggleBtn) {
      e.preventDefault();
      e.stopPropagation();
      var id = parseInt(toggleBtn.dataset.productId, 10);
      if (isInMerkliste(id)) {
        removeProduct(id);
      } else {
        addProduct(id);
      }
      applyTileIcons();
      updateWidget();
      // Popover live aktualisieren wenn offen
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
      // Item aus Popover-DOM entfernen
      var item = removeBtn.closest(".wc-plz-merkliste-item");
      if (item) item.remove();
      applyTileIcons();
      updateWidget();
      // Leer-Zustand prüfen
      if (popover) {
        var list = popover.querySelector("#wc-plz-merkliste-list");
        if (list && !list.querySelector(".wc-plz-merkliste-item")) {
          list.innerHTML = '<p class="wc-plz-merkliste-popover__empty">Keine Produkte auf der Merkliste.</p>';
        }
      }
      return;
    }

    // In-den-Warenkorb-Button im Popover
    var cartBtn = e.target.closest(".wc-plz-merkliste-item__btn--cart");
    if (cartBtn) {
      var url = cartBtn.dataset.addToCartUrl;
      if (url && url !== "#") {
        var isRelative = url.charAt(0) === "/" || url.charAt(0) === ".";
        var isSameOrigin = url.indexOf(window.location.origin + "/") === 0;
        if (isRelative || isSameOrigin) {
          window.location.href = url;
        }
      }
      return;
    }

    // Klick außerhalb schließt Popover
    if (
      popoverOpen &&
      popover &&
      !popover.contains(e.target) &&
      !e.target.closest("#wc-plz-merkliste-btn")
    ) {
      closePopover();
    }
  });

  // Escape schließt Popover; Tab wird innerhalb des Popovers gefangen
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && popoverOpen) { closePopover(); return; }
    if (e.key === "Tab" && popoverOpen && popover) {
      var focusable = getFocusableEls(popover);
      if (!focusable.length) { e.preventDefault(); return; }
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
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
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  // bfcache: Icons nach Back-Navigation neu anwenden
  window.addEventListener("pageshow", function (e) {
    if (e.persisted) {
      applyTileIcons();
      updateWidget();
    }
  });
})();
