<?php
/**
 * Scale and API-contract test.
 *
 * Mocks Zoho at the HTTP layer with `pre_http_request`, so the whole stack runs
 * for real: OAuth token handling, Zoho_API pagination, the batched sync with its
 * checkpoints, the mapper, the writer, the deleted-records pass and the
 * deactivation safety threshold. Only the network is fake.
 *
 * It cannot prove the plugin works against a real Zoho account — the field names
 * and error shapes come from the documentation, not from an account — but it
 * does prove the machinery around them.
 *
 * Writes and deletes thousands of posts. Development sites only:
 *
 *     wp eval-file bin/scale-test.php
 *     wp eval-file bin/scale-test.php 5000
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Encryption;
use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\Settings;
use JobsSyncForZohoRecruit\Sync_Queue;

use function JobsSyncForZohoRecruit\plugin;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/**
 * Holds the fake Zoho account between requests.
 */
final class JSZR_Fake_Zoho {

	/**
	 * How many job openings the fake account has.
	 *
	 * @var int
	 */
	public static $total = 0;

	/**
	 * IDs the fake account should stop returning, simulating deletion.
	 *
	 * @var array<string,bool>
	 */
	public static $withheld = array();

	/**
	 * IDs the fake account reports through the /deleted endpoint.
	 *
	 * @var string[]
	 */
	public static $deleted = array();

	/**
	 * Requests served, by kind.
	 *
	 * @var array<string,int>
	 */
	public static $calls = array(
		'token'   => 0,
		'records' => 0,
		'deleted' => 0,
	);

	/**
	 * Reset the counters.
	 *
	 * @return void
	 */
	public static function reset_calls() {
		self::$calls = array(
			'token'   => 0,
			'records' => 0,
			'deleted' => 0,
		);
	}

