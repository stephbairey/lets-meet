<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database table creation and schema versioning.
 */
class Lets_Meet_Db {

	/**
	 * Create or update custom tables via dbDelta.
	 *
	 * Called on activation and whenever LM_DB_VERSION changes.
	 */
	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$services_table = "CREATE TABLE {$wpdb->prefix}lm_services (
			id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name  VARCHAR(255) NOT NULL,
			slug  VARCHAR(255) NOT NULL,
			duration  INT NOT NULL,
			description  TEXT,
			is_active  TINYINT(1) NOT NULL DEFAULT 1,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$bookings_table = "CREATE TABLE {$wpdb->prefix}lm_bookings (
			id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			service_id  BIGINT UNSIGNED NOT NULL,
			client_name  VARCHAR(255) NOT NULL,
			client_email  VARCHAR(255) NOT NULL,
			client_phone  VARCHAR(50) DEFAULT '',
			client_notes  TEXT,
			start_utc  DATETIME NOT NULL,
			duration  INT NOT NULL,
			site_timezone  VARCHAR(100) NOT NULL,
			status  VARCHAR(20) NOT NULL DEFAULT 'confirmed',
			gcal_event_id  VARCHAR(255) DEFAULT '',
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_start_status (start_utc, status),
			KEY idx_email (client_email)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $services_table );
		dbDelta( $bookings_table );

		update_option( 'lm_db_version', LM_DB_VERSION );
	}

	/**
	 * Run create_tables() only when the DB version has changed.
	 */
	public function maybe_upgrade() {
		if ( get_option( 'lm_db_version' ) !== LM_DB_VERSION ) {
			$this->create_tables();
		}
	}
}
