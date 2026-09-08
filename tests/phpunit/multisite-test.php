<?php
/**
 * Multisite lifecycle tests.
 *
 * Skipped entirely on a single site install. Run them with:
 *
 *     vendor/bin/phpunit -c phpunit-multisite.xml.dist
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

/**
 * Network activation has to reach every existing site and every site created
 * afterwards, and one site's jobs and credentials must never be visible to
 * another.
 *
 * @group multisite
 */
class JSZR_Multisite_Test extends WP_UnitTestCase {

	/**
	 * Skip the whole class outside multisite.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'These tests only apply to a multisite install.' );
		}
	}

	/**
	 * Whether a table exists on the current site.
	 *
	 * @param string $suffix Table name without the prefix.
	 * @return bool
	 */
	private function table_exists( $suffix ) {
		global $wpdb;

		$table = $wpdb->prefix . $suffix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-time schema check.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Per-site activation creates that site's tables, capability and schedule.
	 */
	public function test_activation_sets_a_site_up() {
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );

		Plugin::activate_single_site();

		$this->assertTrue( $this->table_exists( 'jszr_sync_logs' ), 'log table' );
		$this->assertTrue( $this->table_exists( 'jszr_sync_runs' ), 'runs table' );

		$admin = get_role( 'administrator' );
		$this->assertTrue( $admin->has_cap( Plugin::capability() ) );

		$this->assertNotEmpty( get_option( 'jszr_webhook_secret' ) );
		$this->assertNotFalse( wp_next_scheduled( Cron::EXPIRY_HOOK ) );

		restore_current_blog();

		wp_delete_site( $site_id );
	}

	/**
	 * A site created while the plugin is network active is set up too.
	 *
	 * WordPress fires wp_initialize_site on creation; the handler is called
	 * directly here because the plugin is loaded as an ordinary plugin in the
	 * test suite, not network activated.
	 */
	public function test_new_site_is_set_up() {
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		Plugin::activate_single_site();
		$has_tables = $this->table_exists( 'jszr_sync_runs' );
		restore_current_blog();

		$this->assertTrue( $has_tables, 'a newly created site gets its own tables' );

		wp_delete_site( $site_id );
	}

	/**
	 * Each site keeps its own tables, so one site's sync history is not another's.
	 */
	public function test_tables_are_per_site() {
		global $wpdb;

		$main_table = $wpdb->prefix . 'jszr_sync_runs';

		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );

		Plugin::activate_single_site();

		$site_table = $wpdb->prefix . 'jszr_sync_runs';

		$this->assertNotSame( $main_table, $site_table, 'the table name is site specific' );
		$this->assertSame( $site_table, Sync_Queue::table() );
		$this->assertStringContainsString( (string) $site_id, $site_table );

		restore_current_blog();

		$this->assertSame( $main_table, Sync_Queue::table(), 'the main site is unaffected' );

		wp_delete_site( $site_id );
	}

	/**
	 * Settings are per site: connecting one site does not connect another.
	 */
	public function test_settings_do_not_leak_between_sites() {
		Settings::update( array( 'job_slug' => 'main-site-jobs' ) );

		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );

		Settings::flush_cache();

		$this->assertSame(
			'jobs',
			Settings::get( 'job_slug' ),
			'the second site sees the default, not the first site value'
		);

		Settings::update( array( 'job_slug' => 'other-site-jobs' ) );

		restore_current_blog();

		Settings::flush_cache();

		$this->assertSame( 'main-site-jobs', Settings::get( 'job_slug' ) );

		wp_delete_site( $site_id );

		Settings::flush_cache();
	}

	/**
	 * Jobs belong to the site they were synced into.
	 */
	public function test_jobs_do_not_leak_between_sites() {
		$main = Job::upsert(
			'MSMAIN1',
			array(
				'post'   => array( 'post_title' => 'Main site role' ),
				'meta'   => array(),
				'terms'  => array(),
				'mapped' => array(),
			),
			array( 'status' => 'active' )
		);

		$this->assertGreaterThan( 0, $main['post_id'] );

		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );

		// The lookup is a direct query against this site's tables, so the other
		// site's record must be invisible here.
		$this->assertSame( 0, Job::find_by_zoho_id( 'MSMAIN1' ) );

		$found = get_posts(
			array(
				'post_type'      => Post_Type::POST_TYPE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'any',
			)
		);

		$this->assertCount( 0, $found );

		restore_current_blog();

		$this->assertSame( (int) $main['post_id'], Job::find_by_zoho_id( 'MSMAIN1' ) );

		wp_delete_site( $site_id );
	}

	/**
	 * Deactivating one site clears its schedule and leaves its data alone.
	 */
	public function test_deactivation_is_scoped_to_one_site() {
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );

		Plugin::activate_single_site();
		Plugin::deactivate_single_site();

		$this->assertFalse( wp_next_scheduled( Cron::SYNC_HOOK ) );
		$this->assertTrue( $this->table_exists( 'jszr_sync_runs' ), 'deactivation keeps the data' );

		restore_current_blog();

		wp_delete_site( $site_id );
	}

	/**
	 * The logger writes to the current site's table.
	 */
	public function test_logging_is_per_site() {
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );

		Plugin::activate_single_site();
		Logger::info( 'multisite_probe', 'Written on the second site.' );

		$entries = Logger::get_entries( array( 'limit' => 10 ) );
		$events  = wp_list_pluck( $entries, 'event' );

		$this->assertContains( 'multisite_probe', $events );

		restore_current_blog();

		$main_events = wp_list_pluck( Logger::get_entries( array( 'limit' => 50 ) ), 'event' );

		$this->assertNotContains( 'multisite_probe', $main_events, 'the entry did not land on the main site' );

		wp_delete_site( $site_id );
	}
}