	/**
	 * Build one job opening record.
	 *
	 * @param int $index 1-based record number.
	 * @return array
	 */
	public static function record( $index ) {
		$departments = array( 'Engineering', 'Design', 'Sales', 'Support', 'Finance' );
		$types       = array( 'Full time', 'Part time', 'Contract' );
		$cities      = array( 'Chennai', 'Bengaluru', 'London', 'Berlin', 'Toronto' );

		return array(
			'id'                        => sprintf( 'SCALE%013d', $index ),
			'Posting_Title'             => sprintf( 'Test Role %d', $index ),
			'Job_Description'           => sprintf( '<p>Responsibilities for role %d.</p>', $index ),
			'Job_Opening_ID'            => sprintf( 'JOB-%05d', $index ),
			'Job_Opening_Status'        => 'In-progress',
			'Department_Name'           => array(
				'id'   => (string) ( 900 + ( $index % 5 ) ),
				'name' => $departments[ $index % 5 ],
			),
			'Job_Type'                  => $types[ $index % 3 ],
			'Work_Experience'           => '2 - 5 years',
			'City'                      => $cities[ $index % 5 ],
			'State'                     => 'Test State',
			'Country'                   => 'Testland',
			'Remote_Job'                => 0 === $index % 4,
			'Salary'                    => '90000 - 120000 USD per year',
			'Number_of_Positions'       => 1 + ( $index % 3 ),
			'Date_Opened'               => gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) ),
			'Expected_Closing_Date'     => gmdate( 'Y-m-d', time() + ( 60 * DAY_IN_SECONDS ) ),
			'Created_Time'              => gmdate( 'c', time() - ( 30 * DAY_IN_SECONDS ) ),
			'Modified_Time'             => gmdate( 'c' ),
			'Publish_in_Career_Website' => true,
			'Website'                   => 'https://example.test/apply/' . $index,
		);
	}

	/**
	 * The records the fake account currently returns, in order.
	 *
	 * @return array[]
	 */
	public static function visible_records() {
		$records = array();

		for ( $index = 1; $index <= self::$total; $index++ ) {
			$record = self::record( $index );

			if ( isset( self::$withheld[ $record['id'] ] ) ) {
				continue;
			}

			$records[] = $record;
		}

		return $records;
	}

	/**
	 * Answer an HTTP request the plugin makes.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return array|mixed
	 */
	public static function handle( $preempt, $args, $url ) {
		// Another filter already answered -- most likely the failure injector
		// below. Overwriting it would make the injected failure invisible.
		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( false === strpos( $url, 'zoho' ) ) {
			return $preempt;
		}

		if ( false !== strpos( $url, '/oauth/v2/token' ) ) {
			++self::$calls['token'];

			return self::response(
				array(
					'access_token' => 'fake-access-token',
					'expires_in'   => 3600,
					'api_domain'   => 'https://recruit.zoho.com',
					'token_type'   => 'Bearer',
				)
			);
		}

		if ( false !== strpos( $url, '/JobOpenings/deleted' ) ) {
			++self::$calls['deleted'];

			$rows = array();

			foreach ( self::$deleted as $id ) {
				$rows[] = array(
					'id'           => $id,
					'deleted_time' => gmdate( 'c' ),
				);
			}

			if ( empty( $rows ) ) {
				return self::response( null, 204 );
			}

			return self::response(
				array(
					'data' => $rows,
					'info' => array( 'more_records' => false ),
				)
			);
		}

		if ( false !== strpos( $url, '/JobOpenings' ) ) {
			++self::$calls['records'];

			$query = array();
			wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

			$page     = max( 1, (int) ( $query['page'] ?? 1 ) );
			$per_page = max( 1, (int) ( $query['per_page'] ?? 200 ) );

			$all   = self::visible_records();
			$slice = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );

			if ( empty( $slice ) ) {
				return self::response( null, 204 );
			}

			return self::response(
				array(
					'data' => $slice,
					'info' => array(
						'count'        => count( $slice ),
						'page'         => $page,
						'per_page'     => $per_page,
						'more_records' => ( $page * $per_page ) < count( $all ),
					),
				)
			);
		}

		return $preempt;
	}

	/**
	 * Shape a WordPress HTTP API response.
	 *
	 * @param array|null $body   Body to encode, or null for no content.
	 * @param int        $status HTTP status.
	 * @return array
	 */
	private static function response( $body, $status = 200 ) {
		return array(
			'headers'  => array(),
			'body'     => null === $body ? '' : wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'No Content',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}

/**
 * Report one check.
 *
 * @param bool   $passed Whether it passed.
 * @param string $label  What was checked.
 * @param string $detail Extra context.
 * @return bool
 */
function jszr_scale_check( $passed, $label, $detail = '' ) {
	if ( $passed ) {
		WP_CLI::log( '  PASS  ' . $label . ( '' !== $detail ? '  (' . $detail . ')' : '' ) );
	} else {
		WP_CLI::warning( 'FAIL  ' . $label . ( '' !== $detail ? ' -- ' . $detail : '' ) );
	}

	return (bool) $passed;
}

/**
 * Count job posts of any status.
 *
 * @return int
 */
function jszr_scale_count_jobs() {
	global $wpdb;

	// Only this test's jobs. A development site may well have others -- the
	// demo jobs behind the WordPress.org screenshots, for one -- and counting
	// those would make every assertion here wrong for the wrong reason.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-time count.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value LIKE %s",
			Post_Type::POST_TYPE,
			Job::META_ZOHO_ID,
			'SCALE%'
		)
	);
}

/**
 * Count this test's jobs that are currently inactive.
 *
 * @return int
 */
function jszr_scale_count_inactive() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-time count.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} zid ON zid.post_id = p.ID AND zid.meta_key = %s AND zid.meta_value LIKE %s
			INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s AND st.meta_value = 'inactive'
			WHERE p.post_type = %s",
			Job::META_ZOHO_ID,
			'SCALE%',
			Job::META_STATUS,
			Post_Type::POST_TYPE
		)
	);
}

/**
 * Record the status of every synced job this test did not create.
 *
 * @return array<int,string> Post ID => status.
 */
function jszr_scale_snapshot_other_jobs() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-time snapshot.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, st.meta_value AS status
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} zid ON zid.post_id = p.ID AND zid.meta_key = %s AND zid.meta_value NOT LIKE %s
			LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s
			WHERE p.post_type = %s",
			Job::META_ZOHO_ID,
			'SCALE%',
			Job::META_STATUS,
			Post_Type::POST_TYPE
		)
	);

	$snapshot = array();

	foreach ( (array) $rows as $row ) {
		$snapshot[ (int) $row->ID ] = (string) $row->status;
	}

	return $snapshot;
}

/**
 * Put those jobs back the way they were.
 *
 * @param array<int,string> $snapshot Post ID => status.
 * @return int Number restored.
 */
function jszr_scale_restore_other_jobs( array $snapshot ) {
	$restored = 0;

	foreach ( $snapshot as $post_id => $status ) {
		if ( '' === $status ) {
			continue;
		}

		if ( (string) get_post_meta( $post_id, Job::META_STATUS, true ) === $status ) {
			continue;
		}

		update_post_meta( $post_id, Job::META_STATUS, $status );
		++$restored;
	}

	return $restored;
}

