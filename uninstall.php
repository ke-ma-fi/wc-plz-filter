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

global $wpdb;

// 1. Drop the statistics table.
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wc_plz_events`" );

// 2. Delete all options.
delete_option( 'wc_plz_filter_v2' );
delete_option( 'wc_plz_filter' );          // legacy
delete_option( 'wc_plz_filter_settings' ); // legacy
delete_option( 'wc_plz_db_version' );
delete_option( 'wc_plz_stats_epoch' );
delete_option( 'wc_plz_stats_cleanup' );
delete_option( 'wc_plz_hidden_version' );
delete_option( 'wc_plz_updater_repo' );
delete_option( 'wc_plz_updater_secret' );
delete_option( 'wc_plz_updater_log' );
delete_option( 'wc_plz_merkliste_enabled' );
delete_option( 'wc_plz_merkliste_stats' );
delete_option( 'wc_plz_cart_indicator_enabled' );

// 3. Delete transients.
delete_transient( 'wc_plz_local_codes' );
delete_transient( 'wc_plz_diag_scan' );
delete_option( 'wc_plz_diag_baseline' );

// 3a. Versionierte Hidden-IDs- und Statistik-Transients. Die Keys tragen eine
// laufende Version bzw. Epoch im Namen, lassen sich also nicht einzeln über
// delete_transient() ansprechen - hier ist der LIKE-Scan angebracht, weil er
// genau einmal beim Deinstallieren läuft.
foreach ( [ 'wc_plz_hidden_v', 'wplzs_' ] as $prefix ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . $prefix ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
		)
	);
}

// 4. Remove custom capability from roles.
foreach ( [ 'administrator', 'shop_manager' ] as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		$role->remove_cap( 'manage_plz_filter' );
	}
}

// 5. Clear scheduled cron hooks.
wp_clear_scheduled_hook( 'wc_plz_stats_cleanup' );
wp_clear_scheduled_hook( 'wc_plz_reminder_scan' );

// 6. Delete reminder options.
delete_option( 'wc_plz_reminder' );
delete_option( 'wc_plz_reminder_log' );
delete_option( 'wc_plz_reminder_last_run' );
delete_transient( 'wc_plz_reminder_running' );

// 7. Delete the auto-provisioned Produktübersicht page and its options.
$woohoo_po_page_id = (int) get_option( 'woohoo_product_overview_page_id' );
if ( $woohoo_po_page_id ) {
	wp_delete_post( $woohoo_po_page_id, true );
}
delete_option( 'woohoo_product_overview_enabled' );
delete_option( 'woohoo_product_overview_settings' );
delete_option( 'woohoo_product_overview_page_id' );
