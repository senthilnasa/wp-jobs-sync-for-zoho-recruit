<?php
/**
 * Synchronization engine.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what happens to each Zoho record and drives the batched run.
 *
 * The single most important rule enforced here: jobs are only deactivated for
 * being "missing" after a full sync has read every page without error.
 */
class Sync {

	/**
	 * Option storing the last successful sync timestamps.
	 */
	const OPTION_STATE = 'jszr_sync_state';

	/**
	 * Whether a sync is currently writing posts.
	 *
	 * @var bool
	 */
	private static $syncing = false;

	/**
	 * API client.
	 *
	 * @var Zoho_API
	 */
	private $api;

	/**
	 * Field mapper.
	 *
	 * @var Field_Mapper
	 */
	private $mapper;

	/**
	 * Run storage.
	 *
	 * @var Sync_Queue
	 */
	private $queue;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Wall-clock start of the batch currently being processed.
	 *
	 * @var float
	 */
	private $batch_started = 0.0;

	/**
	 * Constructor.
	 *
	 * @param Zoho_API     $api    API client.
	 * @param Field_Mapper $mapper Field mapper.
	 * @param Sync_Queue   $queue  Run storage.
	 * @param Logger       $logger Logger.
	 */
	public function __construct( Zoho_API $api, Field_Mapper $mapper, Sync_Queue $queue, Logger $logger ) {
		$this->api    = $api;
		$this->mapper = $mapper;
		$this->queue  = $queue;
		$this->logger = $logger;

		add_action( 'jszr_check_expired', array( $this, 'expire_due_jobs' ) );
	}

	/**
	 * Whether a sync is writing right now.
	 *
	 * @return bool
	 */
	public static function is_syncing() {
		return self::$syncing;
	}

