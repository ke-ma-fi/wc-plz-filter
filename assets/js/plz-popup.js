/**
 * WC PLZ-Filter v2.7.2 – Frontend (Vanilla JS, no jQuery)
 * Popup (PLZ + Abholung), Badge with Tooltip, Checkout-Sync
 *
 * @copyright Metzgerei Fischer. All rights reserved.
 */
(function () {
  "use strict";

  var D = window.wcPlz;
  if (!D) return;

  var COOKIE = D.cookieName;
  var DAYS = parseInt(D.cookieDays, 10) || 30;
  var nonce = D.nonce; // Initial-Wert aus dem (potentiell gecachten) HTML
  var nonceFetchInFlight = null;

  /* ── Helpers ────────────────────────────────── */

  function $(sel) {
    return document.querySelector(sel);
  }

  function getCookie(name) {
    var m = document.cookie.match(
      new RegExp(
        "(?:^|; )" + name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "=([^;]*)",
      ),
    );
    return m ? decodeURIComponent(m[1]) : "";
  }

  function setCookie(name, value, days) {
    var exp = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie =
      name +
      "=" +
      encodeURIComponent(value) +
      "; expires=" +
      exp +
      "; path=/" +
      (location.protocol === "https:" ? "; Secure" : "") +
      "; SameSite=Lax";
  }

  /* ── Nonce-Refresh (Page-Cache-tolerant) ─── */

  function refreshNonce(cb) {
    if (nonceFetchInFlight) {
      nonceFetchInFlight.push(cb);
      return;
    }
    nonceFetchInFlight = [cb];
    var xhr = new XMLHttpRequest();
    xhr.open("GET", D.nonceUrl, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      var fresh = null;
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          var res = JSON.parse(xhr.responseText);
          if (res && res.nonce) fresh = res.nonce;
        } catch (e) {}
      }
      if (fresh) nonce = fresh;
      var queue = nonceFetchInFlight;
      nonceFetchInFlight = null;
      for (var i = 0; i < queue.length; i++) {
        if (queue[i]) queue[i](fresh);
      }
    };
    xhr.send();
  }

  /* ── AJAX helper (replaces $.post) ─────────── */

  // res === -1 (oder 0) ist die Standard-WP-Antwort bei abgelaufenem/falschem Nonce
  // (admin-ajax.php, check_ajax_referer ohne 3rd arg = die mit -1).
  function isNonceFailure(xhr) {
    if (xhr.status === 403) return true;
    var body = (xhr.responseText || "").trim();
    return body === "-1" || body === "0";
  }

  function post(url, data, onSuccess, onError, _retried) {
    var body = new URLSearchParams();
    for (var key in data) {
      if (data.hasOwnProperty(key) && key !== "nonce") {
        body.append(key, data[key]);
      }
    }
    body.append("nonce", nonce); // immer den aktuellen Token nehmen

    var xhr = new XMLHttpRequest();
    xhr.open("POST", url, true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
    xhr.timeout = 10000;
    xhr.ontimeout = function () { if (onError) onError(); };
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;

      // Nonce abgelaufen (Page-Cache älter als nonce_life) → einmal frischen holen + retry
      if (!_retried && isNonceFailure(xhr)) {
        refreshNonce(function (fresh) {
          if (!fresh) { if (onError) onError(); return; }
          post(url, data, onSuccess, onError, true);
        });
        return;
      }

      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          var res = JSON.parse(xhr.responseText);
          if (onSuccess) onSuccess(res);
        } catch (e) {
          if (onError) onError();
        }
      } else {
        if (onError) onError();
      }
    };
    xhr.send(body.toString());
  }

  /* ── Fade helpers (replaces $.fadeIn/Out) ──── */

  function fadeIn(el, duration) {
    if (!el) return;
    el.style.display = "";
    el.style.opacity = "0";
    el.style.transition = "opacity " + (duration || 200) + "ms ease";
    // Force reflow before transition
    void el.offsetWidth;
    el.style.opacity = "1";
  }

  function fadeOut(el, duration) {
    if (!el) return;
    el.style.transition = "opacity " + (duration || 200) + "ms ease";
    el.style.opacity = "0";
    setTimeout(function () {
      el.style.display = "none";
      el.style.transition = "";
    }, duration || 200);
  }

  /* ── State ──────────────────────────────────── */

  function parseState() {
    var raw = getCookie(COOKIE);
    if (!raw) return { mode: "", plz: "" };
    var parts = raw.split(":");
    var m = parts[0] || "";
    if (m !== "abholung" && m !== "local" && m !== "post" && m !== "skipped") {
      m = "";
    }
    return {
      mode: m,
      plz: (parts[1] || "").replace(/\D/g, ""),
    };
  }

  var state = parseState();
  if (!state.mode && D.state && D.state.mode) {
    state = { mode: D.state.mode, plz: D.state.plz || "" };
  }

  /* ── Popup ──────────────────────────────────── */

  function openPopup() {
    var overlay = $("#wc-plz-overlay");
    if (!overlay) return;
    fadeIn(overlay, 200);
    var input = $("#wc-plz-input");
    if (input) {
      input.value = state.plz;
      input.focus();
    }
    setFeedback("", "");
  }

  function closePopup() {
    // If the popup closes without any delivery choice, treat it as skipped
    if (!state.mode) {
      saveState("skipped", "");
      state = { mode: "skipped", plz: "" };
      updateBadge("skipped", "");
    }
    fadeOut($("#wc-plz-overlay"), 200);
  }

  function setFeedback(msg, type) {
    var fb = $("#wc-plz-feedback");
    if (!fb) return;
    fb.textContent = msg;
    fb.className = "wc-plz-feedback";
    if (type) fb.classList.add("wc-plz-fb--" + type);
  }

  /* ── State speichern ────────────────────────── */

  function saveState(mode, plz, callback) {
    setCookie(COOKIE, mode + ":" + plz, DAYS);

    post(
      D.ajaxUrl,
      {
        action: "wc_plz_save",
        mode: mode,
        plz: plz,
      },
      function (res) {
        if (res && res.success && res.data && res.data.hidden_ids) {
          localStorage.setItem("wc_plz_hidden_ids", JSON.stringify(res.data.hidden_ids));
        }
        if (callback) callback(res);
      },
    );
  }

  /* ── Tooltip direction ─────────────────────── */

  function getTooltipDir(pos) {
    switch (pos) {
      case "top-left":
      case "top-right":
        return "bottom";
      case "left-center":
        return "right";
      case "right-center":
        return "left";
      default:
        return "top";
    }
  }

  /* ── Badge ──────────────────────────────────── */

  function updateBadge(mode, plz) {
    var badge = $("#wc-plz-badge");
    var info = $("#wc-plz-badge-info");
    var icon = $("#wc-plz-badge-icon");
    var tooltip = $("#wc-plz-badge-tooltip");

    if (!badge || !mode) {
      if (badge) badge.style.display = "none";
      return;
    }

    badge.classList.remove(
      "wc-plz-badge--abholung",
      "wc-plz-badge--local",
      "wc-plz-badge--post",
      "wc-plz-badge--skipped",
    );

    // Set tooltip direction
    var tooltipDir = getTooltipDir(D.badgePosition || "bottom-right");
    if (tooltip) {
      tooltip.className = "wc-plz-badge__tooltip";
      tooltip.classList.add("wc-plz-badge__tooltip--" + tooltipDir);
    }

    switch (mode) {
      case "abholung":
        if (icon) icon.textContent = "\uD83C\uDFEA";
        if (info) info.textContent = "Abholung";
        if (tooltip) tooltip.textContent = D.badgeTooltipAbholung || "";
        badge.classList.add("wc-plz-badge--abholung");
        break;
      case "local":
        if (icon) icon.textContent = "\uD83D\uDE9A";
        if (info)
          info.innerHTML =
            plz + ' <span class="wc-plz-badge__sep">\u00B7</span> Lieferung';
        if (tooltip) tooltip.textContent = D.badgeTooltipLocal || "";
        badge.classList.add("wc-plz-badge--local");
        break;
      case "post":
        if (icon) icon.textContent = "\uD83D\uDCE6";
        if (info)
          info.innerHTML =
            plz + ' <span class="wc-plz-badge__sep">\u00B7</span> Versand';
        if (tooltip) tooltip.textContent = D.badgeTooltipPost || "";
        badge.classList.add("wc-plz-badge--post");
        break;
      case "skipped":
        if (icon) icon.textContent = "\uD83D\uDCCD";
        if (info) info.textContent = "Kein Filter gesetzt";
        if (tooltip) tooltip.textContent = D.badgeTooltipSkipped || "";
        badge.classList.add("wc-plz-badge--skipped");
        break;
    }

    fadeIn(badge, 300);
  }

  /* ── Hidden IDs Fetching ────────────────────── */

  function fetchHiddenIds() {
    var xhr = new XMLHttpRequest();
    var url = D.ajaxUrl + "?action=wc_plz_hidden_ids&v=" + (D.version || "0");
    xhr.open("GET", url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          var res = JSON.parse(xhr.responseText);
          if (res.success && res.data && res.data.ids) {
            localStorage.setItem("wc_plz_hidden_ids", JSON.stringify(res.data.ids));
            applyHiddenIds();
          }
        } catch (e) {}
      }
    };
    xhr.send();
  }

  function applyHiddenIds() {
    var styleEl = document.getElementById("wc-plz-hide-style");
    if (state.mode === "post") {
      var ids = [];
      try {
        ids = JSON.parse(localStorage.getItem("wc_plz_hidden_ids") || "[]");
      } catch(e) {}
      
      if (!ids.length) return;
      
      // .pdb{ID} = fgf-Custom-Grid; .products .post-{ID} = WC-Standard-Loops
      // (Cross-Sells / Up-Sells / Related / Shop). Niemals body.post-{ID} oder
      // article.post-{ID} matchen, sonst verschwindet die Single-Product-Page.
      var sel = ids.map(function(id){ return ".pdb" + id + ", .products .post-" + id; }).join(",");
      if (!sel) return;
      
      if (!styleEl) {
        styleEl = document.createElement("style");
        styleEl.id = "wc-plz-hide-style";
        (document.head || document.documentElement).appendChild(styleEl);
      }
      styleEl.textContent = sel + "{display:none!important}";
    } else {
      if (styleEl) {
        styleEl.textContent = "";
      }
    }
  }

  /* ── Prefill PLZ (JS fallback for checkout) ── */

  function prefillPostcode() {
    var billing = $("#billing_postcode");
    if (!billing) return;

    if (state.mode === "abholung") {
      // Don't force clear it if the user typed it, but if we need to sync state:
      // Actually, for pickup we don't strictly need to clear billing postcode as they still need a billing address.
      // But we should ensure we don't prefill a stale one.
    } else if (state.plz && !billing.value) {
      billing.value = state.plz;
      billing.dispatchEvent(new Event("change", { bubbles: true }));
    }
  }

  /* ── PLZ prüfen ─────────────────────────────── */

  function handlePlzSubmit() {
    var btn = $("#wc-plz-submit");
    var input = $("#wc-plz-input");
    if (!btn || !input) return;

    var plz = input.value.replace(/\D/g, "");

    if (!/^\d{5}$/.test(plz)) {
      setFeedback("Bitte eine gültige 5-stellige PLZ eingeben.", "error");
      input.focus();
      return;
    }

    btn.disabled = true;
    btn.textContent = "Prüfe …";
    setFeedback("", "");

    post(
      D.ajaxUrl,
      {
        action: "wc_plz_check",
        plz: plz,
      },
      function (res) {
        btn.disabled = false;
        btn.textContent = "Prüfen";

        if (!res.success) {
          setFeedback(
            res.data && res.data.message ? res.data.message : "Fehler.",
            "error",
          );
          return;
        }

        var r = res.data;
        if (r.hidden_ids) {
          localStorage.setItem("wc_plz_hidden_ids", JSON.stringify(r.hidden_ids));
        }
        setFeedback(r.message, r.is_local ? "ok" : "warn");

        saveState(r.mode, r.plz);
        state = { mode: r.mode, plz: r.plz };
        updateBadge(r.mode, r.plz);

        setTimeout(function () {
          closePopup();
          location.reload();
        }, 1200);
      },
      function () {
        btn.disabled = false;
        btn.textContent = "Prüfen";
        setFeedback("Verbindungsfehler. Bitte erneut versuchen.", "error");
      },
    );
  }

  /* ── Init ───────────────────────────────────── */

  function init() {
    if (state.mode) {
      updateBadge(state.mode, state.plz);
    } else if (!parseInt(D.isCheckout, 10)) {
      setTimeout(openPopup, 800);
    }

    applyHiddenIds();
    fetchHiddenIds();

    prefillPostcode();

    // Submit button
    var submitBtn = $("#wc-plz-submit");
    if (submitBtn) {
      submitBtn.addEventListener("click", handlePlzSubmit);
    }

    // PLZ input
    var plzInput = $("#wc-plz-input");
    if (plzInput) {
      plzInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          handlePlzSubmit();
        }
      });
      plzInput.addEventListener("input", function () {
        this.value = this.value.replace(/\D/g, "").slice(0, 5);
      });
    }

    // Pickup button
    var pickupBtn = $("#wc-plz-pickup");
    if (pickupBtn) {
      pickupBtn.addEventListener("click", function () {
        var btn = $("#wc-plz-submit");
        if (btn) btn.disabled = true;

        saveState("abholung", "");
        state = { mode: "abholung", plz: "" };
        updateBadge("abholung", "");

        setTimeout(function () {
          closePopup();
          location.reload();
        }, 300);
      });
    }

    // Skip button
    var skipBtn = $("#wc-plz-skip");
    if (skipBtn) {
      skipBtn.addEventListener("click", function () {
        saveState("skipped", "");
        state = { mode: "skipped", plz: "" };
        updateBadge("skipped", "");
        closePopup();
      });
    }

    // Overlay click
    var overlay = $("#wc-plz-overlay");
    if (overlay) {
      overlay.addEventListener("click", function (e) {
        if (e.target === overlay) closePopup();
      });
    }

    // Escape key
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") closePopup();
    });

    // Badge click
    var badge = $("#wc-plz-badge");
    if (badge) {
      badge.addEventListener("click", openPopup);
    }

    // Checkout: sync postcode changes
    if (parseInt(D.isCheckout, 10)) {
      document.addEventListener("change", function (e) {
        if (!e.target || e.target.id !== "billing_postcode") return;
        var newPlz = e.target.value.replace(/\D/g, "");
        if (/^\d{5}$/.test(newPlz) && newPlz !== state.plz && state.mode) {
          state.plz = newPlz;
          setCookie(COOKIE, state.mode + ":" + newPlz, DAYS);
          post(
            D.ajaxUrl,
            {
              action: "wc_plz_save",
                    mode: state.mode,
              plz: newPlz,
            },
            function (res) {
              if (res && res.success && res.data && res.data.hidden_ids) {
                localStorage.setItem("wc_plz_hidden_ids", JSON.stringify(res.data.hidden_ids));
                applyHiddenIds();
              }
            }
          );
        }
      });
    }
  }

  /* ── bfcache: re-sync on back/forward navigation ── */

  window.addEventListener("pageshow", function (e) {
    if (e.persisted) {
      // Page was restored from bfcache — re-read cookie
      var fresh = parseState();
      if (fresh.mode !== state.mode || fresh.plz !== state.plz) {
        location.reload();
      }
    }
  });

  // Run when DOM is ready
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
