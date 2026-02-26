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
