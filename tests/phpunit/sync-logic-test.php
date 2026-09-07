<?php
/**
 * Sync decision, status and duplicate-prevention tests.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\Settings;

/**
 * Covers the rules that decide what happens to a record, without touching the
 * network.
 */
class JSZR_Sync_Logic_Test extends WP_UnitTestCase {

	/**
	 * Reset settings between tests.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Settings::flush_cache();
	}

	/**
	 * A mapped Zoho status becomes the local status.
	 */
	public function test_status_mapping() {
		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$this->assertSame(
			'active',
			$sync->determine_status( array( 'Job_Opening_Status' => 'In-progress' ) )
		);

		$this->assertSame(
			'closed',
			$sync->determine_status( array( 'Job_Opening_Status' => 'Filled' ) )
		);
	}

	/**
	 * Status matching ignores case, because Zoho picklists vary between accounts.
	 */
	public function test_status_mapping_is_case_insensitive() {
		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$this->assertSame(
			'closed',
			$sync->determine_status( array( 'Job_Opening_Status' => 'CLOSED' ) )
		);
	}

	/**
	 * An unmapped Zoho status falls back to the configured default.
	 */
	public function test_unknown_status_uses_default() {
		Settings::update( array( 'default_status' => 'draft' ) );

		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$this->assertSame(
			'draft',
			$sync->determine_status( array( 'Job_Opening_Status' => 'Something Custom' ) )
		);
	}

	/**
	 * A job the recruiter unpublished must not stay active.
	 */
	public function test_publish_flag_overrides_active_status() {
		Settings::update(
			array(
				'only_published'  => true,
				'published_field' => 'Publish_in_Career_Website',
			)
		);

		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$status = $sync->determine_status(
			array(
				'Job_Opening_Status'        => 'In-progress',
				'Publish_in_Career_Website' => false,
			)
		);

		$this->assertSame( 'inactive', $status );
	}

	/**
	 * With the publish filter off, the flag is ignored.
	 */
	public function test_publish_flag_ignored_when_filter_disabled() {
		Settings::update( array( 'only_published' => false ) );

		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$status = $sync->determine_status(
			array(
				'Job_Opening_Status'        => 'In-progress',
				'Publish_in_Career_Website' => false,
			)
		);

		$this->assertSame( 'active', $status );
	}

	/**
	 * The same Zoho ID must never produce two posts.
	 */
	public function test_upsert_does_not_duplicate() {
		$payload = array(
			'post'   => array( 'post_title' => 'Software Developer' ),
			'meta'   => array( '_jszr_job_code' => 'JOB-1' ),
			'terms'  => array(),
			'mapped' => array(),
		);

		$first = Job::upsert( '4876000000123456', $payload, array( 'status' => 'active' ) );
		$this->assertSame( 'created', $first['action'] );

		$payload['post']['post_title'] = 'Senior Software Developer';

		$second = Job::upsert( '4876000000123456', $payload, array( 'status' => 'active' ) );

		$this->assertSame( 'updated', $second['action'] );
		$this->assertSame( $first['post_id'], $second['post_id'] );

		$found = get_posts(
			array(
				'post_type'      => Post_Type::POST_TYPE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'any',
			)
		);

		$this->assertCount( 1, $found );
		$this->assertSame( 'Senior Software Developer', get_the_title( $first['post_id'] ) );
	}

	/**
	 * A published job keeps its slug when the Zoho title changes.
	 */
	public function test_slug_is_stable_across_title_changes() {
		$payload = array(
			'post'   => array( 'post_title' => 'Software Developer' ),
			'meta'   => array(),
			'terms'  => array(),
			'mapped' => array(),
		);

		$created = Job::upsert( '111', $payload, array( 'status' => 'active' ) );
		$slug    = get_post_field( 'post_name', $created['post_id'] );

		$payload['post']['post_title'] = 'Software Developer II';

		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		// The guard only applies while a sync is running.
		$reflection = new ReflectionClass( JobsSyncForZohoRecruit\Sync::class );
		$property   = $reflection->getProperty( 'syncing' );
		$property->setAccessible( true );
		$property->setValue( null, true );

		Job::upsert( '111', $payload, array( 'status' => 'active' ) );

		$property->setValue( null, false );

		$this->assertSame( $slug, get_post_field( 'post_name', $created['post_id'] ) );
		unset( $sync );
	}

