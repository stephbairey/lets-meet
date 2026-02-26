<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Booking creation, cancellation, and concurrency control.
 *
 * Uses three layers of double-booking prevention:
 * 1. Fresh availability re-check (live FreeBusy + fresh DB query)
 * 2. MySQL GET_LOCK() per-date serialization
 * 3. Atomic INSERT with NOT EXISTS overlap subquery (safety net)
 *
 * Always release the lock in a finally block.
 */
class Lets_Meet_Bookings {

	/** @var Lets_Meet_Services */
	private $services;

	/** @var Lets_Meet_Availability */
	private $availability;

	/** @var Lets_Meet_Gcal */
	private $gcal;

	public function __construct( Lets_Meet_Services $services, Lets_Meet_Availability $availability, Lets_Meet_Gcal $gcal ) {
		$this->services     = $services;
		$this->availability = $availability;
		$this->gcal         = $gcal;
	}

	/* ── Rate limiting ────────────────────────────────────────────── */

	/**
	 * Check and increment the rate limit for the current IP.
	 *
	 * @return bool True if within limit, false if exceeded.
	 */
	public function check_rate_limit() {
		$ip_hash   = 'lm_rate_' . md5( $this->get_client_ip() );
		$attempts  = absint( get_transient( $ip_hash ) );

		if ( $attempts >= 10 ) {
			return false;
		}

		set_transient( $ip_hash, $attempts + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		// Only trust REMOTE_ADDR — proxy headers can be spoofed.
		return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
	}

	/* ── Booking creation ─────────────────────────────────────────── */

	/**
	 * Create a booking with full concurrency protection.
	 *
	 * @param array $data {
	 *     @type int    $service_id  Service ID.
	 *     @type string $date        'Y-m-d' date in site timezone.
	 *     @type string $time        'H:i' time in site timezone.
	 *     @type string $name        Client name.
	 *     @type string $email       Client email.
	 *     @type string $phone       Client phone (optional).
	 *     @type string $notes       Client notes (optional).
	 * }
	 * @return array|WP_Error Booking data on success, WP_Error on failure.
	 */
	public function create( $data ) {
		global $wpdb;

		$service_id = absint( $data['service_id'] ?? 0 );
		$date       = sanitize_text_field( $data['date'] ?? '' );
		$time       = sanitize_text_field( $data['time'] ?? '' );
		$name       = sanitize_text_field( $data['name'] ?? '' );
		$email      = sanitize_email( $data['email'] ?? '' );
		$phone      = sanitize_text_field( $data['phone'] ?? '' );
		$notes      = sanitize_textarea_field( $data['notes'] ?? '' );

		// ── Validate required fields ─────────────────────────────────
		if ( '' === $name || '' === $email || '' === $date || '' === $time || 0 === $service_id ) {
			return new \WP_Error( 'missing_fields', __( 'Please fill in all required fields.', 'lets-meet' ) );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'lets-meet' ) );
		}

