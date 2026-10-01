<?php
/**
 * Fetching the Job Apply URL by record ID.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Encryption;
use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Settings;

/**
 * Zoho only returns the Job Apply URL from Get Record by ID, and only with
 * `publish_URL=true` in the request. These pin down that the request carries
 * it, that a record from the list endpoint is fetched once more to get the
 * link, and that the extra call is made at most once per job.
 */
class JSZR_Apply_Url_Fetch_Test extends WP_UnitTestCase {

	/**
	 * The Zoho record ID used throughout.
	 */
	const ZOHO_ID = '610716000003764097';

	/**
	 * The link the fake account publishes.
	 */
	const APPLY_URL = 'https://careers.example.test/jobs/Careers/610716000003764097/apply';

	/**
	 * URLs the plugin requested, in order.
	 *
	 * @var string[]
	 */
	private $requests = array();

	/**
	 * What the fake Get Record by ID answers with. Null means an HTTP failure.
	 *
	 * @var array|null
	 */
	private $by_id_response = array();

	/**
	 * Fake a connected account and answer Zoho's endpoints locally.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Settings::flush_cache();

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

		delete_option( 'jszr_auth_failures' );

		$this->requests       = array();
		$this->by_id_response = $this->full_record();

		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * Remove the fake account.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );

		delete_option( 'jszr_credentials' );
		delete_option( 'jszr_tokens' );

		parent::tear_down();
	}

	/**
	 * Answer a request the plugin makes.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return array|WP_Error|mixed
	 */
	public function answer( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'zoho' ) ) {
			return $preempt;
		}

		$this->requests[] = $url;

		if ( false !== strpos( $url, '/oauth/v2/token' ) ) {
			return $this->response(
				array(
					'access_token' => 'fake-access-token',
					'expires_in'   => 3600,
					'api_domain'   => 'https://recruit.zoho.com',
					'token_type'   => 'Bearer',
				)
			);
		}

		if ( false !== strpos( $url, '/JobOpenings/' . self::ZOHO_ID ) ) {
			if ( null === $this->by_id_response ) {
				return new WP_Error( 'http_request_failed', 'Simulated connection reset' );
			}

			return $this->response( array( 'data' => array( $this->by_id_response ) ) );
		}

		return $this->response( null, 204 );
	}

	/**
	 * Shape a WordPress HTTP API response.
	 *
	 * @param array|null $body   Body to encode, or null for no content.
	 * @param int        $status HTTP status.
	 * @return array
	 */
	private function response( $body, $status = 200 ) {
		return array(
			'headers'  => array(),
			'body'     => null === $body ? '' : wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A record as the list endpoint returns it: no link anywhere.
	 *
	 * @param array $overrides Fields to change.
	 * @return array
	 */
	private function list_record( array $overrides = array() ) {
		return array_merge(
			array(
				'id'                        => self::ZOHO_ID,
				'Posting_Title'             => 'Content Writer',
				'Job_Opening_ID'            => 'ZR_1_JOB',
				'Job_Opening_Status'        => 'In-progress',
				'Publish_in_Career_Website' => true,
			),
			$overrides
		);
	}

	/**
	 * The same record as Get Record by ID returns it with publish_URL=true.
	 *
	 * @return array
	 */
	private function full_record() {
		return $this->list_record( array( 'Job_Apply_URL' => self::APPLY_URL ) );
	}

	/**
	 * The by-ID requests made so far.
	 *
	 * @return string[]
	 */
	private function by_id_requests() {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $url ) {
					return false !== strpos( $url, '/JobOpenings/' . self::ZOHO_ID );
				}
			)
		);
	}

	/**
	 * Get Record by ID asks Zoho for the apply link.
	 */
	public function test_get_record_requests_the_publish_url() {
		$record = JobsSyncForZohoRecruit\plugin()->api()->get_record( self::ZOHO_ID );

		$this->assertSame( self::APPLY_URL, $record['Job_Apply_URL'] );

		$by_id = $this->by_id_requests();

		$this->assertCount( 1, $by_id );

		$query = array();
		wp_parse_str( (string) wp_parse_url( $by_id[0], PHP_URL_QUERY ), $query );

		$this->assertSame( 'true', $query['publish_URL'] );
	}

	/**
	 * A record from the list endpoint is fetched once more for its link, and
	 * the link is what the apply button renders.
	 */
	public function test_list_record_is_completed_from_a_by_id_fetch() {
		Settings::update( array( 'career_site_url' => 'https://fallback.example.test' ) );

		$sync   = JobsSyncForZohoRecruit\plugin()->sync();
		$action = $sync->process_record( $this->list_record() );

		$this->assertSame( 'created', $action );
		$this->assertCount( 1, $this->by_id_requests() );

		$post_id = Job::find_by_zoho_id( self::ZOHO_ID );

		$this->assertSame( self::APPLY_URL, get_post_meta( $post_id, '_zoho_recruit_application_url', true ) );
		$this->assertSame( self::APPLY_URL, Job::get_apply_url( $post_id ) );
	}

	/**
	 * The stored link is reused on later syncs instead of another API call,
	 * and it survives the overwrite-all conflict mode.
	 */
	public function test_stored_link_is_reused_not_refetched() {
		Settings::update( array( 'conflict_mode' => 'overwrite_all' ) );

		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$sync->process_record( $this->list_record() );
		$this->assertCount( 1, $this->by_id_requests() );

		$sync->process_record( $this->list_record( array( 'Posting_Title' => 'Senior Content Writer' ) ) );
		$this->assertCount( 1, $this->by_id_requests(), 'the second sync must not fetch by ID again' );

		$post_id = Job::find_by_zoho_id( self::ZOHO_ID );

		$this->assertSame( 'Senior Content Writer', get_the_title( $post_id ) );
		$this->assertSame( self::APPLY_URL, get_post_meta( $post_id, '_zoho_recruit_application_url', true ) );
	}

	/**
	 * A forced re-sync asks Zoho again.
	 */
	public function test_force_refreshes_the_link() {
		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$sync->process_record( $this->list_record() );

		$this->by_id_response = $this->list_record( array( 'Job_Apply_URL' => 'https://careers.example.test/jobs/Careers/moved/apply' ) );

		$sync->process_record( $this->list_record(), 0, false, true );

		$this->assertCount( 2, $this->by_id_requests() );

		$post_id = Job::find_by_zoho_id( self::ZOHO_ID );

		$this->assertSame( 'https://careers.example.test/jobs/Careers/moved/apply', get_post_meta( $post_id, '_zoho_recruit_application_url', true ) );
	}

	/**
	 * A record that already came from Get Record by ID is not fetched twice.
	 */
	public function test_complete_record_is_not_fetched_again() {
		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$sync->process_record( $this->list_record(), 0, false, false, array( 'complete' => true ) );

		$this->assertCount( 0, $this->by_id_requests() );
		$this->assertGreaterThan( 0, Job::find_by_zoho_id( self::ZOHO_ID ) );
	}

	/**
	 * A record that already carries the link needs no extra call either.
	 */
	public function test_record_with_a_link_is_not_fetched() {
		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$sync->process_record( $this->full_record() );

		$this->assertCount( 0, $this->by_id_requests() );

		$post_id = Job::find_by_zoho_id( self::ZOHO_ID );

		$this->assertSame( self::APPLY_URL, get_post_meta( $post_id, '_zoho_recruit_application_url', true ) );
	}

	/**
	 * Jobs that are not active render no apply button, so they are not fetched.
	 */
	public function test_inactive_record_is_not_fetched() {
		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$sync->process_record( $this->list_record( array( 'Job_Opening_Status' => 'Closed' ) ) );

		$this->assertCount( 0, $this->by_id_requests() );
		$this->assertGreaterThan( 0, Job::find_by_zoho_id( self::ZOHO_ID ) );
	}

	/**
	 * Previews write nothing and so fetch nothing.
	 */
	public function test_dry_run_does_not_fetch() {
		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$this->assertSame( 'created', $sync->process_record( $this->list_record(), 0, true ) );
		$this->assertCount( 0, $this->by_id_requests() );
	}

	/**
	 * With the setting off the sync behaves as it did before: no extra call,
	 * and the career site fallback builds the link.
	 */
	public function test_setting_off_skips_the_fetch() {
		Settings::update(
			array(
				'fetch_apply_url' => false,
				'career_site_url' => 'https://fallback.example.test',
			)
		);

		$sync = JobsSyncForZohoRecruit\plugin()->sync();

		$sync->process_record( $this->list_record() );

		$this->assertCount( 0, $this->by_id_requests() );

		$post_id = Job::find_by_zoho_id( self::ZOHO_ID );

		$this->assertSame( '', get_post_meta( $post_id, '_zoho_recruit_application_url', true ) );
		$this->assertSame( 'https://fallback.example.test/jobs/Careers/' . self::ZOHO_ID . '/', Job::get_apply_url( $post_id ) );
	}

	/**
	 * A failed fetch never fails the record.
	 */
	public function test_failed_fetch_still_writes_the_job() {
		Settings::update( array( 'career_site_url' => 'https://fallback.example.test' ) );

		$this->by_id_response = null;

		$sync   = JobsSyncForZohoRecruit\plugin()->sync();
		$action = $sync->process_record( $this->list_record() );

		$this->assertSame( 'created', $action );

		$post_id = Job::find_by_zoho_id( self::ZOHO_ID );

		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( '', get_post_meta( $post_id, '_zoho_recruit_application_url', true ) );
		$this->assertSame( 'https://fallback.example.test/jobs/Careers/' . self::ZOHO_ID . '/', Job::get_apply_url( $post_id ) );

		// The API client retries a failed request, so the exact number of
		// attempts is its business; what matters is that the failure was
		// survived and the next sync asks exactly once more.
		$attempts = count( $this->by_id_requests() );

		$this->assertGreaterThanOrEqual( 1, $attempts );

		$this->by_id_response = $this->full_record();

		$sync->process_record( $this->list_record() );

		$this->assertCount( $attempts + 1, $this->by_id_requests() );
		$this->assertSame( self::APPLY_URL, get_post_meta( $post_id, '_zoho_recruit_application_url', true ) );
	}
}
