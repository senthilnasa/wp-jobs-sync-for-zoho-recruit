<?php
/**
 * WP-CLI commands.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Zoho Recruit job synchronization.
 *
 * Commands honour exactly the same locking, duplicate prevention and safety
 * rules as the admin screens.
 */
class CLI {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Register the command with WP-CLI.
	 *
	 * @param Plugin $plugin Plugin container.
	 * @return void
	 */
	public static function register( Plugin $plugin ) {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'jszr', new self( $plugin ) );
	}

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Synchronize job openings from Zoho Recruit.
	 *
	 * ## OPTIONS
	 *
	 * [--full]
	 * : Run a full sync (default).
	 *
	 * [--incremental]
	 * : Only fetch records modified since the last successful sync.
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jszr sync --full
	 *     wp jszr sync --incremental
	 *     wp jszr sync --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function sync( $args, $assoc_args ) {
		$incremental = ! empty( $assoc_args['incremental'] );
		$type        = $incremental ? 'incremental' : 'full';
		$dry_run     = ! empty( $assoc_args['dry-run'] );

		\WP_CLI::log(
			sprintf(
				/* translators: 1: sync type, 2: yes/no. */
				__( 'Starting %1$s sync (dry run: %2$s)...', 'jobs-sync-for-zoho-recruit' ),
				$type,
				$dry_run ? 'yes' : 'no'
			)
		);

		$result = $this->plugin->sync()->run_now(
			$type,
			array(
				'dry_run' => $dry_run,
				'trigger' => 'cli',
			)
		);

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		$this->print_stats( $result );

		if ( 'failed' === ( $result['state'] ?? '' ) ) {
			\WP_CLI::error( (string) ( $result['message'] ?? __( 'The sync failed.', 'jobs-sync-for-zoho-recruit' ) ) );
		}

		if ( 'partial' === ( $result['state'] ?? '' ) ) {
			\WP_CLI::warning( (string) ( $result['message'] ?? __( 'The sync completed only partially.', 'jobs-sync-for-zoho-recruit' ) ) );

			return;
		}

		\WP_CLI::success( __( 'Sync finished.', 'jobs-sync-for-zoho-recruit' ) );
	}

	/**
	 * Show connection and sync status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jszr status
	 *
	 * @return void
	 */
	public function status() {
		$auth   = $this->plugin->auth();
		$state  = $this->plugin->sync()->get_state();
		$counts = Job::counts();

		$rows = array(
			array(
				'key'   => 'connected',
				'value' => $auth->is_connected() ? 'yes' : 'no',
			),
			array(
				'key'   => 'data_center',
				'value' => Settings::data_center(),
			),
			array(
				'key'   => 'module',
				'value' => $this->plugin->api()->module(),
			),
			array(
				'key'   => 'circuit',
				'value' => $auth->is_circuit_open() ? 'open (paused)' : 'closed',
			),
			array(
				'key'   => 'last_success',
				'value' => $state['last_success_time'] ? gmdate( 'c', $state['last_success_time'] ) : 'never',
			),
			array(
				'key'   => 'next_scheduled',
				'value' => $state['next_scheduled'] ? gmdate( 'c', $state['next_scheduled'] ) : 'not scheduled',
			),
			array(
				'key'   => 'running',
				'value' => $state['active'] ? 'run #' . $state['active']['run_id'] : 'no',
			),
		);

		foreach ( $counts as $key => $value ) {
			$rows[] = array(
				'key'   => 'jobs_' . $key,
				'value' => (string) $value,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * Mark jobs past their closing date as expired.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report the count without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jszr expire
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function expire( $args, $assoc_args ) {
		$count = $this->plugin->sync()->expire_due_jobs( ! empty( $assoc_args['dry-run'] ) );

		\WP_CLI::success(
			sprintf(
				/* translators: %d: number of jobs. */
				_n( '%d job expired.', '%d jobs expired.', $count, 'jobs-sync-for-zoho-recruit' ),
				$count
			)
		);
	}

	/**
	 * Show recent sync runs.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : How many runs to show. Default 10.
	 *
	 * [--errors]
	 * : Show individual error log entries instead of runs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jszr logs --limit=5
	 *     wp jszr logs --errors
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function logs( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 10;

		if ( ! empty( $assoc_args['errors'] ) ) {
			$entries = Logger::get_entries(
				array(
					'level' => Logger::ERROR,
					'limit' => $limit,
				)
			);

			if ( empty( $entries ) ) {
				\WP_CLI::success( __( 'No errors logged.', 'jobs-sync-for-zoho-recruit' ) );

				return;
			}

			$rows = array();

			foreach ( $entries as $entry ) {
				$rows[] = array(
					'time'    => $entry->created_at,
					'event'   => $entry->event,
					'message' => $entry->message,
				);
			}

			\WP_CLI\Utils\format_items( 'table', $rows, array( 'time', 'event', 'message' ) );

			return;
		}

		$runs = Sync_Queue::get_recent( $limit );

		if ( empty( $runs ) ) {
			\WP_CLI::success( __( 'No sync runs recorded yet.', 'jobs-sync-for-zoho-recruit' ) );

			return;
		}

		$rows = array();

		foreach ( $runs as $run ) {
			$rows[] = array(
				'id'      => (int) $run->id,
				'started' => (string) $run->started_at,
				'type'    => (string) $run->type,
				'state'   => (string) $run->state,
				'added'   => (int) $run->created_count,
				'updated' => (int) $run->updated_count,
				'expired' => (int) $run->expired_count,
				'errors'  => (int) $run->error_count,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'started', 'type', 'state', 'added', 'updated', 'expired', 'errors' ) );
	}

	/**
	 * Cancel the sync that is currently running.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jszr cancel
	 *
	 * @return void
	 */
	public function cancel() {
		if ( Sync_Queue::cancel_active() ) {
			\WP_CLI::success( __( 'The running sync was cancelled.', 'jobs-sync-for-zoho-recruit' ) );

			return;
		}

		\WP_CLI::success( __( 'No sync was running.', 'jobs-sync-for-zoho-recruit' ) );
	}

	/**
	 * List the Zoho fields available for mapping.
	 *
	 * ## OPTIONS
	 *
	 * [--refresh]
	 * : Re-fetch the field list from Zoho instead of using the cache.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jszr fields --refresh
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function fields( $args, $assoc_args ) {
		$fields = $this->plugin->metadata()->get_fields( ! empty( $assoc_args['refresh'] ) );

		$rows = array();

		foreach ( $fields as $field ) {
			$rows[] = array(
				'api_name' => $field['api_name'],
				'label'    => $field['label'],
				'type'     => $field['type'],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'api_name', 'label', 'type' ) );
	}

	/**
	 * Disconnect the site from Zoho Recruit.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jszr disconnect --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function disconnect( $args, $assoc_args ) {
		\WP_CLI::confirm( __( 'Revoke the Zoho Recruit token and delete it from this site?', 'jobs-sync-for-zoho-recruit' ), $assoc_args );

		$this->plugin->auth()->disconnect();

		\WP_CLI::success( __( 'Disconnected from Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ) );
	}

	/**
	 * Print a statistics table.
	 *
	 * @param array $stats Run statistics.
	 * @return void
	 */
	private function print_stats( array $stats ) {
		$rows = array();

		foreach ( array( 'run_id', 'type', 'state', 'processed', 'created', 'updated', 'skipped', 'deactivated', 'expired', 'orphaned', 'errors' ) as $key ) {
			if ( ! array_key_exists( $key, $stats ) ) {
				continue;
			}

			$rows[] = array(
				'key'   => $key,
				'value' => is_bool( $stats[ $key ] ) ? ( $stats[ $key ] ? 'yes' : 'no' ) : (string) $stats[ $key ],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
	}
}
