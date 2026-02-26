<?php
/**
 * Plugin Name: Let's Meet
 * Description: A lightweight 1:1 booking plugin for service providers.
 * Version:     1.0.0
 * Author:      Lingua Ink Media
 * Author URI:  https://linguainkmedia.com
 * License:     GPL-2.0-or-later
 * Text Domain: lets-meet
 * Requires PHP: 7.4
 * Requires at least: 6.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LM_VERSION', '1.0.0' );
define( 'LM_DB_VERSION', '1.0.0' );
define( 'LM_PATH', plugin_dir_path( __FILE__ ) );
define( 'LM_URL', plugin_dir_url( __FILE__ ) );
define( 'LM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Log a message to the WordPress debug log.
 *
 * Logs only when WP_DEBUG is enabled. Never log PII, tokens, or full API responses.
 */
function lm_log( $message, $data = [] ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[Let\'s Meet] ' . $message . ( $data ? ' ' . wp_json_encode( $data ) : '' ) );
	}
}

/* ── Includes ─────────────────────────────────────────────────────── */

require_once LM_PATH . 'includes/class-lets-meet-db.php';
require_once LM_PATH . 'includes/class-lets-meet-services.php';
require_once LM_PATH . 'includes/class-lets-meet-admin.php';
require_once LM_PATH . 'includes/class-lets-meet-loader.php';

/* ── Activation ───────────────────────────────────────────────────── */

register_activation_hook( __FILE__, function () {
	$db = new Lets_Meet_Db();
	$db->create_tables();

	// Schedule nightly prewarm cron (if not already scheduled).
	if ( ! wp_next_scheduled( 'lm_prewarm_gcal' ) ) {
		wp_schedule_event( time(), 'daily', 'lm_prewarm_gcal' );
	}

	// Seed default settings if they don't exist yet.
	if ( false === get_option( 'lm_settings' ) ) {
		add_option( 'lm_settings', [
			'buffer'         => 30,
			'horizon'        => 60,
			'min_notice'     => 2,
			'admin_email'    => get_option( 'admin_email' ),
			'admin_notify'   => true,
			'confirm_msg'    => '',
			'keep_data'      => true,
		] );
	}

	// Seed empty availability schedule if it doesn't exist yet.
	if ( false === get_option( 'lm_availability' ) ) {
		add_option( 'lm_availability', [
			'monday'    => [],
			'tuesday'   => [],
			'wednesday' => [],
			'thursday'  => [],
			'friday'    => [],
			'saturday'  => [],
			'sunday'    => [],
		] );
	}
} );

/* ── Deactivation ─────────────────────────────────────────────────── */

register_deactivation_hook( __FILE__, function () {
	$timestamp = wp_next_scheduled( 'lm_prewarm_gcal' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'lm_prewarm_gcal' );
	}
} );

/* ── Bootstrap ────────────────────────────────────────────────────── */

add_action( 'plugins_loaded', function () {
	$loader = new Lets_Meet_Loader();
	$loader->run();
} );
