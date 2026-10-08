<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Tariff data takes real work to enter and uploaded bills are customer
 * records, so nothing is dropped unless the site owner has explicitly asked
 * for it by defining AEC_REMOVE_ALL_DATA. Deactivating and deleting a plugin
 * to troubleshoot something should not cost anybody their data.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'AEC_REMOVE_ALL_DATA' ) || ! AEC_REMOVE_ALL_DATA ) {
	return;
}

global $wpdb;

$aec_tables = array(
	$wpdb->prefix . 'aec_rates',
	$wpdb->prefix . 'aec_plans',
	$wpdb->prefix . 'aec_retailers',
	$wpdb->prefix . 'aec_leads',
	$wpdb->prefix . 'aec_bills',
);

foreach ( $aec_tables as $aec_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$aec_table}" );
}

delete_option( 'aec_settings' );
delete_option( 'aec_db_version' );

// Any stored bill PDFs go with it.
$aec_uploads = wp_get_upload_dir();
$aec_dir     = trailingslashit( $aec_uploads['basedir'] ) . 'aec-bills';

if ( is_dir( $aec_dir ) ) {
	$aec_files = glob( $aec_dir . '/*' );
	foreach ( (array) $aec_files as $aec_file ) {
		if ( is_file( $aec_file ) ) {
			@unlink( $aec_file );
		}
	}
	@rmdir( $aec_dir );
}
