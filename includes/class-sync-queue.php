<?php
/**
 * Background sync run storage and locking.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Persists the state of a sync run so an interrupted sync can resume from its
 * last checkpoint instead of starting over (or, worse, concluding that every
 * unprocessed job was deleted).
 */
class Sync_Queue {

	/**
	 * Transient key for the global sync lock.
	 */
	const LOCK_KEY = 'jszr_sync_lock';

	/**
	 * Seconds a lock is considered valid before it is treated as stale.
	 */
	const LOCK_TTL = 600;

	/**
	 * Cron hook that processes the next batch.
	 */
	const BATCH_HOOK = 'jszr_process_batch';

	/**
	 * Hook registration.
	 */
	public function __construct() {
		add_action( self::BATCH_HOOK, array( $this, 'run_batch' ), 10, 1 );
	}

	/**
	 * Fully qualified runs table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'jszr_sync_runs';
	}

	/**
	 * Create or update the runs table.
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT 'full',
			state varchar(20) NOT NULL DEFAULT 'pending',
			dry_run tinyint(1) NOT NULL DEFAULT 0,
			force_overwrite tinyint(1) NOT NULL DEFAULT 0,
			trigger_source varchar(20) NOT NULL DEFAULT 'manual',
			page int(11) NOT NULL DEFAULT 1,
			page_offset int(11) NOT NULL DEFAULT 0,
			per_page smallint(6) NOT NULL DEFAULT 200,
			processed int(11) NOT NULL DEFAULT 0,
			total int(11) NOT NULL DEFAULT 0,
			created_count int(11) NOT NULL DEFAULT 0,
			updated_count int(11) NOT NULL DEFAULT 0,
			skipped_count int(11) NOT NULL DEFAULT 0,
			deactivated_count int(11) NOT NULL DEFAULT 0,
			expired_count int(11) NOT NULL DEFAULT 0,
			orphaned_count int(11) NOT NULL DEFAULT 0,
			error_count int(11) NOT NULL DEFAULT 0,
			seen_ids longtext NULL,
			modified_since varchar(40) NOT NULL DEFAULT '',
			message text NULL,
			started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			KEY state (state),
			KEY started_at (started_at)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Start a new run.
	 *
	 * @param string $type    full|incremental|webhook.
	 * @param array  $args    Optional: dry_run, force, trigger, modified_since, per_page.
	 * @return int Run ID, or 0 on failure.
	 */
	public static function create( $type, array $args = array() ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$data = array(
			'type'            => in_array( $type, array( 'full', 'incremental', 'webhook', 'expiry' ), true ) ? $type : 'full',
			'state'           => 'pending',
			'dry_run'         => ! empty( $args['dry_run'] ) ? 1 : 0,
			'force_overwrite' => ! empty( $args['force'] ) ? 1 : 0,
			'trigger_source'  => isset( $args['trigger'] ) ? substr( sanitize_key( (string) $args['trigger'] ), 0, 20 ) : 'manual',
			'page'            => 1,
			'page_offset'     => 0,
			'per_page'        => isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : (int) Settings::get( 'per_request', 200 ),
			'seen_ids'        => wp_json_encode( array() ),
			'modified_since'  => isset( $args['modified_since'] ) ? substr( sanitize_text_field( (string) $args['modified_since'] ), 0, 40 ) : '',
			'started_at'      => $now,
			'updated_at'      => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$inserted = $wpdb->insert( self::table(), $data );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch a run.
	 *
	 * @param int $run_id Run ID.
	 * @return object|null
	 */
	public static function get( $run_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read fresh on purpose.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), (int) $run_id ) );

