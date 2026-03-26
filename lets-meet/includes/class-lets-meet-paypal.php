<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal Standard IPN payment integration.
 *
 * Handles PayPal redirect URL generation, IPN endpoint registration,
 * IPN verification, and payment confirmation.
 */
class Lets_Meet_Paypal {

	/**
	 * Register the IPN rewrite rule and query var.
	 *
	 * Hooked to init.
	 */
	public function register_ipn_endpoint() {
		add_rewrite_rule(
			'^lets-meet-ipn/?$',
			'index.php?lm_ipn=1',
			'top'
		);
	}

	/**
	 * Register lm_ipn as a recognized query var.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'lm_ipn';
		return $vars;
	}

	/**
	 * Handle incoming IPN if the query var is set.
	 *
	 * Hooked to template_redirect.
	 */
	public function maybe_handle_ipn() {
		if ( ! get_query_var( 'lm_ipn' ) ) {
			return;
		}

		$this->handle_ipn();
		exit;
	}

	/**
	 * Build the PayPal redirect URL for a paid booking.
	 *
	 * @param int    $booking_id  Booking ID.
	 * @param string $service_name Service name for display.
	 * @param float  $price       Service price.
	 * @return string PayPal URL.
	 */
	public function get_redirect_url( $booking_id, $service_name, $price ) {
		$settings      = get_option( 'lm_settings', [] );
		$paypal_email  = $settings['paypal_email'] ?? '';
		$sandbox       = ! empty( $settings['paypal_sandbox'] );

		$base_url = $sandbox
			? 'https://www.sandbox.paypal.com/cgi-bin/webscr'
			: 'https://www.paypal.com/cgi-bin/webscr';

		$args = [
			'cmd'           => '_xclick',
			'business'      => $paypal_email,
			'item_name'     => sanitize_text_field( $service_name ),
			'amount'        => number_format( (float) $price, 2, '.', '' ),
			'currency_code' => 'USD',
			'custom'        => absint( $booking_id ),
			'notify_url'    => home_url( '/lets-meet-ipn/' ),
			'return'        => add_query_arg( [
				'lm_payment' => 'success',
				'booking'    => absint( $booking_id ),
			], home_url( '/' ) ),
			'cancel_return' => add_query_arg( [
				'lm_payment' => 'cancelled',
				'booking'    => absint( $booking_id ),
			], home_url( '/' ) ),
			'no_shipping'   => '1',
			'no_note'       => '1',
		];

		return $base_url . '?' . http_build_query( $args, '', '&' );
	}

	/**
	 * Process an incoming IPN POST from PayPal.
	 */
	private function handle_ipn() {
		// Must be a POST request.
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			status_header( 405 );
			exit;
		}

		// Read raw POST data.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- IPN is server-to-server, no nonce.
		$post_data = $_POST;

		if ( empty( $post_data ) ) {
			status_header( 400 );
			exit;
		}

		// Respond 200 immediately (PayPal requires this).
		status_header( 200 );

		// Verify with PayPal.
		if ( ! $this->verify_ipn( $post_data ) ) {
			lm_log( 'IPN verification failed.' );
			exit;
		}

		// Only process completed payments.
		$payment_status = sanitize_text_field( $post_data['payment_status'] ?? '' );
		if ( 'Completed' !== $payment_status ) {
			lm_log( 'IPN ignored: payment_status is not Completed.', [ 'status' => $payment_status ] );
			exit;
		}

		// Validate receiver email matches our PayPal email.
		$settings     = get_option( 'lm_settings', [] );
		$paypal_email = $settings['paypal_email'] ?? '';
		$receiver     = sanitize_email( $post_data['receiver_email'] ?? '' );

		if ( strtolower( $receiver ) !== strtolower( $paypal_email ) ) {
			lm_log( 'IPN receiver_email mismatch.', [ 'expected' => $paypal_email, 'received' => $receiver ] );
			exit;
		}

		// Validate currency.
		$currency = sanitize_text_field( $post_data['mc_currency'] ?? '' );
		if ( 'USD' !== $currency ) {
			lm_log( 'IPN currency mismatch.', [ 'currency' => $currency ] );
			exit;
		}

		// Extract booking ID and payment details.
		$booking_id = absint( $post_data['custom'] ?? 0 );
		$amount     = (float) ( $post_data['mc_gross'] ?? 0 );
		$txn_id     = sanitize_text_field( $post_data['txn_id'] ?? '' );

		if ( 0 === $booking_id || '' === $txn_id ) {
			lm_log( 'IPN missing booking_id or txn_id.' );
			exit;
		}

