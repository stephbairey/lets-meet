<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin pages, menu registration, and form handlers.
 */
class Lets_Meet_Admin {

	/** @var Lets_Meet_Services */
	private $services;

	/** @var Lets_Meet_Gcal */
	private $gcal;

	public function __construct( Lets_Meet_Services $services, Lets_Meet_Gcal $gcal ) {
		$this->services = $services;
		$this->gcal     = $gcal;
	}

	/**
	 * Register the admin menu.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Let\'s Meet', 'lets-meet' ),
			__( 'Let\'s Meet', 'lets-meet' ),
			'manage_options',
			'lets-meet',
			[ $this, 'render_bookings_page' ],
			'dashicons-calendar-alt',
			30
		);

		add_submenu_page(
			'lets-meet',
			__( 'Bookings', 'lets-meet' ),
			__( 'Bookings', 'lets-meet' ),
			'manage_options',
			'lets-meet',
			[ $this, 'render_bookings_page' ]
		);

		add_submenu_page(
			'lets-meet',
			__( 'Services', 'lets-meet' ),
			__( 'Services', 'lets-meet' ),
			'manage_options',
			'lets-meet-services',
			[ $this, 'render_services_page' ]
		);

		add_submenu_page(
			'lets-meet',
			__( 'Settings', 'lets-meet' ),
			__( 'Settings', 'lets-meet' ),
			'manage_options',
			'lets-meet-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Enqueue admin assets only on Let's Meet pages.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'lets-meet' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'lm-admin',
			LM_URL . 'assets/css/admin.css',
			[],
			LM_VERSION
		);

		wp_enqueue_script(
			'lm-admin',
			LM_URL . 'assets/js/admin.js',
			[],
			LM_VERSION,
			true
		);
	}

	/**
	 * Handle admin actions that require a redirect before headers are sent.
	 *
	 * Hooked to admin_init so wp_safe_redirect() works properly.
	 */
	public function handle_early_actions() {
		if ( ! isset( $_GET['page'] ) || 'lets-meet-services' !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['action'] ) || 'toggle' !== $_GET['action'] ) {
			return;
		}

