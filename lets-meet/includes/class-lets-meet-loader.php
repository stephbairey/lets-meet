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
	}
}
