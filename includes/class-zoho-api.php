<?php
/**
 * Zoho Recruit API v2 client.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Thin, defensive wrapper around the Zoho Recruit REST API.
 *
 * Everything goes through the WordPress HTTP API so proxies, cURL-less hosts
 * and request filters all keep working.
 */
class Zoho_API {

	/**
	 * Maximum retry attempts for transient failures.
	 */
	const MAX_RETRIES = 3;

	/**
	 * Longest we will ever sleep between retries, in seconds.
	 */
	const MAX_BACKOFF = 8;

	/**
	 * OAuth handler.
	 *
	 * @var Zoho_Auth
	 */
	private $auth;

	/**
	 * Constructor.
	 *
	 * @param Zoho_Auth $auth OAuth handler.
	 */
	public function __construct( Zoho_Auth $auth ) {
		$this->auth = $auth;
	}

	/**
	 * The Job Openings module API name.
	 *
	 * @return string
	 */
	public function module() {
		/**
		 * Filter the Zoho module API name used for job openings.
		 *
		 * Some Zoho Recruit editions expose a different API name.
		 *
		 * @param string $module Module API name.
		 */
		$module = (string) apply_filters( 'jszr_module_name', 'JobOpenings' );

		return Settings::sanitize_api_name( $module );
	}

