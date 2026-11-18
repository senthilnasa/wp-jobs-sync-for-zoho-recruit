<?php
/**
 * Formatted fields are read from Get Record by ID, not the list endpoint.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Encryption;
use JobsSyncForZohoRecruit\Field_Mapper;
use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Settings;

/**
 * Zoho's list endpoint sends the job description with its formatting stripped:
 * no tags and no line breaks. On a live site every bulk sync therefore stored
 * one run of plain text whatever the transform, while "Resync from Zoho" --
 * which fetches by ID -- restored the formatting. A list record whose
 * formatted fields are mapped is now completed by ID before it is mapped.
 */
class JSZR_Full_Record_Fetch_Test extends WP_UnitTestCase {

	/**
	 * The Zoho record ID used throughout.
	 */
	const ZOHO_ID = '247098000000400596';

	/**
	 * The description as the list endpoint sends it.
	 */
	const FLAT = 'Job Description Job Title: Senior Executive Department: Procurement Responsibilities: · Handle procurement.';

	/**
	 * The description as Get Record by ID sends it.
	 */
	const RICH = '<p align="center"><b><span>Job Description</span></b><br /></p><p><b>Job Title:</b> Senior Executive<br /><b>Department:</b> Procurement</p><ul style="list-style: none"><li>Handle procurement.</li></ul>';

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
		Field_Mapper::reset_mapping();

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
		$this->by_id_response = $this->list_record( array( 'Job_Description' => self::RICH ) );

		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * Remove the fake account.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
		remove_all_filters( 'jszr_fetch_full_record' );

