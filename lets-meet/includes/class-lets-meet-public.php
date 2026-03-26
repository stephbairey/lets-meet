<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend: shortcode, asset enqueueing, AJAX handlers.
 *
 * Renders the booking widget via [lets_meet] shortcode,
 * enqueues public CSS/JS only on pages that use the shortcode,
 * and handles AJAX requests for slot fetching and booking submission.
 */
class Lets_Meet_Public {

	/** @var Lets_Meet_Services */
	private $services;

	/** @var Lets_Meet_Availability */
	private $availability;

	/** @var Lets_Meet_Bookings */
	private $bookings;

	public function __construct( Lets_Meet_Services $services, Lets_Meet_Availability $availability, Lets_Meet_Bookings $bookings ) {
		$this->services     = $services;
		$this->availability = $availability;
		$this->bookings     = $bookings;
	}

	/* ── Shortcode ────────────────────────────────────────────────── */

	/**
	 * Register the [lets_meet] shortcode.
	 */
	public function register_shortcode() {
		add_shortcode( 'lets_meet', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the [lets_meet] shortcode output.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( [
			'service' => '',
		], $atts, 'lets_meet' );

		$active_services = $this->services->get_all( true );

		if ( empty( $active_services ) ) {
			return '<p class="lm-no-services">' . esc_html__( 'No services are currently available.', 'lets-meet' ) . '</p>';
		}

		// If a service slug was specified, pre-select it.
		$preselected_id = 0;
		if ( '' !== $atts['service'] ) {
			$service = $this->services->get_by_slug( sanitize_title( $atts['service'] ) );
			if ( $service && $service->is_active ) {
				$preselected_id = (int) $service->id;
			}
		}

		$settings = get_option( 'lm_settings', [] );
		$horizon  = absint( $settings['horizon'] ?? 60 );

		// Get availability windows for calendar greying-out.
		$availability = get_option( 'lm_availability', [] );
		$available_days = [];
		$day_map = [
			'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
			'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 0,
		];
		foreach ( $availability as $day_name => $windows ) {
			if ( ! empty( $windows ) ) {
				// Check at least one window has both start and end.
				foreach ( $windows as $w ) {
					if ( ! empty( $w['start'] ) && ! empty( $w['end'] ) ) {
						$available_days[] = $day_map[ $day_name ] ?? -1;
						break;
					}
				}
			}
		}

		ob_start();

		echo '<div id="lm-booking-widget" class="lm-booking-widget">';

		// Step 1: Service selection.
		include LM_PATH . 'templates/frontend/calendar-view.php';

		echo '</div>';

		return ob_get_clean();
	}

	/* ── Client cancel/reschedule pages ──────────────────────────── */

	/**
	 * Intercept ?lm_action=cancel|reschedule&lm_token=... on any page.
	 *
	 * Hooked to template_redirect.
	 */
	public function handle_client_action() {
		// Handle PayPal return pages.
		$payment_action = sanitize_text_field( $_GET['lm_payment'] ?? '' );
		if ( '' !== $payment_action ) {
			$this->handle_payment_return( $payment_action );
			return;
		}

		$action = sanitize_text_field( $_GET['lm_action'] ?? '' );
		$token  = sanitize_text_field( $_GET['lm_token'] ?? '' );

		if ( '' === $action || '' === $token ) {
			return;
		}

		if ( ! in_array( $action, [ 'cancel', 'reschedule' ], true ) ) {
			return;
		}

		$booking = $this->bookings->get_by_token( $token );

		if ( ! $booking ) {
			status_header( 404 );
			get_header();
			echo '<div style="max-width: 520px; margin: 60px auto; text-align: center; padding: 0 20px;">';
			echo '<h2>' . esc_html__( 'Booking Not Found', 'lets-meet' ) . '</h2>';
			echo '<p>' . esc_html__( 'This link is no longer valid.', 'lets-meet' ) . '</p>';
			echo '</div>';
			get_footer();
			exit;
		}

		// Build display values.
		try {
			$tz = new \DateTimeZone( $booking->site_timezone ?: wp_timezone_string() );
		} catch ( \Exception $e ) {
			$tz = wp_timezone();
		}
		$start        = new \DateTimeImmutable( $booking->start_utc, new \DateTimeZone( 'UTC' ) );
		$local        = $start->setTimezone( $tz );
		$service      = $this->services->get( $booking->service_id );
		$service_name = $service ? $service->name : __( '(deleted)', 'lets-meet' );
		$date_display = wp_date( 'l, F j, Y', $local->getTimestamp() );
		$time_display = wp_date( 'g:i A', $local->getTimestamp() );

		if ( 'cancel' === $action ) {
			if ( 'cancelled' === $booking->status ) {
				// Already cancelled — show confirmation.
				$confirmed = true;
				include LM_PATH . 'templates/frontend/cancel-page.php';
				exit;
			}

			// Check if cancellation was just performed (redirect back).
			$confirmed = ! empty( $_GET['lm_cancelled'] );
			include LM_PATH . 'templates/frontend/cancel-page.php';
			exit;
		}

		if ( 'reschedule' === $action ) {
			if ( 'cancelled' === $booking->status ) {
				status_header( 410 );
				get_header();
				echo '<div style="max-width: 520px; margin: 60px auto; text-align: center; padding: 0 20px;">';
				echo '<h2>' . esc_html__( 'Booking Cancelled', 'lets-meet' ) . '</h2>';
				echo '<p>' . esc_html__( 'This booking has already been cancelled and cannot be rescheduled.', 'lets-meet' ) . '</p>';
				echo '</div>';
				get_footer();
				exit;
			}

			// Enqueue assets for the reschedule page.
			wp_enqueue_style( 'lm-public', LM_URL . 'assets/css/public.css', [], LM_VERSION );
			wp_enqueue_script( 'lm-public', LM_URL . 'assets/js/public.js', [], LM_VERSION, true );
			wp_localize_script( 'lm-public', 'lmData', [
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'lm_frontend_nonce' ),
				'horizon'  => absint( ( get_option( 'lm_settings', [] ) )['horizon'] ?? 60 ),
				'timezone' => wp_timezone_string(),
				'i18n'     => [
					'loading'  => __( 'Loading...', 'lets-meet' ),
					'noSlots'  => __( 'No available times on this date.', 'lets-meet' ),
				],
			] );

			$settings       = get_option( 'lm_settings', [] );
			$horizon        = absint( $settings['horizon'] ?? 60 );
			$service_id     = $booking->service_id;
			$availability   = get_option( 'lm_availability', [] );
			$available_days = [];
			$day_map        = [
				'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
				'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 0,
			];
			foreach ( $availability as $day_name => $windows ) {
				if ( ! empty( $windows ) ) {
					foreach ( $windows as $w ) {
						if ( ! empty( $w['start'] ) && ! empty( $w['end'] ) ) {
							$available_days[] = $day_map[ $day_name ] ?? -1;
							break;
						}
					}
				}
			}

			include LM_PATH . 'templates/frontend/reschedule-page.php';
			exit;
		}
	}

