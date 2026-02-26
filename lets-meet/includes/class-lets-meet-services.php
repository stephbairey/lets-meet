<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service CRUD operations.
 *
 * No delete — deactivate only, to preserve booking history integrity.
 */
class Lets_Meet_Services {

	/**
	 * Get all services, ordered by name.
	 *
	 * @param bool $active_only Whether to return only active services.
	 * @return array
	 */
	public function get_all( $active_only = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lm_services';

		if ( $active_only ) {
			return $wpdb->get_results(
				"SELECT * FROM {$table} WHERE is_active = 1 ORDER BY name ASC"
			);
		}

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );
	}

	/**
	 * Get a single service by ID.
	 *
	 * @param int $id Service ID.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lm_services';

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d",
			$id
		) );
	}

	/**
	 * Get a single service by slug.
	 *
	 * @param string $slug Service slug.
	 * @return object|null
	 */
	public function get_by_slug( $slug ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lm_services';

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE slug = %s",
			$slug
		) );
	}

	/**
	 * Create a new service.
	 *
	 * @param array $data {
	 *     @type string $name        Service name.
	 *     @type int    $duration    Duration in minutes (15–240).
	 *     @type string $description Optional description.
	 * }
	 * @return int|false Inserted ID on success, false on failure.
	 */
	public function create( $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lm_services';

		$slug = $this->generate_unique_slug( $data['name'] );

		$result = $wpdb->insert(
			$table,
			[
				'name'        => $data['name'],
				'slug'        => $slug,
				'duration'    => $data['duration'],
				'description' => $data['description'] ?? '',
				'is_active'   => 1,
			],
			[ '%s', '%s', '%d', '%s', '%d' ]
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update an existing service.
	 *
	 * @param int   $id   Service ID.
	 * @param array $data Fields to update (name, duration, description).
	 * @return bool
	 */
	public function update( $id, $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lm_services';

		$fields  = [];
		$formats = [];

		if ( isset( $data['name'] ) ) {
			$fields['name'] = $data['name'];
			$formats[]      = '%s';

			// Regenerate slug when name changes.
			$fields['slug'] = $this->generate_unique_slug( $data['name'], $id );
			$formats[]      = '%s';
		}

		if ( isset( $data['duration'] ) ) {
			$fields['duration'] = $data['duration'];
			$formats[]          = '%d';
		}

		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = $data['description'];
			$formats[]             = '%s';
		}

		if ( empty( $fields ) ) {
			return false;
		}

		$result = $wpdb->update(
			$table,
			$fields,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Toggle is_active for a service.
	 *
	 * @param int $id Service ID.
	 * @return bool
	 */
	public function toggle_active( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lm_services';

		$service = $this->get( $id );
		if ( ! $service ) {
			return false;
		}

		$new_status = $service->is_active ? 0 : 1;

		$result = $wpdb->update(
			$table,
			[ 'is_active' => $new_status ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Validate service data before save.
	 *
	 * @param array $data Raw input data.
	 * @return array|WP_Error Sanitized data or error.
	 */
	public function validate( $data ) {
		$errors = [];

		// Name: required, non-empty.
		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			$errors[] = __( 'Service name is required.', 'lets-meet' );
		}

		// Duration: required, 15–240 in 15-min increments.
		$duration = absint( $data['duration'] ?? 0 );
		if ( $duration < 15 || $duration > 240 || $duration % 15 !== 0 ) {
			$errors[] = __( 'Duration must be between 15 and 240 minutes in 15-minute increments.', 'lets-meet' );
		}

		// Description: optional, sanitized.
		$description = sanitize_textarea_field( $data['description'] ?? '' );

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'lm_validation', implode( ' ', $errors ) );
		}

		return [
			'name'        => $name,
			'duration'    => $duration,
			'description' => $description,
		];
	}

	/**
	 * Generate a unique slug from a service name.
	 *
	 * @param string   $name       Service name.
	 * @param int|null $exclude_id Service ID to exclude from uniqueness check (for updates).
	 * @return string
	 */
	private function generate_unique_slug( $name, $exclude_id = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lm_services';

		$slug = sanitize_title( $name );
		$base = $slug;
		$i    = 2;

		$max = 100;
		while ( $i <= $max ) {
			if ( $exclude_id ) {
				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table} WHERE slug = %s AND id != %d",
					$slug,
					$exclude_id
				) );
			} else {
				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table} WHERE slug = %s",
					$slug
				) );
			}

			if ( ! $existing ) {
				break;
			}

			$slug = $base . '-' . $i;
			$i++;
		}

		// Fallback if all numbered slugs are taken.
		if ( $i > $max ) {
			$slug = $base . '-' . wp_generate_password( 6, false, false );
		}

		return $slug;
	}
}
