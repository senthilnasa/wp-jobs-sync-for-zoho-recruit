<?php
/**
 * Diagnostics catching a mapping that points at fields the account lacks.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Diagnostics;
use JobsSyncForZohoRecruit\Field_Mapper;
use JobsSyncForZohoRecruit\Field_Metadata;
use JobsSyncForZohoRecruit\Settings;

/**
 * Every Zoho account renames fields. A mapping pointing at a field that is not
 * there fails silently, so the report has to say so.
 */
class JSZR_Mapping_Check_Test extends WP_UnitTestCase {

	/**
	 * Reset settings, mapping and the cached field list.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		delete_option( Field_Mapper::OPTION );
		delete_option( Diagnostics::OPTION );
		Settings::flush_cache();
	}

	/**
	 * Pretend the account returned this field list.
	 *
	 * @param string[] $names Zoho API names.
	 */
	private function cache_fields( array $names ) {
		$fields = array();

		foreach ( $names as $name ) {
			$fields[ $name ] = array(
				'label' => $name,
				'type'  => 'text',
			);
		}

		update_option( Field_Metadata::OPTION_CACHE, $fields, false );
	}

	/**
	 * Find one check by label.
	 *
	 * @param array  $report Report.
	 * @param string $label  Check label.
	 * @return array|null
	 */
	private function find_check( array $report, $label ) {
		foreach ( (array) $report['checks'] as $check ) {
			if ( $label === $check['label'] ) {
				return $check;
			}
		}

		return null;
	}

	/**
	 * A mapped field the account does not have is reported, and named.
	 */
	public function test_a_missing_mapped_field_is_reported() {
		$this->cache_fields( array( 'Job_Description', 'Client_Name', 'Salary_Budget' ) );

		Field_Mapper::save_mapping(
			array(
				array(
					'zoho_field' => 'Job_Description',
					'target'     => 'post_content',
					'transform'  => 'html',
				),
				array(
					'zoho_field' => 'Department_Name',
					'target'     => 'meta:_zoho_recruit_industry',
					'transform'  => 'text',
				),
			)
		);

		$check = $this->find_check( Diagnostics::run( false ), 'Mapped fields exist in Zoho' );

		$this->assertNotNull( $check );
		$this->assertSame( 'warning', $check['status'] );
		$this->assertStringContainsString( 'Department_Name', $check['detail'] );
		$this->assertStringNotContainsString( 'Job_Description', $check['detail'] );
	}

	/**
	 * A mapping that matches the account passes.
	 */
	public function test_a_matching_mapping_passes() {
		$this->cache_fields( array( 'Job_Description' ) );

		Field_Mapper::save_mapping(
			array(
				array(
					'zoho_field' => 'Job_Description',
					'target'     => 'post_content',
					'transform'  => 'html',
				),
			)
		);

		$check = $this->find_check( Diagnostics::run( false ), 'Mapped fields exist in Zoho' );

		$this->assertSame( 'pass', $check['status'] );
	}

	/**
	 * The publish flag gets its own line, because it is not in the mapping.
	 */
	public function test_a_wrong_publish_flag_is_reported() {
		$this->cache_fields( array( 'Publish', 'Job_Description' ) );

		$settings = (array) get_option( Settings::OPTION, array() );

		$settings['only_published']  = true;
		$settings['published_field'] = 'Publish_in_Career_Website';

		update_option( Settings::OPTION, $settings );
		Settings::flush_cache();

		$check = $this->find_check( Diagnostics::run( false ), 'Publish flag field exists' );

		$this->assertNotNull( $check );
		$this->assertSame( 'warning', $check['status'] );
		$this->assertStringContainsString( 'Publish_in_Career_Website', $check['detail'] );
	}

	/**
	 * The right flag name passes.
	 */
	public function test_the_right_publish_flag_passes() {
		$this->cache_fields( array( 'Publish' ) );

		$settings = (array) get_option( Settings::OPTION, array() );

		$settings['only_published']  = true;
		$settings['published_field'] = 'Publish';

		update_option( Settings::OPTION, $settings );
		Settings::flush_cache();

		$check = $this->find_check( Diagnostics::run( false ), 'Publish flag field exists' );

		$this->assertSame( 'pass', $check['status'] );
	}

	/**
	 * A field the layout does not list but records do carry must not be
	 * reported.
	 *
	 * Posting_Title is the real case: it is absent from the field metadata on
	 * at least one live account, and present on every record that account
	 * returns, so the titles sync perfectly. Reporting it would send someone
	 * to fix a mapping that works.
	 */
	public function test_a_field_seen_only_on_records_is_not_reported() {
		$this->cache_fields( array( 'Job_Description' ) );

		$job = self::factory()->post->create(
			array(
				'post_type'   => \JobsSyncForZohoRecruit\Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta(
			$job,
			\JobsSyncForZohoRecruit\Job::META_RAW,
			wp_slash( (string) wp_json_encode( array( 'Posting_Title' => 'Content Writer' ) ) )
		);

		Field_Mapper::save_mapping(
			array(
				array(
					'zoho_field' => 'Posting_Title',
					'target'     => 'post_title',
					'transform'  => 'text',
				),
				array(
					'zoho_field' => 'Department_Name',
					'target'     => 'meta:_zoho_recruit_industry',
					'transform'  => 'text',
				),
			)
		);

		$check = $this->find_check( Diagnostics::run( false ), 'Mapped fields exist in Zoho' );

		$this->assertStringNotContainsString( 'Posting_Title', $check['detail'] );
		$this->assertStringContainsString( 'Department_Name', $check['detail'] );
	}

	/**
	 * Without a live field list, nothing is claimed. The bundled fallback list
	 * is not evidence about a particular account.
	 */
	public function test_nothing_is_claimed_without_a_live_field_list() {
		delete_option( Field_Metadata::OPTION_CACHE );

		$report = Diagnostics::run( false );

		$this->assertNull( $this->find_check( $report, 'Mapped fields exist in Zoho' ) );
		$this->assertNull( $this->find_check( $report, 'Publish flag field exists' ) );
	}
}