	/**
	 * Handle PayPal return/cancel pages.
	 *
	 * @param string $action 'success' or 'cancelled'.
	 */
	private function handle_payment_return( $action ) {
		$booking_id = absint( $_GET['booking'] ?? 0 );

		get_header();
		echo '<div style="max-width: 520px; margin: 60px auto; text-align: center; padding: 0 20px;">';

		if ( 'success' === $action ) {
			echo '<h2>' . esc_html__( 'Thank You!', 'lets-meet' ) . '</h2>';
			echo '<p>' . esc_html__( 'Payment received! You\'ll get a confirmation email shortly.', 'lets-meet' ) . '</p>';
		} elseif ( 'cancelled' === $action ) {
			echo '<h2>' . esc_html__( 'Payment Cancelled', 'lets-meet' ) . '</h2>';
			echo '<p>' . esc_html__( 'Your payment was cancelled. Your booking is being held.', 'lets-meet' ) . '</p>';

			if ( $booking_id ) {
				$booking = $this->bookings->get( $booking_id );
				if ( $booking && 'pending_payment' === $booking->status ) {
					$service = $this->services->get( $booking->service_id );
					if ( $service ) {
						$paypal = new Lets_Meet_Paypal();
						$retry_url = $paypal->get_redirect_url( $booking_id, $service->name, $service->price );
						echo '<p><a href="' . esc_url( $retry_url ) . '" style="display:inline-block; background-color:#0073aa; color:#fff; text-decoration:none; padding:10px 24px; border-radius:4px; font-weight:600;">'
							. esc_html__( 'Complete Payment', 'lets-meet' ) . '</a></p>';
					}
				}
			}

			$settings     = get_option( 'lm_settings', [] );
			$contact_email = $settings['admin_email'] ?? get_option( 'admin_email' );
			echo '<p>' . sprintf(
				/* translators: %s: admin email */
				esc_html__( 'To complete your booking, click the button above or contact us at %s.', 'lets-meet' ),
				'<a href="mailto:' . esc_attr( $contact_email ) . '">' . esc_html( $contact_email ) . '</a>'
			) . '</p>';
		}

		echo '</div>';
		get_footer();
		exit;
	}