		// Validate date format and calendar validity.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new \WP_Error( 'invalid_date', __( 'Invalid date format.', 'lets-meet' ) );
		}
		$date_parts = explode( '-', $date );
		if ( ! checkdate( (int) $date_parts[1], (int) $date_parts[2], (int) $date_parts[0] ) ) {
			return new \WP_Error( 'invalid_date', __( 'Invalid date.', 'lets-meet' ) );
		}

		// Validate time format and range.
		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return new \WP_Error( 'invalid_time', __( 'Invalid time format.', 'lets-meet' ) );
		}
		$time_parts = explode( ':', $time );
		if ( (int) $time_parts[0] > 23 || (int) $time_parts[1] > 59 ) {
			return new \WP_Error( 'invalid_time', __( 'Invalid time.', 'lets-meet' ) );
		}

		// Validate service exists and is active.
		$service = $this->services->get( $service_id );
		if ( ! $service || ! $service->is_active ) {
			return new \WP_Error( 'invalid_service', __( 'This service is no longer available.', 'lets-meet' ) );
		}

		$duration = absint( $service->duration );
		$tz       = wp_timezone();

		// Build start time in UTC.
		try {
			$start_local = new \DateTimeImmutable( $date . ' ' . $time . ':00', $tz );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'invalid_datetime', __( 'Invalid date or time.', 'lets-meet' ) );
		}
		$start_utc = $start_local->setTimezone( new \DateTimeZone( 'UTC' ) );
		$end_utc     = $start_utc->modify( "+{$duration} minutes" );

		$start_utc_str = $start_utc->format( 'Y-m-d H:i:s' );
		$end_utc_str   = $end_utc->format( 'Y-m-d H:i:s' );

		// ── Layer 1: Fresh availability re-check ─────────────────────
		// Use fresh GCal data (bypass cache) for concurrency safety.
		$this->gcal->get_busy_fresh( $date, $tz );
		$available_slots = $this->availability->get_available_slots( $date, $service_id );

		if ( ! in_array( $time, $available_slots, true ) ) {
			return new \WP_Error( 'slot_taken', __( 'Sorry, this time slot is no longer available. Please choose another time.', 'lets-meet' ) );
		}

		// ── Layer 2: MySQL GET_LOCK() ────────────────────────────────
		$lock_name = 'lm_book_' . $date;
		$table     = $wpdb->prefix . 'lm_bookings';

		// GET_LOCK returns "1" (acquired), "0" (timeout), or null (error).
		// Both "0" and null are falsy, so a single ! check covers both failure modes.
		$acquired = $wpdb->get_var( $wpdb->prepare(
			"SELECT GET_LOCK(%s, 10)",
			$lock_name
		) );

		if ( ! $acquired ) {
			lm_log( 'GET_LOCK acquisition failed.', [ 'lock' => $lock_name ] );
			return new \WP_Error( 'server_busy', __( 'Server is busy. Please try again in a moment.', 'lets-meet' ) );
		}

		try {
			// ── Layer 3: Atomic INSERT with NOT EXISTS ────────────────
			$rows = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table} (service_id, client_name, client_email, client_phone, client_notes, start_utc, duration, site_timezone, status)
				SELECT %d, %s, %s, %s, %s, %s, %d, %s, %s
				FROM DUAL
				WHERE NOT EXISTS (
					SELECT 1 FROM {$table}
					WHERE status = 'confirmed'
					AND start_utc < %s
					AND DATE_ADD(start_utc, INTERVAL duration MINUTE) > %s
				)",
				$service_id,
				$name,
				$email,
				$phone,
				$notes,
				$start_utc_str,
				$duration,
				$tz->getName(),
				'confirmed',
				$end_utc_str,    // candidate end < existing start? No overlap.
				$start_utc_str   // existing end > candidate start? Overlap.
			) );

			if ( 0 === $rows || false === $rows ) {
				if ( false === $rows ) {
					lm_log( 'Booking INSERT query error.', [ 'error' => $wpdb->last_error ] );
				} else {
					lm_log( 'Booking INSERT 0 rows — slot taken by overlap guard.' );
				}
				return new \WP_Error( 'slot_taken', __( 'Sorry, this time slot was just booked. Please choose another time.', 'lets-meet' ) );
			}

			$booking_id = $wpdb->insert_id;

		} finally {
			// Always release the lock.
			$wpdb->query( $wpdb->prepare(
				"SELECT RELEASE_LOCK(%s)",
				$lock_name
			) );
		}

		// ── Post-insert: GCal push (non-blocking) ────────────────────
		$gcal_event_id = $this->gcal->push_event( [
			'booking_id'   => $booking_id,
			'start_utc'    => $start_utc_str,
			'duration'     => $duration,
			'client_name'  => $name,
			'client_email' => $email,
			'client_phone' => $phone,
			'client_notes' => $notes,
			'service_name' => $service->name,
		] );

		if ( $gcal_event_id ) {
			$wpdb->update(
				$table,
				[ 'gcal_event_id' => $gcal_event_id ],
				[ 'id' => $booking_id ],
				[ '%s' ],
				[ '%d' ]
			);
		}

		// ── Build booking data for hooks and response ─────────────────
		$booking_data = [
			'booking_id'    => $booking_id,
			'service_id'    => $service_id,
			'service_name'  => $service->name,
			'client_name'   => $name,
			'client_email'  => $email,
			'client_phone'  => $phone,
			'client_notes'  => $notes,
			'start_utc'     => $start_utc_str,
			'duration'      => $duration,
			'site_timezone' => $tz->getName(),
			'gcal_event_id' => $gcal_event_id ?: '',
			'date_display'  => wp_date( 'l, F j, Y', $start_local->getTimestamp() ),
			'time_display'  => wp_date( 'g:i A', $start_local->getTimestamp() ),
		];

		/**
		 * Fires after a booking is successfully created.
		 *
		 * @param int   $booking_id   Booking ID.
		 * @param array $booking_data Booking data.
		 */
		do_action( 'lm_booking_created', $booking_id, $booking_data );

		return $booking_data;
	}

	/* ── Booking cancellation ─────────────────────────────────────── */

	/**
	 * Cancel a booking by ID.
	 *
	 * Updates status to 'cancelled' and deletes the GCal event if present.
	 *
	 * @param int $booking_id Booking ID.
	 * @return bool True on success, false on failure.
	 */
	public function cancel( $booking_id ) {
		global $wpdb;

		$booking_id = absint( $booking_id );
		$table      = $wpdb->prefix . 'lm_bookings';

		$booking = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, gcal_event_id, status FROM {$table} WHERE id = %d",
			$booking_id
		) );

		if ( ! $booking ) {
			return false;
		}

		if ( 'cancelled' === $booking->status ) {
			return true; // Already cancelled.
		}

		$updated = $wpdb->update(
			$table,
			[ 'status' => 'cancelled' ],
			[ 'id' => $booking_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			lm_log( 'Booking cancel update failed.', [ 'booking_id' => $booking_id, 'error' => $wpdb->last_error ] );
			return false;
		}

		// Delete GCal event if it exists.
		if ( ! empty( $booking->gcal_event_id ) ) {
			$this->gcal->delete_event( $booking->gcal_event_id );
		}

		/**
		 * Fires after a booking is cancelled.
		 *
		 * @param int $booking_id Booking ID.
		 */
		do_action( 'lm_booking_cancelled', $booking_id );

		return true;
	}

	/* ── Helpers ───────────────────────────────────────────────────── */

	/**
	 * Get a booking by ID.
	 *
	 * @param int $booking_id Booking ID.
	 * @return object|null Booking row or null.
	 */
	public function get( $booking_id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}lm_bookings WHERE id = %d",
			absint( $booking_id )
		) );
	}
}