	/**
	 * Preserve mode leaves a manually edited field alone.
	 */
	public function test_preserve_manual_keeps_local_edits() {
		Settings::update( array( 'conflict_mode' => 'preserve_manual' ) );

		$payload = array(
			'post'   => array( 'post_title' => 'Software Developer' ),
			'meta'   => array( '_zoho_recruit_city' => 'Chennai' ),
			'terms'  => array(),
			'mapped' => array(),
		);

		$created = Job::upsert( '222', $payload, array( 'status' => 'active' ) );

		// An editor rewrites the title by hand.
		wp_update_post(
			array(
				'ID'         => $created['post_id'],
				'post_title' => 'Software Developer (Hybrid)',
			)
		);

		$payload['post']['post_title']         = 'Software Engineer';
		$payload['meta']['_zoho_recruit_city'] = 'Bengaluru';

		Job::upsert( '222', $payload, array( 'status' => 'active' ) );

		$this->assertSame( 'Software Developer (Hybrid)', get_the_title( $created['post_id'] ) );
		$this->assertSame( 'Bengaluru', get_post_meta( $created['post_id'], '_zoho_recruit_city', true ) );
	}

	/**
	 * A job past its closing date is not active, even before the cron runs.
	 */
	public function test_job_past_closing_date_is_inactive() {
		$payload = array(
			'post'   => array( 'post_title' => 'Old Role' ),
			'meta'   => array( '_zoho_recruit_closing_date' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) ),
			'terms'  => array(),
			'mapped' => array(),
		);

		$created = Job::upsert( '333', $payload, array( 'status' => 'active' ) );

		$this->assertFalse( Job::is_active( $created['post_id'] ) );
	}

	/**
	 * A job closing today stays open for the whole day.
	 */
	public function test_job_closing_today_is_still_active() {
		$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

		$payload = array(
			'post'   => array( 'post_title' => 'Closing Today' ),
			'meta'   => array( '_zoho_recruit_closing_date' => $today ),
			'terms'  => array(),
			'mapped' => array(),
		);

		$created = Job::upsert( '444', $payload, array( 'status' => 'active' ) );

		$this->assertTrue( Job::is_active( $created['post_id'] ) );
	}

	/**
	 * The expiry pass marks overdue jobs and reports how many it changed.
	 */
	public function test_expire_due_jobs() {
		$payload = array(
			'post'   => array( 'post_title' => 'Expired Role' ),
			'meta'   => array( '_zoho_recruit_closing_date' => gmdate( 'Y-m-d', time() - ( 3 * DAY_IN_SECONDS ) ) ),
			'terms'  => array(),
			'mapped' => array(),
		);

		$created = Job::upsert( '555', $payload, array( 'status' => 'active' ) );

		$count = JobsSyncForZohoRecruit\plugin()->sync()->expire_due_jobs();

		$this->assertSame( 1, $count );
		$this->assertSame( 'expired', Job::get_status( $created['post_id'] ) );
	}

	/**
	 * A job created by hand is never deactivated by the plugin.
	 */
	public function test_manual_jobs_are_never_deactivated() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => Post_Type::POST_TYPE,
				'post_title' => 'Hand written job',
			)
		);

		$this->assertTrue( Job::is_manual( $post_id ) );
		$this->assertFalse( Job::deactivate( $post_id ) );
		$this->assertSame( 'skipped', Job::orphan( $post_id ) );
	}

	/**
	 * A Zoho ID with unexpected characters is rejected rather than trusted.
	 */
	public function test_zoho_id_is_sanitized() {
		$this->assertSame( '123DROPTABLEabc', Job::sanitize_zoho_id( "123'; DROP TABLE --abc" ) );
		$this->assertSame( '', Job::sanitize_zoho_id( '---' ) );
		$this->assertSame( '', Job::sanitize_zoho_id( array( 'nested' ) ) );
	}
}
