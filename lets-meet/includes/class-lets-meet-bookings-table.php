<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Bookings list table for the admin dashboard.
 *
 * Extends WP_List_Table to display bookings with sorting,
 * status filtering, row actions, and bulk cancel.
 */
class Lets_Meet_Bookings_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'booking',
			'plural'   => 'bookings',
			'ajax'     => false,
		] );
	}

	/* ── Column definitions ──────────────────────────────────────── */

	public function get_columns() {
		return [
			'cb'          => '<input type="checkbox" />',
			'date_time'   => __( 'Date & Time', 'lets-meet' ),
			'client_name' => __( 'Client', 'lets-meet' ),
			'client_email' => __( 'Email', 'lets-meet' ),
			'service'     => __( 'Service', 'lets-meet' ),
			'status'      => __( 'Status', 'lets-meet' ),
		];
	}

	public function get_sortable_columns() {
		return [
			'date_time' => [ 'start_utc', true ], // Default sort, ascending (upcoming first).
		];
	}

	protected function get_default_primary_column_name() {
		return 'date_time';
	}

	/* ── Column rendering ────────────────────────────────────────── */

	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="booking_ids[]" value="%d" />',
			absint( $item->id )
		);
	}

	public function column_date_time( $item ) {
		try {
			$tz = new DateTimeZone( $item->site_timezone ?: wp_timezone_string() );
		} catch ( Exception $e ) {
			$tz = wp_timezone();
		}
		$start = new DateTimeImmutable( $item->start_utc, new DateTimeZone( 'UTC' ) );
		$local = $start->setTimezone( $tz );

		$display = wp_date( 'M j, Y — g:i A', $local->getTimestamp() );

		// Row actions.
		$actions = [];

		$detail_url = add_query_arg( [
			'page'       => 'lets-meet',
			'action'     => 'view',
			'booking_id' => absint( $item->id ),
		], admin_url( 'admin.php' ) );

		$actions['view'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $detail_url ),
			esc_html__( 'View', 'lets-meet' )
		);

		if ( 'cancelled' !== $item->status ) {
			$cancel_url = wp_nonce_url(
				add_query_arg( [
					'page'       => 'lets-meet',
					'action'     => 'cancel',
					'booking_id' => absint( $item->id ),
				], admin_url( 'admin.php' ) ),
				'lm_cancel_booking_' . $item->id
			);

			$actions['cancel'] = sprintf(
				'<a href="%s" class="lm-cancel-link" style="color: #b32d2e;">%s</a>',
				esc_url( $cancel_url ),
				esc_html__( 'Cancel', 'lets-meet' )
			);
		}

		return esc_html( $display ) . $this->row_actions( $actions );
	}

	public function column_client_name( $item ) {
		return esc_html( $item->client_name );
	}

	public function column_client_email( $item ) {
		return sprintf(
			'<a href="mailto:%s">%s</a>',
			esc_attr( $item->client_email ),
			esc_html( $item->client_email )
		);
	}

	public function column_service( $item ) {
		return esc_html( $item->service_name ?? __( '(deleted)', 'lets-meet' ) );
	}

	public function column_status( $item ) {
		$labels = [
			'confirmed' => __( 'Confirmed', 'lets-meet' ),
			'cancelled' => __( 'Cancelled', 'lets-meet' ),
		];

		$label = $labels[ $item->status ] ?? $item->status;
		$class = 'confirmed' === $item->status ? 'lm-status-confirmed' : 'lm-status-cancelled';

		return sprintf(
			'<span class="lm-status %s">%s</span>',
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	/* ── Bulk actions ────────────────────────────────────────────── */

	public function get_bulk_actions() {
		return [
			'bulk_cancel' => __( 'Cancel', 'lets-meet' ),
		];
	}

	/* ── Status filter views ─────────────────────────────────────── */

	protected function get_views() {
		global $wpdb;

		$table   = $wpdb->prefix . 'lm_bookings';
		$current = sanitize_text_field( $_GET['status'] ?? 'all' );

		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$confirmed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'confirmed' ) );
		$cancelled = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'cancelled' ) );

		$base_url = admin_url( 'admin.php?page=lets-meet' );

		$views = [];

		$views['all'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			esc_url( $base_url ),
			'all' === $current ? 'current' : '',
			esc_html__( 'All', 'lets-meet' ),
			$total
		);

		$views['confirmed'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			esc_url( add_query_arg( 'status', 'confirmed', $base_url ) ),
			'confirmed' === $current ? 'current' : '',
			esc_html__( 'Confirmed', 'lets-meet' ),
			$confirmed
		);

		$views['cancelled'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			esc_url( add_query_arg( 'status', 'cancelled', $base_url ) ),
			'cancelled' === $current ? 'current' : '',
			esc_html__( 'Cancelled', 'lets-meet' ),
			$cancelled
		);

		return $views;
	}

	/* ── Data preparation ────────────────────────────────────────── */

	public function prepare_items() {
		global $wpdb;

		$table     = $wpdb->prefix . 'lm_bookings';
		$svc_table = $wpdb->prefix . 'lm_services';

		$per_page = 20;
		$paged    = $this->get_pagenum();
		$offset   = ( $paged - 1 ) * $per_page;

		// Status filter.
		$status = sanitize_text_field( $_GET['status'] ?? '' );
		$where  = '';
		if ( in_array( $status, [ 'confirmed', 'cancelled' ], true ) ) {
			$where = $wpdb->prepare( "WHERE b.status = %s", $status );
		}

		// Sorting — only allow start_utc.
		$orderby = 'b.start_utc';
		$order   = ( isset( $_GET['order'] ) && 'desc' === strtolower( $_GET['order'] ) ) ? 'DESC' : 'ASC';

		// Total count for pagination.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} b {$where}" );

		// Fetch items with service name join.
		$this->items = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.*, s.name AS service_name
			 FROM {$table} b
			 LEFT JOIN {$svc_table} s ON b.service_id = s.id
			 {$where}
			 ORDER BY {$orderby} {$order}
			 LIMIT %d OFFSET %d",
			$per_page,
			$offset
		) );

		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		] );

		$this->_column_headers = [
			$this->get_columns(),
			[],
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		];
	}

	/* ── Empty state ─────────────────────────────────────────────── */

	public function no_items() {
		esc_html_e( 'No bookings found.', 'lets-meet' );
	}
}