	/**
	 * Handle client cancellation form POST.
	 *
	 * Hooked to admin_post_lm_client_cancel and admin_post_nopriv_lm_client_cancel.
	 */
	public function handle_client_cancel() {
		$token = sanitize_text_field( $_POST['lm_token'] ?? '' );

		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'lm_client_cancel_' . $token ) ) {
			wp_die( esc_html__( 'Invalid or expired link. Please try again.', 'lets-meet' ) );
		}

		$booking = $this->bookings->get_by_token( $token );
		if ( ! $booking ) {
			wp_die( esc_html__( 'Booking not found.', 'lets-meet' ) );
		}

		if ( 'confirmed' === $booking->status ) {
			$this->bookings->cancel( $booking->id );
		}

		$redirect = add_query_arg( [
			'lm_action'    => 'cancel',
			'lm_token'     => $token,
			'lm_cancelled' => '1',
		], home_url( '/' ) );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * AJAX handler: reschedule a booking (client-facing).
	 */
	public function ajax_reschedule_booking() {
		check_ajax_referer( 'lm_frontend_nonce', 'nonce' );

		$token    = sanitize_text_field( $_POST['token'] ?? '' );
		$new_date = sanitize_text_field( $_POST['date'] ?? '' );
		$new_time = sanitize_text_field( $_POST['time'] ?? '' );

		$booking = $this->bookings->get_by_token( $token );
		if ( ! $booking ) {
			wp_send_json_error( [ 'message' => __( 'Booking not found.', 'lets-meet' ) ] );
			return;
		}

		if ( 'confirmed' !== $booking->status ) {
			wp_send_json_error( [ 'message' => __( 'This booking has been cancelled.', 'lets-meet' ) ] );
			return;
		}

		$result = $this->bookings->reschedule( $booking->id, $new_date, $new_time );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			return;
		}

		wp_send_json_success( [
			'service'  => $result['service_name'],
			'date'     => $result['date_display'],
			'time'     => $result['time_display'],
			'duration' => sprintf( __( '%d minutes', 'lets-meet' ), $result['duration'] ),
		] );
	}

	/* ── Asset enqueueing ─────────────────────────────────────────── */

	/**
	 * Enqueue frontend CSS and JS on pages with the shortcode.
	 */
	public function enqueue_public_assets() {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'lets_meet' ) ) {
			return;
		}

		wp_enqueue_style(
			'lm-public',
			LM_URL . 'assets/css/public.css',
			[],
			LM_VERSION
		);

		wp_enqueue_script(
			'lm-public',
			LM_URL . 'assets/js/public.js',
			[],
			LM_VERSION,
			true
		);

		$settings = get_option( 'lm_settings', [] );

		wp_localize_script( 'lm-public', 'lmData', [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'lm_frontend_nonce' ),
			'horizon'  => absint( $settings['horizon'] ?? 60 ),
			'timezone' => wp_timezone_string(),
			'i18n'     => [
				'loading'       => __( 'Loading...', 'lets-meet' ),
				'noSlots'       => __( 'No available times on this date.', 'lets-meet' ),
				'selectDate'    => __( 'Select a date to see available times.', 'lets-meet' ),
				'selectTime'    => __( 'Select a time', 'lets-meet' ),
				'bookNow'       => __( 'Book Now', 'lets-meet' ),
				'submitting'    => __( 'Booking...', 'lets-meet' ),
				'errorGeneric'  => __( 'Something went wrong. Please try again.', 'lets-meet' ),
				'prevMonth'     => __( '&laquo; Prev', 'lets-meet' ),
				'nextMonth'     => __( 'Next &raquo;', 'lets-meet' ),
			],
		] );
	}

	/* ── AJAX: get slots ──────────────────────────────────────────── */

	/**
	 * AJAX handler: return available time slots for a date and service.
	 */
	public function ajax_get_slots() {
		check_ajax_referer( 'lm_frontend_nonce', 'nonce' );

		$date       = sanitize_text_field( $_POST['date'] ?? '' );
		$service_id = absint( $_POST['service_id'] ?? 0 );

		// Validate date format.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid date format.', 'lets-meet' ) ] );
			return; // wp_send_json_error() calls wp_die(), but explicit return for clarity.
		}

		// Validate service exists and is active.
		$service = $this->services->get( $service_id );
		if ( ! $service || ! $service->is_active ) {
			wp_send_json_error( [ 'message' => __( 'Invalid service.', 'lets-meet' ) ] );
			return;
		}

		$slots = $this->availability->get_available_slots( $date, $service_id );

		// Format slots for display.
		$tz             = wp_timezone();
		$formatted      = [];
		foreach ( $slots as $time_str ) {
			$dt = new DateTimeImmutable( $date . ' ' . $time_str . ':00', $tz );
			$formatted[] = [
				'value'   => $time_str,
				'display' => wp_date( 'g:i A', $dt->getTimestamp() ),
			];
		}

		wp_send_json_success( [
			'slots'    => $formatted,
			'date'     => $date,
			'duration' => absint( $service->duration ),
		] );
	}

	/* ── AJAX: submit booking ─────────────────────────────────────── */

	/**
	 * AJAX handler: submit a booking.
	 */
	public function ajax_submit_booking() {
		check_ajax_referer( 'lm_frontend_nonce', 'nonce' );

		// Honeypot check — reject if filled.
		$honeypot = sanitize_text_field( $_POST['honeypot'] ?? '' );
		if ( '' !== $honeypot ) {
			wp_send_json_error( [ 'message' => __( 'Something went wrong. Please try again.', 'lets-meet' ) ] );
			return;
		}

		// Rendered-at timestamp check — reject if submitted < 3 seconds after render.
		$rendered_at = absint( $_POST['rendered_at'] ?? 0 );
		$now         = current_datetime()->getTimestamp();
		if ( 0 === $rendered_at || ( $now - $rendered_at ) < 3 ) {
			wp_send_json_error( [ 'message' => __( 'Something went wrong. Please try again.', 'lets-meet' ) ] );
			return;
		}

		// Rate limiting.
		if ( ! $this->bookings->check_rate_limit() ) {
			wp_send_json_error( [ 'message' => __( 'Too many booking attempts. Please try again later.', 'lets-meet' ) ] );
			return;
		}

		// Attempt to create the booking.
		$result = $this->bookings->create( [
			'service_id' => absint( $_POST['service_id'] ?? 0 ),
			'date'       => sanitize_text_field( $_POST['date'] ?? '' ),
			'time'       => sanitize_text_field( $_POST['time'] ?? '' ),
			'name'       => sanitize_text_field( $_POST['name'] ?? '' ),
			'email'      => sanitize_email( $_POST['email'] ?? '' ),
			'phone'      => sanitize_text_field( $_POST['phone'] ?? '' ),
			'notes'      => sanitize_textarea_field( $_POST['notes'] ?? '' ),
		] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			return;
		}

		// Paid booking: redirect to PayPal.
		if ( ! empty( $result['paypal_redirect_url'] ) ) {
			wp_send_json_success( [
				'redirect' => $result['paypal_redirect_url'],
			] );
			return;
		}

		wp_send_json_success( [
			'service'  => $result['service_name'],
			'date'     => $result['date_display'],
			'time'     => $result['time_display'],
			'duration' => sprintf(
				/* translators: %d: duration in minutes */
				__( '%d minutes', 'lets-meet' ),
				$result['duration']
			),
		] );
	}
}
