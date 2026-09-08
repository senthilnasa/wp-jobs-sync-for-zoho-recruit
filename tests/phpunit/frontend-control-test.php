<?php
/**
 * Disabling the default jobs frontend, the customization surface, and the
 * AJAX render endpoint.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\Settings;
use JobsSyncForZohoRecruit\Templates;

/**
 * Covers the promise the setting makes: the plugin stops rendering the job
 * pages, and absolutely nothing else changes.
 */
class JSZR_Frontend_Control_Test extends WP_UnitTestCase {

	/**
	 * A job to route to.
	 *
	 * @var int
	 */
	private $job_id;

	/**
	 * Reset settings and create a job.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Settings::flush_cache();

		$this->job_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Content Writer',
				'post_name'   => 'content-writer',
			)
		);

		update_post_meta( $this->job_id, Job::META_STATUS, 'active' );
		update_post_meta( $this->job_id, '_jszr_job_code', 'CW-003' );
		update_post_meta( $this->job_id, Job::META_ZOHO_ID, 'zoho-1' );
	}

	/**
	 * Turn the setting on.
	 *
	 * @param bool $disabled Whether to disable the default frontend.
	 */
	private function set_disabled( $disabled ) {
		$settings = (array) get_option( Settings::OPTION, array() );

		$settings['disable_default_jobs_frontend'] = (bool) $disabled;

		update_option( Settings::OPTION, $settings );
		Settings::flush_cache();
	}

	// ------------------------------------------------------------------
	// Default mode
	// ------------------------------------------------------------------

	/**
	 * Existing sites must keep what they have, so the default is off.
	 */
	public function test_default_is_off() {
		$this->assertFalse( (bool) Settings::get( 'disable_default_jobs_frontend', false ) );
		$this->assertTrue( Templates::frontend_enabled() );
		$this->assertTrue( jszr_jobs_frontend_enabled() );
	}

	/**
	 * By default the archive renders through the plugin's own template.
	 */
	public function test_archive_uses_the_plugin_template_by_default() {
		$this->go_to( get_post_type_archive_link( Post_Type::POST_TYPE ) );

		$this->assertTrue( is_post_type_archive( Post_Type::POST_TYPE ) );
		$this->assertSame(
			Templates::locate( 'archive.php' ),
			Templates::template_include( 'theme-archive.php' )
		);
	}

	/**
	 * And so does a single job.
	 */
	public function test_single_uses_the_plugin_template_by_default() {
		$this->go_to( get_permalink( $this->job_id ) );

		$this->assertTrue( is_singular( Post_Type::POST_TYPE ) );
		$this->assertSame(
			Templates::locate( 'single.php' ),
			Templates::template_include( 'theme-single.php' )
		);
	}

	// ------------------------------------------------------------------
	// Disabled mode
	// ------------------------------------------------------------------

	/**
	 * With the setting on, the theme's own template is handed back untouched.
	 */
	public function test_archive_leaves_the_theme_template_alone_when_disabled() {
		$this->set_disabled( true );
		$this->go_to( get_post_type_archive_link( Post_Type::POST_TYPE ) );

		$this->assertFalse( Templates::frontend_enabled() );
		$this->assertSame( 'theme-archive.php', Templates::template_include( 'theme-archive.php' ) );
	}

	/**
	 * The same for a single job.
	 */
	public function test_single_leaves_the_theme_template_alone_when_disabled() {
		$this->set_disabled( true );
		$this->go_to( get_permalink( $this->job_id ) );

		$this->assertSame( 'theme-single.php', Templates::template_include( 'theme-single.php' ) );
	}

	/**
	 * The URLs still resolve. Disabling rendering must not unregister a route.
	 */
	public function test_urls_still_resolve_when_disabled() {
		$this->set_disabled( true );

		$this->go_to( get_post_type_archive_link( Post_Type::POST_TYPE ) );
		$this->assertTrue( is_post_type_archive( Post_Type::POST_TYPE ) );
		$this->assertFalse( is_404() );

		$this->go_to( get_permalink( $this->job_id ) );
		$this->assertTrue( is_singular( Post_Type::POST_TYPE ) );
		$this->assertFalse( is_404() );
		$this->assertSame( $this->job_id, get_queried_object_id() );
	}

