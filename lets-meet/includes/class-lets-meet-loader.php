<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hook registration orchestrator.
 *
 * Instantiates plugin classes and wires their methods to WordPress hooks.
 * New hooks are added here as each phase is built.
 */
class Lets_Meet_Loader {

	/**
	 * Register all hooks for the plugin.
	 */
	public function run() {
		// Check for DB schema upgrades on every admin load.
		$db = new Lets_Meet_Db();
		add_action( 'admin_init', [ $db, 'maybe_upgrade' ] );

		// Core classes.
		$services     = new Lets_Meet_Services();
		$gcal         = new Lets_Meet_Gcal();
		$availability = new Lets_Meet_Availability( $services, $gcal );

		// Admin: menu, assets, form handlers.
		$admin = new Lets_Meet_Admin( $services, $gcal );

		add_action( 'admin_init', [ $admin, 'handle_early_actions' ] );
		add_action( 'admin_menu', [ $admin, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_admin_assets' ] );
		add_action( 'admin_post_lm_save_service', [ $admin, 'handle_save_service' ] );
		add_action( 'admin_post_lm_save_settings', [ $admin, 'handle_save_settings' ] );

		// Google Calendar: OAuth callback, credentials, admin notice.
		add_action( 'admin_post_lm_gcal_callback', [ $gcal, 'handle_oauth_callback' ] );
		add_action( 'admin_post_lm_save_gcal_settings', [ $admin, 'handle_save_gcal_settings' ] );
		add_action( 'admin_post_lm_gcal_disconnect', [ $admin, 'handle_gcal_disconnect' ] );
		add_action( 'admin_notices', [ $gcal, 'maybe_show_admin_notice' ] );

		// Bookings: creation, cancellation, concurrency.
		$bookings = new Lets_Meet_Bookings( $services, $availability, $gcal );

		// Frontend: shortcode, assets, AJAX.
		$public = new Lets_Meet_Public( $services, $availability, $bookings );

		add_action( 'init', [ $public, 'register_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $public, 'enqueue_public_assets' ] );
		add_action( 'wp_ajax_lm_get_slots', [ $public, 'ajax_get_slots' ] );
		add_action( 'wp_ajax_nopriv_lm_get_slots', [ $public, 'ajax_get_slots' ] );
		add_action( 'wp_ajax_lm_submit_booking', [ $public, 'ajax_submit_booking' ] );
		add_action( 'wp_ajax_nopriv_lm_submit_booking', [ $public, 'ajax_submit_booking' ] );
	}
}
