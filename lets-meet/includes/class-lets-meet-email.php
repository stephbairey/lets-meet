<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email confirmations via wp_mail().
 *
 * Sends client and admin confirmation emails on booking creation.
 * Templates are overridable by themes: place files in theme/lets-meet/emails/.
 * Fires lm_email_client_args and lm_email_admin_args filters before sending.
 */
class Lets_Meet_Email {

	/**
	 * Send confirmation emails for a new booking.
	 *
	 * Hooked to lm_booking_created action.
	 *
	 * @param int   $booking_id   Booking ID.
	 * @param array $booking_data Booking data from Lets_Meet_Bookings::create().
	 */
	public function send_confirmation( $booking_id, $booking_data ) {
		$this->send_client_email( $booking_data );
		$this->send_admin_email( $booking_data );
	}

	/* ── Client email ─────────────────────────────────────────────── */

	/**
	 * Send the client confirmation email.
	 *
	 * @param array $booking_data Booking data.
	 */
	private function send_client_email( $booking_data ) {
		if ( ! is_email( $booking_data['client_email'] ?? '' ) ) {
			lm_log( 'Invalid client email for confirmation.', [
				'booking_id' => $booking_data['booking_id'],
			] );
			return;
		}

		$settings = get_option( 'lm_settings', [] );

		$subject = sprintf(
			/* translators: %s: service name */
			__( 'Your session is confirmed — %s', 'lets-meet' ),
			sanitize_text_field( $booking_data['service_name'] )
		);

		$body = $this->render_template( 'confirmation-client', $booking_data );

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$reply_to = $settings['admin_email'] ?? get_option( 'admin_email' );
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$email_args = [
			'to'      => $booking_data['client_email'],
			'subject' => $subject,
			'body'    => $body,
			'headers' => $headers,
		];

		/**
		 * Filter the client confirmation email arguments.
		 *
		 * @param array $email_args Email args (to, subject, body, headers).
		 */
		$email_args = apply_filters( 'lm_email_client_args', $email_args );

		if ( ! is_email( $email_args['to'] ) ) {
			lm_log( 'Client email recipient invalid after filter.', [
				'booking_id' => $booking_data['booking_id'],
			] );
			return;
		}

		$sent = wp_mail(
			$email_args['to'],
			$email_args['subject'],
			$email_args['body'],
			$email_args['headers']
		);

		if ( ! $sent ) {
			lm_log( 'Client confirmation email failed.', [
				'booking_id' => $booking_data['booking_id'],
			] );
		}
	}

	/* ── Admin email ──────────────────────────────────────────────── */

	/**
	 * Send the admin notification email.
	 *
	 * @param array $booking_data Booking data.
	 */
	private function send_admin_email( $booking_data ) {
		$settings = get_option( 'lm_settings', [] );

		// Check if admin notifications are enabled.
		if ( empty( $settings['admin_notify'] ) ) {
			return;
		}

		$admin_email = $settings['admin_email'] ?? get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: client name, 2: service name */
			__( 'New booking: %1$s — %2$s', 'lets-meet' ),
			sanitize_text_field( $booking_data['client_name'] ),
			sanitize_text_field( $booking_data['service_name'] )
		);

		$body = $this->render_template( 'confirmation-admin', $booking_data );

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$email_args = [
			'to'      => $admin_email,
			'subject' => $subject,
			'body'    => $body,
			'headers' => $headers,
		];

		/**
		 * Filter the admin notification email arguments.
		 *
		 * @param array $email_args Email args (to, subject, body, headers).
		 */
		$email_args = apply_filters( 'lm_email_admin_args', $email_args );

		if ( ! is_email( $email_args['to'] ) ) {
			lm_log( 'Admin email recipient invalid after filter.', [
				'booking_id' => $booking_data['booking_id'],
			] );
			return;
		}

		$sent = wp_mail(
			$email_args['to'],
			$email_args['subject'],
			$email_args['body'],
			$email_args['headers']
		);

