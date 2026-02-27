<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Calendar integration: OAuth, FreeBusy, event push/delete.
 *
 * Uses direct wp_remote_post()/wp_remote_get() — no Google SDK.
 * Tokens encrypted at rest with openssl_encrypt() using wp_salt('auth').
 * Retry-with-backoff (1 retry after 1s) on all API calls.
 * Graceful degradation: if disconnected, everything still works via DB-only.
 */
class Lets_Meet_Gcal {

	/** @var string Encryption method. */
	private const CIPHER = 'aes-256-cbc';

	/** @var string Google OAuth token endpoint. */
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/** @var string Google OAuth authorization endpoint. */
	private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

	/** @var string Google Calendar API base. */
	private const API_BASE = 'https://www.googleapis.com/calendar/v3';

	/** @var array Required OAuth scopes. */
	private const SCOPES = [
		'https://www.googleapis.com/auth/calendar.freebusy',
		'https://www.googleapis.com/auth/calendar.events',
	];

	/* ── Connection status ─────────────────────────────────────────── */

	/**
	 * Check if GCal is connected (credentials + tokens exist).
	 *
	 * @return bool
	 */
	public function is_connected() {
		$client_id = get_option( 'lm_gcal_client_id', '' );
		$tokens    = $this->get_tokens();

		return '' !== $client_id && ! empty( $tokens['refresh_token'] );
	}

	/* ── Encryption ────────────────────────────────────────────────── */

	/**
	 * Encrypt a string using AES-256-CBC with a key from wp_salt('auth').
	 *
	 * @param string $plaintext Data to encrypt.
	 * @return string Base64-encoded IV + ciphertext.
	 */
	private function encrypt( $plaintext ) {
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = openssl_random_pseudo_bytes( $iv_len );
		$cipher = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt a string encrypted with encrypt().
	 *
	 * @param string $encoded Base64-encoded IV + ciphertext.
	 * @return string|false Plaintext on success, false on failure.
	 */
	private function decrypt( $encoded ) {
		if ( '' === $encoded ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$data = base64_decode( $encoded, true );
		if ( false === $data ) {
			return false;
		}

		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv_len = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $data ) < $iv_len ) {
			return false;
		}

		$iv     = substr( $data, 0, $iv_len );
		$cipher = substr( $data, $iv_len );

		$result = openssl_decrypt( $cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return $result;
	}

	/* ── Token management ──────────────────────────────────────────── */

	/**
	 * Store tokens (encrypted, no autoload).
	 *
	 * @param array $tokens Token data (access_token, refresh_token, expires_at).
	 */
	private function save_tokens( $tokens ) {
		$encrypted = $this->encrypt( wp_json_encode( $tokens ) );

		if ( false === get_option( 'lm_gcal_tokens' ) ) {
			add_option( 'lm_gcal_tokens', $encrypted, '', 'no' );
		} else {
			update_option( 'lm_gcal_tokens', $encrypted, 'no' );
		}
	}

	/**
	 * Retrieve and decrypt stored tokens.
	 *
	 * @return array|false Token array or false on failure.
	 */
	private function get_tokens() {
		$encrypted = get_option( 'lm_gcal_tokens', '' );
		if ( '' === $encrypted ) {
			return false;
		}

		$json = $this->decrypt( $encrypted );
		if ( false === $json ) {
			lm_log( 'Token decryption failed — re-authorization needed.' );
			return false;
		}

		$tokens = json_decode( $json, true );
		return is_array( $tokens ) ? $tokens : false;
	}

	/**
	 * Delete stored tokens (disconnect).
	 */
	public function disconnect() {
		delete_option( 'lm_gcal_tokens' );
		delete_option( 'lm_gcal_client_id' );
		delete_option( 'lm_gcal_client_secret' );
		delete_option( 'lm_gcal_calendar_id' );
		delete_transient( 'lm_gcal_error' );
	}

	/**
	 * Get a valid access token, refreshing if expired.
	 *
	 * @return string|false Access token or false on failure.
	 */
	private function get_access_token() {
		$tokens = $this->get_tokens();
		if ( ! $tokens ) {
			return false;
		}

		// If token hasn't expired yet, return it.
		if ( ! empty( $tokens['expires_at'] ) && current_datetime()->getTimestamp() < $tokens['expires_at'] - 60 ) {
			return $tokens['access_token'];
		}

		// Refresh the token.
		return $this->refresh_access_token( $tokens );
	}

	/**
	 * Refresh the access token using the refresh token.
	 *
	 * @param array $tokens Current token data.
	 * @return string|false New access token or false on failure.
	 */
	private function refresh_access_token( $tokens ) {
		if ( empty( $tokens['refresh_token'] ) ) {
			lm_log( 'No refresh token available.' );
			$this->set_error_flag();
			return false;
		}

		$client_secret_enc = get_option( 'lm_gcal_client_secret', '' );
		$client_secret     = $this->decrypt( $client_secret_enc );
		if ( false === $client_secret ) {
			lm_log( 'Client secret decryption failed.' );
			$this->set_error_flag();
			return false;
		}

		$body = null;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( 1 );
			}

			$response = wp_remote_post( self::TOKEN_URL, [
				'body' => [
					'client_id'     => get_option( 'lm_gcal_client_id', '' ),
					'client_secret' => $client_secret,
					'refresh_token' => $tokens['refresh_token'],
					'grant_type'    => 'refresh_token',
				],
			] );

			if ( is_wp_error( $response ) ) {
				lm_log( 'Token refresh HTTP error.', [
					'attempt' => $attempt + 1,
					'error'   => $response->get_error_message(),
				] );
				continue;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! empty( $body['access_token'] ) ) {
				break;
			}

			lm_log( 'Token refresh failed.', [
				'attempt' => $attempt + 1,
				'status'  => wp_remote_retrieve_response_code( $response ),
			] );
		}

