/**
 * WC PLZ-Filter v2 – Frontend
 * Popup (PLZ + Abholung), Badge, Checkout-Sync
 */
(function ($) {
  "use strict";

  var D = wcPlz;
  var COOKIE = D.cookieName;
  var DAYS = parseInt(D.cookieDays, 10) || 30;

  /* ── Cookie-Helfer ──────────────────────────── */

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
    $("#wc-plz-overlay").fadeIn(200);
    $("#wc-plz-input").val(state.plz).trigger("focus");
    setFeedback("", "");
  }

  function closePopup() {
    $("#wc-plz-overlay").fadeOut(200);
  }

  function setFeedback(msg, type) {
    var $fb = $("#wc-plz-feedback");
    $fb.text(msg).removeClass("wc-plz-fb--ok wc-plz-fb--warn wc-plz-fb--error");
    if (type) $fb.addClass("wc-plz-fb--" + type);
  }

  /* ── State speichern ────────────────────────── */

  function saveState(mode, plz, callback) {
    setCookie(COOKIE, mode + ":" + plz, DAYS);

    $.post(
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

  /* ── Badge ──────────────────────────────────── */

  function updateBadge(mode, plz) {
    var $badge = $("#wc-plz-badge");
    var $text = $("#wc-plz-badge-text");
    var $icon = $("#wc-plz-badge-icon");

    if (!mode) {
      $badge.hide();
      return;
    }

    $badge.removeClass(
      "wc-plz-badge--abholung wc-plz-badge--local wc-plz-badge--post",
    );

    switch (mode) {
      case "abholung":
        $icon.text("\uD83C\uDFEA");
        $text.text("Abholung \u2014 alle Produkte verf\u00FCgbar");
        $badge.addClass("wc-plz-badge--abholung");
        break;
      case "local":
        $icon.text("\uD83D\uDE9A");
        $text.text("Lieferung nach " + plz);
        $badge.addClass("wc-plz-badge--local");
        break;
      case "post":
        $icon.text("\uD83D\uDCE6");
        $text.text("Versand nach " + plz + " \u2014 eingeschr\u00E4nkt");
        $badge.addClass("wc-plz-badge--post");
        break;
    }

    $badge.fadeIn(300);
  }

  /* ── Checkout Prefill ───────────────────────── */

  function prefillCheckout() {
    if (!parseInt(D.isCheckout, 10) || !state.plz) return;
    var $f = $("#billing_postcode");
    if ($f.length && !$f.val()) {
      $f.val(state.plz).trigger("change");
    }
  }

  /* ── PLZ pruefen ────────────────────────────── */

  function handlePlzSubmit() {
    var $btn = $("#wc-plz-submit");
    var $input = $("#wc-plz-input");
    var plz = $input.val().replace(/\D/g, "");

    if (!/^\d{5}$/.test(plz)) {
      setFeedback("Bitte eine g\u00FCltige 5-stellige PLZ eingeben.", "error");
      $input.trigger("focus");
      return;
    }

    $btn.prop("disabled", true).text("Pr\u00FCfe \u2026");
    setFeedback("", "");

    $.post(
      D.ajaxUrl,
      {
        action: "wc_plz_check",
        nonce: D.nonce,
        plz: plz,
      },
      function (res) {
        $btn.prop("disabled", false).text("Pr\u00FCfen");

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
    ).fail(function () {
      $btn.prop("disabled", false).text("Pr\u00FCfen");
      setFeedback("Verbindungsfehler. Bitte erneut versuchen.", "error");
    });
  }

  /* ── Init ───────────────────────────────────── */

  $(function () {
    if (state.mode) {
      updateBadge(state.mode, state.plz);
    } else if (!parseInt(D.isCheckout, 10)) {
      setTimeout(openPopup, 800);
    }

    prefillCheckout();

    $("#wc-plz-submit").on("click", handlePlzSubmit);

    $("#wc-plz-input")
      .on("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          handlePlzSubmit();
        }
      })
      .on("input", function () {
        this.value = this.value.replace(/\D/g, "").slice(0, 5);
      });

    $("#wc-plz-pickup").on("click", function () {
      var prevMode = state.mode;

      saveState("abholung", "");
      state = { mode: "abholung", plz: "" };
      updateBadge("abholung", "");
      closePopup();

      if (prevMode === "post") {
        location.reload();
      }
    });

    $("#wc-plz-skip").on("click", closePopup);

    $("#wc-plz-overlay").on("click", function (e) {
      if ($(e.target).is("#wc-plz-overlay")) closePopup();
    });

    $(document).on("keydown", function (e) {
      if (e.key === "Escape") closePopup();
    });

    $("#wc-plz-badge").on("click", openPopup);

    if (parseInt(D.isCheckout, 10)) {
      $(document).on("change", "#billing_postcode", function () {
        var newPlz = $(this).val().replace(/\D/g, "");
        if (/^\d{5}$/.test(newPlz) && newPlz !== state.plz && state.mode) {
          state.plz = newPlz;
          setCookie(COOKIE, state.mode + ":" + newPlz, DAYS);
          $.post(D.ajaxUrl, {
            action: "wc_plz_save",
            nonce: D.nonce,
            mode: state.mode,
            plz: newPlz,
          });
        }
      });
    }
  });
})(jQuery);
