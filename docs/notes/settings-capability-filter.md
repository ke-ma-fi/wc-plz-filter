# Settings Capability Filter: Central Registry

Every `register_setting()` group in this plugin needs a matching
`option_page_capability_{$group}` filter, or WordPress's `options.php`
defaults to requiring `manage_options` instead of the plugin's own
`WC_PLZ_Filter::MANAGE_CAP` (`manage_plz_filter`). An account that holds
`MANAGE_CAP` but not `manage_options` (e.g. `shop_manager`) can open the tab
fine — the tab itself is gated by `MANAGE_CAP` — but gets rejected by
`options.php` on save, which surfaces as a 403 / "Sorry, you are not allowed
to modify this page."

This used to be solved with one filter closure per module, and it was
forgotten more than once when a new group was added. It is now centralized
(roadmap item C4).

## Where it lives

[`includes/class-wc-plz-filter.php`](../../includes/class-wc-plz-filter.php):

- `WC_PLZ_Filter::settings_group_caps()` — the registry, a plain
  `group => capability` array.
- `WC_PLZ_Filter::register_settings_capabilities()` — loops over the
  registry and adds one `option_page_capability_{$group}` filter per group.
  Hooked on `admin_init` in `WC_PLZ_Filter::init()`.

No module adds its own `option_page_capability_*` filter any more; each
`register_setting()` call carries a short comment pointing to the registry.

| Group | Registered in | Who can save |
|---|---|---|
| `wc_plz_filter_group` | `WC_PLZ_Filter` | `manage_options` OR `MANAGE_CAP` |
| `wc_plz_widgets_group` | `WC_PLZ_Merkliste`, `WC_PLZ_Cart_Indicator` | `manage_options` OR `MANAGE_CAP` |
| `wc_plz_reminder_group` | `WC_PLZ_Reminder` | `manage_options` OR `MANAGE_CAP` |
| `woohoo_product_overview_group` | `Woohoo_Product_Overview` | `manage_options` OR `MANAGE_CAP` |
| `wc_plz_updater_group` | `WC_PLZ_Updater` | `WC_PLZ_Updater::MANAGE_UPDATE_CAP` only (strict) |

Rule: a group mapped to `MANAGE_CAP` gets the `manage_options` shortcut, so
an administrator whose role lost `MANAGE_CAP` (e.g. the activation hook
never re-ran) is not locked out. Any other capability is applied strictly —
the updater group stays limited to administrators plus explicitly granted
users (`manage_woohoo_updates`).

## Adding a new settings group

1. Call `register_setting( 'my_group', ... )` on `admin_init` as usual, with
   a one-line comment pointing to `WC_PLZ_Filter::settings_group_caps()`.
2. Add `'my_group' => self::MANAGE_CAP,` (or a stricter capability) to
   `settings_group_caps()`. Nothing else.

A group missing from the registry shows up as the 403 above for
`shop_manager` while administrators can still save — check the registry
first.

## Why a method and not a class constant

A class constant such as
`const SETTINGS_GROUP_CAPS = [ ..., 'wc_plz_updater_group' => WC_PLZ_Updater::MANAGE_UPDATE_CAP ];`
is resolved by PHP when `WC_PLZ_Filter` is first instantiated, which happens
at plugin load (`WC_PLZ_Filter::instance()` in `wc-plz-filter.php`) — before
`class-wc-plz-updater.php` is required in `init()`. That would be a fatal
"Class not found" on every request. The method is only evaluated on
`admin_init`, after `init()` (on `plugins_loaded`) has loaded every module.

## Load order

`register_settings_capabilities()` is registered in `WC_PLZ_Filter::init()`
next to `register_settings()`, outside the `is_admin() && ! wp_doing_ajax()`
block that loads the `Woohoo_Admin_Page` / `Woohoo_Module_*` classes. It
therefore does not depend on the admin module classes and runs on every
request that fires `admin_init`: normal wp-admin pages, `admin-post.php`,
`admin-ajax.php`, and in particular the `options.php` POST that saves a
settings form (`options.php` reads the filter after `admin_init`). Frontend
and REST requests don't fire `admin_init`, so nothing is registered there —
they don't use `options.php` either. If WooCommerce is inactive, `init()`
returns early and neither the groups nor their filters are registered.

### Rejected alternative: auto-detect by naming convention

Hook WordPress's `all` filter once and, inside it, watch for any
`option_page_capability_wc_plz_*` / `option_page_capability_woohoo_*` hook
name via `current_filter()`, then `add_filter()` that specific dynamic hook
on the fly (this works because `all` callbacks run *before* the named
filter's own callback list is read, per `apply_filters()` in
`wp-includes/plugin.php`). This would need zero registration for future
modules, but was rejected: it relies on a non-obvious ordering quirk of the
`all` hook, and the updater group's different capability
(`MANAGE_UPDATE_CAP`) means even the auto-detect version still needs a
per-prefix/per-group capability rule, not a single constant — most of the
complexity of the explicit registry, with less readable code.

### Rejected alternative: `get_settings_groups()` on `Woohoo_Module`

The admin module classes are only loaded when `is_admin() && ! wp_doing_ajax()`,
and the groups are registered by the feature classes, not the modules.
Keeping the registry in `WC_PLZ_Filter` avoids coupling the save path to the
admin-page UI layer.
