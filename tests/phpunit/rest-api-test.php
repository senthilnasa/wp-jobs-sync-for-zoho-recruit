<?php
/**
 * REST API tests.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\REST_API;
use JobsSyncForZohoRecruit\Settings;

/**
 * Confirms the public API only exposes what it should, and that the protected
 * endpoints stay protected.
 */
class JSZR_REST_API_Test extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Set up the REST server and a couple of jobs.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		delete_option( Settings::OPTION );
		Settings::flush_cache();
		Settings::update( array( 'cache_ttl' => 0 ) );

		do_action( 'rest_api_init' );
	}

	/**
	 * Create a job.
	 *
	 * @param string $zoho_id Zoho record ID.
	 * @param string $title   Job title.
	 * @param string $status  Normalised status.
	 * @param array  $meta    Extra meta.
	 * @return int Post ID.
	 */
	private function make_job( $zoho_id, $title, $status = 'active', array $meta = array() ) {
		$result = Job::upsert(
			$zoho_id,
			array(
				'post'   => array( 'post_title' => $title ),
				'meta'   => array_merge( array( '_jszr_job_code' => 'JOB-' . $zoho_id ), $meta ),
				'terms'  => array(),
				'mapped' => array(),
			),
			array( 'status' => $status )
		);

		return (int) $result['post_id'];
	}

	/**
	 * The collection returns only active jobs by default.
	 */
	public function test_collection_returns_only_active_jobs() {
		$this->make_job( '1', 'Open Role', 'active' );
		$this->make_job( '2', 'Closed Role', 'closed' );

		$request  = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/jobs' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertCount( 1, $data['data'] );
		$this->assertSame( 'Open Role', $data['data'][0]['title'] );
		$this->assertSame( 1, $data['pagination']['total'] );
	}

	/**
	 * A per_page above the configured maximum is rejected.
	 */
	public function test_per_page_is_capped() {
		Settings::update( array( 'rest_max_per_page' => 50 ) );

		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/jobs' );
		$request->set_param( 'per_page', 500 );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Only allowed fields appear in the response.
	 */
	public function test_field_allow_list_is_enforced() {
		Settings::update(
			array(
				'rest_fields' => array( 'id', 'title', 'link' ),
			)
		);

		$this->make_job( '3', 'Allow List Role', 'active' );

		$request  = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/jobs' );
		$response = $this->server->dispatch( $request );
		$item     = $response->get_data()['data'][0];

		$this->assertSame( array( 'id', 'title', 'link' ), array_keys( $item ) );
		$this->assertArrayNotHasKey( 'zoho_id', $item );
		$this->assertArrayNotHasKey( 'content', $item );
	}

	/**
	 * Internal meta is never registered for the core REST endpoint unless it is
	 * on the allow-list.
	 */
	public function test_raw_data_is_not_public() {
		$post_id = $this->make_job( '4', 'Private Meta Role', 'active' );

		update_post_meta( $post_id, Job::META_RAW, wp_json_encode( array( 'secret' => 'value' ) ) );

		$registered = get_registered_meta_keys( 'post', Post_Type::POST_TYPE );

		$this->assertArrayHasKey( Job::META_RAW, $registered );
		$this->assertFalse( $registered[ Job::META_RAW ]['show_in_rest'] );
	}

	/**
	 * An inactive job must return 404 rather than confirming it exists.
	 */
	public function test_inactive_single_job_is_not_enumerable() {
		$post_id = $this->make_job( '5', 'Hidden Role', 'closed' );

		$request  = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/jobs/' . $post_id );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Keyword search matches the job code as well as the title.
	 */
	public function test_search_matches_job_code() {
		$this->make_job( '6', 'Completely Different Title', 'active' );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/jobs' );
		$request->set_param( 'search', 'JOB-6' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['data'] );
		$this->assertSame( 'Completely Different Title', $data['data'][0]['title'] );
	}

	/**
	 * A filter that matches no term returns nothing, not everything.
	 */
	public function test_unknown_filter_returns_no_results() {
		$this->make_job( '7', 'Some Role', 'active' );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/jobs' );
		$request->set_param( 'department', 'no-such-department' );

		$response = $this->server->dispatch( $request );

		$this->assertCount( 0, $response->get_data()['data'] );
	}

	/**
	 * Anonymous visitors cannot start a sync.
	 */
	public function test_sync_endpoint_requires_capability() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/' . REST_API::NAMESPACE_V1 . '/sync' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The status endpoint is protected too.
	 */
	public function test_status_endpoint_requires_capability() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/sync/status' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Disabling the API hides the public routes.
	 */
	public function test_disabling_the_api_returns_404() {
		Settings::update( array( 'rest_enabled' => false ) );

		$request  = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/jobs' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * The webhook rejects a request without the secret.
	 */
	public function test_webhook_rejects_bad_token() {
		Settings::update( array( 'webhook_enabled' => true ) );

		$request = new WP_REST_Request( 'POST', '/' . REST_API::NAMESPACE_V1 . '/webhook' );
		$request->set_param( 'token', 'wrong-token' );
		$request->set_param( 'id', '123' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
