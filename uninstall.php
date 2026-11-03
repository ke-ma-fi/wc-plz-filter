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

// This file runs standalone: none of the plugin classes are loaded, so every
// key below is a literal string. Where a class constant exists, the comment
// names it - keep both in sync when renaming anything.

// 1. Drop the statistics table.
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wc_plz_events`" );

// 2. Delete all options.
delete_option( 'wc_plz_filter_v2' );              // WC_PLZ_Filter::OPT
delete_option( 'wc_plz_filter' );                 // legacy
delete_option( 'wc_plz_filter_settings' );        // legacy
delete_option( 'wc_plz_hidden_version' );         // WC_PLZ_Filter::HIDDEN_VERSION
delete_option( 'wc_plz_rocket_busted' );          // WC_PLZ_Filter::maybe_bust_rocket_cache()
delete_option( 'wc_plz_upgraded_version' );       // WC_PLZ_Filter::OPT_UPGRADED_VERSION
delete_option( 'wc_plz_db_version' );             // WC_PLZ_Stats (table schema version)
delete_option( 'wc_plz_stats_epoch' );            // WC_PLZ_Stats::cache_epoch()
delete_option( 'wc_plz_stats_cleanup' );          // WC_PLZ_Stats::OPT_CLEANUP
delete_option( 'wc_plz_updater_repo' );           // WC_PLZ_Updater::OPT_REPO
delete_option( 'wc_plz_updater_branch' );         // WC_PLZ_Updater::OPT_BRANCH
delete_option( 'wc_plz_updater_secret' );         // WC_PLZ_Updater::OPT_SECRET
delete_option( 'wc_plz_updater_log' );            // WC_PLZ_Updater::OPT_LOG
delete_option( 'wc_plz_updater_cap_version' );    // WC_PLZ_Updater::OPT_CAP_VERSION
delete_option( 'wc_plz_updater_lock' );           // WC_PLZ_Updater upgrade lock
delete_option( 'wc_plz_updater_deliveries' );     // WC_PLZ_Updater processed webhook deliveries
delete_option( 'wc_plz_merkliste_enabled' );      // WC_PLZ_Merkliste::OPTION
delete_option( 'wc_plz_merkliste_stats' );        // WC_PLZ_Merkliste::STATS_OPTION
delete_option( 'wc_plz_cart_indicator_enabled' ); // WC_PLZ_Cart_Indicator::OPTION
delete_option( 'woohoo_mail_log' );               // Woohoo_Mailer::OPT_LOG (contains customer e-mail addresses)

// 3. Delete transients.
delete_transient( 'wc_plz_local_codes' );         // WC_PLZ_Filter::CACHE


// 3a. Versioned transients belonging to this plugin. Their keys carry a
// running version or epoch (wc_plz_hidden_v42_..., wplzs_7_...) or a hash
// (woohoo_po_fails_<md5 of IP>, Woohoo_Product_Overview::FAIL_TRANSIENT_PREFIX),
// so they cannot be addressed individually through delete_transient().
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
foreach ( [ 'wc_plz_hidden_v', 'wplzs_', 'woohoo_po_fails_' ] as $wc_plz_prefix ) {
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

// 4. Remove custom capabilities from roles ...
//    manage_plz_filter     = WC_PLZ_Filter::MANAGE_CAP
//    manage_woohoo_updates = WC_PLZ_Updater::MANAGE_UPDATE_CAP
foreach ( [ 'administrator', 'shop_manager' ] as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		$role->remove_cap( 'manage_plz_filter' );
		$role->remove_cap( 'manage_woohoo_updates' );
	}
}
unset( $role_name, $role );

// 4a. ... and from individual users. WC_PLZ_Updater::handle_grant_update_cap()
// grants manage_woohoo_updates per user (stored in the user's capabilities
// meta), and a role editor may have done the same for manage_plz_filter.
// The 'capability' query (WP 5.9+) finds users holding the cap through a role
// as well; WP_User::remove_cap() is a no-op for those, it only touches caps
// stored on the user itself. On older WordPress the unknown argument is
// ignored and all users are checked - slower, but equally correct.
foreach ( [ 'manage_plz_filter', 'manage_woohoo_updates' ] as $wc_plz_cap ) {
	$wc_plz_users = get_users( [
		'capability' => $wc_plz_cap,
		'fields'     => 'all',
	] );
	foreach ( $wc_plz_users as $wc_plz_user ) {
		if ( $wc_plz_user instanceof WP_User ) {
			$wc_plz_user->remove_cap( $wc_plz_cap );
		}
	}
}
unset( $wc_plz_cap, $wc_plz_users, $wc_plz_user );

// 5. Clear scheduled cron hooks.
wp_clear_scheduled_hook( 'wc_plz_stats_cleanup' );  // WC_PLZ_Stats::CRON_HOOK
wp_clear_scheduled_hook( 'wc_plz_reminder_scan' );  // WC_PLZ_Reminder::CRON_HOOK

// 6. Delete reminder options. The order meta reminded_pending_payment
// (WC_PLZ_Reminder::META_FLAG) is deliberately kept: it is order history.
delete_option( 'wc_plz_reminder' );                 // WC_PLZ_Reminder::OPT
delete_option( 'wc_plz_reminder_log' );             // WC_PLZ_Reminder::OPT_LOG
delete_option( 'wc_plz_reminder_last_run' );
delete_transient( 'wc_plz_reminder_running' );      // WC_PLZ_Reminder::LOCK_KEY
delete_transient( 'wc_plz_reminder_notice' );

// 7. Delete the auto-provisioned Produktübersicht page and its options.
$woohoo_po_page_id = (int) get_option( 'woohoo_product_overview_page_id' );
if ( $woohoo_po_page_id ) {
	wp_delete_post( $woohoo_po_page_id, true );
}
delete_option( 'woohoo_product_overview_enabled' );
delete_option( 'woohoo_product_overview_settings' );
delete_option( 'woohoo_product_overview_page_id' );
