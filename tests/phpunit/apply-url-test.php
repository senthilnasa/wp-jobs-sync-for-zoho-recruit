<?php
/**
 * Resolving the application URL.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\Settings;

/**
 * Zoho's Job Openings API returns no link to the public job posting, so the
 * apply button has to be built. These cover the order the sources are tried in
 * and the shape of the career-site link.
 */
class JSZR_Apply_Url_Test extends WP_UnitTestCase {

	/**
	 * The Zoho record ID used throughout, shaped like a real one.
	 */
	const ZOHO_ID = '610716000003764097';

	/**
	 * Job under test.
	 *
	 * @var int
	 */
	private $job_id;

	/**
	 * Start from defaults with one synced job.
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
				'post_name'   => 'content-writer-3',
			)
		);

		update_post_meta( $this->job_id, Job::META_STATUS, 'active' );
		update_post_meta( $this->job_id, Job::META_ZOHO_ID, self::ZOHO_ID );
		update_post_meta( $this->job_id, '_jszr_job_code', 'ZR_1_JOB' );
	}

	/**
	 * Write one setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 */
	private function set_setting( $key, $value ) {
		$settings = (array) get_option( Settings::OPTION, array() );

		$settings[ $key ] = $value;

		update_option( Settings::OPTION, $settings );
		Settings::flush_cache();
	}

	/**
	 * With nothing configured there is no link, and so no apply button.
	 *
	 * This is what a stock install against a real Zoho account actually does:
	 * the API sends no application URL, so there is nothing to fall back on.
	 */
	public function test_no_link_without_configuration() {
		$this->assertSame( '', Job::get_apply_url( $this->job_id ) );
	}

	/**
	 * The career site address is enough on its own.
	 *
	 * The default path is the record ID and nothing else, which is the shortest
	 * thing Zoho will resolve.
	 */
	public function test_career_site_builds_a_link() {
		$this->set_setting( 'career_site_url', 'https://careers.example.edu.in' );

		$this->assertSame(
			'https://careers.example.edu.in/jobs/Careers/' . self::ZOHO_ID . '/',
			Job::get_apply_url( $this->job_id )
		);
	}

	/**
	 * A trailing slash on the setting must not double up in the path.
	 */
	public function test_trailing_slash_is_tolerated() {
		$this->set_setting( 'career_site_url', 'https://example.zohorecruit.com/' );

		$this->assertStringContainsString( '.com/jobs/Careers/', Job::get_apply_url( $this->job_id ) );
		$this->assertStringNotContainsString( '.com//jobs', Job::get_apply_url( $this->job_id ) );
	}

	/**
	 * The path pattern is configurable, and {title} is readable.
	 *
	 * The post slug here is content-writer-3, because WordPress had to
	 * de-duplicate it. Zoho serves the posting from the record ID and treats
	 * the rest of the path as decoration -- verified against a live career site,
	 * where the ID alone and a deliberately wrong title both returned the right
	 * job -- so {title} gives the readable title without the numeric suffix.
	 */
	public function test_the_path_pattern_is_configurable() {
		$this->set_setting( 'career_site_url', 'https://example.zohorecruit.com' );
		$this->set_setting( 'career_site_path', '/jobs/Careers/{zoho_id}/{title}' );

		$this->assertSame(
			'https://example.zohorecruit.com/jobs/Careers/' . self::ZOHO_ID . '/content-writer',
			Job::get_apply_url( $this->job_id )
		);
	}

	/**
	 * Every token the settings screen documents actually resolves.
	 */
	public function test_every_path_token_resolves() {
		$this->set_setting( 'career_site_url', 'https://example.zohorecruit.com' );
		$this->set_setting(
			'career_site_path',
			'/{zoho_id}/{job_code}/{slug}/{title}/{id}'
		);

		$this->assertSame(
			'https://example.zohorecruit.com/' . self::ZOHO_ID
				. '/ZR_1_JOB/content-writer-3/content-writer/' . $this->job_id,
			Job::get_apply_url( $this->job_id )
		);
	}

