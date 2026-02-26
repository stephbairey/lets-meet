<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin booking notification email template.
 *
 * Available variables:
 * @var array  $args     Booking data (service_name, date_display, time_display, duration, client_name, client_email, client_phone, client_notes, booking_id, etc.)
 * @var array  $settings Plugin settings (admin_email, etc.)
 *
 * This template can be overridden by copying it to:
 *   your-theme/lets-meet/emails/confirmation-admin.php
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
						<td style="background-color: #2e7d32; padding: 24px 32px; text-align: center;">
							<h1 style="margin: 0; color: #ffffff; font-size: 22px; font-weight: 600;">
								<?php esc_html_e( 'New Booking', 'lets-meet' ); ?>
							</h1>
						</td>
					</tr>

					<!-- Body -->
					<tr>
						<td style="padding: 32px;">
							<p style="margin: 0 0 24px; font-size: 15px; color: #555555; line-height: 1.6;">
								<?php esc_html_e( 'A new booking has been submitted. Here are the details:', 'lets-meet' ); ?>
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

							<!-- Client details box -->
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #fef9f0; border-radius: 6px; margin-bottom: 24px;">
								<tr>
									<td style="padding: 20px 24px;">
										<p style="margin: 0 0 12px; font-size: 14px; font-weight: 600; color: #1e1e1e;">
											<?php esc_html_e( 'Client Details', 'lets-meet' ); ?>
										</p>
										<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
											<tr>
												<td style="padding: 6px 0; font-size: 14px; color: #646970; width: 100px;"><?php esc_html_e( 'Name', 'lets-meet' ); ?></td>
												<td style="padding: 6px 0; font-size: 14px; color: #1e1e1e;"><?php echo esc_html( $args['client_name'] ); ?></td>
											</tr>
											<tr>
												<td style="padding: 6px 0; font-size: 14px; color: #646970;"><?php esc_html_e( 'Email', 'lets-meet' ); ?></td>
												<td style="padding: 6px 0; font-size: 14px; color: #1e1e1e;"><?php echo esc_html( $args['client_email'] ); ?></td>
											</tr>
											<?php if ( ! empty( $args['client_phone'] ) ) : ?>
											<tr>
												<td style="padding: 6px 0; font-size: 14px; color: #646970;"><?php esc_html_e( 'Phone', 'lets-meet' ); ?></td>
												<td style="padding: 6px 0; font-size: 14px; color: #1e1e1e;"><?php echo esc_html( $args['client_phone'] ); ?></td>
											</tr>
											<?php endif; ?>
											<?php if ( ! empty( $args['client_notes'] ) ) : ?>
											<tr>
												<td style="padding: 6px 0; font-size: 14px; color: #646970; vertical-align: top;"><?php esc_html_e( 'Notes', 'lets-meet' ); ?></td>
												<td style="padding: 6px 0; font-size: 14px; color: #1e1e1e;"><?php echo esc_html( $args['client_notes'] ); ?></td>
											</tr>
											<?php endif; ?>
										</table>
									</td>
								</tr>
							</table>

							<!-- Admin link -->
							<p style="margin: 0; text-align: center;">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=lets-meet' ) ); ?>" style="display: inline-block; background-color: #2e7d32; color: #ffffff; text-decoration: none; padding: 10px 24px; border-radius: 4px; font-size: 14px; font-weight: 600;">
									<?php esc_html_e( 'View Bookings', 'lets-meet' ); ?>
								</a>
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