		$this->handle_toggle_service();
	}

	/* ── Bookings page (placeholder for Phase 9) ──────────────────── */

	public function render_bookings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Bookings', 'lets-meet' ) . '</h1>';
		echo '<p>' . esc_html__( 'Bookings dashboard coming soon.', 'lets-meet' ) . '</p></div>';
	}

	/* ── Settings page ─────────────────────────────────────────────── */

	/**
	 * Render the settings page with tabs.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = [
			'availability' => __( 'Availability', 'lets-meet' ),
			'gcal'         => __( 'Google Calendar', 'lets-meet' ),
			'email'        => __( 'Email', 'lets-meet' ),
			'general'      => __( 'General', 'lets-meet' ),
		];

		$current_tab = sanitize_text_field( $_GET['tab'] ?? 'availability' );
		if ( ! array_key_exists( $current_tab, $tabs ) ) {
			$current_tab = 'availability';
		}

		// Admin notices.
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'lets-meet' ) . '</p></div>';
		}

		$errors = get_transient( 'lm_admin_error_' . get_current_user_id() );
		if ( $errors ) {
			delete_transient( 'lm_admin_error_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible">';
			foreach ( (array) $errors as $err ) {
				echo '<p>' . esc_html( $err ) . '</p>';
			}
			echo '</div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Let\'s Meet Settings', 'lets-meet' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=lets-meet-settings&tab=' . $slug ) ); ?>"
						class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="lm-settings-content">
				<?php
				switch ( $current_tab ) {
					case 'availability':
						$this->render_tab_availability();
						break;
					case 'gcal':
						$this->render_tab_gcal();
						break;
					case 'email':
						$this->render_tab_email();
						break;
					case 'general':
						$this->render_tab_general();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle saving Google Calendar credentials.
	 */
	public function handle_save_gcal_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'lets-meet' ) );
		}

		check_admin_referer( 'lm_save_gcal_settings', 'lm_nonce' );

		$client_id     = sanitize_text_field( $_POST['lm_gcal_client_id'] ?? '' );
		$client_secret = sanitize_text_field( $_POST['lm_gcal_client_secret'] ?? '' );
		$calendar_id   = sanitize_text_field( $_POST['lm_gcal_calendar_id'] ?? 'primary' );

		// If secret is empty and we already have one saved, keep it.
		if ( '' === $client_secret && $this->gcal->is_connected() ) {
			// Only update client_id and calendar_id.
			if ( false === get_option( 'lm_gcal_client_id' ) ) {
				add_option( 'lm_gcal_client_id', $client_id, '', 'no' );
			} else {
				update_option( 'lm_gcal_client_id', $client_id, 'no' );
			}
			if ( '' === $calendar_id ) {
				$calendar_id = 'primary';
			}
			if ( false === get_option( 'lm_gcal_calendar_id' ) ) {
				add_option( 'lm_gcal_calendar_id', $calendar_id, '', 'no' );
			} else {
				update_option( 'lm_gcal_calendar_id', $calendar_id, 'no' );
			}
		} else {
			$this->gcal->save_credentials( $client_id, $client_secret, $calendar_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=lets-meet-settings&tab=gcal&updated=1' ) );
		exit;
	}

	/**
	 * Handle disconnecting Google Calendar.
	 */
	public function handle_gcal_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'lets-meet' ) );
		}

		check_admin_referer( 'lm_gcal_disconnect' );

		$this->gcal->disconnect();

		wp_safe_redirect( admin_url( 'admin.php?page=lets-meet-settings&tab=gcal&gcal_disconnected=1' ) );
		exit;
	}

	/**
	 * Handle saving settings from any tab.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'lets-meet' ) );
		}

		check_admin_referer( 'lm_save_settings', 'lm_nonce' );

		$tab        = sanitize_text_field( $_POST['lm_tab'] ?? 'availability' );
		$valid_tabs = [ 'availability', 'email', 'general' ];

		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			wp_die( esc_html__( 'Invalid tab.', 'lets-meet' ) );
		}

		$has_errors = false;

		switch ( $tab ) {
			case 'availability':
				$has_errors = $this->save_tab_availability();
				break;
			case 'email':
				$this->save_tab_email();
				break;
			case 'general':
				$this->save_tab_general();
				break;
		}

		$redirect = admin_url( 'admin.php?page=lets-meet-settings&tab=' . $tab );
		if ( ! $has_errors ) {
			$redirect = add_query_arg( 'updated', '1', $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/* ── Settings tab renderers ────────────────────────────────────── */

	/**
	 * Render the Availability tab.
	 */
	private function render_tab_availability() {
		$availability = get_option( 'lm_availability', [] );
		$settings     = get_option( 'lm_settings', [] );
		$days         = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
		$day_labels   = [
			'monday'    => __( 'Monday', 'lets-meet' ),
			'tuesday'   => __( 'Tuesday', 'lets-meet' ),
			'wednesday' => __( 'Wednesday', 'lets-meet' ),
			'thursday'  => __( 'Thursday', 'lets-meet' ),
			'friday'    => __( 'Friday', 'lets-meet' ),
			'saturday'  => __( 'Saturday', 'lets-meet' ),
			'sunday'    => __( 'Sunday', 'lets-meet' ),
		];
		$time_options = $this->get_time_options();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lm_save_settings">
			<input type="hidden" name="lm_tab" value="availability">
			<?php wp_nonce_field( 'lm_save_settings', 'lm_nonce' ); ?>

			<h2><?php esc_html_e( 'Weekly Schedule', 'lets-meet' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Set up to 3 availability windows per day. Leave a row blank to skip it.', 'lets-meet' ); ?>
			</p>

			<div class="lm-availability-grid">
				<?php foreach ( $days as $index => $day ) :
					$windows = $availability[ $day ] ?? [];
				?>
					<div class="lm-day" data-day="<?php echo esc_attr( $day ); ?>">
						<h3>
							<?php echo esc_html( $day_labels[ $day ] ); ?>
							<?php if ( $index < 6 ) : ?>
								<button type="button" class="button button-small lm-copy-right"
									data-from="<?php echo esc_attr( $day ); ?>"
									data-to="<?php echo esc_attr( $days[ $index + 1 ] ); ?>"
									title="<?php esc_attr_e( 'Copy to next day', 'lets-meet' ); ?>">
									<?php echo esc_html__( 'Copy', 'lets-meet' ) . ' &rarr;'; ?>
								</button>
							<?php endif; ?>
						</h3>
						<?php for ( $slot = 0; $slot < 3; $slot++ ) :
							$start = $windows[ $slot ]['start'] ?? '';
							$end   = $windows[ $slot ]['end'] ?? '';
						?>
							<div class="lm-slot-row">
								<select name="lm_avail[<?php echo esc_attr( $day ); ?>][<?php echo absint( $slot ); ?>][start]">
									<option value="">&mdash;</option>
									<?php foreach ( $time_options as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>"
											<?php selected( $val, $start ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<span class="lm-to"><?php esc_html_e( 'to', 'lets-meet' ); ?></span>
								<select name="lm_avail[<?php echo esc_attr( $day ); ?>][<?php echo absint( $slot ); ?>][end]">
									<option value="">&mdash;</option>
									<?php foreach ( $time_options as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>"
											<?php selected( $val, $end ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						<?php endfor; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Booking Rules', 'lets-meet' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="lm-buffer"><?php esc_html_e( 'Buffer Time', 'lets-meet' ); ?></label>
					</th>
					<td>
						<select id="lm-buffer" name="lm_buffer">
							<?php foreach ( [ 15, 30, 45, 60 ] as $mins ) : ?>
								<option value="<?php echo absint( $mins ); ?>"
									<?php selected( $mins, absint( $settings['buffer'] ?? 30 ) ); ?>>
									<?php
									printf(
										/* translators: %d: number of minutes */
										esc_html__( '%d minutes', 'lets-meet' ),
										$mins
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Buffer before and after every booking and calendar event.', 'lets-meet' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lm-notice"><?php esc_html_e( 'Minimum Notice', 'lets-meet' ); ?></label>
					</th>
					<td>
						<select id="lm-notice" name="lm_min_notice">
							<?php foreach ( [ 1, 2, 4, 8, 24 ] as $hours ) : ?>
								<option value="<?php echo absint( $hours ); ?>"
									<?php selected( $hours, absint( $settings['min_notice'] ?? 2 ) ); ?>>
									<?php
									printf(
										/* translators: %d: number of hours */
										esc_html( _n( '%d hour', '%d hours', $hours, 'lets-meet' ) ),
										$hours
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'How far in advance a visitor must book.', 'lets-meet' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lm-horizon"><?php esc_html_e( 'Booking Horizon', 'lets-meet' ); ?></label>
					</th>
					<td>
						<select id="lm-horizon" name="lm_horizon">
							<?php foreach ( [ 14, 30, 60, 90 ] as $d ) : ?>
								<option value="<?php echo absint( $d ); ?>"
									<?php selected( $d, absint( $settings['horizon'] ?? 60 ) ); ?>>
									<?php
									printf(
										/* translators: %d: number of days */
										esc_html__( '%d days', 'lets-meet' ),
										$d
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'How far into the future visitors can book.', 'lets-meet' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render the Google Calendar tab (placeholder for Phase 5).
	 */
	private function render_tab_gcal() {
		$is_connected = $this->gcal->is_connected();
		$client_id    = get_option( 'lm_gcal_client_id', '' );
		$calendar_id  = get_option( 'lm_gcal_calendar_id', 'primary' );

		// Notices.
		if ( isset( $_GET['gcal_connected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Google Calendar connected successfully.', 'lets-meet' ) . '</p></div>';
		}
		if ( isset( $_GET['gcal_disconnected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Google Calendar disconnected.', 'lets-meet' ) . '</p></div>';
		}
		if ( isset( $_GET['gcal_error'] ) ) {
			$err = sanitize_text_field( $_GET['gcal_error'] );
			if ( 'auth_denied' === $err ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Google Calendar authorization was denied. Please try again.', 'lets-meet' ) . '</p></div>';
			} elseif ( 'token_exchange' === $err ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to exchange authorization code. Please check your credentials and try again.', 'lets-meet' ) . '</p></div>';
			}
		}
		?>
		<h2><?php esc_html_e( 'Google Calendar', 'lets-meet' ); ?></h2>

		<?php if ( $is_connected ) : ?>
			<div class="lm-gcal-status lm-gcal-status--connected">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Connected', 'lets-meet' ); ?>
			</div>
		<?php else : ?>
			<div class="lm-gcal-status lm-gcal-status--disconnected">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'Not connected', 'lets-meet' ); ?>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lm_save_gcal_settings">
			<?php wp_nonce_field( 'lm_save_gcal_settings', 'lm_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="lm-gcal-client-id"><?php esc_html_e( 'Client ID', 'lets-meet' ); ?></label>
					</th>
					<td>
						<input type="text" id="lm-gcal-client-id" name="lm_gcal_client_id"
							value="<?php echo esc_attr( $client_id ); ?>"
							class="large-text">
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lm-gcal-client-secret"><?php esc_html_e( 'Client Secret', 'lets-meet' ); ?></label>
					</th>
					<td>
						<input type="password" id="lm-gcal-client-secret" name="lm_gcal_client_secret"
							value="" class="regular-text" autocomplete="new-password">
						<p class="description">
							<?php
							if ( $is_connected ) {
								esc_html_e( 'Leave blank to keep the current secret.', 'lets-meet' );
							} else {
								esc_html_e( 'Enter your Google OAuth Client Secret.', 'lets-meet' );
							}
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lm-gcal-calendar-id"><?php esc_html_e( 'Calendar ID', 'lets-meet' ); ?></label>
					</th>
					<td>
						<input type="text" id="lm-gcal-calendar-id" name="lm_gcal_calendar_id"
							value="<?php echo esc_attr( $calendar_id ); ?>"
							class="regular-text">
						<p class="description">
							<?php esc_html_e( 'Default: primary. Use your calendar\'s email address for a specific calendar.', 'lets-meet' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Credentials', 'lets-meet' ) ); ?>
		</form>

		<hr>

		<?php if ( $is_connected ) : ?>
			<h3><?php esc_html_e( 'Connection', 'lets-meet' ); ?></h3>
			<p><?php esc_html_e( 'Your Google Calendar is connected. Availability will include your Google Calendar events.', 'lets-meet' ); ?></p>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lm_gcal_disconnect' ), 'lm_gcal_disconnect' ) ); ?>"
				class="button" onclick="return confirm('<?php echo esc_js( __( 'Disconnect Google Calendar?', 'lets-meet' ) ); ?>');">
				<?php esc_html_e( 'Disconnect', 'lets-meet' ); ?>
			</a>
		<?php elseif ( '' !== $client_id ) : ?>
			<h3><?php esc_html_e( 'Connection', 'lets-meet' ); ?></h3>
			<p><?php esc_html_e( 'Credentials saved. Click below to authorize access to your Google Calendar.', 'lets-meet' ); ?></p>
			<a href="<?php echo esc_url( $this->gcal->get_auth_url() ); ?>" class="button button-primary">
				<?php esc_html_e( 'Connect Google Calendar', 'lets-meet' ); ?>
			</a>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Enter your Google OAuth credentials above and save, then you can connect.', 'lets-meet' ); ?>
			</p>
		<?php endif;
	}

	/**
	 * Render the Email tab.
	 */
	private function render_tab_email() {
		$settings = get_option( 'lm_settings', [] );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lm_save_settings">
			<input type="hidden" name="lm_tab" value="email">
			<?php wp_nonce_field( 'lm_save_settings', 'lm_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="lm-reply-to"><?php esc_html_e( 'Reply-To Email', 'lets-meet' ); ?></label>
					</th>
					<td>
						<input type="email" id="lm-reply-to" name="lm_admin_email"
							value="<?php echo esc_attr( $settings['admin_email'] ?? get_option( 'admin_email' ) ); ?>"
							class="regular-text">
						<p class="description">
							<?php esc_html_e( 'Reply-to address on client confirmation emails.', 'lets-meet' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lm-confirm-msg"><?php esc_html_e( 'Confirmation Message', 'lets-meet' ); ?></label>
					</th>
					<td>
						<textarea id="lm-confirm-msg" name="lm_confirm_msg" rows="4"
							class="large-text"><?php echo esc_textarea( $settings['confirm_msg'] ?? '' ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Custom message included in the client confirmation email.', 'lets-meet' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Admin Notifications', 'lets-meet' ); ?></th>
					<td>
						<label for="lm-admin-notify">
							<input type="checkbox" id="lm-admin-notify" name="lm_admin_notify" value="1"
								<?php checked( ! empty( $settings['admin_notify'] ) ); ?>>
							<?php esc_html_e( 'Send me an email when a new booking is made.', 'lets-meet' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render the General tab.
	 */
	private function render_tab_general() {
		$settings = get_option( 'lm_settings', [] );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lm_save_settings">
			<input type="hidden" name="lm_tab" value="general">
			<?php wp_nonce_field( 'lm_save_settings', 'lm_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall Behavior', 'lets-meet' ); ?></th>
					<td>
						<label for="lm-keep-data">
							<input type="checkbox" id="lm-keep-data" name="lm_keep_data" value="1"
								<?php checked( ! empty( $settings['keep_data'] ) ); ?>>
							<?php esc_html_e( 'Keep all data (services, bookings, settings) when the plugin is uninstalled.', 'lets-meet' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Uncheck this to remove all plugin data on uninstall. Default: keep data.', 'lets-meet' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ── Settings save handlers ────────────────────────────────────── */

	/**
	 * Save the Availability tab.
	 *
	 * @return bool True if validation errors occurred, false on success.
	 */
	private function save_tab_availability() {
		$days      = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
		$raw_avail = $_POST['lm_avail'] ?? [];
		$allowed   = $this->get_time_options();
		$schedule  = [];
		$errors    = [];

		foreach ( $days as $day ) {
			$schedule[ $day ] = [];
			$day_slots        = $raw_avail[ $day ] ?? [];

			for ( $i = 0; $i < 3; $i++ ) {
				$start = sanitize_text_field( $day_slots[ $i ]['start'] ?? '' );
				$end   = sanitize_text_field( $day_slots[ $i ]['end'] ?? '' );

				// Skip empty rows.
				if ( '' === $start && '' === $end ) {
					continue;
				}

				// Both must be set.
				if ( '' === $start || '' === $end ) {
					$errors[] = sprintf(
						/* translators: %s: day of week */
						__( '%s: Both start and end times are required for each window.', 'lets-meet' ),
						ucfirst( $day )
					);
					continue;
				}

				// Must be valid time values.
				if ( ! isset( $allowed[ $start ] ) || ! isset( $allowed[ $end ] ) ) {
					$errors[] = sprintf(
						/* translators: %s: day of week */
						__( '%s: Invalid time value.', 'lets-meet' ),
						ucfirst( $day )
					);
					continue;
				}

				// End must be after start.
				if ( $end <= $start ) {
					$errors[] = sprintf(
						/* translators: %s: day of week */
						__( '%s: End time must be after start time.', 'lets-meet' ),
						ucfirst( $day )
					);
					continue;
				}

				$schedule[ $day ][] = [ 'start' => $start, 'end' => $end ];
			}

			// Check for overlapping windows within the day.
			$windows = $schedule[ $day ];
			for ( $a = 0; $a < count( $windows ); $a++ ) {
				for ( $b = $a + 1; $b < count( $windows ); $b++ ) {
					if ( $windows[ $a ]['start'] < $windows[ $b ]['end'] && $windows[ $b ]['start'] < $windows[ $a ]['end'] ) {
						$errors[] = sprintf(
							/* translators: %s: day of week */
							__( '%s: Availability windows overlap.', 'lets-meet' ),
							ucfirst( $day )
						);
						break 2; // One overlap message per day is enough.
					}
				}
			}
		}

		if ( ! empty( $errors ) ) {
			set_transient( 'lm_admin_error_' . get_current_user_id(), $errors, 30 );
			// Do not save availability or booking rules when there are errors.
			return true;
		}

		update_option( 'lm_availability', $schedule );

		// Save booking rules.
		$settings = get_option( 'lm_settings', [] );

		$buffer = absint( $_POST['lm_buffer'] ?? 30 );
		if ( ! in_array( $buffer, [ 15, 30, 45, 60 ], true ) ) {
			$buffer = 30;
		}

		$min_notice = absint( $_POST['lm_min_notice'] ?? 2 );
		if ( ! in_array( $min_notice, [ 1, 2, 4, 8, 24 ], true ) ) {
			$min_notice = 2;
		}

		$horizon = absint( $_POST['lm_horizon'] ?? 60 );
		if ( ! in_array( $horizon, [ 14, 30, 60, 90 ], true ) ) {
			$horizon = 60;
		}

		$settings['buffer']     = $buffer;
		$settings['min_notice'] = $min_notice;
		$settings['horizon']    = $horizon;

		update_option( 'lm_settings', $settings );

		return false;
	}

	/**
	 * Save the Email tab.
	 */
	private function save_tab_email() {
		$settings = get_option( 'lm_settings', [] );

		$admin_email = sanitize_email( $_POST['lm_admin_email'] ?? '' );
		if ( ! is_email( $admin_email ) ) {
			$admin_email = get_option( 'admin_email' );
		}

		$settings['admin_email']  = $admin_email;
		$settings['confirm_msg']  = sanitize_textarea_field( $_POST['lm_confirm_msg'] ?? '' );
		$settings['admin_notify'] = ! empty( $_POST['lm_admin_notify'] );

		update_option( 'lm_settings', $settings );
	}

	/**
	 * Save the General tab.
	 */
	private function save_tab_general() {
		$settings = get_option( 'lm_settings', [] );

		$settings['keep_data'] = ! empty( $_POST['lm_keep_data'] );

		update_option( 'lm_settings', $settings );
	}

	/* ── Settings helpers ──────────────────────────────────────────── */

	/**
	 * Generate time options in 30-min increments (00:00–23:30).
	 *
	 * @return array Keyed by 'HH:MM' value, display label as value.
	 */
	private function get_time_options() {
		$options = [];
		$format  = get_option( 'time_format', 'g:i A' );
		$tz      = wp_timezone();
		for ( $h = 0; $h < 24; $h++ ) {
			for ( $m = 0; $m < 60; $m += 30 ) {
				$val = sprintf( '%02d:%02d', $h, $m );
				$dt  = new DateTimeImmutable( "2026-01-01 {$val}", $tz );
				$options[ $val ] = wp_date( $format, $dt->getTimestamp() );
			}
		}
		return $options;
	}

	/* ── Services page ─────────────────────────────────────────────── */

	/**
	 * Handle service form submissions, then render the services page.
	 */
	public function render_services_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action  = sanitize_text_field( $_GET['action'] ?? '' );
		$edit_id = absint( $_GET['edit'] ?? 0 );

		echo '<div class="wrap">';

		if ( 'edit' === $action && $edit_id ) {
			$this->render_edit_form( $edit_id );
		} elseif ( 'new' === $action ) {
			$this->render_new_form();
		} else {
			$this->render_services_list();
		}

		echo '</div>';
	}

	/**
	 * Handle saving a service (add or edit).
	 */
	public function handle_save_service() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'lets-meet' ) );
		}

		check_admin_referer( 'lm_save_service', 'lm_nonce' );

		$validated = $this->services->validate( $_POST );

		if ( is_wp_error( $validated ) ) {
			// Store error in transient and redirect back.
			set_transient( 'lm_admin_error_' . get_current_user_id(), $validated->get_error_message(), 30 );
			wp_safe_redirect( wp_get_referer() );
			exit;
		}

		$service_id = absint( $_POST['service_id'] ?? 0 );

		if ( $service_id ) {
			$this->services->update( $service_id, $validated );
			$redirect = admin_url( 'admin.php?page=lets-meet-services&updated=1' );
		} else {
			$this->services->create( $validated );
			$redirect = admin_url( 'admin.php?page=lets-meet-services&added=1' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle toggling a service active/inactive.
	 */
	public function handle_toggle_service() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'lets-meet' ) );
		}

		$service_id = absint( $_GET['service_id'] ?? 0 );

		check_admin_referer( 'lm_toggle_service_' . $service_id );

		if ( $service_id ) {
			$this->services->toggle_active( $service_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=lets-meet-services&toggled=1' ) );
		exit;
	}

	/* ── Render helpers ────────────────────────────────────────────── */

	/**
	 * Render the services list table.
	 */
	private function render_services_list() {
		$services = $this->services->get_all();

		// Admin notices.
		if ( isset( $_GET['added'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Service added.', 'lets-meet' ) . '</p></div>';
		}
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Service updated.', 'lets-meet' ) . '</p></div>';
		}
		if ( isset( $_GET['toggled'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Service status changed.', 'lets-meet' ) . '</p></div>';
		}

		$error = get_transient( 'lm_admin_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'lm_admin_error_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}

		$new_url = admin_url( 'admin.php?page=lets-meet-services&action=new' );
		?>
		<h1>
			<?php esc_html_e( 'Services', 'lets-meet' ); ?>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'lets-meet' ); ?>
			</a>
		</h1>

		<?php if ( empty( $services ) ) : ?>
			<p><?php esc_html_e( 'No services yet. Add your first service to get started.', 'lets-meet' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'lets-meet' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Slug', 'lets-meet' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Duration', 'lets-meet' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'lets-meet' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'lets-meet' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $services as $service ) : ?>
						<tr class="<?php echo $service->is_active ? '' : 'lm-inactive'; ?>">
							<td>
								<strong><?php echo esc_html( $service->name ); ?></strong>
								<?php if ( $service->description ) : ?>
									<br><span class="description"><?php echo esc_html( $service->description ); ?></span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $service->slug ); ?></code></td>
							<td>
								<?php
								printf(
									/* translators: %d: number of minutes */
									esc_html__( '%d min', 'lets-meet' ),
									absint( $service->duration )
								);
								?>
							</td>
							<td>
								<?php if ( $service->is_active ) : ?>
									<span class="lm-status lm-status--active"><?php esc_html_e( 'Active', 'lets-meet' ); ?></span>
								<?php else : ?>
									<span class="lm-status lm-status--inactive"><?php esc_html_e( 'Inactive', 'lets-meet' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								$edit_url   = admin_url( 'admin.php?page=lets-meet-services&action=edit&edit=' . absint( $service->id ) );
								$toggle_url = wp_nonce_url(
									admin_url( 'admin.php?page=lets-meet-services&action=toggle&service_id=' . absint( $service->id ) ),
									'lm_toggle_service_' . absint( $service->id )
								);
								$toggle_label = $service->is_active
									? __( 'Deactivate', 'lets-meet' )
									: __( 'Activate', 'lets-meet' );
								?>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'lets-meet' ); ?></a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( $toggle_url ); ?>"><?php echo esc_html( $toggle_label ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
	}

	/**
	 * Render the "add new service" form.
	 */
	private function render_new_form() {
		?>
		<h1><?php esc_html_e( 'Add New Service', 'lets-meet' ); ?></h1>
		<?php $this->render_service_form( null ); ?>
		<?php
	}

	/**
	 * Render the "edit service" form.
	 */
	private function render_edit_form( $id ) {
		$service = $this->services->get( $id );
		if ( ! $service ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Service not found.', 'lets-meet' ) . '</p></div>';
			return;
		}
		?>
		<h1><?php esc_html_e( 'Edit Service', 'lets-meet' ); ?></h1>
		<?php $this->render_service_form( $service ); ?>
		<?php
	}

	/**
	 * Render the service form (shared between add/edit).
	 *
	 * @param object|null $service Existing service for editing, or null for new.
	 */
	private function render_service_form( $service ) {
		$error = get_transient( 'lm_admin_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'lm_admin_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		$name        = $service->name ?? '';
		$duration    = $service->duration ?? 60;
		$description = $service->description ?? '';
		$service_id  = $service->id ?? 0;
		$back_url    = admin_url( 'admin.php?page=lets-meet-services' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lm_save_service">
			<input type="hidden" name="service_id" value="<?php echo absint( $service_id ); ?>">
			<?php wp_nonce_field( 'lm_save_service', 'lm_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="lm-name"><?php esc_html_e( 'Name', 'lets-meet' ); ?></label>
					</th>
					<td>
						<input type="text" id="lm-name" name="name" value="<?php echo esc_attr( $name ); ?>"
							class="regular-text" required>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lm-duration"><?php esc_html_e( 'Duration (minutes)', 'lets-meet' ); ?></label>
					</th>
					<td>
						<select id="lm-duration" name="duration">
							<?php for ( $m = 15; $m <= 240; $m += 15 ) : ?>
								<option value="<?php echo absint( $m ); ?>"
									<?php selected( $m, absint( $duration ) ); ?>>
									<?php
									if ( $m < 60 ) {
										printf(
											/* translators: %d: number of minutes */
											esc_html__( '%d min', 'lets-meet' ),
											$m
										);
									} elseif ( $m % 60 === 0 ) {
										printf(
											/* translators: %d: number of hours */
											esc_html( _n( '%d hour', '%d hours', $m / 60, 'lets-meet' ) ),
											$m / 60
										);
									} else {
										printf(
											/* translators: 1: hours, 2: minutes */
											esc_html__( '%1$dh %2$dm', 'lets-meet' ),
											floor( $m / 60 ),
											$m % 60
										);
									}
									?>
								</option>
							<?php endfor; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lm-description"><?php esc_html_e( 'Description', 'lets-meet' ); ?></label>
					</th>
					<td>
						<textarea id="lm-description" name="description" rows="4"
							class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional. Shown to visitors on the booking page.', 'lets-meet' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( $service_id ? __( 'Update Service', 'lets-meet' ) : __( 'Add Service', 'lets-meet' ) ); ?>
			<a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( '&larr; Back to Services', 'lets-meet' ); ?></a>
		</form>
		<?php
	}
}
