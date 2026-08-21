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


// 3a. Versioned transients belonging to this plugin. Their keys carry a
// running version or epoch (wc_plz_hidden_v42_..., wplzs_7_...), so they
// cannot be addressed individually through delete_transient().
//
// Until now the only thing removing them was the daily wp_scheduled_delete
// cron: an outdated key is never read again, so the lazy expiry inside
// get_transient() never fires either. With cron stalled the rows pile up
// without bound - and after an uninstall they stay forever, because by then
// nobody knows what they were for.
//
// Deleted in batches because wp_options is the most-read table in the
// installation: a single DELETE spanning possibly six figures of rows would
// hold row locks on it for the entire run. Each batch commits on its own, so
// a PHP timeout costs only the remainder, not the progress already made.
foreach ( [ 'wc_plz_hidden_v', 'wplzs_' ] as $wc_plz_prefix ) {
	foreach ( [ '_transient_', '_transient_timeout_' ] as $wc_plz_kind ) {
		$wc_plz_like = $wpdb->esc_like( $wc_plz_kind . $wc_plz_prefix ) . '%';

		do {
			$wc_plz_deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
					$wc_plz_like,
					1000
				)
			);
		} while ( $wc_plz_deleted >= 1000 );
	}
}

unset( $wc_plz_prefix, $wc_plz_kind, $wc_plz_like, $wc_plz_deleted );

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
