<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package WC_PLZ_Filter
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. Aktuelle und potenziell alte Optionen aus der wp_options Tabelle löschen
delete_option( 'wc_plz_filter_v2' ); // Aktuelle Version
delete_option( 'wc_plz_filter' );    // Vermuteter Name der alten Version
delete_option( 'wc_plz_filter_settings' );

// 2. Transients (Zwischenspeicher) löschen
delete_transient( 'wc_plz_local_codes' );

// Falls du noch weitere alte Werte kennst (z.B. aus der Datenbank), 
// können diese hier als delete_option('options_name'); ergänzt werden.
