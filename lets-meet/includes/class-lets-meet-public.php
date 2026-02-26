<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend: shortcode, asset enqueueing, AJAX handlers.
 *
 * Renders the booking widget via [lets_meet] shortcode,
 * enqueues public CSS/JS only on pages that use the shortcode,
 * and handles AJAX requests for slot fetching.
 */
class Lets_Meet_Public {

	/** @var Lets_Meet_Services */
	private $services;

	/** @var Lets_Meet_Availability */
	private $availability;

	public function __construct( Lets_Meet_Services $services, Lets_Meet_Availability $availability ) {
		$this->services     = $services;
		$this->availability = $availability;
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
}