	/**
	 * Permalinks are untouched by the setting.
	 */
	public function test_permalinks_are_unchanged_when_disabled() {
		$before_single  = get_permalink( $this->job_id );
		$before_archive = get_post_type_archive_link( Post_Type::POST_TYPE );

		$this->set_disabled( true );

		$this->assertSame( $before_single, get_permalink( $this->job_id ) );
		$this->assertSame( $before_archive, get_post_type_archive_link( Post_Type::POST_TYPE ) );
	}

	/**
	 * The job data itself is completely unaffected.
	 */
	public function test_job_data_survives_when_disabled() {
		$this->set_disabled( true );

		$job = jszr_get_job( $this->job_id );

		$this->assertSame( 'Content Writer', $job['title'] );
		$this->assertSame( 'CW-003', $job['job_code'] );
		$this->assertSame( 'active', Job::get_status( $this->job_id ) );

		$listing = jszr_get_jobs( array( 'search' => 'writer' ) );

		$this->assertSame( 1, $listing['total'] );
	}

	/**
	 * The expired-job handler must not redirect once the site owns the page.
	 */
	public function test_no_redirect_is_generated_when_disabled() {
		update_post_meta( $this->job_id, Job::META_STATUS, 'expired' );

		$settings = (array) get_option( Settings::OPTION, array() );

		$settings['expired_behavior']              = 'redirect';
		$settings['disable_default_jobs_frontend'] = true;

		update_option( Settings::OPTION, $settings );
		Settings::flush_cache();

		$this->go_to( get_permalink( $this->job_id ) );

		$redirected = false;

		$watch = static function () use ( &$redirected ) {
			$redirected = true;

			return false;
		};

		add_filter( 'wp_redirect', $watch );
		Templates::handle_expired_job();
		remove_filter( 'wp_redirect', $watch );

		$this->assertFalse( $redirected );
	}

	/**
	 * A site can take the pages over conditionally, without the setting.
	 */
	public function test_the_filter_can_disable_the_frontend() {
		add_filter( 'jszr_frontend_enabled', '__return_false' );
		$enabled = Templates::frontend_enabled();
		remove_filter( 'jszr_frontend_enabled', '__return_false' );

		$this->assertFalse( $enabled );
		$this->assertTrue( Templates::frontend_enabled() );
	}

	// ------------------------------------------------------------------
	// Template overrides and filters
	// ------------------------------------------------------------------

	/**
	 * With no override, the plugin's own file is used.
	 */
	public function test_template_falls_back_to_the_plugin() {
		$path = Templates::locate( 'card.php' );

		$this->assertStringEndsWith( 'templates/card.php', str_replace( '\\', '/', $path ) );
		$this->assertFileExists( $path );
	}

	/**
	 * A theme override is picked up ahead of the plugin's copy.
	 */
	public function test_a_theme_override_wins() {
		$custom = '/theme/jobs-sync-for-zoho-recruit/card.php';

		$filter = static function ( $found, $template ) use ( $custom ) {
			return 'card.php' === $template ? $custom : $found;
		};

		add_filter( 'jszr_template_path', $filter, 10, 2 );
		$path = Templates::locate( 'card.php' );
		remove_filter( 'jszr_template_path', $filter, 10 );

		$this->assertSame( $custom, $path );
	}

	/**
	 * The archive template can be swapped wholesale.
	 */
	public function test_the_archive_template_can_be_filtered() {
		$this->go_to( get_post_type_archive_link( Post_Type::POST_TYPE ) );

		$filter = static function () {
			return '/custom/jobs.php';
		};

		add_filter( 'jszr_jobs_template', $filter );
		$path = Templates::template_include( 'theme-archive.php' );
		remove_filter( 'jszr_jobs_template', $filter );

		$this->assertSame( '/custom/jobs.php', $path );
	}

	/**
	 * So can the single job template.
	 */
	public function test_the_job_template_can_be_filtered() {
		$this->go_to( get_permalink( $this->job_id ) );

		$filter = static function () {
			return '/custom/job.php';
		};

		add_filter( 'jszr_job_template', $filter );
		$path = Templates::template_include( 'theme-single.php' );
		remove_filter( 'jszr_job_template', $filter );

		$this->assertSame( '/custom/job.php', $path );
	}

