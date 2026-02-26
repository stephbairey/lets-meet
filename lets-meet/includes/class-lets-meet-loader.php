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
		$availability = new Lets_Meet_Availability( $services );

		// Admin: menu, assets, form handlers.
		$admin = new Lets_Meet_Admin( $services );

		add_action( 'admin_init', [ $admin, 'handle_early_actions' ] );
		add_action( 'admin_menu', [ $admin, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_admin_assets' ] );
		add_action( 'admin_post_lm_save_service', [ $admin, 'handle_save_service' ] );
		add_action( 'admin_post_lm_save_settings', [ $admin, 'handle_save_settings' ] );
	}
}
