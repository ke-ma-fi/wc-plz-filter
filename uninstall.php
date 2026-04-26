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

// 3. Delete transients.
delete_transient( 'wc_plz_local_codes' );

// 4. Clear scheduled cron hook.
wp_clear_scheduled_hook( 'wc_plz_stats_cleanup' );