		$this->confirm_payment( $booking_id, $amount, $txn_id );
	}

	/**
	 * Verify IPN data with PayPal's servers.
	 *
	 * @param array $post_data The raw POST data from PayPal.
	 * @return bool True if verified, false otherwise.
	 */
	private function verify_ipn( $post_data ) {
		$settings = get_option( 'lm_settings', [] );
		$sandbox  = ! empty( $settings['paypal_sandbox'] );

		$verify_url = $sandbox
			? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr'
			: 'https://ipnpb.paypal.com/cgi-bin/webscr';

		// Prepend cmd=_notify-validate.
		$body = 'cmd=_notify-validate';
		foreach ( $post_data as $key => $value ) {
			$body .= '&' . urlencode( $key ) . '=' . urlencode( $value );
		}

		$response = wp_remote_post( $verify_url, [
			'body'        => $body,
			'timeout'     => 30,
			'httpversion' => '1.1',
			'sslverify'   => true,
		] );

		if ( is_wp_error( $response ) ) {
			lm_log( 'IPN verify request failed.', [ 'error' => $response->get_error_message() ] );
			return false;
		}

		$result = wp_remote_retrieve_body( $response );
		return 'VERIFIED' === trim( $result );
	}

	/**
	 * Confirm payment: update booking, push GCal, send emails.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param float  $amount     Amount paid.
	 * @param string $txn_id     PayPal transaction ID.
	 */
	private function confirm_payment( $booking_id, $amount, $txn_id ) {
		global $wpdb;

		$table   = $wpdb->prefix . 'lm_bookings';
		$booking = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d",
			$booking_id
		) );

		if ( ! $booking ) {
			lm_log( 'IPN confirm_payment: booking not found.', [ 'booking_id' => $booking_id ] );
			return;
		}

		// Must be in pending_payment status.
		if ( 'pending_payment' !== $booking->status ) {
			lm_log( 'IPN confirm_payment: booking not pending_payment.', [
				'booking_id' => $booking_id,
				'status'     => $booking->status,
			] );
			return;
		}

		// Prevent duplicate IPN processing — check txn_id uniqueness.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE payment_txn_id = %s",
			$txn_id
		) );

		if ( $existing ) {
			lm_log( 'IPN confirm_payment: duplicate txn_id.', [ 'txn_id' => $txn_id ] );
			return;
		}

		// Update the booking.
		$wpdb->update(
			$table,
			[
				'status'         => 'confirmed',
				'payment_status' => 'paid',
				'payment_amount' => $amount,
				'payment_txn_id' => $txn_id,
				'payment_date'   => current_time( 'mysql', true ),
			],
			[ 'id' => $booking_id ],
			[ '%s', '%s', '%f', '%s', '%s' ],
			[ '%d' ]
		);

		// Push Zoom meeting + GCal event.
		$services = new Lets_Meet_Services();
		$service  = $services->get( $booking->service_id );

		$zoom_join_url = '';

		// Create Zoom meeting if service has zoom_enabled.
		if ( $service && ! empty( $service->zoom_enabled ) ) {
			$zoom = new Lets_Meet_Zoom();
			if ( $zoom->is_connected() ) {
				$zoom_result = $zoom->create_meeting( [
					'start_utc'    => $booking->start_utc,
					'duration'     => $booking->duration,
					'client_name'  => $booking->client_name,
					'service_name' => $service->name,
				] );

				if ( $zoom_result ) {
					$wpdb->update(
						$table,
						[
							'zoom_meeting_id' => $zoom_result['meeting_id'],
							'zoom_join_url'   => $zoom_result['join_url'],
						],
						[ 'id' => $booking_id ],
						[ '%s', '%s' ],
						[ '%d' ]
					);
					$zoom_join_url = $zoom_result['join_url'];
				}
			}
		}

		$gcal = new Lets_Meet_Gcal();
		$gcal_event_id = $gcal->push_event( [
			'booking_id'    => $booking_id,
			'start_utc'     => $booking->start_utc,
			'duration'      => $booking->duration,
			'client_name'   => $booking->client_name,
			'client_email'  => $booking->client_email,
			'client_phone'  => $booking->client_phone,
			'client_notes'  => $booking->client_notes,
			'service_name'  => $service ? $service->name : '',
			'zoom_join_url' => $zoom_join_url,
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

		// Build booking data for email hooks.
		try {
			$tz = new \DateTimeZone( $booking->site_timezone ?: wp_timezone_string() );
		} catch ( \Exception $e ) {
			$tz = wp_timezone();
		}
		$start       = new \DateTimeImmutable( $booking->start_utc, new \DateTimeZone( 'UTC' ) );
		$local       = $start->setTimezone( $tz );

		$booking_data = [
			'booking_id'     => $booking_id,
			'service_id'     => $booking->service_id,
			'service_name'   => $service ? $service->name : '',
			'client_name'    => $booking->client_name,
			'client_email'   => $booking->client_email,
			'client_phone'   => $booking->client_phone,
			'client_notes'   => $booking->client_notes,
			'start_utc'      => $booking->start_utc,
			'duration'       => $booking->duration,
			'site_timezone'  => $booking->site_timezone,
			'cancel_token'   => $booking->cancel_token,
			'date_display'   => wp_date( 'l, F j, Y', $local->getTimestamp() ),
			'time_display'   => wp_date( 'g:i A', $local->getTimestamp() ),
			'payment_status' => 'paid',
			'payment_amount' => $amount,
			'payment_txn_id' => $txn_id,
			'zoom_join_url'  => $zoom_join_url,
		];

		/**
		 * Fires after a booking is confirmed via payment.
		 * Uses the same hook as regular booking creation so emails fire.
		 */
		do_action( 'lm_booking_created', $booking_id, $booking_data );
	}
}