	/**
	 * An empty pattern restores the default rather than breaking the button.
	 */
	public function test_an_empty_path_falls_back_to_the_default() {
		$this->set_setting( 'career_site_url', 'https://example.zohorecruit.com' );
		$this->set_setting( 'career_site_path', '   ' );

		$this->assertStringEndsWith(
			'/jobs/Careers/' . self::ZOHO_ID . '/',
			Job::get_apply_url( $this->job_id )
		);
	}

	/**
	 * A pattern cannot be used to point the apply button at another host.
	 *
	 * The career site address is the administrator's; the path is only a path.
	 * A scheme, an authority or a protocol-relative prefix is stripped rather
	 * than honoured, so a mistake here cannot send candidates elsewhere.
	 */
	public function test_a_path_cannot_change_the_host() {
		$this->set_setting( 'career_site_url', 'https://example.zohorecruit.com' );

		foreach ( array(
			'https://evil.example/jobs/{zoho_id}',
			'//evil.example/jobs/{zoho_id}',
			'../../evil/{zoho_id}',
		) as $attempt ) {
			$this->set_setting( 'career_site_path', $attempt );

			$url = Job::get_apply_url( $this->job_id );

			// The host is the property that matters. A stripped "//host" ends up
			// as a harmless path segment, which is why this checks the parsed
			// host rather than searching the string.
			$this->assertSame(
				'example.zohorecruit.com',
				(string) wp_parse_url( $url, PHP_URL_HOST ),
				$attempt
			);

			$this->assertStringStartsWith( 'https://example.zohorecruit.com/', $url, $attempt );
		}
	}

	/**
	 * A job added by hand has no Zoho record, so there is nothing to link to.
	 */
	public function test_manual_jobs_get_no_career_site_link() {
		$this->set_setting( 'career_site_url', 'https://example.zohorecruit.com' );

		$manual = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Added By Hand',
			)
		);

		$this->assertSame( '', Job::get_apply_url( $manual ) );
	}

	/**
	 * A URL that came from Zoho still wins over anything built here.
	 */
	public function test_a_stored_url_wins() {
		$this->set_setting( 'career_site_url', 'https://example.zohorecruit.com' );
		update_post_meta( $this->job_id, '_zoho_recruit_application_url', 'https://apply.example.org/job/1' );

		$this->assertSame( 'https://apply.example.org/job/1', Job::get_apply_url( $this->job_id ) );
	}

	/**
	 * The existing template setting keeps precedence over the career site, so
	 * sites that already configured one are unaffected.
	 */
	public function test_the_template_still_wins_over_the_career_site() {
		$this->set_setting( 'career_site_url', 'https://example.zohorecruit.com' );
		$this->set_setting( 'apply_url_template', 'https://jobs.example.org/{job_code}' );

		$this->assertSame( 'https://jobs.example.org/ZR_1_JOB', Job::get_apply_url( $this->job_id ) );
	}

	/**
	 * Tracking parameters are still appended to a career-site link.
	 */
	public function test_utm_parameters_are_appended() {
		$this->set_setting( 'career_site_url', 'https://example.zohorecruit.com' );
		$this->set_setting( 'apply_utm', 'utm_source=careers-site' );

		$this->assertStringContainsString( 'utm_source=careers-site', Job::get_apply_url( $this->job_id ) );
	}

	/**
	 * The filter still has the last word.
	 */
	public function test_the_filter_still_wins() {
		$this->set_setting( 'career_site_url', 'https://example.zohorecruit.com' );

		$filter = static function () {
			return 'https://elsewhere.example/apply';
		};

		add_filter( 'jszr_apply_url', $filter );
		$url = Job::get_apply_url( $this->job_id );
		remove_filter( 'jszr_apply_url', $filter );

		$this->assertSame( 'https://elsewhere.example/apply', $url );
	}

	/**
	 * The apply button appears on the job page once a link can be built.
	 */
	public function test_the_button_appears_once_a_link_exists() {
		$this->assertSame( '', jszr_apply_link( $this->job_id ) );

		$this->set_setting( 'career_site_url', 'https://example.zohorecruit.com' );

		$this->assertStringContainsString( 'jszr-apply-button', jszr_apply_link( $this->job_id ) );
	}
}
