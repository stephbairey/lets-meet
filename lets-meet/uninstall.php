<?php
/**
 * Fired when the plugin is uninstalled via the WordPress admin.
 *
 * This is a standalone file — no plugin code is loaded.
 * Respects the "keep data on uninstall" setting (default: keep).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'lm_settings', [] );

// Default is to keep data. Only remove if explicitly opted out.
if ( empty( $settings['keep_data'] ) ) {
	global $wpdb;

	// Drop custom tables.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lm_bookings" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lm_services" );

	// Delete all plugin options.
	$option_keys = [
		'lm_availability',
		'lm_settings',
		'lm_db_version',
		'lm_gcal_client_id',
		'lm_gcal_client_secret',
		'lm_gcal_tokens',
		'lm_gcal_calendar_id',
	];
	foreach ( $option_keys as $key ) {
		delete_option( $key );
	}

	// Unschedule cron events.
	$timestamp = wp_next_scheduled( 'lm_prewarm_gcal' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'lm_prewarm_gcal' );
	}
}
