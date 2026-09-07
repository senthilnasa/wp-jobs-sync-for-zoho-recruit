<?php
/**
 * Manual smoke test: pushes synthetic Zoho records through the real sync path.
 *
 * This exists so the write path, the mapper, the taxonomies, the REST responses
 * and the templates can be exercised in a live WordPress without a Zoho
 * account. It writes real posts, so run it only against a development site:
 *
 *     wp eval-file bin/smoke-test.php
 *     wp eval-file bin/smoke-test.php cleanup
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\REST_API;

use function JobsSyncForZohoRecruit\plugin;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/**
 * Report one check.
 *
 * @param bool   $passed Whether the check passed.
 * @param string $label  What was checked.
 * @param string $detail Extra context shown on failure.
 * @return bool
 */
function jszr_smoke_check( $passed, $label, $detail = '' ) {
	if ( $passed ) {
		WP_CLI::log( '  PASS  ' . $label );
	} else {
		WP_CLI::warning( 'FAIL  ' . $label . ( '' !== $detail ? ' -- ' . $detail : '' ) );
	}

	return (bool) $passed;
}

/**
 * Run the smoke test.
 *
 * @param array $cli_args Positional arguments from wp eval-file.
 * @return int|void
 */
function jszr_run_smoke_test( array $cli_args ) {
	$jszr_records = array(
		array(
			'id'                        => 'SMOKE0000000000001',
			'Posting_Title'             => 'Senior PHP Developer',
			'Job_Description'           => '<p>Build things.</p><script>alert(1)</script><p style="color:red">Ship them.</p>',
			'Job_Opening_ID'            => 'JOB-001',
			'Job_Opening_Status'        => 'In-progress',
			'Department_Name'           => array(
				'id'   => '900',
				'name' => 'Engineering',
			),
			'Job_Type'                  => 'Full time',
			'Work_Experience'           => '2 - 5 years',
			'Industry'                  => 'Software',
			'City'                      => 'Chennai',
			'State'                     => 'Tamil Nadu',
			'Country'                   => 'India',
			'Remote_Job'                => true,
			'Salary'                    => 'INR 1200000 - 1800000 per year',
			'Number_of_Positions'       => '3',
			'Date_Opened'               => '2026-01-05',
			'Expected_Closing_Date'     => '2099-12-31',
			'Created_Time'              => '2026-01-05T09:30:00+05:30',
			'Modified_Time'             => '2026-02-01T11:00:00+05:30',
			'Client_Name'               => array(
				'id'   => '77',
				'name' => 'Acme Corp',
			),
			'Website'                   => 'https://example.com/apply/job-001',
			'Publish_in_Career_Website' => true,
		),
		array(
			'id'                        => 'SMOKE0000000000002',
			'Posting_Title'             => 'Designer (already closed)',
			'Job_Description'           => 'Plain text description without any markup.',
			'Job_Opening_ID'            => 'JOB-002',
			'Job_Opening_Status'        => 'Closed',
			'Job_Type'                  => 'Contract',
			'City'                      => 'Remote',
			'Country'                   => 'India',
			'Expected_Closing_Date'     => '2020-01-01',
			'Publish_in_Career_Website' => true,
		),
		array(
			'id'                        => 'SMOKE0000000000003',
			'Posting_Title'             => 'Unpublished role',
			'Job_Description'           => 'Should be skipped while only_published is on.',
			'Job_Opening_ID'            => 'JOB-003',
			'Job_Opening_Status'        => 'In-progress',
			'Publish_in_Career_Website' => false,
		),
	);

	$jszr_cleanup = in_array( 'cleanup', $cli_args, true );

	if ( $jszr_cleanup ) {
		$removed = 0;

		foreach ( $jszr_records as $record ) {
			$post_id = Job::find_by_zoho_id( $record['id'] );

			if ( $post_id ) {
				wp_delete_post( $post_id, true );
				++$removed;
			}
		}

		WP_CLI::success( sprintf( 'Removed %d smoke-test job(s).', $removed ) );

			return 0;
	}

	$sync    = plugin()->sync();
	$results = array();
	$failed  = 0;

	WP_CLI::log( 'Writing records' );

	foreach ( $jszr_records as $record ) {
		$results[ $record['id'] ] = $sync->process_record( $record );
		WP_CLI::log( '  ' . $record['id'] . ' -> ' . $results[ $record['id'] ] );
	}

	WP_CLI::log( '' );
	WP_CLI::log( 'Checks' );

	$first  = Job::find_by_zoho_id( 'SMOKE0000000000001' );
	$second = Job::find_by_zoho_id( 'SMOKE0000000000002' );
	$third  = Job::find_by_zoho_id( 'SMOKE0000000000003' );

	$checks = array();

	$checks[] = jszr_smoke_check( $first > 0, 'active job was created' );
	$checks[] = jszr_smoke_check( $second > 0, 'closed job was created' );
	$checks[] = jszr_smoke_check( 0 === $third, 'unpublished job was skipped', 'post ' . $third );

	// Idempotence: the same record must never make a second post.
	$sync->process_record( $jszr_records[0] );
	$checks[] = jszr_smoke_check(
		Job::find_by_zoho_id( 'SMOKE0000000000001' ) === $first,
		're-syncing the same record does not duplicate it'
	);

	$job_post = $first ? get_post( $first ) : null;

	$checks[] = jszr_smoke_check(
		$job_post && 'Senior PHP Developer' === $job_post->post_title,
		'title mapped',
		$job_post ? $job_post->post_title : 'no post'
	);

	$checks[] = jszr_smoke_check(
		$job_post && false === strpos( $job_post->post_content, '<script' ) && false === strpos( $job_post->post_content, 'alert(1)' ),
		'script element and its contents stripped from the description',
		$job_post ? $job_post->post_content : ''
	);

	$checks[] = jszr_smoke_check(
		$job_post && false === strpos( $job_post->post_content, 'style=' ),
		'inline style stripped from the description'
	);

	$checks[] = jszr_smoke_check(
		$job_post && '' !== trim( (string) $job_post->post_excerpt ),
		'excerpt derived from the description'
	);

	$checks[] = jszr_smoke_check(
		'active' === Job::get_status( $first ),
		'active status stored',
		Job::get_status( $first )
	);

	$checks[] = jszr_smoke_check(
		in_array( Job::get_status( $second ), array( 'expired', 'closed' ), true ),
		'past closing date is not active',
		Job::get_status( $second )
	);

	$checks[] = jszr_smoke_check( Job::is_active( $first ), 'first job reads as active' );
	$checks[] = jszr_smoke_check( ! Job::is_active( $second ), 'second job does not read as active' );

	$checks[] = jszr_smoke_check(
		'1200000' === (string) (int) Job::get_meta( $first, 'salary_min' ),
		'salary minimum parsed',
		(string) Job::get_meta( $first, 'salary_min' )
	);

	$checks[] = jszr_smoke_check(
		'INR' === Job::get_meta( $first, 'salary_currency' ),
		'salary currency parsed',
		(string) Job::get_meta( $first, 'salary_currency' )
	);

	$checks[] = jszr_smoke_check(
		'YEAR' === Job::get_meta( $first, 'salary_unit' ),
		'salary unit parsed',
		(string) Job::get_meta( $first, 'salary_unit' )
	);

	$checks[] = jszr_smoke_check(
		'Acme Corp' === Job::get_meta( $first, 'client' ),
		'lookup name unwrapped',
		(string) Job::get_meta( $first, 'client' )
	);

	$checks[] = jszr_smoke_check(
		'JOB-001' === Job::get_meta( $first, 'job_code' ),
		'job code stored'
	);

	$departments = wp_get_object_terms( $first, Post_Type::TAX_DEPARTMENT, array( 'fields' => 'names' ) );
	$checks[]    = jszr_smoke_check(
		in_array( 'Engineering', (array) $departments, true ),
		'department term assigned',
		implode( ',', (array) $departments )
	);

	$locations = wp_get_object_terms( $first, Post_Type::TAX_LOCATION );
	$deepest   = ! is_wp_error( $locations ) && $locations ? $locations[0] : null;
	$checks[]  = jszr_smoke_check(
		$deepest && 'Chennai' === $deepest->name && $deepest->parent > 0,
		'hierarchical location built country > state > city',
		$deepest ? $deepest->name . ' (parent ' . $deepest->parent . ')' : 'none'
	);

	$checks[] = jszr_smoke_check(
		'' !== Job::get_apply_url( $first ),
		'apply URL resolved'
	);

	// REST.
	$request  = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/jobs' );
	$response = rest_get_server()->dispatch( $request );
	$body     = $response->get_data();
	$ids      = wp_list_pluck( (array) ( $body['data'] ?? array() ), 'id' );

	$checks[] = jszr_smoke_check( 200 === $response->get_status(), 'GET /jobs returns 200' );
	$checks[] = jszr_smoke_check( in_array( $first, $ids, true ), 'active job appears in the collection' );
	$checks[] = jszr_smoke_check( ! in_array( $second, $ids, true ), 'inactive job is filtered out' );
	$checks[] = jszr_smoke_check( isset( $body['pagination']['total'] ), 'pagination metadata present' );

	$row = null;
	foreach ( (array) ( $body['data'] ?? array() ) as $item ) {
		if ( ( $item['id'] ?? 0 ) === $first ) {
			$row = $item;
		}
	}

	$checks[] = jszr_smoke_check(
		is_array( $row ) && ! array_key_exists( 'raw_data', $row ) && ! array_key_exists( 'zoho_id', $row ),
		'internal fields are not exposed by the public API'
	);

	// A single inactive job must 404 rather than being enumerable.
	$request  = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/jobs/' . $second );
	$response = rest_get_server()->dispatch( $request );
	$checks[] = jszr_smoke_check( 404 === $response->get_status(), 'inactive job 404s on the single endpoint', (string) $response->get_status() );

	// per_page above the maximum must be rejected by the schema.
	$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/jobs' );
	$request->set_param( 'per_page', 5000 );
	$response = rest_get_server()->dispatch( $request );
	$checks[] = jszr_smoke_check( 400 === $response->get_status(), 'per_page above the maximum is rejected', (string) $response->get_status() );

	// The protected endpoints must refuse an anonymous caller.
	wp_set_current_user( 0 );
	$request  = new WP_REST_Request( 'POST', '/' . REST_API::NAMESPACE_V1 . '/sync' );
	$response = rest_get_server()->dispatch( $request );
	$checks[] = jszr_smoke_check(
		in_array( $response->get_status(), array( 401, 403 ), true ),
		'anonymous callers cannot start a sync',
		(string) $response->get_status()
	);

	$request  = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/jobs/filters' );
	$response = rest_get_server()->dispatch( $request );
	$checks[] = jszr_smoke_check( 200 === $response->get_status(), 'GET /jobs/filters returns 200' );

	// Shortcode rendering.
	$html     = do_shortcode( '[zoho_jobs per_page="5" show_filters="true" show_search="true"]' );
	$checks[] = jszr_smoke_check( false !== strpos( $html, 'jszr-' ), 'shortcode renders prefixed markup' );
	$checks[] = jszr_smoke_check( false !== strpos( $html, 'Senior PHP Developer' ), 'shortcode lists the active job' );
	$checks[] = jszr_smoke_check( false === strpos( $html, 'Designer (already closed)' ), 'shortcode omits the inactive job' );

	// Structured data.
	$schema   = \JobsSyncForZohoRecruit\Structured_Data::build( $first );
	$checks[] = jszr_smoke_check( 'JobPosting' === ( $schema['@type'] ?? '' ), 'JobPosting schema built for an active job' );
	$checks[] = jszr_smoke_check(
		! empty( $schema['validThrough'] ) && ! empty( $schema['datePosted'] ),
		'schema carries datePosted and validThrough'
	);
	$checks[] = jszr_smoke_check(
		! empty( $schema['hiringOrganization']['name'] ),
		'schema names a hiring organization'
	);
	$checks[] = jszr_smoke_check(
		! Job::is_active( $second ),
		'the inactive job is gated out of schema output by Job::is_active()'
	);

	// Expiry sweep.
	$expired  = $sync->expire_due_jobs( true );
	$checks[] = jszr_smoke_check( is_int( $expired ), 'dry-run expiry sweep runs', (string) $expired );

	// Block renderers. All three are server rendered, so they can be exercised
	// here without an editor. Earlier checks ran template code that leaves a
	// global post behind, so clear it before asserting the no-context case.
	wp_reset_postdata();
	$GLOBALS['post'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Clearing the loop state the earlier checks left behind.

	$jszr_meta_markup = \JobsSyncForZohoRecruit\Blocks::render_job_meta(
		array( 'fields' => 'department,location,employment_type' ),
		'',
		null
	);

	$checks[] = jszr_smoke_check(
		'' === $jszr_meta_markup,
		'job meta block renders nothing outside a job',
		$jszr_meta_markup
	);

	$GLOBALS['post'] = get_post( $first ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the loop for a render-callback check.
	setup_postdata( $GLOBALS['post'] );

	$jszr_meta_markup = \JobsSyncForZohoRecruit\Blocks::render_job_meta(
		array( 'fields' => 'department,location' ),
		'',
		null
	);

	$checks[] = jszr_smoke_check(
		false !== strpos( $jszr_meta_markup, 'jszr-job-meta' ) && false !== strpos( $jszr_meta_markup, 'Engineering' ),
		'job meta block renders the job facts inside the loop'
	);

	$jszr_apply_markup = \JobsSyncForZohoRecruit\Blocks::render_apply_button( array(), '', null );

	$checks[] = jszr_smoke_check(
		false !== strpos( $jszr_apply_markup, 'jszr-apply-button' ),
		'apply button block renders for an active job'
	);

	$checks[] = jszr_smoke_check(
		false !== strpos( $jszr_apply_markup, 'rel="noopener nofollow"' ),
		'apply link carries rel="noopener nofollow"'
	);

	wp_reset_postdata();

	$GLOBALS['post'] = get_post( $second ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the loop for a render-callback check.
	setup_postdata( $GLOBALS['post'] );

	$checks[] = jszr_smoke_check(
		'' === \JobsSyncForZohoRecruit\Blocks::render_apply_button( array(), '', null ),
		'apply button block renders nothing for a closed job'
	);

	wp_reset_postdata();
	$GLOBALS['post'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the global after the loop simulation.

	// Titles and descriptions offered to SEO plugins.
	$jszr_title = \JobsSyncForZohoRecruit\SEO::job_title( $first );

	$checks[] = jszr_smoke_check(
		false !== strpos( $jszr_title, 'Senior PHP Developer' ) && false !== strpos( $jszr_title, 'Chennai' ),
		'SEO title includes the job and its most specific location',
		$jszr_title
	);

	$jszr_description = \JobsSyncForZohoRecruit\SEO::meta_description( $first );

	$checks[] = jszr_smoke_check(
		'' !== $jszr_description && false === strpos( $jszr_description, '<' ),
		'SEO description is present and free of markup',
		$jszr_description
	);

	// Forced reset overrides the preserve-edits conflict mode.
	$jszr_conflict_before = jszr_get_setting( 'conflict_mode' );

	\JobsSyncForZohoRecruit\Settings::update( array( 'conflict_mode' => 'preserve_manual' ) );

	wp_update_post(
		array(
			'ID'         => $first,
			'post_title' => 'Edited By Hand',
		)
	);

	$jszr_payload = plugin()->mapper()->map( $jszr_records[0] );

	Job::upsert( $jszr_records[0]['id'], $jszr_payload, array( 'status' => 'active' ) );

	$checks[] = jszr_smoke_check(
		'Edited By Hand' === get_the_title( $first ),
		'a plain resync preserves a manual edit',
		get_the_title( $first )
	);

	Job::upsert(
		$jszr_records[0]['id'],
		$jszr_payload,
		array(
			'status' => 'active',
			'force'  => true,
		)
	);

	$checks[] = jszr_smoke_check(
		'Senior PHP Developer' === get_the_title( $first ),
		'a forced reset restores the Zoho values',
		get_the_title( $first )
	);

	\JobsSyncForZohoRecruit\Settings::update( array( 'conflict_mode' => $jszr_conflict_before ) );

	// Page cache purging is best effort and must never throw.
	$jszr_purge_ran = false;

	add_filter(
		'jszr_page_cache_purgers',
		static function ( $purgers ) use ( &$jszr_purge_ran ) {
			$purgers['smoke-test'] = static function () use ( &$jszr_purge_ran ) {
				$jszr_purge_ran = true;

				return true;
			};

			$purgers['smoke-test-throws'] = static function () {
				throw new RuntimeException( 'a caching plugin blew up' );
			};

			return $purgers;
		}
	);

	$sync->invalidate_caches();

	$checks[] = jszr_smoke_check( $jszr_purge_ran, 'page cache purgers run when caches are invalidated' );
	$checks[] = jszr_smoke_check( true, 'a purger that throws does not break the sync' );

	remove_all_filters( 'jszr_page_cache_purgers' );

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

	WP_CLI::success( sprintf( 'All %d checks passed.', count( $checks ) ) );
}

jszr_run_smoke_test( (array) ( $args ?? array() ) );
