<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GDPR compliance: personal data exporter and eraser.
 *
 * Registers with WordPress's built-in privacy tools so site admins can
 * handle data export and erasure requests for booking data.
 *
 * Exporter: returns all bookings for a given email address.
 * Eraser:   anonymizes bookings (replaces PII with "[deleted]") rather than
 *           deleting rows, so the admin's schedule integrity is preserved.
 */
class Lets_Meet_Privacy {

	/**
	 * Register the exporter with WordPress.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['lets-meet'] = [
			'exporter_friendly_name' => __( 'Let\'s Meet Booking Data', 'lets-meet' ),
			'callback'               => [ $this, 'export_personal_data' ],
		];
		return $exporters;
	}

	/**
	 * Register the eraser with WordPress.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['lets-meet'] = [
			'eraser_friendly_name' => __( 'Let\'s Meet Booking Data', 'lets-meet' ),
			'callback'             => [ $this, 'erase_personal_data' ],
		];
		return $erasers;
	}

	/**
	 * Export personal data for a given email address.
	 *
	 * Returns booking records in the format WordPress expects for its
	 * personal data export ZIP file.
	 *
	 * @param string $email_address The email to export data for.
	 * @param int    $page          Page number (1-indexed) for batched processing.
	 * @return array {
	 *     @type array $data Array of export items.
	 *     @type bool  $done Whether all data has been returned.
	 * }
	 */
	public function export_personal_data( $email_address, $page = 1 ) {
		global $wpdb;

		$per_page = 50;
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $wpdb->prefix . 'lm_bookings';

		$bookings = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.*, s.name AS service_name
			FROM {$table} AS b
			LEFT JOIN {$wpdb->prefix}lm_services AS s ON b.service_id = s.id
			WHERE b.client_email = %s
			ORDER BY b.id ASC
			LIMIT %d OFFSET %d",
			$email_address,
			$per_page,
			$offset
		) );

		$export_items = [];

		foreach ( $bookings as $booking ) {
			$start_display = '';
			if ( ! empty( $booking->start_utc ) && ! empty( $booking->site_timezone ) ) {
				try {
					$utc   = new \DateTimeImmutable( $booking->start_utc, new \DateTimeZone( 'UTC' ) );
					$local = $utc->setTimezone( new \DateTimeZone( $booking->site_timezone ) );
					$start_display = wp_date(
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						$local->getTimestamp()
					);
				} catch ( \Exception $e ) {
					$start_display = $booking->start_utc . ' (UTC)';
				}
			}

			$data = [
				[
					'name'  => __( 'Service', 'lets-meet' ),
					'value' => $booking->service_name ?? __( '(deleted service)', 'lets-meet' ),
				],
				[
					'name'  => __( 'Name', 'lets-meet' ),
					'value' => $booking->client_name,
				],
				[
					'name'  => __( 'Email', 'lets-meet' ),
					'value' => $booking->client_email,
				],
				[
					'name'  => __( 'Phone', 'lets-meet' ),
					'value' => $booking->client_phone,
				],
				[
					'name'  => __( 'Notes', 'lets-meet' ),
					'value' => $booking->client_notes,
				],
				[
					'name'  => __( 'Date/Time', 'lets-meet' ),
					'value' => $start_display,
				],
				[
					'name'  => __( 'Duration (minutes)', 'lets-meet' ),
					'value' => $booking->duration,
				],
				[
					'name'  => __( 'Status', 'lets-meet' ),
					'value' => $booking->status,
				],
				[
					'name'  => __( 'Booked on', 'lets-meet' ),
					'value' => $booking->created_at,
				],
			];

			$export_items[] = [
				'group_id'          => 'lm-bookings',
				'group_label'       => __( 'Bookings', 'lets-meet' ),
				'group_description' => __( 'Booking data from Let\'s Meet.', 'lets-meet' ),
				'item_id'           => "lm-booking-{$booking->id}",
				'data'              => $data,
			];
		}

		return [
			'data' => $export_items,
			'done' => count( $bookings ) < $per_page,
		];
	}

	/**
	 * Erase (anonymize) personal data for a given email address.
	 *
	 * Replaces PII fields with "[deleted]" rather than removing rows, so the
	 * admin's calendar/schedule integrity is maintained.
	 *
	 * @param string $email_address The email to erase data for.
	 * @param int    $page          Page number (1-indexed) for batched processing.
	 * @return array {
	 *     @type int      $items_removed  Number of items anonymized.
	 *     @type int      $items_retained Number of items kept unchanged.
	 *     @type array    $messages       User-facing messages.
	 *     @type bool     $done           Whether all data has been processed.
	 * }
	 */
	public function erase_personal_data( $email_address, $page = 1 ) {
		global $wpdb;

		$per_page = 50;
		$table    = $wpdb->prefix . 'lm_bookings';

		// Get IDs for this batch. We re-query each page because previous
		// batches anonymize the email, so use the original email only.
		$booking_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table}
			WHERE client_email = %s
			ORDER BY id ASC
			LIMIT %d",
			$email_address,
			$per_page
		) );

		if ( empty( $booking_ids ) ) {
			return [
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => [],
				'done'           => true,
			];
		}

		$deleted_label = '[deleted]';
		$items_removed = 0;

		foreach ( $booking_ids as $id ) {
			$updated = $wpdb->update(
				$table,
				[
					'client_name'  => $deleted_label,
					'client_email' => $deleted_label,
					'client_phone' => $deleted_label,
					'client_notes' => $deleted_label,
					'updated_at'   => current_datetime()->format( 'Y-m-d H:i:s' ),
				],
				[ 'id' => absint( $id ) ],
				[ '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);

			if ( false !== $updated ) {
				$items_removed++;
			}
		}

		lm_log( 'Privacy erasure completed.', [ 'items_anonymized' => $items_removed ] );

		// Check if there are more rows to process.
		$remaining = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE client_email = %s",
			$email_address
		) );

		return [
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => [],
			'done'           => 0 === absint( $remaining ),
		];
	}
}
