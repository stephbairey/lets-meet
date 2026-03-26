<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client-facing cancellation page.
 *
 * Variables:
 * @var object $booking   Booking row from DB.
 * @var string $token     Cancel token.
 * @var string $service_name Service name.
 * @var string $date_display Formatted date.
 * @var string $time_display Formatted time.
 * @var bool   $confirmed Whether cancellation was just confirmed.
 */

get_header();
?>

<div class="lm-client-page" style="max-width: 520px; margin: 275px auto 100px; padding: 0 20px;">
<?php if ( ! empty( $confirmed ) ) : ?>

	<div style="text-align: center; padding: 40px 0;">
		<span style="font-size: 48px; color: #b32d2e;">&#10007;</span>
		<h2><?php esc_html_e( 'Booking Cancelled', 'lets-meet' ); ?></h2>
		<p style="color: #555;"><?php esc_html_e( 'Your booking has been successfully cancelled.', 'lets-meet' ); ?></p>
	</div>

<?php else : ?>

	<h2><?php esc_html_e( 'Cancel Your Booking', 'lets-meet' ); ?></h2>

	<div style="background: #f0f6fc; border-radius: 6px; padding: 20px 24px; margin-bottom: 24px;">
		<table style="width: 100%; border-collapse: collapse;">
			<tr>
				<td style="padding: 6px 0; color: #646970; width: 90px;"><?php esc_html_e( 'Service', 'lets-meet' ); ?></td>
				<td style="padding: 6px 0; font-weight: 600;"><?php echo esc_html( $service_name ); ?></td>
			</tr>
			<tr>
				<td style="padding: 6px 0; color: #646970;"><?php esc_html_e( 'Date', 'lets-meet' ); ?></td>
				<td style="padding: 6px 0; font-weight: 600;"><?php echo esc_html( $date_display ); ?></td>
			</tr>
			<tr>
				<td style="padding: 6px 0; color: #646970;"><?php esc_html_e( 'Time', 'lets-meet' ); ?></td>
				<td style="padding: 6px 0; font-weight: 600;"><?php echo esc_html( $time_display ); ?></td>
			</tr>
		</table>
	</div>

	<p style="color: #555; margin-bottom: 20px;"><?php esc_html_e( 'Are you sure you want to cancel this booking? This cannot be undone.', 'lets-meet' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="lm_client_cancel">
		<input type="hidden" name="lm_token" value="<?php echo esc_attr( $token ); ?>">
		<?php wp_nonce_field( 'lm_client_cancel_' . $token, '_wpnonce' ); ?>
		<button type="submit"
				style="background-color: #b32d2e; color: #fff; border: none; padding: 12px 32px; border-radius: 4px; font-size: 15px; font-weight: 600; cursor: pointer;">
			<?php esc_html_e( 'Yes, Cancel My Booking', 'lets-meet' ); ?>
		</button>
	</form>

<?php endif; ?>
</div>

<?php
get_footer();