	/**
	 * Start a sync run.
	 *
	 * @param string $type full|incremental.
	 * @param array  $args Optional: dry_run, trigger.
	 * @return int|\WP_Error Run ID.
	 */
	public function start( $type = 'full', array $args = array() ) {
		$type = in_array( $type, array( 'full', 'incremental' ), true ) ? $type : 'full';

		$auth = plugin()->auth();

		if ( ! $auth->is_connected() ) {
			return new \WP_Error( 'jszr_not_connected', __( 'Connect Zoho Recruit before running a sync.', 'jobs-sync-for-zoho-recruit' ) );
		}

		if ( $auth->is_circuit_open() ) {
			return new \WP_Error( 'jszr_circuit_open', __( 'Syncing is paused after repeated authentication failures. Reconnect Zoho Recruit to resume.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$active = Sync_Queue::get_active();

		if ( $active ) {
			return new \WP_Error(
				'jszr_sync_running',
				__( 'A sync is already running. Wait for it to finish or cancel it first.', 'jobs-sync-for-zoho-recruit' ),
				array( 'run_id' => (int) $active->id )
			);
		}

		if ( Sync_Queue::is_locked() ) {
			return new \WP_Error( 'jszr_sync_locked', __( 'Another sync process holds the lock. Try again shortly.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$create_args = array(
			'dry_run' => ! empty( $args['dry_run'] ),
			'trigger' => isset( $args['trigger'] ) ? $args['trigger'] : 'manual',
		);

		if ( 'incremental' === $type ) {
			$since = $this->last_successful_time();

			if ( '' !== $since ) {
				$create_args['modified_since'] = $since;
			}
		}

		$run_id = Sync_Queue::create( $type, $create_args );

		if ( ! $run_id ) {
			return new \WP_Error( 'jszr_run_not_created', __( 'The sync could not be started because its run record could not be stored.', 'jobs-sync-for-zoho-recruit' ) );
		}

		if ( ! Sync_Queue::acquire_lock( $run_id ) ) {
			Sync_Queue::finish( $run_id, 'failed', __( 'Could not acquire the sync lock.', 'jobs-sync-for-zoho-recruit' ) );

			return new \WP_Error( 'jszr_sync_locked', __( 'Another sync process holds the lock. Try again shortly.', 'jobs-sync-for-zoho-recruit' ) );
		}

		Logger::info(
			'sync_started',
			sprintf( 'Started a %s sync.', $type ),
			array(
				'dry_run' => ! empty( $args['dry_run'] ),
				'trigger' => $create_args['trigger'],
			),
			$run_id
		);

		/**
		 * Fires when a sync run begins.
		 *
		 * @param string $type   Sync type.
		 * @param int    $run_id Run ID.
		 */
		do_action( 'jszr_sync_started', $type, $run_id );

		Sync_Queue::schedule_batch( $run_id, 1 );

		return $run_id;
	}

	/**
	 * Run a sync to completion in the current process.
	 *
	 * Used by WP-CLI, where batching across requests would be pointless.
	 *
	 * @param string $type full|incremental.
	 * @param array  $args Optional: dry_run, trigger.
	 * @return array|\WP_Error Statistics.
	 */
	public function run_now( $type = 'full', array $args = array() ) {
		$run_id = $this->start( $type, $args );

		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}

		// The scheduled first batch is redundant when running inline.
		wp_clear_scheduled_hook( Sync_Queue::BATCH_HOOK, array( (int) $run_id ) );

		$guard = 0;

		do {
			$this->process_batch( (int) $run_id, true );

			$run = Sync_Queue::get( $run_id );
			++$guard;
		} while ( $run && in_array( $run->state, array( 'pending', 'running' ), true ) && $guard < 10000 );

		wp_clear_scheduled_hook( Sync_Queue::BATCH_HOOK, array( (int) $run_id ) );

		return Sync_Queue::stats( Sync_Queue::get( $run_id ) );
	}

	/**
	 * Process one batch of a run.
	 *
	 * @param int  $run_id Run ID.
	 * @param bool $inline Whether the caller drives the loop itself.
	 * @return void
	 */
	public function process_batch( $run_id, $inline = false ) {
		$run_id = (int) $run_id;
		$run    = Sync_Queue::get( $run_id );

		if ( ! $run || ! in_array( $run->state, array( 'pending', 'running' ), true ) ) {
			return;
		}

		if ( ! Sync_Queue::acquire_lock( $run_id ) ) {
			// Someone else holds the lock; try again shortly.
			if ( ! $inline ) {
				Sync_Queue::schedule_batch( $run_id, 60 );
			}

			return;
		}

		Sync_Queue::touch_lock( $run_id );

		if ( 'pending' === $run->state ) {
			Sync_Queue::update( $run_id, array( 'state' => 'running' ) );
			$run->state = 'running';
		}

		self::$syncing       = true;
		$this->batch_started = microtime( true );

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		wp_suspend_cache_addition( true );

		try {
			if ( 0 === (int) $run->page ) {
				$this->finalize( $run );

				return;
			}

			$this->process_page( $run, $inline );
		} catch ( \Throwable $error ) {
			Logger::error(
				'sync_exception',
				'The sync stopped with an unexpected error.',
				array( 'error' => $error->getMessage() ),
				$run_id
			);

			$this->fail( $run, $error->getMessage() );
		} finally {
			wp_suspend_cache_addition( false );
			wp_defer_comment_counting( false );
			wp_defer_term_counting( false );

			self::$syncing = false;
		}
	}

	/**
	 * Fetch and process one API page.
	 *
	 * @param object $run    Run row.
	 * @param bool   $inline Whether the caller drives the loop.
	 * @return void
	 */
	private function process_page( $run, $inline ) {
		$run_id  = (int) $run->id;
		$page    = max( 1, (int) $run->page );
		$offset  = max( 0, (int) $run->page_offset );
		$dry_run = (bool) $run->dry_run;

		$options = array();

		if ( 'incremental' === $run->type && '' !== (string) $run->modified_since ) {
			$options['modified_since'] = (string) $run->modified_since;
		}

		$result = $this->api->get_records(
			array(
				'page'     => $page,
				'per_page' => (int) $run->per_page,
			),
			$options
		);

		if ( is_wp_error( $result ) ) {
			$this->handle_page_error( $run, $result, $inline );

			return;
		}

		$records = isset( $result['records'] ) ? (array) $result['records'] : array();
		$info    = isset( $result['info'] ) ? (array) $result['info'] : array();

		if ( isset( $info['count'] ) && 1 === $page ) {
			Sync_Queue::update( $run_id, array( 'total' => (int) $info['count'] ) );
		}

		/*
		 * A batch reads one API page and then writes up to batch_size records
		 * from it, so a batch size below the page size makes the same page be
		 * fetched again for each remaining chunk. At the defaults the two match
		 * and each page costs one request; lowering the batch size shortens each
		 * background request at the cost of extra reads against Zoho's daily
		 * API credits.
		 */
		$batch_size = max( 1, (int) Settings::get( 'batch_size', 200 ) );
		$processed  = 0;
		$seen       = array();
		$stats      = array(
			'processed'     => 0,
			'created_count' => 0,
			'updated_count' => 0,
			'skipped_count' => 0,
			'error_count'   => 0,
		);

		$total_records = count( $records );
		$index         = $offset;

		for ( ; $index < $total_records; $index++ ) {
			if ( $processed >= $batch_size || $this->should_yield() ) {
				break;
			}

			$record  = is_array( $records[ $index ] ) ? $records[ $index ] : array();
			$zoho_id = Job::sanitize_zoho_id( $record['id'] ?? '' );

			if ( '' !== $zoho_id ) {
				$seen[] = $zoho_id;
			}

			$outcome = $this->process_record( $record, $run_id, $dry_run );

			switch ( $outcome ) {
				case 'created':
					++$stats['created_count'];
					break;
				case 'updated':
					++$stats['updated_count'];
					break;
				case 'error':
					++$stats['error_count'];
					break;
				default:
					++$stats['skipped_count'];
					break;
			}

			++$stats['processed'];
			++$processed;
		}

		Sync_Queue::add_seen( $run_id, $seen );
		Sync_Queue::increment( $run_id, $stats );
		Sync_Queue::touch_lock( $run_id );

		$page_finished = $index >= $total_records;

		if ( ! $page_finished ) {
			// Stopped early inside the page; resume from the same offset.
			Sync_Queue::update( $run_id, array( 'page_offset' => $index ) );

			if ( ! $inline ) {
				Sync_Queue::schedule_batch( $run_id, 5 );
			}

			return;
		}

		$more = ! empty( $info['more_records'] ) && ! empty( $records );

		if ( $more ) {
			Sync_Queue::update(
				$run_id,
				array(
					'page'        => $page + 1,
					'page_offset' => 0,
				)
			);
		} else {
			// Page 0 marks the finalization stage.
			Sync_Queue::update(
				$run_id,
				array(
					'page'        => 0,
					'page_offset' => 0,
				)
			);
		}

		delete_transient( 'jszr_run_retries_' . $run_id );

		if ( ! $inline ) {
			Sync_Queue::schedule_batch( $run_id, 2 );
		}
	}

	/**
	 * Handle a failed page fetch.
	 *
	 * @param object    $run    Run row.
	 * @param \WP_Error $error  Error.
	 * @param bool      $inline Whether the caller drives the loop.
	 * @return void
	 */
	private function handle_page_error( $run, \WP_Error $error, $inline ) {
		$run_id  = (int) $run->id;
		$key     = 'jszr_run_retries_' . $run_id;
		$retries = (int) get_transient( $key );

		$retriable = in_array(
			$error->get_error_code(),
			array( 'jszr_api_transport', 'jszr_api_http_429', 'jszr_api_http_500', 'jszr_api_http_502', 'jszr_api_http_503', 'jszr_api_http_504' ),
			true
		);

		Sync_Queue::increment( $run_id, array( 'error_count' => 1 ) );

		Logger::error(
			'sync_page_failed',
			$error->get_error_message(),
			array(
				'page'    => (int) $run->page,
				'code'    => $error->get_error_code(),
				'retries' => $retries,
			),
			$run_id
		);

		if ( $retriable && $retries < 3 && ! $inline ) {
			set_transient( $key, $retries + 1, HOUR_IN_SECONDS );

			Sync_Queue::schedule_batch( $run_id, min( 300, 30 * ( $retries + 1 ) ) );

			return;
		}

		$this->fail( $run, $error->get_error_message() );
	}

	/**
	 * Decide what to do with a single Zoho record and write it.
	 *
	 * @param array $record  Raw Zoho record.
	 * @param int   $run_id  Run ID.
	 * @param bool  $dry_run Whether to skip writes.
	 * @param bool  $force   Overwrite fields even when they were edited in
	 *                       WordPress. Only ever set by an explicit
	 *                       administrator action, never by a scheduled sync.
	 * @return string created|updated|skipped|error
	 */
	public function process_record( array $record, $run_id = 0, $dry_run = false, $force = false ) {
		$zoho_id = Job::sanitize_zoho_id( $record['id'] ?? '' );

		if ( '' === $zoho_id ) {
			Logger::warning( 'record_missing_id', 'Skipped a Zoho record with no ID.', array(), $run_id );

			return 'error';
		}

		/**
		 * Filter whether a Zoho record should be synced at all.
		 *
		 * @param bool  $should_sync Whether to sync.
		 * @param array $record      Raw Zoho record.
		 */
		if ( ! apply_filters( 'jszr_should_sync_record', true, $record ) ) {
			return 'skipped';
		}

		$status = $this->determine_status( $record );

		if ( 'skip' === $status ) {
			return 'skipped';
		}

		// A job that is not published to the website and has never been imported
		// stays out of WordPress entirely — importing it would only add a hidden
		// post nobody asked for. One that already exists is deactivated instead,
		// so losing the flag never deletes content.
		if ( self::is_unpublished( $record ) && ! Job::find_by_zoho_id( $zoho_id ) ) {
			return 'skipped';
		}

		$payload = $this->mapper->map( $record );

		// Expiry is decided from the mapped closing date, not from Zoho's status.
		if ( 'active' === $status && $this->is_past_closing( $payload ) ) {
			$status = 'expired';
		}

		if ( $dry_run ) {
			$existing = Job::find_by_zoho_id( $zoho_id );

			return $existing ? 'updated' : 'created';
		}

		$result = Job::upsert(
			$zoho_id,
			$payload,
			array(
				'record' => $record,
				'status' => $status,
				'force'  => (bool) $force,
			)
		);

		if ( is_wp_error( $result ) ) {
			Logger::error(
				'record_write_failed',
				$result->get_error_message(),
				array( 'zoho_id' => $zoho_id ),
				$run_id
			);

			return 'error';
		}

		$post_id = (int) $result['post_id'];

		/**
		 * Fires after a job has been written from a Zoho record.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $record  Raw Zoho record.
		 */
		do_action( 'jszr_job_synced', $post_id, $record );

		if ( 'created' === $result['action'] ) {
			/**
			 * Fires after a new job has been created.
			 *
			 * @param int   $post_id Post ID.
			 * @param array $record  Raw Zoho record.
			 */
			do_action( 'jszr_job_created', $post_id, $record );
		} else {
			/**
			 * Fires after an existing job has been updated.
			 *
			 * @param int   $post_id Post ID.
			 * @param array $record  Raw Zoho record.
			 */
			do_action( 'jszr_job_updated', $post_id, $record );
		}

		return $result['action'];
	}

	/**
	 * Work out the normalised status for a record.
	 *
	 * @param array $record Raw Zoho record.
	 * @return string active|inactive|draft|closed|expired|skip
	 */
	public function determine_status( array $record ) {
		$status = (string) Settings::get( 'default_status', 'active' );

		$raw_status = '';
		$field      = $this->status_field();

		if ( '' !== $field && isset( $record[ $field ] ) ) {
			$raw_status = trim( Field_Mapper::stringify( $record[ $field ] ) );
		}

		if ( '' !== $raw_status ) {
			$map = (array) Settings::get( 'status_map', array() );

			/**
			 * Filter the Zoho status to local status map.
			 *
			 * @param array $map Zoho status => local status.
			 */
			$map = (array) apply_filters( 'jszr_status_mapping', $map );

			$matched = null;

			foreach ( $map as $zoho_status => $local ) {
				if ( 0 === strcasecmp( (string) $zoho_status, $raw_status ) ) {
					$matched = (string) $local;
					break;
				}
			}

			if ( null !== $matched ) {
				$status = $matched;
			}
		}

		// The "publish to website" flag overrides an otherwise active status.
		if ( self::is_unpublished( $record ) ) {
			$status = 'inactive';
		}

		/**
		 * Filter the normalised status decided for a record.
		 *
		 * @param string $status Normalised status.
		 * @param array  $record Raw Zoho record.
		 */
		return (string) apply_filters( 'jszr_record_status', $status, $record );
	}

	/**
	 * Whether the "publish to website" filter rules this record out.
	 *
	 * Returns false when the filter is off, when no field is configured, or when
	 * the record does not carry the field at all — an absent field is not
	 * evidence that a job is unpublished.
	 *
	 * @param array $record Raw Zoho record.
	 * @return bool
	 */
	public static function is_unpublished( array $record ) {
		if ( ! Settings::get( 'only_published', true ) ) {
			return false;
		}

		$flag_field = Settings::sanitize_api_name( (string) Settings::get( 'published_field', '' ) );

		if ( '' === $flag_field || ! array_key_exists( $flag_field, $record ) ) {
			return false;
		}

		return ! Field_Mapper::to_boolean( $record[ $flag_field ] );
	}

	/**
	 * The Zoho field holding the job status, based on the mapping.
	 *
	 * @return string
	 */
	private function status_field() {
		foreach ( Field_Mapper::get_mapping() as $row ) {
			if ( 'meta:_zoho_recruit_status' === $row['target'] ) {
				return $row['zoho_field'];
			}
		}

		return 'Job_Opening_Status';
	}

	/**
	 * Whether a mapped payload's closing date has passed.
	 *
	 * @param array $payload Mapped payload.
	 * @return bool
	 */
	private function is_past_closing( array $payload ) {
		$closing = isset( $payload['meta']['_zoho_recruit_closing_date'] )
			? (string) $payload['meta']['_zoho_recruit_closing_date']
			: '';

		if ( '' === $closing ) {
			return false;
		}

		try {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $closing ) ) {
				$date = new \DateTimeImmutable( $closing . ' 23:59:59', wp_timezone() );
			} else {
				$date = new \DateTimeImmutable( $closing );
			}
		} catch ( \Exception $e ) {
			return false;
		}

		return $date->getTimestamp() < time();
	}

	// ----------------------------------------------------------------------
	// Finalization
	// ----------------------------------------------------------------------

	/**
	 * Run the closing stage of a sync.
	 *
	 * @param object $run Run row.
	 * @return void
	 */
	private function finalize( $run ) {
		$run_id  = (int) $run->id;
		$dry_run = (bool) $run->dry_run;

		$orphaned = $this->process_deleted_records( $run, $dry_run );

		if ( is_wp_error( $orphaned ) ) {
			// A failure here must not cause mass deactivation.
			Logger::warning( 'deleted_check_failed', $orphaned->get_error_message(), array(), $run_id );
			$orphaned = 0;
		}

		$expired = $this->expire_due_jobs( $dry_run );

		$deactivated = 0;
		$state       = 'completed';
		$message     = '';

		if ( 'full' === $run->type ) {
			$outcome = $this->deactivate_missing( $run, $dry_run );

			if ( is_wp_error( $outcome ) ) {
				$state   = 'partial';
				$message = $outcome->get_error_message();

				Logger::warning( 'deactivation_blocked', $message, array(), $run_id );

				Notifications::send_threshold_alert( $message );
			} else {
				$deactivated = (int) $outcome;
			}
		}

		Sync_Queue::increment(
			$run_id,
			array(
				'deactivated_count' => $deactivated,
				'expired_count'     => $expired,
				'orphaned_count'    => is_numeric( $orphaned ) ? (int) $orphaned : 0,
			)
		);

		Sync_Queue::finish( $run_id, $state, $message );

		if ( ! $dry_run && 'completed' === $state ) {
			$this->record_success( (string) $run->type );
		}

		$stats = Sync_Queue::stats( Sync_Queue::get( $run_id ) );

		Logger::info( 'sync_completed', 'Sync finished.', $stats, $run_id );

		$this->invalidate_caches();

		/**
		 * Fires when a sync run finishes successfully (or partially).
		 *
		 * @param array $stats Run statistics.
		 */
		do_action( 'jszr_sync_completed', $stats );
	}

	/**
	 * Deactivate jobs that were not seen during a completed full sync.
	 *
	 * @param object $run     Run row.
	 * @param bool   $dry_run Whether to skip writes.
	 * @return int|\WP_Error Number deactivated, or WP_Error when blocked.
	 */
	private function deactivate_missing( $run, $dry_run ) {
		$seen     = Sync_Queue::get_seen( (int) $run->id );
		$existing = $this->get_synced_job_map();

		if ( empty( $existing ) ) {
			return 0;
		}

		$missing = array();

		foreach ( $existing as $zoho_id => $post_id ) {
			if ( ! in_array( (string) $zoho_id, $seen, true ) ) {
				$missing[ (string) $zoho_id ] = (int) $post_id;
			}
		}

		if ( empty( $missing ) ) {
			return 0;
		}

		$threshold = (int) Settings::get( 'deactivation_threshold', 50 );
		$ratio     = ( count( $missing ) / max( 1, count( $existing ) ) ) * 100;

		if ( $ratio > $threshold ) {
			return new \WP_Error(
				'jszr_threshold_exceeded',
				sprintf(
					/* translators: 1: percentage of jobs, 2: configured threshold percentage, 3: number of jobs. */
					__( 'Deactivation was skipped: the sync would have deactivated %1$d%% of jobs (%3$d jobs), which is above the %2$d%% safety threshold. Check the Zoho connection and filters, then run a full sync again.', 'jobs-sync-for-zoho-recruit' ),
					(int) round( $ratio ),
					$threshold,
					count( $missing )
				)
			);
		}

		if ( $dry_run ) {
			return count( $missing );
		}

		$count = 0;

		foreach ( $missing as $post_id ) {
			if ( Job::deactivate( $post_id, 'missing_from_full_sync' ) ) {
				++$count;
			}
		}

		if ( $count > 0 ) {
			Logger::info(
				'jobs_deactivated',
				'Deactivated jobs that are no longer returned by Zoho.',
				array( 'count' => $count ),
				(int) $run->id
			);
		}

		return $count;
	}

	/**
	 * Apply the orphan action to records Zoho reports as deleted.
	 *
	 * @param object $run     Run row.
	 * @param bool   $dry_run Whether to skip writes.
	 * @return int|\WP_Error Number handled.
	 */
	private function process_deleted_records( $run, $dry_run ) {
		if ( 'none' === (string) Settings::get( 'orphan_action', 'draft' ) ) {
			return 0;
		}

		$options = array();

		if ( 'incremental' === $run->type && '' !== (string) $run->modified_since ) {
			$options['modified_since'] = (string) $run->modified_since;
		}

		$handled = 0;
		$page    = 1;

		do {
			$result = $this->api->get_deleted_records(
				array(
					'page'     => $page,
					'per_page' => 200,
				),
				$options
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( (array) $result['records'] as $deleted ) {
				$zoho_id = Job::sanitize_zoho_id( $deleted['id'] ?? '' );

				if ( '' === $zoho_id ) {
					continue;
				}

				$post_id = Job::find_by_zoho_id( $zoho_id );

				if ( ! $post_id ) {
					continue;
				}

				if ( $dry_run ) {
					++$handled;
					continue;
				}

				$action = Job::orphan( $post_id );

				if ( 'skipped' === $action || 'none' === $action ) {
					continue;
				}

				++$handled;

				Logger::info(
					'job_orphaned',
					'Applied the orphan action to a job deleted in Zoho.',
					array(
						'zoho_id' => $zoho_id,
						'post_id' => $post_id,
						'action'  => $action,
					),
					(int) $run->id
				);
			}

			$more = ! empty( $result['info']['more_records'] );
			++$page;
		} while ( $more && $page <= 50 );

		return $handled;
	}

	/**
	 * Map of Zoho ID => post ID for every imported job.
	 *
	 * @return array<string,int>
	 */
	private function get_synced_job_map() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sync-time bulk read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS zoho_id, pm.post_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status != 'trash'",
				Job::META_ZOHO_ID,
				Post_Type::POST_TYPE
			)
		);

		$map = array();

		foreach ( (array) $rows as $row ) {
			$zoho_id = (string) $row->zoho_id;

			if ( '' !== $zoho_id ) {
				$map[ $zoho_id ] = (int) $row->post_id;
			}
		}

		return $map;
	}