		if ( empty( $body['access_token'] ) ) {
			$this->set_error_flag();
			return false;
		}

		// Update stored tokens.
		$tokens['access_token'] = $body['access_token'];
		$tokens['expires_at']   = current_datetime()->getTimestamp() + absint( $body['expires_in'] ?? 3600 );
		$this->save_tokens( $tokens );
		$this->clear_error_flag();

		return $body['access_token'];
	}

	/* ── Error flag (admin notice) ─────────────────────────────────── */

	/**
	 * Set a transient flag to show a persistent admin notice.
	 */
	private function set_error_flag() {
		set_transient( 'lm_gcal_error', '1', DAY_IN_SECONDS );
	}

	/**
	 * Clear the error flag.
	 */
	private function clear_error_flag() {
		delete_transient( 'lm_gcal_error' );
	}

	/**
	 * Show admin notice if GCal tokens need re-authorization.
	 */
	public function maybe_show_admin_notice() {
		if ( ! get_transient( 'lm_gcal_error' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=lets-meet-settings&tab=gcal' );
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Let\'s Meet: Google Calendar connection needs attention.', 'lets-meet' );
		echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Reconnect', 'lets-meet' ) . '</a>';
		echo '</p></div>';
	}

	/* ── OAuth flow ────────────────────────────────────────────────── */

	/**
	 * Get the OAuth redirect URI.
	 *
	 * @return string
	 */
	public function get_redirect_uri() {
		return admin_url( 'admin-post.php?action=lm_gcal_callback' );
	}

	/**
	 * Generate the Google OAuth authorization URL.
	 *
	 * @return string
	 */
	public function get_auth_url() {
		$state = wp_create_nonce( 'lm_gcal_oauth' );

		return add_query_arg( [
			'client_id'     => get_option( 'lm_gcal_client_id', '' ),
			'redirect_uri'  => $this->get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => implode( ' ', self::SCOPES ),
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		], self::AUTH_URL );
	}

	/**
	 * Handle the OAuth callback — exchange auth code for tokens.
	 */
	public function handle_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'lets-meet' ) );
		}

		// CSRF check.
		$state = sanitize_text_field( $_GET['state'] ?? '' );
		if ( ! wp_verify_nonce( $state, 'lm_gcal_oauth' ) ) {
			wp_die( esc_html__( 'Google Calendar authorization expired or was invalid. Please return to Settings and try connecting again.', 'lets-meet' ) );
		}

		// Check for errors from Google.
		if ( ! empty( $_GET['error'] ) ) {
			lm_log( 'OAuth error from Google.', [ 'error' => sanitize_text_field( $_GET['error'] ) ] );
			wp_safe_redirect( admin_url( 'admin.php?page=lets-meet-settings&tab=gcal&gcal_error=auth_denied' ) );
			exit;
		}

		$code = sanitize_text_field( $_GET['code'] ?? '' );
		if ( '' === $code ) {
			wp_die( esc_html__( 'No authorization code received.', 'lets-meet' ) );
		}

		$client_secret_enc = get_option( 'lm_gcal_client_secret', '' );
		$client_secret     = $this->decrypt( $client_secret_enc );
		if ( false === $client_secret ) {
			wp_die( esc_html__( 'Client secret decryption failed.', 'lets-meet' ) );
		}

		// Exchange code for tokens.
		$response = wp_remote_post( self::TOKEN_URL, [
			'body' => [
				'code'          => $code,
				'client_id'     => get_option( 'lm_gcal_client_id', '' ),
				'client_secret' => $client_secret,
				'redirect_uri'  => $this->get_redirect_uri(),
				'grant_type'    => 'authorization_code',
			],
		] );

		if ( is_wp_error( $response ) ) {
			lm_log( 'OAuth token exchange HTTP error.', [ 'error' => $response->get_error_message() ] );
			wp_safe_redirect( admin_url( 'admin.php?page=lets-meet-settings&tab=gcal&gcal_error=token_exchange' ) );
			exit;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			lm_log( 'OAuth token exchange failed.', [ 'status' => wp_remote_retrieve_response_code( $response ) ] );
			wp_safe_redirect( admin_url( 'admin.php?page=lets-meet-settings&tab=gcal&gcal_error=token_exchange' ) );
			exit;
		}

		$this->save_tokens( [
			'access_token'  => $body['access_token'],
			'refresh_token' => $body['refresh_token'] ?? '',
			'expires_at'    => current_datetime()->getTimestamp() + absint( $body['expires_in'] ?? 3600 ),
		] );

		$this->clear_error_flag();

		wp_safe_redirect( admin_url( 'admin.php?page=lets-meet-settings&tab=gcal&gcal_connected=1' ) );
		exit;
	}

	/* ── FreeBusy API ──────────────────────────────────────────────── */

	/**
	 * Get busy intervals for a date from Google Calendar.
	 *
	 * Returns cached result if available (5-min transient).
	 * Falls back to empty array on any failure (graceful degradation).
	 *
	 * @param string       $date 'Y-m-d' date string in site timezone.
	 * @param DateTimeZone $tz   Site timezone.
	 * @return array Array of ['start' => DateTimeImmutable, 'end' => DateTimeImmutable].
	 */
	public function get_busy( $date, $tz ) {
		if ( ! $this->is_connected() ) {
			return [];
		}

		// Check transient cache.
		$cache_key = 'lm_gcal_busy_' . $date;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $this->parse_busy_response( $cached, $tz );
		}

		$access_token = $this->get_access_token();
		if ( ! $access_token ) {
			return [];
		}

		$day_start  = new DateTimeImmutable( $date . ' 00:00:00', $tz );
		$day_end    = $day_start->modify( '+1 day' );
		$cal_id     = get_option( 'lm_gcal_calendar_id', 'primary' );

		$body = wp_json_encode( [
			'timeMin' => $day_start->format( 'c' ),
			'timeMax' => $day_end->format( 'c' ),
			'items'   => [ [ 'id' => $cal_id ] ],
		] );

		$result = $this->api_request_with_retry(
			self::API_BASE . '/freeBusy',
			[
				'method'  => 'POST',
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => $body,
			]
		);

		if ( ! $result ) {
			return [];
		}

		// Extract busy array from response.
		// Google may key by the actual email address instead of "primary".
		$calendars = $result['calendars'] ?? [];
		if ( isset( $calendars[ $cal_id ] ) ) {
			$busy_data = $calendars[ $cal_id ]['busy'] ?? [];
		} elseif ( ! empty( $calendars ) ) {
			$first     = reset( $calendars );
			$busy_data = $first['busy'] ?? [];
		} else {
			$busy_data = [];
		}

		// Cache for 5 minutes.
		set_transient( $cache_key, $busy_data, 5 * MINUTE_IN_SECONDS );

		return $this->parse_busy_response( $busy_data, $tz );
	}

	/**
	 * Get busy intervals with a FRESH API call (no cache).
	 *
	 * Used during booking submission for concurrency safety.
	 *
	 * @param string       $date 'Y-m-d' date string in site timezone.
	 * @param DateTimeZone $tz   Site timezone.
	 * @return array
	 */
	public function get_busy_fresh( $date, $tz ) {
		$cache_key = 'lm_gcal_busy_' . $date;
		delete_transient( $cache_key );

		return $this->get_busy( $date, $tz );
	}

	/**
	 * Parse FreeBusy busy array into DateTimeImmutable intervals.
	 *
	 * @param array        $busy_data Raw busy array from Google.
	 * @param DateTimeZone $tz        Site timezone.
	 * @return array
	 */
	private function parse_busy_response( $busy_data, $tz ) {
		$utc       = new DateTimeZone( 'UTC' );
		$intervals = [];

		foreach ( $busy_data as $block ) {
			$start = $block['start'] ?? '';
			$end   = $block['end'] ?? '';

			if ( '' === $start || '' === $end ) {
				continue;
			}

			$intervals[] = [
				'start' => ( new DateTimeImmutable( $start, $utc ) )->setTimezone( $tz ),
				'end'   => ( new DateTimeImmutable( $end, $utc ) )->setTimezone( $tz ),
			];
		}

		return $intervals;
	}

	/* ── Event push/delete ─────────────────────────────────────────── */

	/**
	 * Create a Google Calendar event for a booking.
	 *
	 * Non-blocking: if this fails, the booking still succeeds.
	 *
	 * @param array $booking_data Booking data.
	 * @return string|false GCal event ID on success, false on failure.
	 */
	public function push_event( $booking_data ) {
		if ( ! $this->is_connected() ) {
			return false;
		}

		$access_token = $this->get_access_token();
		if ( ! $access_token ) {
			return false;
		}

		$tz      = wp_timezone();
		$start   = new DateTimeImmutable( $booking_data['start_utc'], new DateTimeZone( 'UTC' ) );
		$end     = $start->modify( '+' . absint( $booking_data['duration'] ) . ' minutes' );

		$event = [
			'summary'     => sprintf(
				/* translators: %s: client name */
				__( 'Booking — %s', 'lets-meet' ),
				$booking_data['client_name']
			),
			'description' => sprintf(
				"Booked via Let's Meet\nService: %s\nEmail: %s\nPhone: %s\nNotes: %s",
				$booking_data['service_name'] ?? '',
				$booking_data['client_email'],
				$booking_data['client_phone'] ?? '',
				$booking_data['client_notes'] ?? ''
			),
			'start'     => [ 'dateTime' => $start->setTimezone( $tz )->format( 'c' ), 'timeZone' => $tz->getName() ],
			'end'       => [ 'dateTime' => $end->setTimezone( $tz )->format( 'c' ), 'timeZone' => $tz->getName() ],
			'reminders' => [ 'useDefault' => true ],
		];

		/**
		 * Filter the GCal event data before pushing.
		 *
		 * @param array $event        Event data.
		 * @param int   $booking_id   Booking ID.
		 */
		$event = apply_filters( 'lm_gcal_event_data', $event, $booking_data['booking_id'] ?? 0 );

		$cal_id = get_option( 'lm_gcal_calendar_id', 'primary' );
		$url    = self::API_BASE . '/calendars/' . rawurlencode( $cal_id ) . '/events';

		$result = $this->api_request_with_retry( $url, [
			'method'  => 'POST',
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $event ),
		] );

		if ( ! $result || empty( $result['id'] ) ) {
			lm_log( 'GCal event push failed.' );
			return false;
		}

		return $result['id'];
	}

	/**
	 * Delete a Google Calendar event.
	 *
	 * Handles 410 Gone gracefully (event already deleted).
	 *
	 * @param string $event_id GCal event ID.
	 * @return bool
	 */
	public function delete_event( $event_id ) {
		if ( ! $this->is_connected() || '' === $event_id ) {
			return false;
		}

		$access_token = $this->get_access_token();
		if ( ! $access_token ) {
			return false;
		}

		$cal_id = get_option( 'lm_gcal_calendar_id', 'primary' );
		$url    = self::API_BASE . '/calendars/' . rawurlencode( $cal_id ) . '/events/' . rawurlencode( $event_id );

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( 1 );
			}

			$response = wp_remote_request( $url, [
				'method'  => 'DELETE',
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
				],
			] );

			if ( is_wp_error( $response ) ) {
				lm_log( 'GCal event delete HTTP error.', [
					'attempt' => $attempt + 1,
					'error'   => $response->get_error_message(),
				] );
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			// 204 = deleted, 404 = not found, 410 = already gone — all mean the event is gone.
			if ( 204 === $code || 404 === $code || 410 === $code ) {
				if ( 410 === $code ) {
					lm_log( 'GCal event already deleted (410 Gone).', [ 'event_id' => $event_id ] );
				}
				if ( 404 === $code ) {
					lm_log( 'GCal event not found (404).', [ 'event_id' => $event_id ] );
				}
				return true;
			}

			// On 401, refresh the token and retry with the new token.
			if ( 401 === $code && 0 === $attempt ) {
				$this->refresh_access_token();
				$new_token = $this->get_access_token();
				if ( $new_token ) {
					$access_token = $new_token;
				}
			}

			lm_log( 'GCal event delete failed.', [
				'attempt'  => $attempt + 1,
				'status'   => $code,
				'event_id' => $event_id,
			] );
		}

		return false;
	}

	/* ── API request helper ────────────────────────────────────────── */

	/**
	 * Make an API request with retry-on-failure (1 retry after 1 second).
	 *
	 * @param string $url  Request URL.
	 * @param array  $args wp_remote_request args.
	 * @return array|false Decoded JSON response or false on failure.
	 */
	private function api_request_with_retry( $url, $args ) {
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( 1 );
			}

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				lm_log( 'GCal API HTTP error.', [
					'attempt' => $attempt + 1,
					'error'   => $response->get_error_message(),
				] );
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code >= 200 && $code < 300 ) {
				return is_array( $body ) ? $body : [];
			}

			// On 401, refresh the token and retry with the new token.
			if ( 401 === $code && 0 === $attempt ) {
				$this->refresh_access_token();
				$new_token = $this->get_access_token();
				if ( $new_token ) {
					$args['headers']['Authorization'] = 'Bearer ' . $new_token;
				}
			}

			lm_log( 'GCal API error response.', [
				'attempt' => $attempt + 1,
				'status'  => $code,
			] );
		}

		return false;
	}

	/* ── Admin settings helpers ────────────────────────────────────── */

	/**
	 * Save OAuth credentials (Client ID + encrypted Client Secret).
	 *
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client Secret (plaintext — will be encrypted).
	 * @param string $calendar_id   Calendar ID.
	 */
	public function save_credentials( $client_id, $client_secret, $calendar_id ) {
		$client_id     = sanitize_text_field( $client_id );
		$client_secret = sanitize_text_field( $client_secret );
		$calendar_id   = sanitize_text_field( $calendar_id );

		if ( false === get_option( 'lm_gcal_client_id' ) ) {
			add_option( 'lm_gcal_client_id', $client_id, '', 'no' );
		} else {
			update_option( 'lm_gcal_client_id', $client_id, 'no' );
		}

		$encrypted_secret = $this->encrypt( $client_secret );
		if ( false === get_option( 'lm_gcal_client_secret' ) ) {
			add_option( 'lm_gcal_client_secret', $encrypted_secret, '', 'no' );
		} else {
			update_option( 'lm_gcal_client_secret', $encrypted_secret, 'no' );
		}

		if ( '' === $calendar_id ) {
			$calendar_id = 'primary';
		}
		if ( false === get_option( 'lm_gcal_calendar_id' ) ) {
			add_option( 'lm_gcal_calendar_id', $calendar_id, '', 'no' );
		} else {
			update_option( 'lm_gcal_calendar_id', $calendar_id, 'no' );
		}
	}
}
