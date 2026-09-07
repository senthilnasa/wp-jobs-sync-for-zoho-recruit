<?php
/**
 * Optional webhook receiver for near real-time updates.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts a signed ping from Zoho and uses it only as a trigger.
 *
 * The payload is never trusted as job data: the plugin re-fetches the record
 * from the API using the authenticated connection, so a forged request cannot
 * inject content into the site.
 */
class Webhook {

	/**
	 * Option storing the per-site secret.
	 */
	const OPTION_SECRET = 'jszr_webhook_secret';

	/**
	 * Maximum accepted requests per hour.
	 */
	const RATE_LIMIT = 120;

	/**
	 * Sync engine.
	 *
	 * @var Sync
	 */
	private $sync;

	/**
	 * Constructor.
	 *
	 * @param Sync $sync Sync engine.
	 */
	public function __construct( Sync $sync ) {
		$this->sync = $sync;

		add_action( 'jszr_webhook_sync_record', array( $this, 'process_record_id' ), 10, 1 );
	}

	/**
	 * The site's webhook secret, generating one if needed.
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = (string) get_option( self::OPTION_SECRET, '' );

		if ( '' === $secret ) {
			$secret = wp_generate_password( 40, false, false );
			update_option( self::OPTION_SECRET, $secret, false );
		}

		return $secret;
	}

	/**
	 * Replace the webhook secret.
	 *
	 * @return string The new secret.
	 */
	public static function regenerate_secret() {
		$secret = wp_generate_password( 40, false, false );

		update_option( self::OPTION_SECRET, $secret, false );

		return $secret;
	}

	/**
	 * The URL to paste into Zoho.
	 *
	 * @return string
	 */
	public static function url() {
		return add_query_arg(
			'token',
			self::secret(),
			rest_url( REST_API::NAMESPACE_V1 . '/webhook' )
		);
	}

	/**
	 * Handle an incoming webhook request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( $request ) {
		if ( ! Settings::get( 'webhook_enabled', false ) ) {
			return new \WP_Error(
				'jszr_webhook_disabled',
				__( 'Webhooks are disabled on this site.', 'jobs-sync-for-zoho-recruit' ),
				array( 'status' => 404 )
			);
		}

		$token = (string) $request->get_param( 'token' );

		if ( '' === $token ) {
			$token = (string) $request->get_header( 'x_jszr_token' );
		}

		if ( '' === $token || ! hash_equals( self::secret(), $token ) ) {
			Logger::warning( 'webhook_rejected', 'Rejected a webhook request with an invalid token.' );

			return new \WP_Error(
				'jszr_webhook_forbidden',
				__( 'Invalid webhook token.', 'jobs-sync-for-zoho-recruit' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->within_rate_limit() ) {
			return new \WP_Error(
				'jszr_webhook_rate_limited',
				__( 'Too many webhook requests.', 'jobs-sync-for-zoho-recruit' ),
				array( 'status' => 429 )
			);
		}

		$record_id = $this->extract_record_id( $request );

		if ( '' === $record_id ) {
			// No usable ID: fall back to a queued incremental sync.
			if ( ! Sync_Queue::get_active() && ! Sync_Queue::is_locked() ) {
				$this->sync->start( 'incremental', array( 'trigger' => 'webhook' ) );
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array( 'queued' => 'incremental' ),
				)
			);
		}

		$dedupe_key = 'jszr_webhook_' . md5( $record_id );

		if ( get_transient( $dedupe_key ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array( 'queued' => 'duplicate' ),
				)
			);
		}

		set_transient( $dedupe_key, 1, MINUTE_IN_SECONDS );

		// Do the network round trip on cron, not in Zoho's request.
		wp_schedule_single_event( time() + 5, 'jszr_webhook_sync_record', array( $record_id ) );

		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			spawn_cron();
		}

		Logger::debug( 'webhook_received', 'Queued a single-record refresh from a webhook.', array( 'zoho_id' => $record_id ) );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array( 'queued' => $record_id ),
			)
		);
	}

	/**
	 * Refresh one record after a webhook.
	 *
	 * @param string $record_id Zoho record ID.
	 * @return void
	 */
	public function process_record_id( $record_id ) {
		$result = $this->sync->sync_single( (string) $record_id );

		if ( is_wp_error( $result ) ) {
			Logger::warning(
				'webhook_sync_failed',
				$result->get_error_message(),
				array( 'zoho_id' => Job::sanitize_zoho_id( $record_id ) )
			);

			return;
		}

		Logger::info(
			'webhook_synced',
			'Refreshed a job from a webhook trigger.',
			array(
				'zoho_id' => Job::sanitize_zoho_id( $record_id ),
				'action'  => $result['action'],
			)
		);
	}

	/**
	 * Pull a Zoho record ID out of the request.
	 *
	 * Only the ID is read; every other value in the payload is ignored.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function extract_record_id( $request ) {
		$candidates = array( 'id', 'record_id', 'entity_id', 'Job_Opening_Id', 'job_id' );

		foreach ( $candidates as $key ) {
			$value = $request->get_param( $key );

			if ( is_scalar( $value ) ) {
				$id = Job::sanitize_zoho_id( $value );

				if ( '' !== $id ) {
					return $id;
				}
			}
		}

		$body = $request->get_json_params();

		if ( is_array( $body ) ) {
			foreach ( $candidates as $key ) {
				if ( isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
					$id = Job::sanitize_zoho_id( $body[ $key ] );

					if ( '' !== $id ) {
						return $id;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Simple hourly rate limit.
	 *
	 * @return bool
	 */
	private function within_rate_limit() {
		$key   = 'jszr_webhook_rate';
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			Logger::warning( 'webhook_rate_limited', 'Webhook rate limit reached.', array( 'count' => $count ) );

			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}
}
