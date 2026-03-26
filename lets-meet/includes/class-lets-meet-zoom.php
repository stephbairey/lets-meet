<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zoom Meeting integration: Server-to-Server OAuth, meeting CRUD.
 *
 * Uses direct wp_remote_post()/wp_remote_request() — no Zoom SDK.
 * Client Secret encrypted at rest with openssl_encrypt() using wp_salt('auth').
 * Access token cached in transient (~59 min TTL). No refresh token needed.
 * Graceful degradation: if disconnected, bookings still work without Zoom.
 */
class Lets_Meet_Zoom {

	/** @var string Encryption method. */
	private const CIPHER = 'aes-256-cbc';

	/** @var string Zoom OAuth token endpoint. */
	private const TOKEN_URL = 'https://zoom.us/oauth/token';

	/** @var string Zoom API base. */
	private const API_BASE = 'https://api.zoom.us/v2';

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

		return openssl_decrypt( $cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
	}

	/* ── Connection status ─────────────────────────────────────────── */

	/**
	 * Check if Zoom is connected (all 3 credential options exist).
	 *
	 * @return bool
	 */
	public function is_connected() {
		$account_id    = get_option( 'lm_zoom_account_id', '' );
		$client_id     = get_option( 'lm_zoom_client_id', '' );
		$client_secret = get_option( 'lm_zoom_client_secret', '' );

		return '' !== $account_id && '' !== $client_id && '' !== $client_secret;
	}

	/* ── Credential management ─────────────────────────────────────── */

	/**
	 * Save Zoom credentials (Account ID + Client ID + encrypted Client Secret).
	 *
	 * @param string $account_id    Zoom Account ID.
	 * @param string $client_id     Zoom Client ID.
	 * @param string $client_secret Zoom Client Secret (plaintext — will be encrypted).
	 */
	public function save_credentials( $account_id, $client_id, $client_secret ) {
		$account_id    = sanitize_text_field( $account_id );
		$client_id     = sanitize_text_field( $client_id );
		$client_secret = sanitize_text_field( $client_secret );

		$this->save_option_no_autoload( 'lm_zoom_account_id', $account_id );
		$this->save_option_no_autoload( 'lm_zoom_client_id', $client_id );
		$this->save_option_no_autoload( 'lm_zoom_client_secret', $this->encrypt( $client_secret ) );

		// Clear any cached token so the next request uses the new credentials.
		delete_transient( 'lm_zoom_token' );
	}

	/**
	 * Disconnect Zoom — delete all options and cached token.
	 */
	public function disconnect() {
		delete_option( 'lm_zoom_account_id' );
		delete_option( 'lm_zoom_client_id' );
		delete_option( 'lm_zoom_client_secret' );
		delete_transient( 'lm_zoom_token' );
		delete_transient( 'lm_zoom_error' );
	}