		delete_option( 'jszr_credentials' );
		delete_option( 'jszr_tokens' );
		Field_Mapper::reset_mapping();
		delete_option( Field_Mapper::OPTION_CHANGED );

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
	 * A record as the list endpoint returns it.
	 *
	 * @param array $overrides Fields to change.
	 * @return array
	 */
	private function list_record( array $overrides = array() ) {
		return array_merge(
			array(
				'id'                        => self::ZOHO_ID,
				'Posting_Title'             => 'Senior Executive-Procurement',
				'Job_Opening_Name'          => 'Senior Executive-Procurement',
				'Job_Opening_ID'            => 'ZR_18_JOB',
				'Job_Opening_Status'        => 'In-progress',
				'Publish_in_Career_Website' => true,
				'Job_Description'           => self::FLAT,
				'Job_Apply_URL'             => 'https://careers.example.test/apply/' . self::ZOHO_ID,
			),
			$overrides
		);
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
	 * Map the description with a transform.
	 *
	 * @param string $transform Transform for the Job_Description row.
	 */
	private function describe_with( $transform ) {
		$mapping = Field_Mapper::default_mapping();

		foreach ( $mapping as $index => $row ) {
			if ( 'Job_Description' === $row['zoho_field'] ) {
				$mapping[ $index ]['transform'] = $transform;
			}
		}

		Field_Mapper::save_mapping( $mapping );
	}

	/**
	 * The stored description of the test job.
	 *
	 * @return string
	 */
	private function stored_description() {
		return (string) get_post_field( 'post_content', Job::find_by_zoho_id( self::ZOHO_ID ) );
	}

	/**
	 * A bulk sync stores the formatted description, not the stripped one.
	 */
	public function test_list_record_description_comes_from_the_full_record() {
		$this->describe_with( 'raw_html' );

		$action = JobsSyncForZohoRecruit\plugin()->sync()->process_record( $this->list_record() );

		$this->assertSame( 'created', $action );
		$this->assertCount( 1, $this->by_id_requests() );

		$content = $this->stored_description();

		$this->assertStringContainsString( '<b>Job Title:</b>', $content );
		$this->assertStringContainsString( 'list-style: none', $content );
		$this->assertStringNotContainsString( '·', $content );
	}

	/**
	 * The default HTML transform gets the tags too.
	 */
	public function test_html_transform_also_gets_the_formatting() {
		$this->describe_with( 'html' );

		JobsSyncForZohoRecruit\plugin()->sync()->process_record( $this->list_record() );

		$this->assertStringContainsString( '<b>Job Title:</b>', $this->stored_description() );
	}

	/**
	 * One fetch serves both the description and the apply link.
	 */
	public function test_one_fetch_serves_the_description_and_the_apply_link() {
		$this->describe_with( 'raw_html' );

		JobsSyncForZohoRecruit\plugin()->sync()->process_record( $this->list_record( array( 'Job_Apply_URL' => null ) ) );

		$this->assertCount( 1, $this->by_id_requests() );
	}

	/**
	 * With the description mapped as plain text there is nothing to gain,
	 * so no extra API credit is spent.
	 */
	public function test_plain_text_mapping_does_not_fetch() {
		$this->describe_with( 'text' );

		JobsSyncForZohoRecruit\plugin()->sync()->process_record( $this->list_record() );

		$this->assertCount( 0, $this->by_id_requests() );
		$this->assertSame( self::FLAT, $this->stored_description() );
	}

	/**
	 * A record that already came from Get Record by ID is not fetched again.
	 */
	public function test_complete_record_is_not_fetched_again() {
		$this->describe_with( 'raw_html' );

		JobsSyncForZohoRecruit\plugin()->sync()->process_record(
			$this->list_record( array( 'Job_Description' => self::RICH ) ),
			0,
			false,
			false,
			array( 'complete' => true )
		);

		$this->assertCount( 0, $this->by_id_requests() );
	}

	/**
	 * A dry run makes no extra calls.
	 */
	public function test_dry_run_does_not_fetch() {
		$this->describe_with( 'raw_html' );

		JobsSyncForZohoRecruit\plugin()->sync()->process_record( $this->list_record(), 0, true );

		$this->assertCount( 0, $this->by_id_requests() );
	}

	/**
	 * The fetch can be switched off to save API credits.
	 */
	public function test_filter_can_switch_the_fetch_off() {
		$this->describe_with( 'raw_html' );
		add_filter( 'jszr_fetch_full_record', '__return_false' );

		JobsSyncForZohoRecruit\plugin()->sync()->process_record( $this->list_record() );

		$this->assertCount( 0, $this->by_id_requests() );
	}

	/**
	 * When the fetch fails, an existing job keeps last sync's formatted
	 * description instead of being overwritten with the stripped copy, while
	 * everything else is still updated.
	 */
	public function test_failed_fetch_keeps_the_formatted_description() {
		$this->describe_with( 'raw_html' );

		$sync = JobsSyncForZohoRecruit\plugin()->sync();
		$sync->process_record( $this->list_record() );

		$this->assertStringContainsString( '<b>Job Title:</b>', $this->stored_description() );

		$this->by_id_response = null;

		$action = $sync->process_record( $this->list_record( array( 'Posting_Title' => 'Senior Executive - Procurement' ) ) );

		$this->assertSame( 'updated', $action );
		$this->assertStringContainsString( '<b>Job Title:</b>', $this->stored_description() );
		$this->assertSame( 'Senior Executive - Procurement', get_post_field( 'post_title', Job::find_by_zoho_id( self::ZOHO_ID ) ) );
	}

	/**
	 * A new job still gets created from the list copy when the fetch fails.
	 */
	public function test_failed_fetch_still_creates_a_new_job() {
		$this->describe_with( 'raw_html' );
		$this->by_id_response = null;

		$action = JobsSyncForZohoRecruit\plugin()->sync()->process_record( $this->list_record() );

		$this->assertSame( 'created', $action );
		$this->assertStringContainsString( 'Senior Executive', $this->stored_description() );
	}

	/**
	 * The fallback gives a formatted meta target its stored value back, so the
	 * overwrite-all conflict mode cannot delete it.
	 */
	public function test_without_rich_text_keeps_stored_meta() {
		Field_Mapper::save_mapping(
			array(
				array(
					'zoho_field' => 'Job_Description',
					'target'     => 'post_content',
					'transform'  => 'raw_html',
				),
				array(
					'zoho_field' => 'Requirements',
					'target'     => 'meta:_zoho_recruit_client',
					'transform'  => 'html',
				),
			)
		);

		$payload = Field_Mapper::without_rich_text(
			array(
				'post'   => array(
					'post_title'   => 'Title',
					'post_content' => 'flat',
				),
				'meta'   => array( '_zoho_recruit_client' => 'flat' ),
				'terms'  => array(),
				'mapped' => array(
					'post_content'              => 'flat',
					'meta:_zoho_recruit_client' => 'flat',
				),
			),
			array( '_zoho_recruit_client' => '<p>kept</p>' )
		);

		$this->assertArrayNotHasKey( 'post_content', $payload['post'] );
		$this->assertSame( 'Title', $payload['post']['post_title'] );
		$this->assertSame( '<p>kept</p>', $payload['meta']['_zoho_recruit_client'] );
		$this->assertArrayNotHasKey( 'post_content', $payload['mapped'] );
	}
}
