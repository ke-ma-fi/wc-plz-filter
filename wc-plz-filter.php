<?php
/**
 * Plugin Name:  DT Woohoo
 * Plugin URI:   https://fischer.digitale-theke.com
 * Description:  PLZ-Popup mit drei Modi (Abholung, Lokale Lieferung, Postversand). Filtert Produkte dynamisch nach WooCommerce-Versandklassen und füllt den Checkout vor.
 * Version:      2.12.8
 * Author:       Metzgerei Fischer
 * License:      Proprietary
 * License URI:  https://fischer.digitale-theke.com
 * Text Domain:  wc-plz-filter
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

// Lifecycle hooks.
register_activation_hook( __FILE__, function () {
    require_once WC_PLZ_FILTER_DIR . 'includes/class-wc-plz-stats.php';
    WC_PLZ_Stats::activate();

    require_once WC_PLZ_FILTER_DIR . 'includes/class-wc-plz-reminder.php';
    WC_PLZ_Reminder::activate();

    require_once WC_PLZ_FILTER_DIR . 'includes/class-wc-plz-updater.php';

    // Capability an Rollen vergeben, die standardmäßig Zugriff haben sollen.
    // Einzelne Benutzer können zusätzlich über "Benutzer > Bearbeiten" berechtigt werden.
    foreach ( [ 'administrator', 'shop_manager' ] as $role_name ) {
        $role = get_role( $role_name );
        if ( $role ) {
            $role->add_cap( WC_PLZ_Filter::MANAGE_CAP );
        }
    }

    // Auto-Update-Verwaltung ist strenger als die allgemeine Plugin-Verwaltung:
    // nur Administratoren, kein shop_manager (siehe manage_woohoo_updates).
    $admin_role = get_role( 'administrator' );
    if ( $admin_role ) {
        $admin_role->add_cap( WC_PLZ_Updater::MANAGE_UPDATE_CAP );
    }
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'wc_plz_stats_cleanup' );

    require_once WC_PLZ_FILTER_DIR . 'includes/class-wc-plz-reminder.php';
    WC_PLZ_Reminder::deactivate();

    require_once WC_PLZ_FILTER_DIR . 'includes/class-wc-plz-updater.php';

    foreach ( [ 'administrator', 'shop_manager' ] as $role_name ) {
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
