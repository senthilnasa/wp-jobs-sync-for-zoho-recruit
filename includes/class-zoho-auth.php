<?php
/**
 * Zoho OAuth 2.0 handling.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the OAuth lifecycle: authorization, token exchange, refresh, revoke.
 *
 * No other class reads or writes the stored tokens.
 */
class Zoho_Auth {

	/**
	 * Option storing encrypted client credentials.
	 */
	const OPTION_CREDENTIALS = 'jszr_credentials';

	/**
	 * Option storing encrypted tokens.
	 */
	const OPTION_TOKENS = 'jszr_tokens';

	/**
	 * Option storing consecutive authentication failure count.
	 */
	const OPTION_FAILURES = 'jszr_auth_failures';

	/**
	 * Option storing the connection health state.
	 */
	const OPTION_STATE = 'jszr_auth_state';

	/**
	 * Consecutive failures before automatic retries stop.
	 */
	const CIRCUIT_THRESHOLD = 5;

	/**
	 * Seconds subtracted from the real expiry to avoid using a token that is
	 * about to expire mid-request.
	 */
	const EXPIRY_SKEW = 120;

	/**
	 * Hook registration.
	 */
	public function __construct() {
		add_action( 'admin_post_jszr_oauth_callback', array( $this, 'handle_callback' ) );
		add_action( 'admin_post_jszr_oauth_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_jszr_oauth_disconnect', array( $this, 'handle_disconnect' ) );
	}

	/* ---------------------------------------------------------------------
	 * Credentials
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the client ID comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function client_id_is_constant() {
		return defined( 'JSZR_CLIENT_ID' ) && '' !== (string) JSZR_CLIENT_ID;
	}

	/**
	 * Whether the client secret comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function client_secret_is_constant() {
		return defined( 'JSZR_CLIENT_SECRET' ) && '' !== (string) JSZR_CLIENT_SECRET;
	}

	/**
	 * Whether the data center comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function data_center_is_constant() {
		return defined( 'JSZR_DATA_CENTER' ) && '' !== (string) JSZR_DATA_CENTER;
	}

	/**
	 * Get the OAuth client ID.
	 *
	 * @return string
	 */
	public function client_id() {
		if ( self::client_id_is_constant() ) {
			return (string) JSZR_CLIENT_ID;
		}

		$stored = get_option( self::OPTION_CREDENTIALS, array() );

		if ( ! is_array( $stored ) || empty( $stored['client_id'] ) ) {
			return '';
		}

		return Encryption::decrypt( (string) $stored['client_id'] );
	}

	/**
	 * Get the OAuth client secret.
	 *
	 * @return string
	 */
	public function client_secret() {
		if ( self::client_secret_is_constant() ) {
			return (string) JSZR_CLIENT_SECRET;
		}

		$stored = get_option( self::OPTION_CREDENTIALS, array() );

		if ( ! is_array( $stored ) || empty( $stored['client_secret'] ) ) {
			return '';
		}

		return Encryption::decrypt( (string) $stored['client_secret'] );
	}