/**
 * Delete every job written by this test.
 *
 * @return int
 */
function jszr_scale_cleanup() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-time cleanup.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value LIKE %s",
			Post_Type::POST_TYPE,
			Job::META_ZOHO_ID,
			'SCALE%'
		)
	);

	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}

	return count( $ids );
}

/**
 * Run the scale test.
 *
 * @param array $cli_args Positional arguments.
 * @return void
 */
function jszr_run_scale_test( array $cli_args ) {
	if ( ! empty( $cli_args[0] ) && 'cleanup' === $cli_args[0] ) {
		WP_CLI::success( sprintf( 'Removed %d scale-test job(s).', jszr_scale_cleanup() ) );

		return;
	}

	$total = ! empty( $cli_args[0] ) ? max( 1, (int) $cli_args[0] ) : 2000;

	JSZR_Fake_Zoho::$total    = $total;
	JSZR_Fake_Zoho::$withheld = array();
	JSZR_Fake_Zoho::$deleted  = array();

	add_filter( 'pre_http_request', array( 'JSZR_Fake_Zoho', 'handle' ), 10, 3 );

	// A connection the plugin believes in, without a real Zoho account.
	update_option(
		'jszr_credentials',
		array(
			'client_id'     => Encryption::encrypt( 'fake.client.id' ),
			'client_secret' => Encryption::encrypt( 'fake-client-secret' ),
			'fingerprint'   => Encryption::key_fingerprint(),
		),
		false
	);

	update_option(
		'jszr_tokens',
		array(
			'refresh_token' => Encryption::encrypt( 'fake-refresh-token' ),
			'access_token'  => '',
			'expires_at'    => 0,
			'api_domain'    => 'https://recruit.zoho.com',
			'fingerprint'   => Encryption::key_fingerprint(),
			'connected_at'  => time(),
		),
		false
	);

	delete_option( 'jszr_auth_state' );
	delete_option( 'jszr_auth_failures' );
	delete_option( 'jszr_sync_state' );

	// per_request and batch_size are deliberately left at their defaults: how
	// many API calls a sync costs is one of the things this test measures, and
	// overriding them here would measure the override instead.
	Settings::update(
		array(
			'store_raw'              => true,
			'deactivation_threshold' => 50,
			'notify_on_failure'      => false,
		)
	);

	jszr_scale_cleanup();

	/*
	 * A full sync against this fake account will quite correctly decide that
	 * every job it does not return has gone from Zoho, and deactivate it. On a
	 * development site that means any real or demo jobs already present. Their
	 * statuses are snapshotted here and restored at the end, so running the
	 * test does not quietly take a site's jobs offline.
	 */
	$restore = jszr_scale_snapshot_other_jobs();

	if ( ! empty( $restore ) ) {
		WP_CLI::log(
			sprintf(
				'Noting %d existing job(s) not created by this test, to restore afterwards.',
				count( $restore )
			)
		);
	}

	$checks = array();

	WP_CLI::log( '' );
	WP_CLI::log( sprintf( '== Full sync of %s records ==', number_format_i18n( $total ) ) );

	$started    = microtime( true );
	$mem_before = memory_get_usage( true );

	JSZR_Fake_Zoho::reset_calls();

	$stats = plugin()->sync()->run_now( 'full', array( 'trigger' => 'scale-test' ) );

	$elapsed = microtime( true ) - $started;
	$peak    = memory_get_peak_usage( true );

	if ( is_wp_error( $stats ) ) {
		WP_CLI::error( 'Sync could not start: ' . $stats->get_error_message() );
	}

	WP_CLI::log(
		sprintf(
			'   %s in %.1fs (%.0f jobs/s), peak memory %s, %d API page requests, %d token requests',
			number_format_i18n( (int) $stats['processed'] ),
			$elapsed,
			$elapsed > 0 ? $stats['processed'] / $elapsed : 0,
			size_format( $peak ),
			JSZR_Fake_Zoho::$calls['records'],
			JSZR_Fake_Zoho::$calls['token']
		)
	);

	unset( $mem_before );

	$checks[] = jszr_scale_check( 'completed' === $stats['state'], 'full sync completes', $stats['state'] );
	$checks[] = jszr_scale_check( (int) $stats['created'] === $total, 'every record was created', $stats['created'] . ' of ' . $total );
	$checks[] = jszr_scale_check( 0 === (int) $stats['errors'], 'no errors', (string) $stats['errors'] );
	$checks[] = jszr_scale_check( jszr_scale_count_jobs() === $total, 'post count matches', (string) jszr_scale_count_jobs() );
	$checks[] = jszr_scale_check( 1 === JSZR_Fake_Zoho::$calls['token'], 'the access token was fetched once, not per request', (string) JSZR_Fake_Zoho::$calls['token'] );

	// Spot-check the mapping survived the volume.
	$sample = Job::find_by_zoho_id( sprintf( 'SCALE%013d', (int) ceil( $total / 2 ) ) );

	$checks[] = jszr_scale_check( $sample > 0, 'a middle-of-the-run record exists' );

	if ( $sample ) {
		$checks[] = jszr_scale_check(
			'' !== get_post_meta( $sample, '_jszr_job_code', true ),
			'its job code was mapped'
		);
		$checks[] = jszr_scale_check(
			! empty( get_the_terms( $sample, Post_Type::TAX_LOCATION ) ),
			'its hierarchical location was built'
		);
		$checks[] = jszr_scale_check(
			'90000' === (string) (int) get_post_meta( $sample, '_zoho_recruit_salary_min', true ),
			'its salary was parsed'
		);
	}

	WP_CLI::log( '' );
	WP_CLI::log( '== Second full sync: updates, never duplicates ==' );

	$started = microtime( true );
	$stats   = plugin()->sync()->run_now( 'full', array( 'trigger' => 'scale-test' ) );
	$elapsed = microtime( true ) - $started;

	WP_CLI::log( sprintf( '   re-synced in %.1fs', $elapsed ) );

	$checks[] = jszr_scale_check( 0 === (int) $stats['created'], 'nothing was created the second time', (string) $stats['created'] );
	$checks[] = jszr_scale_check( (int) $stats['updated'] === $total, 'everything was updated', (string) $stats['updated'] );
	$checks[] = jszr_scale_check( jszr_scale_count_jobs() === $total, 'still no duplicates', (string) jszr_scale_count_jobs() );

	WP_CLI::log( '' );
	WP_CLI::log( '== Safety threshold: Zoho suddenly returns 40% of the jobs ==' );

	$withhold = (int) floor( $total * 0.6 );

	for ( $index = 1; $index <= $withhold; $index++ ) {
		JSZR_Fake_Zoho::$withheld[ sprintf( 'SCALE%013d', $index ) ] = true;
	}

	$stats = plugin()->sync()->run_now( 'full', array( 'trigger' => 'scale-test' ) );

	$active_after = 0;

	foreach ( array_keys( JSZR_Fake_Zoho::$withheld ) as $zoho_id ) {
		$post_id = Job::find_by_zoho_id( $zoho_id );

		if ( $post_id && 'active' === Job::get_status( $post_id ) ) {
			++$active_after;
		}
	}

	$checks[] = jszr_scale_check( 'partial' === $stats['state'], 'the run is marked partial', $stats['state'] );
	$checks[] = jszr_scale_check( 0 === (int) $stats['deactivated'], 'nothing was deactivated', (string) $stats['deactivated'] );
	$checks[] = jszr_scale_check( $active_after === $withhold, 'every withheld job is still active', $active_after . ' of ' . $withhold );
	$checks[] = jszr_scale_check( '' !== (string) $stats['message'], 'the run explains why' );

	WP_CLI::log( '' );
	WP_CLI::log( '== Under the threshold: a small disappearance does deactivate ==' );

	JSZR_Fake_Zoho::$withheld = array();

	$small = max( 1, (int) floor( $total * 0.1 ) );

	for ( $index = 1; $index <= $small; $index++ ) {
		JSZR_Fake_Zoho::$withheld[ sprintf( 'SCALE%013d', $index ) ] = true;
	}

	$stats = plugin()->sync()->run_now( 'full', array( 'trigger' => 'scale-test' ) );

	$checks[] = jszr_scale_check( 'completed' === $stats['state'], 'the run completes', $stats['state'] );
	$checks[] = jszr_scale_check( (int) $stats['deactivated'] === $small, 'the missing jobs were deactivated', $stats['deactivated'] . ' of ' . $small );

	$still_there = Job::find_by_zoho_id( 'SCALE0000000000001' );

	$checks[] = jszr_scale_check( $still_there > 0, 'a deactivated job is kept, not deleted' );
	$checks[] = jszr_scale_check( 'inactive' === Job::get_status( $still_there ), 'and is marked inactive', Job::get_status( $still_there ) );

	WP_CLI::log( '' );
	WP_CLI::log( '== Deleted in Zoho: the /deleted endpoint drives the orphan action ==' );

	JSZR_Fake_Zoho::$withheld = array();
	JSZR_Fake_Zoho::$deleted  = array( 'SCALE0000000000002' );

	Settings::update( array( 'orphan_action' => 'draft' ) );

	$stats  = plugin()->sync()->run_now( 'incremental', array( 'trigger' => 'scale-test' ) );
	$orphan = Job::find_by_zoho_id( 'SCALE0000000000002' );

	$checks[] = jszr_scale_check( (int) $stats['orphaned'] >= 1, 'the deleted record was handled', (string) $stats['orphaned'] );
	$checks[] = jszr_scale_check( $orphan > 0 && 'draft' === get_post_status( $orphan ), 'it was drafted, not deleted', $orphan ? get_post_status( $orphan ) : 'gone' );

	WP_CLI::log( '' );
	WP_CLI::log( '== Interrupted sync: a failure mid-run deactivates nothing ==' );

	JSZR_Fake_Zoho::$deleted = array();

	// Fail on the second page request, so the run always breaks partway through
	// however many pages the current settings produce. Tying this to a fixed
	// number of calls made it depend on the batch size, which is exactly the
	// setting this test exists to measure.
	$pages_expected = (int) ceil( $total / max( 1, (int) Settings::get( 'per_request', 200 ) ) );
	$fail_after     = 1;
	$page_calls     = 0;

	$breaker = static function ( $preempt, $args, $url ) use ( &$page_calls, $fail_after ) {
		if ( false !== strpos( $url, '/JobOpenings' ) && false === strpos( $url, '/deleted' ) ) {
			++$page_calls;

			if ( $page_calls > $fail_after ) {
				return new WP_Error( 'http_request_failed', 'Simulated connection reset' );
			}
		}

		return $preempt;
	};

	if ( $pages_expected < 2 ) {
		WP_CLI::log( '   skipped: this record count fits in one page, so there is no mid-run to interrupt.' );
	} else {
		add_filter( 'pre_http_request', $breaker, 5, 3 );
	}

	// A failed run still applies the pages it did read, so the right property to
	// assert is that it deactivated nothing -- not that nothing changed at all.
	$inactive_before = jszr_scale_count_inactive();

	$stats = plugin()->sync()->run_now( 'full', array( 'trigger' => 'scale-test' ) );

	remove_filter( 'pre_http_request', $breaker, 5 );

	$inactive_after = jszr_scale_count_inactive();

	if ( $pages_expected >= 2 ) {
		$checks[] = jszr_scale_check( 'failed' === $stats['state'], 'the interrupted run is marked failed', $stats['state'] );
		$checks[] = jszr_scale_check( 0 === (int) $stats['deactivated'], 'it deactivated nothing', (string) $stats['deactivated'] );
		$checks[] = jszr_scale_check(
			$inactive_after <= $inactive_before,
			'no job was deactivated by the failed run',
			$inactive_before . ' inactive before, ' . $inactive_after . ' after'
		);
	}

	$checks[] = jszr_scale_check( jszr_scale_count_jobs() === $total, 'and nothing was lost', (string) jszr_scale_count_jobs() );

	// Tidy up.
	remove_filter( 'pre_http_request', array( 'JSZR_Fake_Zoho', 'handle' ), 10 );

	delete_option( 'jszr_credentials' );
	delete_option( 'jszr_tokens' );
	delete_option( 'jszr_sync_state' );

	Sync_Queue::clear();

	$removed  = jszr_scale_cleanup();
	$restored = jszr_scale_restore_other_jobs( $restore );

	WP_CLI::log( '' );
	WP_CLI::log( sprintf( 'Cleaned up %s job(s) and the fake credentials.', number_format_i18n( $removed ) ) );

	if ( $restored > 0 ) {
		WP_CLI::log( sprintf( 'Restored the status of %d pre-existing job(s).', $restored ) );
	}

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
		WP_CLI::error( sprintf( '%d of %d checks failed.', $failed, count( $checks ) ) );
	}

	WP_CLI::success( sprintf( 'All %d scale checks passed.', count( $checks ) ) );
}

jszr_run_scale_test( (array) ( $args ?? array() ) );
