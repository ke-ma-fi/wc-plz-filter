/**
 * WC PLZ-Filter v2.4 – Frontend (Vanilla JS, no jQuery)
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

  /* ── AJAX helper (replaces $.post) ─────────── */

  function post(url, data, onSuccess, onError) {
    var body = new URLSearchParams();
    for (var key in data) {
      if (data.hasOwnProperty(key)) {
        body.append(key, data[key]);
      }
    }

    var xhr = new XMLHttpRequest();
    xhr.open("POST", url, true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
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
    if (m !== "abholung" && m !== "local" && m !== "post") {
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
        nonce: D.nonce,
        mode: mode,
        plz: plz,
      },
      function (res) {
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
    }

    fadeIn(badge, 300);
  }

  /* ── Prefill PLZ (Checkout + Cart) ──────────── */

  function prefillPostcode() {
    if (!state.plz) return;

    // Checkout: billing_postcode
    var billing = $("#billing_postcode");
    if (billing && !billing.value) {
      billing.value = state.plz;
      billing.dispatchEvent(new Event("change", { bubbles: true }));
    }

    // Cart: shipping calculator postcode
    var calcShipping = $("#calc_shipping_postcode");
    if (calcShipping && !calcShipping.value) {
      calcShipping.value = state.plz;
      calcShipping.dispatchEvent(new Event("change", { bubbles: true }));
    }
  }

  /* ── PLZ pruefen ────────────────────────────── */

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
        nonce: D.nonce,
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
        var prevMode = state.mode;

        saveState("abholung", "");
        state = { mode: "abholung", plz: "" };
        updateBadge("abholung", "");
        closePopup();

        if (prevMode === "post") {
          location.reload();
        }
      });
    }

    // Skip button
    var skipBtn = $("#wc-plz-skip");
    if (skipBtn) {
      skipBtn.addEventListener("click", closePopup);
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
          post(D.ajaxUrl, {
            action: "wc_plz_save",
            nonce: D.nonce,
            mode: state.mode,
            plz: newPlz,
          });
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
        state = fresh;
        updateBadge(state.mode, state.plz);
        prefillPostcode();
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
