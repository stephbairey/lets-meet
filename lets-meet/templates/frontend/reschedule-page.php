<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client-facing reschedule page.
 *
 * Variables:
 * @var object $booking        Booking row from DB.
 * @var string $token          Cancel token.
 * @var string $service_name   Service name.
 * @var int    $service_id     Service ID.
 * @var string $date_display   Formatted date.
 * @var string $time_display   Formatted time.
 * @var int    $horizon        Booking horizon in days.
 * @var array  $available_days JS day numbers that have availability windows.
 */

get_header();
?>

<div class="lm-client-page lm-reschedule-page" style="max-width: 600px; margin: 275px auto 100px; padding: 0 20px;">

	<h2><?php esc_html_e( 'Reschedule Your Booking', 'lets-meet' ); ?></h2>

	<div style="background: #f0f6fc; border-radius: 6px; padding: 20px 24px; margin-bottom: 24px;">
		<p style="margin: 0 0 8px; font-size: 13px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;"><?php esc_html_e( 'Current Booking', 'lets-meet' ); ?></p>
		<p style="margin: 0; font-weight: 600;"><?php echo esc_html( $service_name ); ?></p>
		<p style="margin: 4px 0 0; color: #333;"><?php echo esc_html( $date_display . ' — ' . $time_display ); ?></p>
	</div>

	<h3 style="margin-bottom: 16px;"><?php esc_html_e( 'Pick a New Date & Time', 'lets-meet' ); ?></h3>

	<!-- Reuse the booking widget calendar -->
	<div id="lm-reschedule-widget">
		<div class="lm-calendar-wrap">
			<div class="lm-calendar-nav">
				<button type="button" class="lm-cal-prev" aria-label="<?php esc_attr_e( 'Previous month', 'lets-meet' ); ?>">&laquo;</button>
				<span class="lm-cal-month-label"></span>
				<button type="button" class="lm-cal-next" aria-label="<?php esc_attr_e( 'Next month', 'lets-meet' ); ?>">&raquo;</button>
			</div>

			<table class="lm-calendar" role="grid">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Sun', 'lets-meet' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Mon', 'lets-meet' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tue', 'lets-meet' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Wed', 'lets-meet' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Thu', 'lets-meet' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Fri', 'lets-meet' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sat', 'lets-meet' ); ?></th>
					</tr>
				</thead>
				<tbody class="lm-cal-body"></tbody>
			</table>
		</div>

		<!-- Time slots -->
		<div class="lm-slots-wrap">
			<p class="lm-slots-prompt"><?php esc_html_e( 'Select a date to see available times.', 'lets-meet' ); ?></p>
			<div class="lm-slots-loading lm-hidden"><?php esc_html_e( 'Loading...', 'lets-meet' ); ?></div>
			<div class="lm-slots-list"></div>
			<p class="lm-slots-empty lm-hidden"><?php esc_html_e( 'No available times on this date.', 'lets-meet' ); ?></p>
		</div>

		<!-- Confirm reschedule -->
		<div class="lm-reschedule-confirm lm-hidden" style="margin-top: 24px;">
			<p class="lm-reschedule-summary" style="font-weight: 600; margin-bottom: 16px;"></p>
			<button type="button" class="lm-btn lm-btn--reschedule"
					style="background-color: #0073aa; color: #fff; border: none; padding: 12px 32px; border-radius: 4px; font-size: 15px; font-weight: 600; cursor: pointer;">
				<?php esc_html_e( 'Confirm Reschedule', 'lets-meet' ); ?>
			</button>
			<div class="lm-reschedule-error lm-hidden" style="color: #b32d2e; margin-top: 12px;"></div>
		</div>

		<!-- Success -->
		<div class="lm-reschedule-success lm-hidden" style="text-align: center; padding: 40px 0;">
			<span style="font-size: 48px; color: #00a32a;">&#10003;</span>
			<h2><?php esc_html_e( 'Booking Rescheduled!', 'lets-meet' ); ?></h2>
			<p class="lm-reschedule-success-details" style="color: #555;"></p>
			<p style="color: #888; font-size: 14px;"><?php esc_html_e( 'A confirmation email has been sent to your email address.', 'lets-meet' ); ?></p>
		</div>
	</div>
</div>

<script type="application/json" id="lm-reschedule-config"><?php
	echo wp_json_encode( [
		'bookingId'     => absint( $booking->id ),
		'serviceId'     => absint( $service_id ),
		'token'         => $token,
		'availableDays' => array_values( array_unique( $available_days ) ),
		'horizon'       => $horizon,
	] );
?></script>

<?php
get_footer();