		return $row ? $row : null;
	}

	/**
	 * The run that is currently pending or running, if any.
	 *
	 * @return object|null
	 */
	public static function get_active() {
		global $wpdb;

		self::reap_abandoned();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read fresh on purpose.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE state IN ('pending','running') ORDER BY id DESC LIMIT 1",
				self::table()
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Fail runs that were abandoned by a process that never came back.
	 *
	 * A batch that dies -- the PHP process is killed, the host restarts, a fatal
	 * error escapes -- leaves its row saying "running" with nothing scheduled to
	 * continue it. The lock expires on its own after LOCK_TTL, but the row does
	 * not, and Sync::start() refuses to begin while any run is still active. One
	 * crash would otherwise block every future sync, cron included, until an
	 * administrator noticed and clicked Cancel.
	 *
	 * A run only counts as abandoned when it has had no update for well over a
	 * batch cycle *and* has no batch queued, so a slow-but-alive run is safe.
	 *
	 * @return int Number of runs reaped.
	 */
	public static function reap_abandoned() {
		global $wpdb;

		/**
		 * Filter how long a run may go without an update before it is abandoned.
		 *
		 * @param int $seconds Idle time in seconds.
		 */
		$timeout = (int) apply_filters( 'jszr_abandoned_run_timeout', 2 * self::LOCK_TTL );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - max( 60, $timeout ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE state IN ('pending','running') AND updated_at < %s",
				self::table(),
				$cutoff
			)
		);

		$reaped = 0;

		foreach ( (array) $candidates as $run_id ) {
			$run_id = (int) $run_id;

			// Still queued to continue: slow, not dead.
			if ( wp_next_scheduled( self::BATCH_HOOK, array( $run_id ) ) ) {
				continue;
			}

			self::finish(
				$run_id,
				'failed',
				__( 'The sync stopped without finishing and was not resumed. It was closed automatically so later syncs can run.', 'jobs-sync-for-zoho-recruit' )
			);

			Logger::warning(
				'sync_abandoned',
				'Closed a sync run that stopped without finishing.',
				array( 'idle_seconds' => $timeout ),
				$run_id
			);

			++$reaped;
		}

		return $reaped;
	}

	/**
	 * Recent runs, newest first.
	 *
	 * @param int $limit  Maximum rows.
	 * @param int $offset Offset.
	 * @return array<int,object>
	 */
	public static function get_recent( $limit = 20, $offset = 0 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read fresh on purpose.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
				self::table(),
				max( 1, min( 200, (int) $limit ) ),
				max( 0, (int) $offset )
			)
		);
	}

	/**
	 * Total number of stored runs.
	 *
	 * @return int
	 */
	public static function count_runs() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read fresh on purpose.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * The most recent run that completed successfully.
	 *
	 * @param string $type Optional type filter.
	 * @return object|null
	 */
	public static function get_last_successful( $type = '' ) {
		global $wpdb;

		$table = self::table();

		if ( '' !== $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read fresh on purpose.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE state = 'completed' AND type = %s ORDER BY id DESC LIMIT 1",
					$table,
					$type
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read fresh on purpose.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE state = 'completed' ORDER BY id DESC LIMIT 1",
					$table
				)
			);
		}

		return $row ? $row : null;
	}

	/**
	 * Update columns on a run.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $fields Column => value.
	 * @return void
	 */
	public static function update( $run_id, array $fields ) {
		global $wpdb;

		$fields['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $run_id ) );
	}

	/**
	 * Increment counters on a run.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $deltas Column => increment.
	 * @return void
	 */
	public static function increment( $run_id, array $deltas ) {
		global $wpdb;

		$allowed = array(
			'processed',
			'created_count',
			'updated_count',
			'skipped_count',
			'deactivated_count',
			'expired_count',
			'orphaned_count',
			'error_count',
		);

		$sets   = array();
		$params = array( self::table() );

		foreach ( $deltas as $column => $delta ) {
			if ( ! in_array( $column, $allowed, true ) || 0 === (int) $delta ) {
				continue;
			}

			$sets[]   = "{$column} = {$column} + %d";
			$params[] = (int) $delta;
		}

		if ( empty( $sets ) ) {
			return;
		}

		$params[] = current_time( 'mysql', true );
		$params[] = (int) $run_id;

		$sql = 'UPDATE %i SET ' . implode( ', ', $sets ) . ', updated_at = %s WHERE id = %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Column names come from the allow-list above.
		$wpdb->query( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Append Zoho IDs seen during this run.
	 *
	 * @param int      $run_id Run ID.
	 * @param string[] $ids    Zoho record IDs.
	 * @return void
	 */
	public static function add_seen( $run_id, array $ids ) {
		if ( empty( $ids ) ) {
			return;
		}

		$run = self::get( $run_id );

		if ( ! $run ) {
			return;
		}

		$seen = json_decode( (string) $run->seen_ids, true );
		$seen = is_array( $seen ) ? $seen : array();
		$seen = array_values( array_unique( array_merge( $seen, array_map( 'strval', $ids ) ) ) );

		self::update( $run_id, array( 'seen_ids' => wp_json_encode( $seen ) ) );
	}

	/**
	 * IDs seen during a run.
	 *
	 * @param int $run_id Run ID.
	 * @return string[]
	 */
	public static function get_seen( $run_id ) {
		$run = self::get( $run_id );

		if ( ! $run ) {
			return array();
		}

		$seen = json_decode( (string) $run->seen_ids, true );

		return is_array( $seen ) ? array_map( 'strval', $seen ) : array();
	}

	/**
	 * Mark a run finished.
	 *
	 * @param int    $run_id  Run ID.
	 * @param string $state   completed|failed|partial|cancelled.
	 * @param string $message Optional message.
	 * @return void
	 */
	public static function finish( $run_id, $state, $message = '' ) {
		$allowed = array( 'completed', 'failed', 'partial', 'cancelled' );
		$state   = in_array( $state, $allowed, true ) ? $state : 'failed';

		self::update(
			$run_id,
			array(
				'state'       => $state,
				'message'     => Logger::scrub_string( (string) $message ),
				'finished_at' => current_time( 'mysql', true ),
				// The ID list is only needed while the run is in flight.
				'seen_ids'    => wp_json_encode( array() ),
			)
		);

		self::release_lock();
	}

	/**
	 * Statistics array for a run.
	 *
	 * @param object $run Run row.
	 * @return array
	 */
	public static function stats( $run ) {
		if ( ! is_object( $run ) ) {
			return array();
		}

		return array(
			'run_id'      => (int) $run->id,
			'type'        => (string) $run->type,
			'state'       => (string) $run->state,
			'dry_run'     => (bool) $run->dry_run,
			'force'       => ! empty( $run->force_overwrite ),
			'page'        => (int) $run->page,
			'processed'   => (int) $run->processed,
			'total'       => (int) $run->total,
			'created'     => (int) $run->created_count,
			'updated'     => (int) $run->updated_count,
			'skipped'     => (int) $run->skipped_count,
			'deactivated' => (int) $run->deactivated_count,
			'expired'     => (int) $run->expired_count,
			'orphaned'    => (int) $run->orphaned_count,
			'errors'      => (int) $run->error_count,
			'message'     => (string) $run->message,
			'started_at'  => (string) $run->started_at,
			'finished_at' => (string) $run->finished_at,
		);
	}

	// ----------------------------------------------------------------------
	// Batch scheduling
	// ----------------------------------------------------------------------

	/**
	 * Schedule the next batch for a run.
	 *
	 * @param int $run_id Run ID.
	 * @param int $delay  Delay in seconds.
	 * @return void
	 */
	public static function schedule_batch( $run_id, $delay = 5 ) {
		$run_id = (int) $run_id;
		$args   = array( $run_id );

		if ( wp_next_scheduled( self::BATCH_HOOK, $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::BATCH_HOOK, $args );

		// Nudge cron so the batch starts promptly after an admin click.
		if ( ! defined( 'DOING_CRON' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}

	/**
	 * Process the next batch of a run.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public function run_batch( $run_id ) {
		plugin()->sync()->process_batch( (int) $run_id );
	}

	/**
	 * Cancel the active run.
	 *
	 * @return bool Whether a run was cancelled.
	 */
	public static function cancel_active() {
		$run = self::get_active();

		if ( ! $run ) {
			self::release_lock();

			return false;
		}

		wp_clear_scheduled_hook( self::BATCH_HOOK, array( (int) $run->id ) );

		self::finish( (int) $run->id, 'cancelled', __( 'Cancelled by an administrator.', 'jobs-sync-for-zoho-recruit' ) );

		Logger::warning( 'sync_cancelled', 'Sync cancelled by an administrator.', array(), (int) $run->id );

		return true;
	}

	// ----------------------------------------------------------------------
	// Locking
	// ----------------------------------------------------------------------

	/**
	 * Try to take the global sync lock.
	 *
	 * @param int $run_id Run ID that will own the lock.
	 * @return bool
	 */
	public static function acquire_lock( $run_id ) {
		$lock = get_transient( self::LOCK_KEY );

		if ( is_array( $lock ) ) {
			$age = time() - (int) ( $lock['time'] ?? 0 );

			if ( $age < self::LOCK_TTL ) {
				return (int) ( $lock['run_id'] ?? 0 ) === (int) $run_id;
			}

			// Stale lock left behind by a crashed process.
			Logger::warning(
				'sync_lock_stale',
				'Cleared a stale sync lock.',
				array(
					'previous_run' => (int) ( $lock['run_id'] ?? 0 ),
					'age'          => $age,
				)
			);
		}

		set_transient(
			self::LOCK_KEY,
			array(
				'run_id' => (int) $run_id,
				'time'   => time(),
			),
			self::LOCK_TTL
		);

		return true;
	}

	/**
	 * Refresh the lock timestamp during a long run.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public static function touch_lock( $run_id ) {
		set_transient(
			self::LOCK_KEY,
			array(
				'run_id' => (int) $run_id,
				'time'   => time(),
			),
			self::LOCK_TTL
		);
	}

	/**
	 * Release the global sync lock.
	 *
	 * @return void
	 */
	public static function release_lock() {
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Whether a sync currently holds the lock.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		$lock = get_transient( self::LOCK_KEY );

		if ( ! is_array( $lock ) ) {
			return false;
		}

		return ( time() - (int) ( $lock['time'] ?? 0 ) ) < self::LOCK_TTL;
	}

	/**
	 * Delete old run rows.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		$table = self::table();
		$max   = (int) Settings::get( 'log_retention_max', 200 );
		$days  = (int) Settings::get( 'log_retention_days', 30 );

		if ( $days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read fresh on purpose.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM %i WHERE state NOT IN ('pending','running') AND started_at < %s",
					$table,
					$cutoff
				)
			);
		}

		if ( $max > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read fresh on purpose.
			$threshold = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, $max ) );

			if ( $threshold ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read fresh on purpose.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM %i WHERE id <= %d AND state NOT IN ('pending','running')",
						$table,
						(int) $threshold
					)
				);
			}
		}
	}

	/**
	 * Clear every run row.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read fresh on purpose.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
	}
}