		if ( ! $sent ) {
			lm_log( 'Admin notification email failed.', [
				'booking_id' => $booking_data['booking_id'],
			] );
		}
	}

	/* ── Cancellation email ──────────────────────────────────────── */

	/**
	 * Send cancellation confirmation email to the client.
	 *
	 * Hooked to lm_booking_cancelled action.
	 *
	 * @param int $booking_id Booking ID.
	 */
	public function send_cancellation_email( $booking_id ) {
		global $wpdb;

		$booking = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}lm_bookings WHERE id = %d",
			absint( $booking_id )
		) );

		if ( ! $booking || ! is_email( $booking->client_email ) ) {
			return;
		}

		// Build display values.
		try {
			$tz = new \DateTimeZone( $booking->site_timezone ?: wp_timezone_string() );
		} catch ( \Exception $e ) {
			$tz = wp_timezone();
		}
		$start = new \DateTimeImmutable( $booking->start_utc, new \DateTimeZone( 'UTC' ) );
		$local = $start->setTimezone( $tz );

		$services = new Lets_Meet_Services();
		$service  = $services->get( $booking->service_id );

		$booking_data = [
			'booking_id'   => $booking->id,
			'service_name' => $service ? $service->name : __( '(deleted)', 'lets-meet' ),
			'client_name'  => $booking->client_name,
			'client_email' => $booking->client_email,
			'duration'     => $booking->duration,
			'date_display' => wp_date( 'l, F j, Y', $local->getTimestamp() ),
			'time_display' => wp_date( 'g:i A', $local->getTimestamp() ),
		];

		$settings = get_option( 'lm_settings', [] );

		$subject = sprintf(
			/* translators: %s: service name */
			__( 'Your booking has been cancelled — %s', 'lets-meet' ),
			sanitize_text_field( $booking_data['service_name'] )
		);

		$body = $this->render_template( 'cancellation-client', $booking_data );

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$reply_to = $settings['admin_email'] ?? get_option( 'admin_email' );
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		wp_mail( $booking->client_email, $subject, $body, $headers );
	}

	/* ── Reschedule email ────────────────────────────────────────── */

	/**
	 * Send reschedule confirmation emails.
	 *
	 * Hooked to lm_booking_rescheduled action.
	 *
	 * @param int   $booking_id   Booking ID.
	 * @param array $booking_data Updated booking data.
	 */
	public function send_reschedule_confirmation( $booking_id, $booking_data ) {
		$this->send_reschedule_client_email( $booking_data );
		$this->send_reschedule_admin_email( $booking_data );
	}

	/**
	 * Send the reschedule confirmation to the client.
	 */
	private function send_reschedule_client_email( $booking_data ) {
		if ( ! is_email( $booking_data['client_email'] ?? '' ) ) {
			return;
		}

		$settings = get_option( 'lm_settings', [] );

		$subject = sprintf(
			/* translators: %s: service name */
			__( 'Your booking has been rescheduled — %s', 'lets-meet' ),
			sanitize_text_field( $booking_data['service_name'] )
		);

		$body = $this->render_template( 'reschedule-client', $booking_data );

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$reply_to = $settings['admin_email'] ?? get_option( 'admin_email' );
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		wp_mail( $booking_data['client_email'], $subject, $body, $headers );
	}

	/**
	 * Send the reschedule notification to the admin.
	 */
	private function send_reschedule_admin_email( $booking_data ) {
		$settings = get_option( 'lm_settings', [] );

		if ( empty( $settings['admin_notify'] ) ) {
			return;
		}

		$admin_email = $settings['admin_email'] ?? get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: client name, 2: service name */
			__( 'Booking rescheduled: %1$s — %2$s', 'lets-meet' ),
			sanitize_text_field( $booking_data['client_name'] ),
			sanitize_text_field( $booking_data['service_name'] )
		);

		$body = $this->render_template( 'reschedule-admin', $booking_data );

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		wp_mail( $admin_email, $subject, $body, $headers );
	}

	/* ── Template rendering ───────────────────────────────────────── */

	/**
	 * Render an email template.
	 *
	 * Checks for a theme override first:
	 *   theme/lets-meet/emails/{template}.php
	 * Falls back to:
	 *   plugin/templates/emails/{template}.php
	 *
	 * @param string $template     Template name (without .php).
	 * @param array  $booking_data Data available to the template as $args.
	 * @return string Rendered HTML.
	 */
	private function render_template( $template, $booking_data ) {
		$template_file = sanitize_file_name( $template ) . '.php';

		// Check for theme override.
		$theme_path = get_stylesheet_directory() . '/lets-meet/emails/' . $template_file;
		if ( file_exists( $theme_path ) ) {
			$file = $theme_path;
		} else {
			$file = LM_PATH . 'templates/emails/' . $template_file;
		}

		if ( ! file_exists( $file ) ) {
			lm_log( 'Email template not found.', [ 'template' => $template ] );
			return '';
		}

		$args = $booking_data;
		$settings = get_option( 'lm_settings', [] );

		ob_start();
		include $file;
		return ob_get_clean();
	}
}