	/**
	 * Helper to save an option with autoload = no.
	 *
	 * @param string $key   Option key.
	 * @param string $value Option value.
	 */
	private function save_option_no_autoload( $key, $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value, '', 'no' );
		} else {
			update_option( $key, $value, 'no' );
		}
	}

	/* ── Access token ──────────────────────────────────────────────── */

	/**
	 * Get a valid access token, fetching from Zoom if expired.
	 *
	 * Server-to-Server OAuth: POST to token endpoint with account_credentials grant.
	 * Token cached in transient for ~59 minutes.
	 *
	 * @return string|false Access token or false on failure.
	 */
	private function get_access_token() {
		// Check transient cache first.
		$cached = get_transient( 'lm_zoom_token' );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( ! $this->is_connected() ) {
			return false;
		}

		$account_id        = get_option( 'lm_zoom_account_id', '' );
		$client_id         = get_option( 'lm_zoom_client_id', '' );
		$client_secret_enc = get_option( 'lm_zoom_client_secret', '' );
		$client_secret     = $this->decrypt( $client_secret_enc );

		if ( false === $client_secret ) {
			lm_log( 'Zoom client secret decryption failed.' );
			$this->set_error_flag();
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$auth_header = base64_encode( $client_id . ':' . $client_secret );

		$response = wp_remote_post( self::TOKEN_URL, [
			'headers' => [
				'Authorization' => 'Basic ' . $auth_header,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body' => [
				'grant_type'  => 'account_credentials',
				'account_id'  => $account_id,
			],
		] );

		if ( is_wp_error( $response ) ) {
			lm_log( 'Zoom token fetch HTTP error.', [ 'error' => $response->get_error_message() ] );
			$this->set_error_flag();
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			lm_log( 'Zoom token fetch failed.', [ 'status' => wp_remote_retrieve_response_code( $response ) ] );
			$this->set_error_flag();
			return false;
		}

		$expires_in = absint( $body['expires_in'] ?? 3600 );
		// Cache for 1 minute less than actual expiry to avoid edge cases.
		$ttl = max( $expires_in - 60, 60 );

		set_transient( 'lm_zoom_token', $body['access_token'], $ttl );
		$this->clear_error_flag();

		return $body['access_token'];
	}

	/* ── Error flag (admin notice) ─────────────────────────────────── */

	/**
	 * Set a transient flag to show a persistent admin notice.
	 */
	private function set_error_flag() {
		set_transient( 'lm_zoom_error', '1', DAY_IN_SECONDS );
	}

	/**
	 * Clear the error flag.
	 */
	private function clear_error_flag() {
		delete_transient( 'lm_zoom_error' );
	}

	/**
	 * Show admin notice if Zoom credentials need attention.
	 */
	public function maybe_show_admin_notice() {
		if ( ! get_transient( 'lm_zoom_error' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=lets-meet-settings&tab=zoom' );
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Let\'s Meet: Zoom connection needs attention.', 'lets-meet' );
		echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Check Settings', 'lets-meet' ) . '</a>';
		echo '</p></div>';
	}

	/* ── Connection test ───────────────────────────────────────────── */

	/**
	 * Test the Zoom connection and return a diagnostic message.
	 *
	 * @return string Human-readable result.
	 */
	public function test_connection() {
		if ( ! $this->is_connected() ) {
			return __( 'Not connected — credentials are missing.', 'lets-meet' );
		}

		$account_id        = get_option( 'lm_zoom_account_id', '' );
		$client_id         = get_option( 'lm_zoom_client_id', '' );
		$client_secret_enc = get_option( 'lm_zoom_client_secret', '' );
		$client_secret     = $this->decrypt( $client_secret_enc );

		if ( false === $client_secret ) {
			return __( 'Client secret decryption failed. Try re-entering your credentials.', 'lets-meet' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$auth_header = base64_encode( $client_id . ':' . $client_secret );

		$response = wp_remote_post( self::TOKEN_URL, [
			'headers' => [
				'Authorization' => 'Basic ' . $auth_header,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body' => [
				'grant_type' => 'account_credentials',
				'account_id' => $account_id,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return sprintf( __( 'HTTP error: %s', 'lets-meet' ), $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['access_token'] ) ) {
			$this->clear_error_flag();
			return __( 'Success — token received.', 'lets-meet' );
		}

		$reason = $body['reason'] ?? ( $body['error'] ?? 'unknown' );
		return sprintf( __( 'Zoom returned HTTP %1$d: %2$s', 'lets-meet' ), $code, $reason );
	}

	/* ── Meeting CRUD ──────────────────────────────────────────────── */

	/**
	 * Create a Zoom meeting for a booking.
	 *
	 * @param array $booking_data Must contain start_utc, duration, client_name, service_name.
	 * @return array|false ['meeting_id' => string, 'join_url' => string] or false on failure.
	 */
	public function create_meeting( $booking_data ) {
		if ( ! $this->is_connected() ) {
			return false;
		}

		$tz    = wp_timezone();
		$start = new \DateTimeImmutable( $booking_data['start_utc'], new \DateTimeZone( 'UTC' ) );

		$meeting = [
			'topic'      => sprintf(
				/* translators: 1: service name, 2: client name */
				__( '%1$s — %2$s', 'lets-meet' ),
				$booking_data['service_name'] ?? '',
				$booking_data['client_name']
			),
			'type'       => 2, // Scheduled meeting.
			'start_time' => $start->setTimezone( $tz )->format( 'Y-m-d\TH:i:s' ),
			'duration'   => absint( $booking_data['duration'] ),
			'timezone'   => $tz->getName(),
			'settings'   => [
				'join_before_host'  => true,
				'waiting_room'      => false,
				'approval_type'     => 2, // No registration required.
				'meeting_authentication' => false,
			],
		];

		$result = $this->api_request_with_retry(
			self::API_BASE . '/users/me/meetings',
			[
				'method' => 'POST',
				'body'   => wp_json_encode( $meeting ),
			]
		);

		if ( ! $result || empty( $result['id'] ) || empty( $result['join_url'] ) ) {
			lm_log( 'Zoom meeting create failed.' );
			return false;
		}

		return [
			'meeting_id' => (string) $result['id'],
			'join_url'   => $result['join_url'],
		];
	}

	/**
	 * Update a Zoom meeting time (for reschedule).
	 *
	 * @param string $meeting_id  Zoom meeting ID.
	 * @param array  $booking_data Must contain start_utc and duration.
	 * @return bool True on success.
	 */
	public function update_meeting( $meeting_id, $booking_data ) {
		if ( ! $this->is_connected() || '' === $meeting_id ) {
			return false;
		}

		$tz    = wp_timezone();
		$start = new \DateTimeImmutable( $booking_data['start_utc'], new \DateTimeZone( 'UTC' ) );

		$update = [
			'start_time' => $start->setTimezone( $tz )->format( 'Y-m-d\TH:i:s' ),
			'duration'   => absint( $booking_data['duration'] ),
			'timezone'   => $tz->getName(),
		];

		$result = $this->api_request_with_retry(
			self::API_BASE . '/meetings/' . rawurlencode( $meeting_id ),
			[
				'method' => 'PATCH',
				'body'   => wp_json_encode( $update ),
			]
		);

		// PATCH returns 204 No Content on success (empty body), api_request_with_retry returns [].
		if ( false === $result ) {
			lm_log( 'Zoom meeting update failed.', [ 'meeting_id' => $meeting_id ] );
			return false;
		}

		return true;
	}

	/**
	 * Delete a Zoom meeting.
	 *
	 * Handles 204 (deleted) and 404 (not found) gracefully.
	 *
	 * @param string $meeting_id Zoom meeting ID.
	 * @return bool
	 */
	public function delete_meeting( $meeting_id ) {
		if ( ! $this->is_connected() || '' === $meeting_id ) {
			return false;
		}

		$result = $this->api_request_with_retry(
			self::API_BASE . '/meetings/' . rawurlencode( $meeting_id ),
			[
				'method' => 'DELETE',
			]
		);

		// api_request_with_retry returns [] on 2xx/404, false on hard failure.
		if ( false === $result ) {
			lm_log( 'Zoom meeting delete failed.', [ 'meeting_id' => $meeting_id ] );
			return false;
		}

		return true;
	}

	/* ── API request helper ────────────────────────────────────────── */

	/**
	 * Make a Zoom API request with retry-on-failure (2 attempts, 1s sleep).
	 *
	 * On 401, clears the cached token and retries with a fresh one.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Must include 'method'. May include 'body'.
	 * @return array|false Decoded JSON response (or empty array for 204/404) or false on failure.
	 */
	private function api_request_with_retry( $url, $args ) {
		$access_token = $this->get_access_token();
		if ( ! $access_token ) {
			return false;
		}

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( 1 );
			}

			$request_args = [
				'method'  => $args['method'],
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				],
			];

			if ( ! empty( $args['body'] ) ) {
				$request_args['body'] = $args['body'];
			}

			$response = wp_remote_request( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				lm_log( 'Zoom API HTTP error.', [
					'attempt' => $attempt + 1,
					'error'   => $response->get_error_message(),
				] );
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			// 2xx = success, 404 = not found (treat as success for deletes).
			if ( ( $code >= 200 && $code < 300 ) || 404 === $code ) {
				if ( 404 === $code ) {
					lm_log( 'Zoom meeting not found (404).', [ 'url' => $url ] );
				}
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				return is_array( $body ) ? $body : [];
			}

			// On 401, clear cached token and retry with a fresh one.
			if ( 401 === $code && 0 === $attempt ) {
				delete_transient( 'lm_zoom_token' );
				$access_token = $this->get_access_token();
				if ( ! $access_token ) {
					return false;
				}
				continue;
			}

			lm_log( 'Zoom API error response.', [
				'attempt' => $attempt + 1,
				'status'  => $code,
			] );
		}

		return false;
	}
}