	/**
	 * Store client credentials.
	 *
	 * An empty value leaves the existing stored value untouched, so the settings
	 * form can display a masked placeholder without wiping the secret.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @return true|\WP_Error
	 */
	public function save_credentials( $client_id, $client_secret ) {
		if ( ! Encryption::is_available() ) {
			return new \WP_Error(
				'jszr_no_encryption',
				__( 'Credentials cannot be stored securely: this site has no usable encryption extension or security salts.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		$stored = get_option( self::OPTION_CREDENTIALS, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$client_id     = trim( (string) $client_id );
		$client_secret = trim( (string) $client_secret );

		if ( '' !== $client_id ) {
			$stored['client_id'] = Encryption::encrypt( $client_id );
		}

		if ( '' !== $client_secret ) {
			$stored['client_secret'] = Encryption::encrypt( $client_secret );
		}

		$stored['fingerprint'] = Encryption::key_fingerprint();

		update_option( self::OPTION_CREDENTIALS, $stored, false );

		return true;
	}

	/**
	 * Whether both client credentials are present.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== $this->client_id() && '' !== $this->client_secret();
	}

	/**
	 * Masked client ID for display.
	 *
	 * @return string
	 */
	public function masked_client_id() {
		return self::mask( $this->client_id() );
	}

	/**
	 * Masked client secret for display.
	 *
	 * @return string
	 */
	public function masked_client_secret() {
		return self::mask( $this->client_secret() );
	}

	/**
	 * Mask a secret for display.
	 *
	 * @param string $value Secret.
	 * @return string
	 */
	private static function mask( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) <= 8 ) {
			return str_repeat( '*', strlen( $value ) );
		}

		return substr( $value, 0, 4 ) . str_repeat( '*', 12 ) . substr( $value, -4 );
	}

	/* ---------------------------------------------------------------------
	 * Authorization flow
	 * ------------------------------------------------------------------ */

	/**
	 * The exact redirect URI that must be registered in the Zoho console.
	 *
	 * @return string
	 */
	public function redirect_uri() {
		/**
		 * Filter the OAuth redirect URI.
		 *
		 * @param string $uri Redirect URI.
		 */
		return (string) apply_filters(
			'jszr_redirect_uri',
			admin_url( 'admin-post.php?action=jszr_oauth_callback' )
		);
	}

	/**
	 * OAuth scopes requested.
	 *
	 * @return string[]
	 */
	public function scopes() {
		$scopes = array(
			'ZohoRecruit.modules.jobopenings.READ',
			'ZohoRecruit.settings.fields.READ',
		);

		/**
		 * Filter the requested OAuth scopes.
		 *
		 * Keep this list minimal; the plugin only reads job openings.
		 *
		 * @param string[] $scopes Scope names.
		 */
		return array_values( array_unique( (array) apply_filters( 'jszr_oauth_scopes', $scopes ) ) );
	}

	/**
	 * Build the Zoho authorization URL and store a one-time state token.
	 *
	 * @return string|\WP_Error
	 */
	public function authorization_url() {
		if ( ! $this->has_credentials() ) {
			return new \WP_Error(
				'jszr_missing_credentials',
				__( 'Enter your Zoho Client ID and Client Secret before connecting.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		$state = wp_generate_password( 32, false, false );

		set_transient(
			'jszr_oauth_state_' . hash( 'sha256', $state ),
			array(
				'user_id' => get_current_user_id(),
				'time'    => time(),
			),
			15 * MINUTE_IN_SECONDS
		);

		$args = array(
			'response_type' => 'code',
			'client_id'     => $this->client_id(),
			'scope'         => implode( ',', $this->scopes() ),
			'redirect_uri'  => $this->redirect_uri(),
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		);

		return add_query_arg( array_map( 'rawurlencode', $args ), Settings::accounts_url() . '/oauth/v2/auth' );
	}

	/**
	 * Start the OAuth flow from the admin.
	 *
	 * @return void
	 */
	public function handle_start() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to connect Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ), 403 );
		}

		check_admin_referer( 'jszr_oauth_start' );

		$url = $this->authorization_url();

		if ( is_wp_error( $url ) ) {
			$this->redirect_with_notice( 'error', $url->get_error_message() );
		}

		wp_redirect( $url );
		exit;
	}

	/**
	 * Handle the redirect back from Zoho.
	 *
	 * @return void
	 */
	public function handle_callback() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to complete this connection.', 'jobs-sync-for-zoho-recruit' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CSRF is handled by the OAuth state parameter below.
		$raw_state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$key       = 'jszr_oauth_state_' . hash( 'sha256', $raw_state );
		$stored    = '' === $raw_state ? false : get_transient( $key );

		if ( ! is_array( $stored ) ) {
			Logger::warning( 'oauth_state_invalid', 'OAuth callback rejected: missing or expired state parameter.' );
			$this->redirect_with_notice( 'error', __( 'The connection request could not be verified. Please start the connection again.', 'jobs-sync-for-zoho-recruit' ) );
		}

		delete_transient( $key );