	// ----------------------------------------------------------------------
	// Expiry
	// ----------------------------------------------------------------------

	/**
	 * Mark active jobs whose closing date has passed as expired.
	 *
	 * @param bool $dry_run Whether to skip writes.
	 * @return int Number expired.
	 */
	public function expire_due_jobs( $dry_run = false ) {
		$today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

		$query = new \WP_Query(
			array(
				'post_type'      => Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				// This runs on cron over an ID-only, unpaginated query; a smaller
				// page would just mean more round trips for the same work.
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded batch for a cron task.
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Closing dates only exist in meta.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => Job::META_STATUS,
						'value'   => Job::active_statuses(),
						'compare' => 'IN',
					),
					array(
						'key'     => '_zoho_recruit_closing_date',
						'value'   => '',
						'compare' => '!=',
					),
					array(
						'key'     => '_zoho_recruit_closing_date',
						'value'   => $today,
						'compare' => '<',
						'type'    => 'DATE',
					),
				),
			)
		);

		$count = 0;

		foreach ( $query->posts as $post_id ) {
			if ( Job::is_active( (int) $post_id ) ) {
				continue;
			}

			if ( $dry_run ) {
				++$count;
				continue;
			}

			if ( Job::expire( (int) $post_id ) ) {
				++$count;
			}
		}

		if ( $count > 0 && ! $dry_run ) {
			Logger::info( 'jobs_expired', 'Marked jobs as expired.', array( 'count' => $count ) );
			$this->invalidate_caches();
		}

		return $count;
	}

	// ----------------------------------------------------------------------
	// Single record refresh
	// ----------------------------------------------------------------------

	/**
	 * Re-fetch and rewrite a single job from Zoho.
	 *
	 * @param string $zoho_id Zoho record ID.
	 * @param bool   $force   Discard local edits and take Zoho's values, even in
	 *                        "preserve fields edited in WordPress" mode.
	 * @return array|\WP_Error {
	 *     @type string $action created|updated|skipped|deleted.
	 *     @type int    $post_id Post ID when one exists.
	 * }
	 */
	public function sync_single( $zoho_id, $force = false ) {
		$zoho_id = Job::sanitize_zoho_id( $zoho_id );

		if ( '' === $zoho_id ) {
			return new \WP_Error( 'jszr_invalid_record_id', __( 'Invalid Zoho record ID.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$record = $this->api->get_record( $zoho_id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		if ( empty( $record ) ) {
			$post_id = Job::find_by_zoho_id( $zoho_id );

			if ( $post_id ) {
				$action = Job::orphan( $post_id );

				Logger::info(
					'job_orphaned',
					'A single-record refresh found the job missing in Zoho.',
					array(
						'zoho_id' => $zoho_id,
						'action'  => $action,
					)
				);

				$this->invalidate_caches();

				return array(
					'action'  => 'deleted',
					'post_id' => $post_id,
				);
			}

			return new \WP_Error( 'jszr_record_not_found', __( 'That job no longer exists in Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ) );
		}

		self::$syncing = true;

		try {
			$action = $this->process_record( $record, 0, false, (bool) $force );
		} finally {
			self::$syncing = false;
		}

		$this->invalidate_caches();

		return array(
			'action'  => $action,
			'post_id' => Job::find_by_zoho_id( $zoho_id ),
		);
	}

	// ----------------------------------------------------------------------
	// State helpers
	// ----------------------------------------------------------------------

	/**
	 * Mark a run as failed and notify.
	 *
	 * @param object $run     Run row.
	 * @param string $message Failure message.
	 * @return void
	 */
	private function fail( $run, $message ) {
		Sync_Queue::finish( (int) $run->id, 'failed', $message );

		$stats = Sync_Queue::stats( Sync_Queue::get( (int) $run->id ) );

		Notifications::send_failure_alert( $message, $stats );

		/**
		 * Fires when a sync run fails.
		 *
		 * @param string $message Failure message.
		 * @param array  $stats   Run statistics.
		 */
		do_action( 'jszr_sync_failed', $message, $stats );
	}

	/**
	 * Record a successful sync timestamp.
	 *
	 * @param string $type Sync type.
	 * @return void
	 */
	private function record_success( $type ) {
		$state = get_option( self::OPTION_STATE, array() );
		$state = is_array( $state ) ? $state : array();

		$now = time();

		$state['last_success']      = $now;
		$state[ 'last_' . $type ]   = $now;
		$state['last_success_http'] = gmdate( 'D, d M Y H:i:s', $now ) . ' GMT';

		update_option( self::OPTION_STATE, $state, false );
	}

	/**
	 * The If-Modified-Since value for an incremental sync.
	 *
	 * @return string ISO-8601 timestamp, or empty string.
	 */
	public function last_successful_time() {
		$state = get_option( self::OPTION_STATE, array() );

		if ( ! is_array( $state ) || empty( $state['last_success'] ) ) {
			return '';
		}

		// Overlap slightly so records modified during the previous run are not missed.
		$timestamp = max( 0, (int) $state['last_success'] - ( 5 * MINUTE_IN_SECONDS ) );

		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Summary of sync state for the dashboard.
	 *
	 * @return array
	 */
	public function get_state() {
		$state = get_option( self::OPTION_STATE, array() );
		$state = is_array( $state ) ? $state : array();

		$last_run     = Sync_Queue::get_recent( 1 );
		$last_success = Sync_Queue::get_last_successful();

		return array(
			'last_run'          => ! empty( $last_run ) ? Sync_Queue::stats( $last_run[0] ) : null,
			'last_success'      => $last_success ? Sync_Queue::stats( $last_success ) : null,
			'last_success_time' => isset( $state['last_success'] ) ? (int) $state['last_success'] : 0,
			'active'            => Sync_Queue::get_active() ? Sync_Queue::stats( Sync_Queue::get_active() ) : null,
			'next_scheduled'    => (int) wp_next_scheduled( Cron::SYNC_HOOK ),
		);
	}

	/**
	 * Clear cached listings after data changes.
	 *
	 * @return void
	 */
	public function invalidate_caches() {
		wp_cache_delete( 'jszr_job_counts', 'jszr' );

		REST_API::flush_cache();

		/**
		 * Fires when the plugin's caches have been invalidated.
		 */
		do_action( 'jszr_caches_invalidated' );
	}

	/**
	 * Whether the current batch should stop to stay within limits.
	 *
	 * @return bool
	 */
	private function should_yield() {
		if ( $this->batch_started <= 0.0 ) {
			$this->batch_started = microtime( true );
		}

		/**
		 * Filter the wall-clock budget for a single sync batch, in seconds.
		 *
		 * @param int $seconds Time budget.
		 */
		$budget = (int) apply_filters( 'jszr_batch_time_budget', 20 );

		if ( ( microtime( true ) - $this->batch_started ) > $budget ) {
			return true;
		}

		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $limit > 0 && memory_get_usage( true ) > ( $limit * 0.8 ) ) {
			return true;
		}

		return false;
	}
}
