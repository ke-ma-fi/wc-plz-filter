# Settings Capability Filter: Recurring 403 on Save

Every `register_setting()` group in this plugin needs a matching
`option_page_capability_{$group}` filter, or WordPress's `options.php`
defaults to requiring `manage_options` instead of the plugin's own
`WC_PLZ_Filter::MANAGE_CAP` (`manage_plz_filter`). An account that holds
`MANAGE_CAP` but not `manage_options` (e.g. `shop_manager`) can open the tab
fine — the tab itself is gated by `MANAGE_CAP` — but gets rejected by
`options.php` on save, which surfaces as a 403 / "Sorry, you are not allowed
to modify this page."

This has been missed more than once when adding a new settings group (most
recently `wc_plz_widgets_group`, shared by `WC_PLZ_Merkliste` and
`WC_PLZ_Cart_Indicator` — see [class-wc-plz-merkliste.php](../../includes/class-wc-plz-merkliste.php)
and [class-wc-plz-cart-indicator.php](../../includes/class-wc-plz-cart-indicator.php)).
Every group currently carries its own copy of the filter closure:

- `wc_plz_filter_group` — [class-wc-plz-filter.php:922](../../includes/class-wc-plz-filter.php#L922)
- `wc_plz_widgets_group` — in both `WC_PLZ_Merkliste::register_setting()` and `WC_PLZ_Cart_Indicator::register_setting()`
- `wc_plz_reminder_group` — [class-wc-plz-reminder.php:84](../../includes/class-wc-plz-reminder.php#L84)
- `woohoo_product_overview_group` — [class-woohoo-product-overview.php:133-135](../../includes/class-woohoo-product-overview.php#L133-L135)
- `wc_plz_updater_group` — [class-wc-plz-updater.php:269](../../includes/class-wc-plz-updater.php#L269) (uses a *different* capability, `WC_PLZ_Updater::MANAGE_UPDATE_CAP`, not `MANAGE_CAP`)

Because the fix is duplicated per-file, it's easy for a brand-new module to
add `register_setting()` and simply forget the accompanying filter — nothing
fails loudly until someone without `manage_options` tries to save.

## Planned fix (not yet implemented)

Centralize into one list instead of one closure per file. Add a single
registry in `WC_PLZ_Filter` (or a small dedicated helper class) mapping each
settings group to its required capability, and apply all of them from one
`admin_init` hook:

```php
const SETTINGS_GROUP_CAPS = [
    'wc_plz_filter_group'           => self::MANAGE_CAP,
    'wc_plz_widgets_group'          => self::MANAGE_CAP,
    'wc_plz_reminder_group'         => self::MANAGE_CAP,
    'woohoo_product_overview_group' => self::MANAGE_CAP,
    'wc_plz_updater_group'          => WC_PLZ_Updater::MANAGE_UPDATE_CAP,
];

public function register_capability_filters(): void {
    foreach ( self::SETTINGS_GROUP_CAPS as $group => $cap ) {
        add_filter( "option_page_capability_{$group}", function () use ( $cap ) {
            return current_user_can( 'manage_options' ) ? 'manage_options' : $cap;
        } );
    }
}
```

Then remove the per-module `add_filter( 'option_page_capability_...' )`
calls and have every module's `register_setting()` add its group name to
this one array instead. A new settings group failing to save because its
entry is missing from `SETTINGS_GROUP_CAPS` is a one-line, easy-to-spot
fix in one obvious place, rather than a pattern that has to be
independently rediscovered per file.

### Rejected alternative: auto-detect by naming convention

Hook WordPress's `all` filter once and, inside it, watch for any
`option_page_capability_wc_plz_*` / `option_page_capability_woohoo_*` hook
name via `current_filter()`, then `add_filter()` that specific dynamic hook
on the fly (this works because `all` callbacks run *before* the named
filter's own callback list is read, per `apply_filters()` in
`wp-includes/plugin.php`). This would need zero registration for future
modules, but was rejected for now: it relies on a non-obvious ordering
quirk of the `all` hook, and the updater group's different capability
(`MANAGE_UPDATE_CAP`) means even the auto-detect version still needs a
per-prefix/per-group capability rule, not a single constant — most of the
complexity of the explicit registry, with less readable code.