	/**
	 * Job data can be reshaped before a custom frontend sees it.
	 */
	public function test_job_data_can_be_filtered() {
		$filter = static function ( $job ) {
			$job['title'] = 'Filtered';

			return $job;
		};

		add_filter( 'jszr_job_data', $filter );
		$job = jszr_get_job( $this->job_id );
		remove_filter( 'jszr_job_data', $filter );

		$this->assertSame( 'Filtered', $job['title'] );
	}

	/**
	 * And so can the query behind a custom listing.
	 */
	public function test_the_listing_query_can_be_filtered() {
		$filter = static function ( $args ) {
			$args['posts_per_page'] = 1;

			return $args;
		};

		add_filter( 'jszr_jobs_query_args', $filter );
		$listing = jszr_get_jobs();
		remove_filter( 'jszr_jobs_query_args', $filter );

		$this->assertLessThanOrEqual( 1, count( $listing['jobs'] ) );
	}

	/**
	 * A non-job post gets an empty array rather than a broken shape.
	 */
	public function test_get_job_rejects_a_non_job() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( array(), jszr_get_job( $page ) );
	}

	// ------------------------------------------------------------------
	// AJAX render endpoint
	// ------------------------------------------------------------------

	/**
	 * Boot the REST server once per test that needs it.
	 */
	private function rest_server() {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		return $wp_rest_server;
	}

	/**
	 * The endpoint answers with markup, data and no redirect.
	 */
	public function test_render_endpoint_returns_html_and_data() {
		$server   = $this->rest_server();
		$request  = new WP_REST_Request( 'GET', '/jobs-sync-zoho-recruit/v1/jobs/render' );
		$response = $server->dispatch( $request );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $body['success'] );

		// The whole point: an AJAX caller is never told to navigate.
		$this->assertFalse( $body['redirect'] );

		$this->assertStringContainsString( 'jszr-jobs__results', $body['html'] );
		$this->assertStringContainsString( 'Content Writer', $body['html'] );
		$this->assertSame( 1, $body['pagination']['total'] );
		$this->assertSame( 'Content Writer', $body['data'][0]['title'] );
	}

	/**
	 * Searching through the endpoint narrows both the markup and the data.
	 */
	public function test_render_endpoint_honours_search() {
		$server  = $this->rest_server();
		$request = new WP_REST_Request( 'GET', '/jobs-sync-zoho-recruit/v1/jobs/render' );
		$request->set_param( 'search', 'zzzznope' );

		$body = $server->dispatch( $request )->get_data();

		$this->assertSame( 0, $body['pagination']['total'] );
		$this->assertSame( array(), $body['data'] );
		$this->assertStringContainsString( 'jszr-state', $body['html'] );
	}

	/**
	 * Invalid input is rejected by the schema rather than reaching the query.
	 */
	public function test_render_endpoint_validates_input() {
		$server  = $this->rest_server();
		$request = new WP_REST_Request( 'GET', '/jobs-sync-zoho-recruit/v1/jobs/render' );
		$request->set_param( 'style', 'not-a-style' );

		$response = $server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * An anonymous visitor can read it: the listing is public either way.
	 */
	public function test_render_endpoint_is_public() {
		wp_set_current_user( 0 );

		$server   = $this->rest_server();
		$response = $server->dispatch( new WP_REST_Request( 'GET', '/jobs-sync-zoho-recruit/v1/jobs/render' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Turning the public API off closes this endpoint with it.
	 */
	public function test_render_endpoint_respects_the_api_switch() {
		$settings = (array) get_option( Settings::OPTION, array() );

		$settings['rest_enabled'] = false;

		update_option( Settings::OPTION, $settings );
		Settings::flush_cache();

		$server   = $this->rest_server();
		$response = $server->dispatch( new WP_REST_Request( 'GET', '/jobs-sync-zoho-recruit/v1/jobs/render' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * The endpoint keeps working when the default frontend is switched off --
	 * that is the whole point of it for a custom frontend.
	 */
	public function test_render_endpoint_works_when_the_frontend_is_disabled() {
		$this->set_disabled( true );

		$server   = $this->rest_server();
		$response = $server->dispatch( new WP_REST_Request( 'GET', '/jobs-sync-zoho-recruit/v1/jobs/render' ) );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $body['pagination']['total'] );
	}
}
