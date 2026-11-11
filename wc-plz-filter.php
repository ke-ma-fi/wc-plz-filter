<?php
/**
 * Plugin Name:  DT Woohoo
 * Plugin URI:   https://fischer.digitale-theke.com
 * Description:  WooCommerce-Erweiterungen der Metzgerei Fischer: PLZ-Popup mit Liefermodus-Produktfilter, PLZ-Statistik, Zahlungs-Erinnerung mit Mail-Log, Merkliste, Warenkorb-Anzeige, Produktübersicht und GitHub-Auto-Updater.
 * Version:      2.13.1
 * Author:       Metzgerei Fischer
 * License:      Proprietary
 * License URI:  https://fischer.digitale-theke.com
 * Text Domain:  wc-plz-filter
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 *
 * Copyright (c) 2024-2026 Metzgerei Fischer. All rights reserved.
 *
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * modification, distribution, or use of this software, in whole or in part,
 * is strictly prohibited without prior written permission from the copyright
 * holder.
 */

defined( 'ABSPATH' ) || exit;

// Naming convention: see docs/NAMING.md. Internal identifiers (constants, option
// keys, capability, AJAX actions, REST namespace, this file/folder name) stay
// on the legacy WC_PLZ_* / wc_plz_* scheme on purpose — only the plugin's
// display name and admin UI are branded as "DT Woohoo".
define( 'WC_PLZ_FILTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_PLZ_FILTER_URL', plugin_dir_url( __FILE__ ) );

require_once WC_PLZ_FILTER_DIR . 'includes/trait-wc-plz-singleton.php';
require_once WC_PLZ_FILTER_DIR . 'includes/class-wc-plz-filter.php';

WC_PLZ_Filter::instance();

// HPOS (custom order tables): all order access goes through the WooCommerce
// CRUD API (wc_get_orders(), wc_get_order(), WC_Order meta methods). The
// shop itself still runs on posts storage; this only declares that the
// plugin works either way. cart_checkout_blocks is deliberately NOT
// declared: the plugin relies on classic checkout hooks.
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Lifecycle hooks. Activation runs WC_PLZ_Filter::install(); webhook updates
// run the more conservative WC_PLZ_Filter::upgrade() via maybe_upgrade().
register_activation_hook( __FILE__, [ 'WC_PLZ_Filter', 'install' ] );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'wc_plz_stats_cleanup' );

    require_once WC_PLZ_FILTER_DIR . 'includes/class-wc-plz-reminder.php';
    WC_PLZ_Reminder::deactivate();

    require_once WC_PLZ_FILTER_DIR . 'includes/class-wc-plz-updater.php';
    wp_clear_scheduled_hook( WC_PLZ_Updater::RETRY_HOOK );

    foreach ( WC_PLZ_Filter::MANAGE_CAP_ROLES as $role_name ) {
        $role = get_role( $role_name );
        if ( $role ) {
            $role->remove_cap( WC_PLZ_Filter::MANAGE_CAP );
        }
    }

    $admin_role = get_role( 'administrator' );
    if ( $admin_role ) {
        $admin_role->remove_cap( WC_PLZ_Updater::MANAGE_UPDATE_CAP );
    }
} );
