<?php
/**
 * Activation, upgrade and uninstall test.
 *
 * Runs the real lifecycle routines, including uninstall.php, against the current
 * site and puts it back afterwards. It deletes plugin options and drops the
 * plugin's tables along the way, so development sites only:
 *
 *     wp eval-file bin/lifecycle-test.php
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Cron;
use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Logger;
use JobsSyncForZohoRecruit\Plugin;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\Settings;
use JobsSyncForZohoRecruit\Sync_Queue;
use JobsSyncForZohoRecruit\Upgrader;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/**
 * Report one check.
 *
 * @param bool   $passed Whether it passed.
 * @param string $label  What was checked.
 * @param string $detail Extra context.
 * @return bool
 */
function jszr_life_check( $passed, $label, $detail = '' ) {
	if ( $passed ) {
		WP_CLI::log( '  PASS  ' . $label . ( '' !== $detail ? '  (' . $detail . ')' : '' ) );
	} else {
		WP_CLI::warning( 'FAIL  ' . $label . ( '' !== $detail ? ' -- ' . $detail : '' ) );
	}

	return (bool) $passed;
}

/**
 * Whether a plugin table exists.
 *
 * @param string $suffix Table name without the prefix.
 * @return bool
 */
function jszr_life_table_exists( $suffix ) {
	global $wpdb;

	$table = $wpdb->prefix . $suffix;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-time schema check.
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

/**
 * Create one synced job and one hand-made job.
 *
 * @return array{synced:int,manual:int}
 */
function jszr_life_seed() {
	$synced = Job::upsert(
		'LIFECYCLE0001',
		array(
			'post'   => array( 'post_title' => 'Lifecycle synced job' ),
			'meta'   => array( '_jszr_job_code' => 'LIFE-1' ),
			'terms'  => array( Post_Type::TAX_DEPARTMENT => array( 'Lifecycle Dept' ) ),
			'mapped' => array(),
		),
		array( 'status' => 'active' )
	);

	$manual = wp_insert_post(
		array(
			'post_type'   => Post_Type::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'Lifecycle hand-made job',
		)
	);

	return array(
		'synced' => (int) $synced['post_id'],
		'manual' => (int) $manual,
	);
}

/**
 * Run the plugin's real uninstall routine.
 *
 * @return void
 */
function jszr_life_run_uninstall() {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		// WordPress's own constant, which uninstall.php guards on. Defining it
		// is the only way to run the real routine rather than a copy of it.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant, deliberately.
		define( 'WP_UNINSTALL_PLUGIN', 'jobs-sync-for-zoho-recruit/jobs-sync-for-zoho-recruit.php' );
	}

	require JobsSyncForZohoRecruit\PLUGIN_DIR . 'uninstall.php';
}

/**
 * Run the lifecycle test.
 *
 * @return void
 */