	/**
	 * Fetch one page of job openings.
	 *
	 * @param array $params Query parameters (page, per_page, fields, sort_by...).
	 * @param array $options Request options: modified_since.
	 * @return array|\WP_Error {
	 *     @type array $records Record rows.
	 *     @type array $info    Zoho "info" block.
	 *     @type bool  $not_modified True when Zoho returned 304.
	 * }
	 */
	public function get_records( array $params = array(), array $options = array() ) {
		$defaults = array(
			'page'     => 1,
			'per_page' => (int) Settings::get( 'per_request', 200 ),
		);

		$params = array_merge( $defaults, $params );

		$params['per_page'] = max( 1, min( 200, (int) $params['per_page'] ) );
		$params['page']     = max( 1, (int) $params['page'] );

		$headers = array();

		if ( ! empty( $options['modified_since'] ) ) {
			$headers['If-Modified-Since'] = (string) $options['modified_since'];
		}

		$response = $this->request( 'GET', '/' . $this->module(), $params, array( 'headers' => $headers ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 304 === (int) $response['status'] ) {
			return array(
				'records'      => array(),
				'info'         => array( 'more_records' => false ),
				'not_modified' => true,
			);
		}

		if ( 204 === (int) $response['status'] ) {
			return array(
				'records'      => array(),
				'info'         => array( 'more_records' => false ),
				'not_modified' => false,
			);
		}

		$body    = is_array( $response['body'] ) ? $response['body'] : array();
		$records = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
		$info    = isset( $body['info'] ) && is_array( $body['info'] ) ? $body['info'] : array();

		return array(
			'records'      => $records,
			'info'         => $info,
			'not_modified' => false,
		);
	}

	/**
	 * Fetch a single job opening by Zoho record ID.
	 *
	 * @param string $record_id Zoho record ID.
	 * @return array|\WP_Error Record array, or WP_Error. Empty array when absent.
	 */
	public function get_record( $record_id ) {
		$record_id = preg_replace( '/[^0-9A-Za-z]/', '', (string) $record_id );

		if ( '' === $record_id ) {
			return new \WP_Error( 'jszr_invalid_record_id', __( 'Invalid Zoho record ID.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$response = $this->request( 'GET', '/' . $this->module() . '/' . $record_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( in_array( (int) $response['status'], array( 204, 304 ), true ) ) {
			return array();
		}

		$body = is_array( $response['body'] ) ? $response['body'] : array();

		if ( isset( $body['data'][0] ) && is_array( $body['data'][0] ) ) {
			return $body['data'][0];
		}

		return array();
	}

	/**
	 * Fetch records deleted in Zoho.
	 *
	 * @param array $params Query parameters (page, per_page, type).
	 * @param array $options Request options: modified_since.
	 * @return array|\WP_Error {
	 *     @type array $records Deleted record stubs (id, deleted_time...).
	 *     @type array $info    Zoho "info" block.
	 * }
	 */
	public function get_deleted_records( array $params = array(), array $options = array() ) {
		$params = array_merge(
			array(
				'type'     => 'all',
				'page'     => 1,
				'per_page' => 200,
			),
			$params
		);

		$headers = array();

		if ( ! empty( $options['modified_since'] ) ) {
			$headers['If-Modified-Since'] = (string) $options['modified_since'];
		}

		$response = $this->request( 'GET', '/' . $this->module() . '/deleted', $params, array( 'headers' => $headers ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( in_array( (int) $response['status'], array( 204, 304 ), true ) ) {
			return array(
				'records' => array(),
				'info'    => array( 'more_records' => false ),
			);
		}

		$body = is_array( $response['body'] ) ? $response['body'] : array();

		return array(
			'records' => isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array(),
			'info'    => isset( $body['info'] ) && is_array( $body['info'] ) ? $body['info'] : array(),
		);
	}

	/**
	 * Fetch field metadata for the job openings module.
	 *
	 * @return array|\WP_Error List of field definitions.
	 */
	public function get_fields() {
		$response = $this->request( 'GET', '/settings/fields', array( 'module' => $this->module() ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = is_array( $response['body'] ) ? $response['body'] : array();

		return isset( $body['fields'] ) && is_array( $body['fields'] ) ? $body['fields'] : array();
	}

	/**
	 * Lightweight connectivity check.
	 *
	 * @return array|\WP_Error Summary array on success.
	 */
	public function test_connection() {
		$result = $this->get_records( array( 'per_page' => 1 ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$info = isset( $result['info'] ) ? $result['info'] : array();

		return array(
			'module'   => $this->module(),
			'api_base' => $this->auth->api_base(),
			'count'    => isset( $info['count'] ) ? (int) $info['count'] : count( $result['records'] ),
			'sample'   => ! empty( $result['records'] ),
		);
	}

	/**
	 * Perform an authenticated API request with retries.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path below /recruit/v2.
	 * @param array  $query  Query parameters.
	 * @param array  $args   Extra options: headers.
	 * @return array|\WP_Error {
	 *     @type int   $status HTTP status code.
	 *     @type array $body   Decoded JSON body.
	 * }
	 */
	public function request( $method, $path, array $query = array(), array $args = array() ) {
		$attempt      = 0;
		$token_forced = false;

		while ( true ) {
			++$attempt;

			$token = $this->auth->get_access_token( $token_forced );

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$url = $this->auth->api_base() . '/recruit/v2' . $path;

			if ( ! empty( $query ) ) {
				$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
			}

			$request_args = array(
				'method'      => strtoupper( $method ),
				'timeout'     => (int) Settings::get( 'request_timeout', 30 ),
				'redirection' => 2,
				'headers'     => array_merge(
					array(
						'Authorization' => 'Zoho-oauthtoken ' . $token,
						'Accept'        => 'application/json',
					),
					isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array()
				),
			);

			if ( isset( $args['body'] ) ) {
				$request_args['body']                    = wp_json_encode( $args['body'] );
				$request_args['headers']['Content-Type'] = 'application/json';
			}

			/**
			 * Filter the arguments passed to the WordPress HTTP API.
			 *
			 * @param array  $request_args Request arguments.
			 * @param string $path         API path being requested.
			 */
			$request_args = (array) apply_filters( 'jszr_api_request_args', $request_args, $path );

			$response = wp_remote_request( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				if ( $attempt <= self::MAX_RETRIES ) {
					$this->backoff( $attempt );
					continue;
				}

				Logger::error(
					'api_transport_error',
					'Zoho request failed at the transport level.',
					array(
						'path'    => $path,
						'attempt' => $attempt,
						'error'   => $response->get_error_message(),
					)
				);

				return new \WP_Error(
					'jszr_api_transport',
					sprintf(
						/* translators: %s: transport error message. */
						__( 'Could not reach the Zoho Recruit API: %s', 'jobs-sync-for-zoho-recruit' ),
						$response->get_error_message()
					)
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );

			Logger::debug(
				'api_request',
				'Zoho API request completed.',
				array(
					'path'    => $path,
					'status'  => $status,
					'attempt' => $attempt,
					'query'   => $query,
				)
			);

			// Success without content.
			if ( 204 === $status || 304 === $status ) {
				$this->auth->reset_failures();

				return array(
					'status' => $status,
					'body'   => array(),
				);
			}

			$body = json_decode( $raw, true );

			if ( ! is_array( $body ) ) {
				$body = array();
			}

			/**
			 * Filter a decoded Zoho API response body.
			 *
			 * @param array  $body   Decoded body.
			 * @param int    $status HTTP status.
			 * @param string $path   Requested path.
			 */
			$body = (array) apply_filters( 'jszr_api_response', $body, $status, $path );

			if ( $status >= 200 && $status < 300 ) {
				$error = $this->body_error( $body );

				if ( null !== $error ) {
					return $error;
				}

				$this->auth->reset_failures();

				return array(
					'status' => $status,
					'body'   => $body,
				);
			}

			// Expired or invalid token: refresh once, then give up.
			if ( 401 === $status && ! $token_forced ) {
				$token_forced = true;
				continue;
			}

			if ( 429 === $status && $attempt <= self::MAX_RETRIES ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$this->backoff( $attempt, $retry_after );
				continue;
			}

			if ( $status >= 500 && $attempt <= self::MAX_RETRIES ) {
				$this->backoff( $attempt );
				continue;
			}

			return $this->http_error( $status, $body, $path );
		}
	}

	/**
	 * Convert a Zoho JSON error body into a WP_Error, when present.
	 *
	 * @param array $body Decoded body.
	 * @return \WP_Error|null
	 */
	private function body_error( array $body ) {
		if ( ! isset( $body['status'] ) || 'error' !== $body['status'] ) {
			return null;
		}

		$code = isset( $body['code'] ) ? (string) $body['code'] : 'UNKNOWN';

		if ( $this->is_auth_code( $code ) ) {
			$this->auth->record_failure( $code );
		}

		return new \WP_Error(
			'jszr_api_' . strtolower( sanitize_key( $code ) ),
			$this->describe_code( $code, isset( $body['message'] ) ? (string) $body['message'] : '' )
		);
	}

	/**
	 * Build a WP_Error for a non-2xx response.
	 *
	 * @param int    $status HTTP status.
	 * @param array  $body   Decoded body.
	 * @param string $path   Requested path.
	 * @return \WP_Error
	 */
	private function http_error( $status, array $body, $path ) {
		$code = isset( $body['code'] ) ? (string) $body['code'] : '';

		if ( 401 === $status || $this->is_auth_code( $code ) ) {
			$this->auth->record_failure( '' !== $code ? $code : 'http_401' );
		}

		$messages = array(
			400 => __( 'Zoho rejected the request as invalid.', 'jobs-sync-for-zoho-recruit' ),
			401 => __( 'Zoho rejected the access token. Please reconnect Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ),
			403 => __( 'Access to this Zoho module was refused. Check the OAuth scopes and the user profile permissions.', 'jobs-sync-for-zoho-recruit' ),
			404 => __( 'The Zoho endpoint or record was not found. Check the module API name.', 'jobs-sync-for-zoho-recruit' ),
			429 => __( 'Zoho rate limit reached. The sync will resume automatically later.', 'jobs-sync-for-zoho-recruit' ),
			500 => __( 'Zoho reported an internal error.', 'jobs-sync-for-zoho-recruit' ),
			503 => __( 'The Zoho Recruit API is temporarily unavailable.', 'jobs-sync-for-zoho-recruit' ),
		);

		$message = isset( $messages[ $status ] )
			? $messages[ $status ]
			: sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Zoho returned HTTP %d.', 'jobs-sync-for-zoho-recruit' ),
				$status
			);

		if ( '' !== $code ) {
			$message = $this->describe_code( $code, $message );
		}

		Logger::error(
			'api_http_error',
			'Zoho API returned an error status.',
			array(
				'path'   => $path,
				'status' => $status,
				'code'   => $code,
			)
		);

		return new \WP_Error( 'jszr_api_http_' . $status, $message, array( 'status' => $status ) );
	}

	/**
	 * Whether a Zoho error code indicates an authentication problem.
	 *
	 * @param string $code Zoho error code.
	 * @return bool
	 */
	private function is_auth_code( $code ) {
		return in_array(
			strtoupper( (string) $code ),
			array( 'INVALID_TOKEN', 'AUTHENTICATION_FAILURE', 'OAUTH_SCOPE_MISMATCH', 'INVALID_OAUTHTOKEN', 'AUTHORIZATION_FAILED' ),
			true
		);
	}

	/**
	 * Human-readable text for a Zoho error code.
	 *
	 * @param string $code     Zoho error code.
	 * @param string $fallback Message to use when the code is unknown.
	 * @return string
	 */
	private function describe_code( $code, $fallback = '' ) {
		$map = array(
			'INVALID_TOKEN'          => __( 'The Zoho access token is invalid. Please reconnect Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ),
			'AUTHENTICATION_FAILURE' => __( 'Zoho could not authenticate this request. Please reconnect Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ),
			'OAUTH_SCOPE_MISMATCH'   => __( 'The connection is missing a required scope. Disconnect and reconnect to grant the requested permissions.', 'jobs-sync-for-zoho-recruit' ),
			'INVALID_DATA'           => __( 'Zoho rejected one of the request parameters as invalid.', 'jobs-sync-for-zoho-recruit' ),
			'INVALID_MODULE'         => __( 'The configured Zoho module does not exist in this account.', 'jobs-sync-for-zoho-recruit' ),
			'INVALID_URL_PATTERN'    => __( 'The API endpoint is not valid for this Zoho account.', 'jobs-sync-for-zoho-recruit' ),
			'NO_PERMISSION'          => __( 'The connected Zoho user does not have permission to read job openings.', 'jobs-sync-for-zoho-recruit' ),
			'TOO_MANY_REQUESTS'      => __( 'Zoho rate limit reached. The sync will resume automatically later.', 'jobs-sync-for-zoho-recruit' ),
			'INTERNAL_ERROR'         => __( 'Zoho reported an internal error.', 'jobs-sync-for-zoho-recruit' ),
		);

		$key = strtoupper( (string) $code );

		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}

		if ( '' !== $fallback ) {
			return $fallback;
		}

		return sprintf(
			/* translators: %s: Zoho error code. */
			__( 'Zoho reported error code %s.', 'jobs-sync-for-zoho-recruit' ),
			sanitize_text_field( (string) $code )
		);
	}

	/**
	 * Sleep between retries.
	 *
	 * @param int $attempt     1-based attempt number.
	 * @param int $retry_after Server-provided delay in seconds.
	 * @return void
	 */
	private function backoff( $attempt, $retry_after = 0 ) {
		$seconds = $retry_after > 0 ? $retry_after : (int) pow( 2, max( 0, $attempt - 1 ) );
		$seconds = max( 1, min( self::MAX_BACKOFF, $seconds ) );

		/**
		 * Filter the retry backoff duration.
		 *
		 * Returning 0 disables sleeping, which is useful in tests.
		 *
		 * @param int $seconds Seconds to wait.
		 * @param int $attempt Attempt number.
		 */
		$seconds = (int) apply_filters( 'jszr_retry_backoff', $seconds, $attempt );

		if ( $seconds > 0 ) {
			sleep( $seconds );
		}
	}
}
