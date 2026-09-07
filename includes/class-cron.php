<?php
/**
 * Scheduled tasks.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every WP-Cron event the plugin registers.
 */
class Cron {

	/**
	 * Recurring sync event.
	 */
	const SYNC_HOOK = 'jszr_scheduled_sync';

	/**
	 * Daily expiry check.
	 */
	const EXPIRY_HOOK = 'jszr_check_expired';

	/**
	 * Daily log pruning.
	 */
	const PRUNE_HOOK = 'jszr_prune_logs';

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

		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );
		add_action( self::SYNC_HOOK, array( $this, 'run_scheduled_sync' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'maybe_reschedule' ), 10, 2 );
	}

	/**
	 * Register the six-hourly interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedules( $schedules ) {
		if ( ! isset( $schedules['jszr_six_hours'] ) ) {
			$schedules['jszr_six_hours'] = array(
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 6 hours (Zoho Recruit)', 'jobs-sync-for-zoho-recruit' ),
			);
		}

		return $schedules;
	}

	/**
	 * Available frequencies for the settings screen.
	 *
	 * @return array<string,string>
	 */
	public static function frequencies() {
		return array(
			'disabled'       => __( 'Disabled (manual sync only)', 'jobs-sync-for-zoho-recruit' ),
			'hourly'         => __( 'Hourly', 'jobs-sync-for-zoho-recruit' ),
			'jszr_six_hours' => __( 'Every 6 hours', 'jobs-sync-for-zoho-recruit' ),
			'twicedaily'     => __( 'Twice daily', 'jobs-sync-for-zoho-recruit' ),
			'daily'          => __( 'Daily', 'jobs-sync-for-zoho-recruit' ),
		);
	}

	/**
	 * Schedule all events according to current settings.
	 *
	 * @return void
	 */
	public static function schedule() {
		// Activation runs after plugins_loaded, so the container — and with it
		// the constructor that normally registers jszr_six_hours — has not been
		// built yet. Without the schedule registered, wp_schedule_event() would
		// silently refuse the recurrence. Adding the same callback twice is a
		// no-op in WordPress, so this is safe to repeat.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );

		$frequency = (string) Settings::get( 'sync_frequency', 'jszr_six_hours' );

		wp_clear_scheduled_hook( self::SYNC_HOOK );

		if ( 'disabled' !== $frequency && array_key_exists( $frequency, self::frequencies() ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), $frequency, self::SYNC_HOOK );
		}

		if ( ! wp_next_scheduled( self::EXPIRY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EXPIRY_HOOK );
		}

		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Remove all scheduled events.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		wp_clear_scheduled_hook( self::SYNC_HOOK );
		wp_clear_scheduled_hook( self::EXPIRY_HOOK );
		wp_clear_scheduled_hook( self::PRUNE_HOOK );

		$active = Sync_Queue::get_active();

		if ( $active ) {
			wp_clear_scheduled_hook( Sync_Queue::BATCH_HOOK, array( (int) $active->id ) );
		}
	}

	/**
	 * Reschedule when the frequency setting changes.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public static function maybe_reschedule( $old_value, $value ) {
		$before = is_array( $old_value ) && isset( $old_value['sync_frequency'] ) ? $old_value['sync_frequency'] : null;
		$after  = is_array( $value ) && isset( $value['sync_frequency'] ) ? $value['sync_frequency'] : null;

		if ( $before !== $after ) {
			Settings::flush_cache();
			self::schedule();
		}
	}

	/**
	 * Run the scheduled sync.
	 *
	 * @return void
	 */
	public function run_scheduled_sync() {
		$auth = plugin()->auth();

		if ( ! $auth->is_connected() ) {
			Logger::debug( 'cron_skipped', 'Scheduled sync skipped: not connected to Zoho Recruit.' );

			return;
		}

		if ( $auth->is_circuit_open() ) {
			Logger::warning( 'cron_skipped', 'Scheduled sync skipped: automatic requests are paused after repeated authentication failures.' );

			return;
		}

		if ( Sync_Queue::get_active() || Sync_Queue::is_locked() ) {
			Logger::debug( 'cron_skipped', 'Scheduled sync skipped: another sync is already running.' );

			return;
		}

		$type = $this->due_sync_type();

		$result = $this->sync->start( $type, array( 'trigger' => 'cron' ) );

		if ( is_wp_error( $result ) ) {
			Logger::warning( 'cron_start_failed', $result->get_error_message() );
		}
	}

	/**
	 * Decide whether this run should be full or incremental.
	 *
	 * @return string
	 */
	private function due_sync_type() {
		$configured = (string) Settings::get( 'cron_sync_type', 'incremental' );

		if ( 'full' === $configured ) {
			return 'full';
		}

		$last_full = Sync_Queue::get_last_successful( 'full' );

		if ( ! $last_full ) {
			return 'full';
		}

		$interval = (int) Settings::get( 'full_sync_interval_days', 7 );

		if ( $interval <= 0 ) {
			return 'incremental';
		}

		$finished = strtotime( (string) $last_full->finished_at . ' UTC' );

		if ( ! $finished || ( time() - $finished ) > ( $interval * DAY_IN_SECONDS ) ) {
			return 'full';
		}

		return 'incremental';
	}

	/**
	 * Whether WP-Cron is disabled on this site.
	 *
	 * @return bool
	 */
	public static function is_wp_cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}
}