function jszr_run_lifecycle_test() {
	$checks = array();

	WP_CLI::log( '' );
	WP_CLI::log( '== Activation ==' );

	Plugin::activate_single_site();

	$checks[] = jszr_life_check( jszr_life_table_exists( 'jszr_sync_logs' ), 'the log table exists' );
	$checks[] = jszr_life_check( jszr_life_table_exists( 'jszr_sync_runs' ), 'the runs table exists' );
	$checks[] = jszr_life_check( get_role( 'administrator' )->has_cap( Plugin::capability() ), 'administrators have the capability' );
	$checks[] = jszr_life_check( '' !== (string) get_option( 'jszr_webhook_secret', '' ), 'a webhook secret was generated' );
	$checks[] = jszr_life_check( false !== wp_next_scheduled( Cron::EXPIRY_HOOK ), 'the expiry check is scheduled' );
	$checks[] = jszr_life_check( false !== wp_next_scheduled( Cron::PRUNE_HOOK ), 'log pruning is scheduled' );
	$checks[] = jszr_life_check( post_type_exists( Post_Type::POST_TYPE ), 'the post type is registered' );

	$secret_before = (string) get_option( 'jszr_webhook_secret', '' );

	WP_CLI::log( '' );
	WP_CLI::log( '== Activation is idempotent ==' );

	Plugin::activate_single_site();

	$checks[] = jszr_life_check(
		(string) get_option( 'jszr_webhook_secret', '' ) === $secret_before,
		're-activating does not roll the webhook secret'
	);

	WP_CLI::log( '' );
	WP_CLI::log( '== Upgrade from an unknown earlier version ==' );

	Settings::update( array( 'job_slug' => 'careers-lifecycle' ) );

	update_option( 'jszr_db_version', '0', false );

	Upgrader::run( '0' );

	Settings::flush_cache();

	$checks[] = jszr_life_check(
		'careers-lifecycle' === Settings::get( 'job_slug' ),
		'an upgrade does not overwrite existing settings'
	);
	$checks[] = jszr_life_check( jszr_life_table_exists( 'jszr_sync_runs' ), 'tables survive an upgrade' );

	// Put the slug back before anything else reads it.
	Settings::update( array( 'job_slug' => 'jobs' ) );

	WP_CLI::log( '' );
	WP_CLI::log( '== Deactivation keeps data ==' );

	$seeded = jszr_life_seed();

	Logger::info( 'lifecycle_probe', 'Written before deactivation.' );

	Plugin::deactivate_single_site();

	$checks[] = jszr_life_check( false === wp_next_scheduled( Cron::SYNC_HOOK ), 'the sync schedule is cleared' );
	$checks[] = jszr_life_check( jszr_life_table_exists( 'jszr_sync_runs' ), 'tables are kept' );
	$checks[] = jszr_life_check( 'publish' === get_post_status( $seeded['synced'] ), 'jobs are kept' );

	Plugin::activate_single_site();

	WP_CLI::log( '' );
	WP_CLI::log( '== Uninstall, keeping jobs (the default) ==' );

	Settings::update(
		array(
			'uninstall_delete_data' => true,
			'uninstall_delete_jobs' => false,
		)
	);

	jszr_life_run_uninstall();

	$checks[] = jszr_life_check( false === get_option( 'jszr_settings', false ), 'settings are removed' );
	$checks[] = jszr_life_check( false === get_option( 'jszr_tokens', false ), 'tokens are removed' );
	$checks[] = jszr_life_check( false === get_option( 'jszr_webhook_secret', false ), 'the webhook secret is removed' );
	$checks[] = jszr_life_check( ! jszr_life_table_exists( 'jszr_sync_logs' ), 'the log table is dropped' );
	$checks[] = jszr_life_check( ! jszr_life_table_exists( 'jszr_sync_runs' ), 'the runs table is dropped' );
	$checks[] = jszr_life_check( false === wp_next_scheduled( Cron::EXPIRY_HOOK ), 'scheduled events are cleared' );
	$checks[] = jszr_life_check( ! get_role( 'administrator' )->has_cap( Plugin::capability() ), 'the capability is removed' );
	$checks[] = jszr_life_check(
		'publish' === get_post_status( $seeded['synced'] ),
		'the synced job is kept, because the setting said so'
	);
	$checks[] = jszr_life_check(
		'publish' === get_post_status( $seeded['manual'] ),
		'the hand-made job is kept'
	);

	WP_CLI::log( '' );
	WP_CLI::log( '== Uninstall, deleting jobs ==' );

	// Rebuild enough state to uninstall again with the other setting.
	Plugin::activate_single_site();
	Settings::flush_cache();
	Settings::update(
		array(
			'uninstall_delete_data' => true,
			'uninstall_delete_jobs' => true,
		)
	);

	jszr_life_run_uninstall();

	$checks[] = jszr_life_check(
		null === get_post( $seeded['synced'] ),
		'the synced job is deleted'
	);
	$checks[] = jszr_life_check(
		null !== get_post( $seeded['manual'] ),
		'the hand-made job is NEVER deleted, even with delete-jobs on'
	);

	$term = get_term_by( 'name', 'Lifecycle Dept', Post_Type::TAX_DEPARTMENT );

	$checks[] = jszr_life_check( ! $term, 'plugin taxonomy terms are removed' );

	// Leave the site usable.
	WP_CLI::log( '' );
	WP_CLI::log( '== Restoring the site ==' );

	if ( isset( $seeded['manual'] ) && get_post( $seeded['manual'] ) ) {
		wp_delete_post( $seeded['manual'], true );
	}

	Plugin::activate_single_site();
	Settings::flush_cache();
	Sync_Queue::clear();
	Logger::clear();

	$checks[] = jszr_life_check( jszr_life_table_exists( 'jszr_sync_runs' ), 'the site is usable again' );

	$failed = count(
		array_filter(
			$checks,
			static function ( $passed ) {
				return ! $passed;
			}
		)
	);

	WP_CLI::log( '' );

	if ( $failed > 0 ) {
		WP_CLI::error( sprintf( '%d of %d lifecycle checks failed.', $failed, count( $checks ) ) );
	}

	WP_CLI::success( sprintf( 'All %d lifecycle checks passed.', count( $checks ) ) );
}

jszr_run_lifecycle_test();
