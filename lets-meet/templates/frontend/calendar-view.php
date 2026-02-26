<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend booking widget template.
 *
 * Variables available from render_shortcode():
 * @var array  $active_services  Active service objects.
 * @var int    $preselected_id   Pre-selected service ID (0 if none).
 * @var int    $horizon          Booking horizon in days.
 * @var array  $available_days   JS day numbers (0=Sun) that have availability windows.
 */
?>

<!-- Step 1: Service selection -->
<div class="lm-step lm-step-service <?php echo ( 1 === count( $active_services ) || $preselected_id ) ? 'lm-step--hidden' : ''; ?>"
	 data-step="1">
	<h3 class="lm-step-title"><?php esc_html_e( 'Choose a Service', 'lets-meet' ); ?></h3>
	<div class="lm-services-list">
		<?php foreach ( $active_services as $svc ) : ?>
			<label class="lm-service-option">
				<input type="radio" name="lm_service"
					   value="<?php echo esc_attr( $svc->id ); ?>"
					   data-duration="<?php echo esc_attr( $svc->duration ); ?>"
					   <?php checked( $preselected_id ? $preselected_id === (int) $svc->id : 1 === count( $active_services ) ); ?>>
				<span class="lm-service-info">
					<span class="lm-service-name"><?php echo esc_html( $svc->name ); ?></span>
					<span class="lm-service-duration">
						<?php
						printf(
							/* translators: %d: duration in minutes */
							esc_html__( '%d minutes', 'lets-meet' ),
							absint( $svc->duration )
						);
						?>
					</span>
					<?php if ( ! empty( $svc->description ) ) : ?>
						<span class="lm-service-desc"><?php echo esc_html( $svc->description ); ?></span>
					<?php endif; ?>
				</span>
			</label>
		<?php endforeach; ?>
	</div>
</div>

<!-- Step 2: Date & time selection -->
<div class="lm-step lm-step-datetime" data-step="2">
	<h3 class="lm-step-title"><?php esc_html_e( 'Pick a Date & Time', 'lets-meet' ); ?></h3>

	<!-- Calendar navigation -->
	<div class="lm-calendar-wrap">
		<div class="lm-calendar-nav">
			<button type="button" class="lm-cal-prev" aria-label="<?php esc_attr_e( 'Previous month', 'lets-meet' ); ?>">&laquo;</button>
			<span class="lm-cal-month-label"></span>
			<button type="button" class="lm-cal-next" aria-label="<?php esc_attr_e( 'Next month', 'lets-meet' ); ?>">&raquo;</button>
		</div>

		<table class="lm-calendar" role="grid">
			<thead>
				<tr>
					<th scope="col" abbr="<?php esc_attr_e( 'Sunday', 'lets-meet' ); ?>"><?php esc_html_e( 'Sun', 'lets-meet' ); ?></th>
					<th scope="col" abbr="<?php esc_attr_e( 'Monday', 'lets-meet' ); ?>"><?php esc_html_e( 'Mon', 'lets-meet' ); ?></th>
					<th scope="col" abbr="<?php esc_attr_e( 'Tuesday', 'lets-meet' ); ?>"><?php esc_html_e( 'Tue', 'lets-meet' ); ?></th>
					<th scope="col" abbr="<?php esc_attr_e( 'Wednesday', 'lets-meet' ); ?>"><?php esc_html_e( 'Wed', 'lets-meet' ); ?></th>
					<th scope="col" abbr="<?php esc_attr_e( 'Thursday', 'lets-meet' ); ?>"><?php esc_html_e( 'Thu', 'lets-meet' ); ?></th>
					<th scope="col" abbr="<?php esc_attr_e( 'Friday', 'lets-meet' ); ?>"><?php esc_html_e( 'Fri', 'lets-meet' ); ?></th>
					<th scope="col" abbr="<?php esc_attr_e( 'Saturday', 'lets-meet' ); ?>"><?php esc_html_e( 'Sat', 'lets-meet' ); ?></th>
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
</div>

<!-- Step 3: Booking form -->
<div class="lm-step lm-step-form lm-hidden" data-step="3">
	<h3 class="lm-step-title"><?php esc_html_e( 'Your Details', 'lets-meet' ); ?></h3>

	<div class="lm-selected-summary"></div>

	<form class="lm-booking-form" novalidate>
		<input type="hidden" name="lm_service_id" value="">
		<input type="hidden" name="lm_date" value="">
		<input type="hidden" name="lm_time" value="">
		<input type="hidden" name="lm_rendered_at" value="">

		<!-- Honeypot — hidden from humans, bots will fill it -->
		<div class="lm-hp" aria-hidden="true" tabindex="-1">
			<label for="lm-website"><?php esc_html_e( 'Website', 'lets-meet' ); ?></label>
			<input type="text" id="lm-website" name="lm_website" autocomplete="off" tabindex="-1">
		</div>

		<div class="lm-field">
			<label for="lm-name"><?php esc_html_e( 'Name', 'lets-meet' ); ?> <span class="lm-required">*</span></label>
			<input type="text" id="lm-name" name="lm_name" required>
		</div>

		<div class="lm-field">
			<label for="lm-email"><?php esc_html_e( 'Email', 'lets-meet' ); ?> <span class="lm-required">*</span></label>
			<input type="email" id="lm-email" name="lm_email" required>
		</div>

		<div class="lm-field">
			<label for="lm-phone"><?php esc_html_e( 'Phone', 'lets-meet' ); ?></label>
			<input type="tel" id="lm-phone" name="lm_phone">
		</div>

		<div class="lm-field">
			<label for="lm-notes"><?php esc_html_e( 'Notes', 'lets-meet' ); ?></label>
			<textarea id="lm-notes" name="lm_notes" rows="3"></textarea>
		</div>

		<div class="lm-form-actions">
			<button type="button" class="lm-btn lm-btn--back"><?php esc_html_e( 'Back', 'lets-meet' ); ?></button>
			<button type="submit" class="lm-btn lm-btn--submit"><?php esc_html_e( 'Book Now', 'lets-meet' ); ?></button>
		</div>

		<div class="lm-form-error lm-hidden"></div>
	</form>
</div>

<!-- Success message -->
<div class="lm-step lm-step-success lm-hidden" data-step="4">
	<div class="lm-success-message">
		<span class="lm-success-icon" aria-hidden="true">&#10003;</span>
		<h3 class="lm-step-title"><?php esc_html_e( 'Booking Confirmed!', 'lets-meet' ); ?></h3>
		<div class="lm-success-details"></div>
		<p class="lm-success-email"><?php esc_html_e( 'A confirmation email has been sent to your email address.', 'lets-meet' ); ?></p>
	</div>
</div>

<!-- Pass config to JS via data attributes -->
<script type="application/json" id="lm-config"><?php
	echo wp_json_encode( [
		'availableDays'  => array_values( array_unique( $available_days ) ),
		'horizon'        => $horizon,
		'preselectedId'  => $preselected_id,
		'singleService'  => 1 === count( $active_services ),
	] );
?></script>
