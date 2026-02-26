<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client booking confirmation email template.
 *
 * Available variables:
 * @var array  $args     Booking data (service_name, date_display, time_display, duration, client_name, etc.)
 * @var array  $settings Plugin settings (confirm_msg, admin_email, etc.)
 *
 * This template can be overridden by copying it to:
 *   your-theme/lets-meet/emails/confirmation-client.php
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f7; padding: 40px 0;">
		<tr>
			<td align="center">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; max-width: 600px; width: 100%;">
					<!-- Header -->
					<tr>
						<td style="background-color: #0073aa; padding: 24px 32px; text-align: center;">
							<h1 style="margin: 0; color: #ffffff; font-size: 22px; font-weight: 600;">
								<?php esc_html_e( 'Booking Confirmed', 'lets-meet' ); ?>
							</h1>
						</td>
					</tr>

					<!-- Body -->
					<tr>
						<td style="padding: 32px;">
							<p style="margin: 0 0 16px; font-size: 16px; color: #333333;">
								<?php
								printf(
									/* translators: %s: client name */
									esc_html__( 'Hi %s,', 'lets-meet' ),
									esc_html( $args['client_name'] )
								);
								?>
							</p>
							<p style="margin: 0 0 24px; font-size: 15px; color: #555555; line-height: 1.6;">
								<?php esc_html_e( 'Your session has been confirmed. Here are your booking details:', 'lets-meet' ); ?>
							</p>

							<!-- Booking details box -->
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f0f6fc; border-radius: 6px; margin-bottom: 24px;">
								<tr>
									<td style="padding: 20px 24px;">
										<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
											<tr>
												<td style="padding: 6px 0; font-size: 14px; color: #646970; width: 100px;"><?php esc_html_e( 'Service', 'lets-meet' ); ?></td>
												<td style="padding: 6px 0; font-size: 14px; color: #1e1e1e; font-weight: 600;"><?php echo esc_html( $args['service_name'] ); ?></td>
											</tr>
											<tr>
												<td style="padding: 6px 0; font-size: 14px; color: #646970;"><?php esc_html_e( 'Date', 'lets-meet' ); ?></td>
												<td style="padding: 6px 0; font-size: 14px; color: #1e1e1e; font-weight: 600;"><?php echo esc_html( $args['date_display'] ); ?></td>
											</tr>
											<tr>
												<td style="padding: 6px 0; font-size: 14px; color: #646970;"><?php esc_html_e( 'Time', 'lets-meet' ); ?></td>
												<td style="padding: 6px 0; font-size: 14px; color: #1e1e1e; font-weight: 600;"><?php echo esc_html( $args['time_display'] ); ?></td>
											</tr>
											<tr>
												<td style="padding: 6px 0; font-size: 14px; color: #646970;"><?php esc_html_e( 'Duration', 'lets-meet' ); ?></td>
												<td style="padding: 6px 0; font-size: 14px; color: #1e1e1e; font-weight: 600;">
													<?php
													printf(
														/* translators: %d: number of minutes */
														esc_html__( '%d minutes', 'lets-meet' ),
														absint( $args['duration'] )
													);
													?>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>

							<?php
							$custom_msg = $settings['confirm_msg'] ?? '';
							if ( '' !== $custom_msg ) :
							?>
								<p style="margin: 0 0 24px; font-size: 15px; color: #555555; line-height: 1.6;">
									<?php echo esc_html( $custom_msg ); ?>
								</p>
							<?php endif; ?>

							<p style="margin: 0; font-size: 14px; color: #888888;">
								<?php esc_html_e( 'If you need to make changes, please contact us directly.', 'lets-meet' ); ?>
							</p>
						</td>
					</tr>

					<!-- Footer -->
					<tr>
						<td style="padding: 20px 32px; background-color: #f9f9f9; border-top: 1px solid #eeeeee; text-align: center;">
							<p style="margin: 0; font-size: 12px; color: #999999;">
								<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
							</p>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
