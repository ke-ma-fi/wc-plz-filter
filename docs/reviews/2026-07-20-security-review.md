# Security Review Notes — 2026-07-20

Full plugin review covering security and performance. Most findings were
clean. Two known, lower-severity limitations are tracked below at a high
level; exploitation-level detail is kept in an internal, non-public note and
is not published here while the underlying gap remains open.

## 1. Capability scoping gap around the auto-updater admin UI

**Area:** `includes/class-wc-plz-updater.php`, `wc-plz-filter.php`
(admin rendering)

**Status: known limitation, tracked internally, not yet fixed.**

The plugin's custom `manage_plz_filter` capability is granted to both
`administrator` and `shop_manager`. Some auto-updater actions are
intentionally restricted to the stricter, WordPress-native `manage_options`
capability (administrators only) — but the admin screen that displays
updater configuration is currently gated only by the broader
`manage_plz_filter` capability. This means a `shop_manager`-level account can
view configuration that the plugin otherwise treats as admin-only.

Practical impact is bounded to the plugin's own auto-update mechanism and
requires an already-authenticated `shop_manager` (or higher) account — there
is no unauthenticated attack path here. If you fork this plugin and grant
`manage_plz_filter` to non-admin or external/contractor roles, be aware that
those accounts currently have more visibility into updater configuration than
the `manage_options`-gated actions suggest they should.

**Planned fix:** align the admin screen's capability check with the stricter
capability used by the underlying actions, or move the affected UI behind its
own `manage_options` gate.

## 2. GitHub webhook auto-updater is a remote-deploy surface by design

**Area:** `includes/class-wc-plz-updater.php` (REST webhook handler)

**Status: accepted, intentional design tradeoff — not a bug.**

The plugin includes a signed-webhook auto-updater that redeploys from a
configured GitHub repository on push to `main`. Signature verification uses
a standard HMAC scheme with a high-entropy secret and constant-time
comparison, and updates are restricted to a single configured repo/branch —
this is not arbitrary remote code execution from an external source. As with
any webhook-based auto-deploy mechanism, treat the webhook secret as a
sensitive credential: anyone who obtains it can trigger a redeploy of
whatever is currently on the configured branch. Rotate it if you suspect
exposure (a "regenerate secret" action is provided in the admin UI, restricted
to `manage_options`).

This item is related to #1 above: closing the capability gap in #1 also
tightens who can view this credential from within wp-admin.
