# Security Review Notes — 2026-07-20

Full plugin review covering security and performance. Most findings were
clean. Two known, lower-severity limitations were tracked below at a high
level. Item 1 has since been fixed (see below); item 2 is an accepted design
tradeoff.

## 1. Capability scoping gap around the auto-updater admin UI

**Area:** `includes/class-wc-plz-updater.php`, `wc-plz-filter.php`
(admin rendering)

**Status: fixed** on 2026-07-27 (plugin version 2.9.0 at the time) by commits `15526fa` ("implement
auto-update capability management for administrators") and `3d4adea` ("add
visibility checks for Woohoo modules"):

- New capability `manage_woohoo_updates` (`WC_PLZ_Updater::MANAGE_UPDATE_CAP`),
  granted to the `administrator` role only, never to `shop_manager`.
- The **Updates** tab (repo, branch, webhook secret, webhook URL, update log)
  is only shown to holders of that capability
  (`Woohoo_Module_Updater::is_visible()`, and `render_admin_section()` checks
  it again), and the manual update, secret regeneration and the updater
  settings group require it as well.
- Administrators (`manage_options`) can grant the capability to individual
  users in the Updates tab ("Update-Rechte vergeben").

Original finding, kept for reference:

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

**Planned fix (done):** align the admin screen's capability check with the stricter
capability used by the underlying actions, or move the affected UI behind its
own `manage_options` gate.

## 2. GitHub webhook auto-updater is a remote-deploy surface by design

**Area:** `includes/class-wc-plz-updater.php` (REST webhook handler)

**Status: accepted, intentional design tradeoff — not a bug.**

The plugin includes a signed-webhook auto-updater that redeploys from a
configured GitHub repository on push to the configured branch (default
`main`; set in the Updates tab). Signature verification uses
a standard HMAC scheme with a high-entropy secret and constant-time
comparison, and updates are restricted to a single configured repo/branch —
this is not arbitrary remote code execution from an external source. As with
any webhook-based auto-deploy mechanism, treat the webhook secret as a
sensitive credential: anyone who obtains it can trigger a redeploy of
whatever is currently on the configured branch. Rotate it if you suspect
exposure (a "regenerate secret" action is provided in the Updates tab,
restricted to `manage_woohoo_updates`; procedure in
[`docs/RUNBOOK.md`](../RUNBOOK.md)).

This item is related to #1 above: with #1 fixed, only holders of
`manage_woohoo_updates` can view this credential from within wp-admin.
Since 2.13.0 the updater also refuses parallel runs (HTTP 409 while an update
is in progress) and skips duplicate deliveries of the same
`X-GitHub-Delivery` ID.