		if ( (int) $stored['user_id'] !== get_current_user_id() ) {
			Logger::warning( 'oauth_state_user_mismatch', 'OAuth callback rejected: state belongs to a different user.' );
			$this->redirect_with_notice( 'error', __( 'The connection request could not be verified. Please start the connection again.', 'jobs-sync-for-zoho-recruit' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified via state above.
		if ( isset( $_GET['error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified via state above.
			$error = sanitize_text_field( wp_unslash( $_GET['error'] ) );

			Logger::error( 'oauth_denied', 'Zoho returned an OAuth error.', array( 'error' => $error ) );

			$this->redirect_with_notice(
				'error',
				sprintf(
					/* translators: %s: error code returned by Zoho. */
					__( 'Zoho refused the connection: %s', 'jobs-sync-for-zoho-recruit' ),
					self::describe_oauth_error( $error )
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified via state above.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		if ( '' === $code ) {
			$this->redirect_with_notice( 'error', __( 'Zoho did not return an authorization code.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$result = $this->exchange_code( $code );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message() );
		}

		Logger::info( 'oauth_connected', 'Connected to Zoho Recruit.' );

		$this->redirect_with_notice( 'success', __( 'Connected to Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ) );
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code Authorization code.
	 * @return true|\WP_Error
	 */
	public function exchange_code( $code ) {
		$response = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $this->client_id(),
				'client_secret' => $this->client_secret(),
				'redirect_uri'  => $this->redirect_uri(),
				'code'          => $code,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['refresh_token'] ) ) {
			return new \WP_Error(
				'jszr_no_refresh_token',
				__( 'Zoho did not return a refresh token. Remove this site from your Zoho connected apps and try connecting again.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		$tokens = array(
			'refresh_token' => Encryption::encrypt( (string) $response['refresh_token'] ),
			'access_token'  => Encryption::encrypt( (string) ( $response['access_token'] ?? '' ) ),
			'expires_at'    => time() + (int) ( $response['expires_in'] ?? 3600 ),
			'api_domain'    => isset( $response['api_domain'] ) ? esc_url_raw( (string) $response['api_domain'] ) : '',
			'scope'         => isset( $response['scope'] ) ? sanitize_text_field( (string) $response['scope'] ) : implode( ',', $this->scopes() ),
			'fingerprint'   => Encryption::key_fingerprint(),
			'connected_at'  => time(),
		);

		update_option( self::OPTION_TOKENS, $tokens, false );

		$this->reset_failures();

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Token access
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a refresh token is stored.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== $this->refresh_token();
	}

	/**
	 * The stored refresh token.
	 *
	 * @return string
	 */
	private function refresh_token() {
		$tokens = get_option( self::OPTION_TOKENS, array() );

		if ( ! is_array( $tokens ) || empty( $tokens['refresh_token'] ) ) {
			return '';
		}

		return Encryption::decrypt( (string) $tokens['refresh_token'] );
	}

	/**
	 * Whether the stored secrets were encrypted with a different key.
	 *
	 * @return bool
	 */
	public function keys_changed() {
		$tokens = get_option( self::OPTION_TOKENS, array() );

		if ( ! is_array( $tokens ) || empty( $tokens['fingerprint'] ) ) {
			return false;
		}

		return (string) $tokens['fingerprint'] !== Encryption::key_fingerprint();
	}

	/**
	 * Get a usable access token, refreshing it when needed.
	 *
	 * @param bool $force Force a refresh even if the cached token looks valid.
	 * @return string|\WP_Error
	 */
	public function get_access_token( $force = false ) {
		if ( $this->is_circuit_open() ) {
			return new \WP_Error(
				'jszr_circuit_open',
				__( 'Automatic Zoho requests are paused after repeated authentication failures. Reconnect Zoho Recruit to resume.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		if ( ! $this->is_connected() ) {
			return new \WP_Error(
				'jszr_not_connected',
				__( 'This site is not connected to Zoho Recruit.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		if ( $this->keys_changed() ) {
			return new \WP_Error(
				'jszr_keys_changed',
				__( 'Stored Zoho credentials can no longer be decrypted because the site security keys changed. Please reconnect Zoho Recruit.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		$tokens = get_option( self::OPTION_TOKENS, array() );
		$tokens = is_array( $tokens ) ? $tokens : array();

		$expires_at = isset( $tokens['expires_at'] ) ? (int) $tokens['expires_at'] : 0;
		$access     = isset( $tokens['access_token'] ) ? Encryption::decrypt( (string) $tokens['access_token'] ) : '';

		if ( ! $force && '' !== $access && $expires_at > ( time() + self::EXPIRY_SKEW ) ) {
			return $access;
		}

		return $this->refresh_access_token();
	}

	/**
	 * Refresh the access token.
	 *
	 * @return string|\WP_Error
	 */
	public function refresh_access_token() {
		$lock_key = 'jszr_token_refresh_lock';

		// Avoid a refresh stampede when several requests expire together.
		if ( get_transient( $lock_key ) ) {
			// Another process is refreshing; wait briefly then re-read.
			usleep( 500000 );

			$tokens = get_option( self::OPTION_TOKENS, array() );

			if ( is_array( $tokens ) && ! empty( $tokens['access_token'] ) && (int) ( $tokens['expires_at'] ?? 0 ) > time() ) {
				return Encryption::decrypt( (string) $tokens['access_token'] );
			}
		}

		set_transient( $lock_key, 1, 30 );

		$refresh = $this->refresh_token();

		if ( '' === $refresh ) {
			delete_transient( $lock_key );

			return new \WP_Error( 'jszr_not_connected', __( 'This site is not connected to Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$response = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'client_id'     => $this->client_id(),
				'client_secret' => $this->client_secret(),
				'refresh_token' => $refresh,
			)
		);

		delete_transient( $lock_key );

		if ( is_wp_error( $response ) ) {
			$this->record_failure( $response->get_error_code() );

			return $response;
		}

		if ( empty( $response['access_token'] ) ) {
			$this->record_failure( 'no_access_token' );

			return new \WP_Error( 'jszr_refresh_failed', __( 'Zoho did not return a new access token.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$tokens = get_option( self::OPTION_TOKENS, array() );
		$tokens = is_array( $tokens ) ? $tokens : array();

		$tokens['access_token'] = Encryption::encrypt( (string) $response['access_token'] );
		$tokens['expires_at']   = time() + (int) ( $response['expires_in'] ?? 3600 );
		$tokens['fingerprint']  = Encryption::key_fingerprint();

		if ( ! empty( $response['api_domain'] ) ) {
			$tokens['api_domain'] = esc_url_raw( (string) $response['api_domain'] );
		}

		update_option( self::OPTION_TOKENS, $tokens, false );

		$this->reset_failures();

		return (string) $response['access_token'];
	}

	/**
	 * The API base URL Zoho told us to use, falling back to the data center map.
	 *
	 * @return string
	 */
	public function api_base() {
		$tokens = get_option( self::OPTION_TOKENS, array() );

		if ( is_array( $tokens ) && ! empty( $tokens['api_domain'] ) ) {
			$domain = (string) $tokens['api_domain'];

			// Only trust a Zoho-owned host.
			$host = wp_parse_url( $domain, PHP_URL_HOST );

			if ( is_string( $host ) && preg_match( '/(^|\.)zoho(cloud)?\.[a-z.]{2,7}$/i', $host ) ) {
				return untrailingslashit( $domain );
			}
		}

		return untrailingslashit( Settings::api_url() );
	}

	/**
	 * Connection metadata for the dashboard.
	 *
	 * @return array
	 */
	public function connection_info() {
		$tokens  = get_option( self::OPTION_TOKENS, array() );
		$tokens  = is_array( $tokens ) ? $tokens : array();
		$centers = Settings::data_centers();
		$dc      = Settings::data_center();

		return array(
			'connected'     => $this->is_connected(),
			'data_center'   => $dc,
			'data_center_label' => isset( $centers[ $dc ]['label'] ) ? $centers[ $dc ]['label'] : $dc,
			'api_base'      => $this->api_base(),
			'scope'         => isset( $tokens['scope'] ) ? (string) $tokens['scope'] : '',
			'connected_at'  => isset( $tokens['connected_at'] ) ? (int) $tokens['connected_at'] : 0,
			'expires_at'    => isset( $tokens['expires_at'] ) ? (int) $tokens['expires_at'] : 0,
			'keys_changed'  => $this->keys_changed(),
			'failures'      => (int) get_option( self::OPTION_FAILURES, 0 ),
			'state'         => (string) get_option( self::OPTION_STATE, 'ok' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Disconnect
	 * ------------------------------------------------------------------ */

	/**
	 * Handle the disconnect admin action.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to disconnect Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ), 403 );
		}

		check_admin_referer( 'jszr_oauth_disconnect' );

		$this->disconnect();

		$this->redirect_with_notice( 'success', __( 'Disconnected from Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ) );
	}

	/**
	 * Revoke the refresh token at Zoho (best effort) and delete local tokens.
	 *
	 * @return void
	 */
	public function disconnect() {
		$refresh = $this->refresh_token();

		if ( '' !== $refresh ) {
			wp_remote_post(
				Settings::accounts_url() . '/oauth/v2/token/revoke',
				array(
					'timeout' => 15,
					'body'    => array( 'token' => $refresh ),
				)
			);
		}

		delete_option( self::OPTION_TOKENS );
		delete_option( self::OPTION_FAILURES );
		delete_option( self::OPTION_STATE );
		delete_transient( 'jszr_fields_cache' );

		Logger::info( 'oauth_disconnected', 'Disconnected from Zoho Recruit.' );
	}

	/* ---------------------------------------------------------------------
	 * Circuit breaker
	 * ------------------------------------------------------------------ */

	/**
	 * Record an authentication failure.
	 *
	 * @param string $reason Machine-readable reason.
	 * @return void
	 */
	public function record_failure( $reason = '' ) {
		$count = (int) get_option( self::OPTION_FAILURES, 0 ) + 1;

		update_option( self::OPTION_FAILURES, $count, false );

		if ( $count >= self::CIRCUIT_THRESHOLD ) {
			update_option( self::OPTION_STATE, 'circuit_open', false );

			Logger::error(
				'auth_circuit_open',
				'Automatic Zoho requests paused after repeated authentication failures.',
				array(
					'failures' => $count,
					'reason'   => $reason,
				)
			);

			Notifications::send_connection_alert( $count );
		} else {
			update_option( self::OPTION_STATE, 'reconnect', false );
		}
	}

	/**
	 * Reset the failure counter after a successful call.
	 *
	 * @return void
	 */
	public function reset_failures() {
		if ( (int) get_option( self::OPTION_FAILURES, 0 ) > 0 ) {
			update_option( self::OPTION_FAILURES, 0, false );
		}

		if ( 'ok' !== (string) get_option( self::OPTION_STATE, 'ok' ) ) {
			update_option( self::OPTION_STATE, 'ok', false );
		}
	}

	/**
	 * Whether automatic retries are paused.
	 *
	 * @return bool
	 */
	public function is_circuit_open() {
		return 'circuit_open' === (string) get_option( self::OPTION_STATE, 'ok' );
	}

	/**
	 * Manually close the circuit (used after the admin fixes credentials).
	 *
	 * @return void
	 */
	public function reset_circuit() {
		$this->reset_failures();
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Perform a token endpoint request.
	 *
	 * @param array $body Request body.
	 * @return array|\WP_Error Decoded response.
	 */
	private function token_request( array $body ) {
		$url = Settings::accounts_url() . '/oauth/v2/token';

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => (int) Settings::get( 'request_timeout', 30 ),
				'redirection' => 0,
				'headers'     => array(
					'Accept' => 'application/json',
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'oauth_http_error', 'Token request failed.', array( 'error' => $response->get_error_message() ) );

			return new \WP_Error(
				'jszr_oauth_http',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Could not reach Zoho: %s', 'jobs-sync-for-zoho-recruit' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			Logger::error( 'oauth_invalid_json', 'Token endpoint returned a non-JSON response.', array( 'status' => $code ) );

			return new \WP_Error(
				'jszr_oauth_invalid_json',
				__( 'Zoho returned an unreadable response to the token request.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		if ( isset( $data['error'] ) ) {
			$error = is_string( $data['error'] ) ? $data['error'] : 'unknown_error';

			Logger::error( 'oauth_error', 'Token endpoint returned an error.', array( 'error' => $error ) );

			return new \WP_Error( 'jszr_oauth_' . sanitize_key( $error ), self::describe_oauth_error( $error ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'jszr_oauth_status_' . $code,
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Zoho returned HTTP %d for the token request.', 'jobs-sync-for-zoho-recruit' ),
					$code
				)
			);
		}

		return $data;
	}

	/**
	 * Translate a Zoho OAuth error code into readable text.
	 *
	 * @param string $error Error code.
	 * @return string
	 */
	public static function describe_oauth_error( $error ) {
		$map = array(
			'invalid_client'     => __( 'The Client ID or Client Secret is incorrect for this data center.', 'jobs-sync-for-zoho-recruit' ),
			'invalid_code'       => __( 'The authorization code was already used or has expired. Please connect again.', 'jobs-sync-for-zoho-recruit' ),
			'invalid_grant'      => __( 'Zoho rejected the grant. The refresh token may have been revoked; please reconnect.', 'jobs-sync-for-zoho-recruit' ),
			'invalid_redirect_uri' => __( 'The redirect URI does not match the one registered in the Zoho API console.', 'jobs-sync-for-zoho-recruit' ),
			'access_denied'      => __( 'Access was denied in the Zoho consent screen.', 'jobs-sync-for-zoho-recruit' ),
			'invalid_scope'      => __( 'One or more requested scopes are not available for this Zoho account.', 'jobs-sync-for-zoho-recruit' ),
			'invalid_token'      => __( 'The stored token is no longer valid. Please reconnect.', 'jobs-sync-for-zoho-recruit' ),
		);

		$key = strtolower( (string) $error );

		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}

		return sprintf(
			/* translators: %s: raw error code from Zoho. */
			__( 'Zoho reported "%s".', 'jobs-sync-for-zoho-recruit' ),
			sanitize_text_field( (string) $error )
		);
	}

	/**
	 * Redirect back to the settings screen with a transient notice.
	 *
	 * @param string $type    "success" or "error".
	 * @param string $message Message text.
	 * @return void
	 */
	private function redirect_with_notice( $type, $message ) {
		set_transient(
			'jszr_admin_notice_' . get_current_user_id(),
			array(
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => $message,
			),
			60
		);

		wp_safe_redirect( jszr_admin_url( 'jszr-settings', array( 'tab' => 'connection' ) ) );
		exit;
	}
}
