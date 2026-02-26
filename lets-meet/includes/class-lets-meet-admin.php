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

	public function __construct( Lets_Meet_Services $services ) {
		$this->services = $services;
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

	/* ── Settings page (placeholder for Phase 3) ──────────────────── */

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Settings', 'lets-meet' ) . '</h1>';
		echo '<p>' . esc_html__( 'Settings coming soon.', 'lets-meet' ) . '</p></div>';
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
