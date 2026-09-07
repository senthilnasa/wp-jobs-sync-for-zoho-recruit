<?php
/**
 * Field mapper tests.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Field_Mapper;
use JobsSyncForZohoRecruit\Post_Type;

/**
 * The mapper is pure translation, which makes it the cheapest place to pin down
 * the behaviour every sync depends on.
 */
class JSZR_Field_Mapper_Test extends WP_UnitTestCase {

	/**
	 * Reset mapper state between tests.
	 */
	public function set_up() {
		parent::set_up();

		Field_Mapper::flush_cache();
		delete_option( Field_Mapper::OPTION );
	}

	/**
	 * A lookup object should collapse to its display name.
	 */
	public function test_lookup_transform_uses_name() {
		$value = array(
			'id'   => '1234567890',
			'name' => 'Engineering',
		);

		$this->assertSame( 'Engineering', Field_Mapper::transform( $value, 'lookup_name' ) );
	}

	/**
	 * A multi-select picklist should become an array of clean strings.
	 */
	public function test_text_transform_keeps_multiselect_as_array() {
		$result = Field_Mapper::transform( array( 'Full Time', 'Remote' ), 'text' );

		$this->assertSame( array( 'Full Time', 'Remote' ), $result );
	}

	/**
	 * The join transform should produce a readable comma separated string.
	 */
	public function test_join_transform() {
		$this->assertSame(
			'Full Time, Remote',
			Field_Mapper::transform( array( 'Full Time', 'Remote' ), 'join' )
		);
	}

	/**
	 * An ISO-8601 value carrying an offset must not be read as UTC.
	 */
	public function test_datetime_respects_offset() {
		$timestamp = Field_Mapper::to_timestamp( '2026-09-30T18:30:00+05:30' );

		$this->assertSame( gmmktime( 13, 0, 0, 9, 30, 2026 ), $timestamp );
	}

	/**
	 * A bare date is interpreted in the site timezone, not in UTC.
	 */
	public function test_bare_date_uses_site_timezone() {
		update_option( 'timezone_string', 'Asia/Kolkata' );

		$timestamp = Field_Mapper::to_timestamp( '2026-09-30' );

		// Midnight in Asia/Kolkata is 18:30 UTC on the previous day.
		$this->assertSame( gmmktime( 18, 30, 0, 9, 29, 2026 ), $timestamp );

		update_option( 'timezone_string', '' );
	}

	/**
	 * Unparseable input must not throw.
	 */
	public function test_invalid_date_returns_empty_string() {
		$this->assertSame( '', Field_Mapper::to_date( 'not a date' ) );
		$this->assertNull( Field_Mapper::to_timestamp( '' ) );
	}

	/**
	 * Boolean-ish Zoho values.
	 *
	 * @dataProvider boolean_provider
	 *
	 * @param mixed $value    Raw value.
	 * @param bool  $expected Expected result.
	 */
	public function test_boolean_interpretation( $value, $expected ) {
		$this->assertSame( $expected, Field_Mapper::to_boolean( $value ) );
	}

	/**
	 * Data provider for boolean interpretation.
	 *
	 * @return array
	 */
	public function boolean_provider() {
		return array(
			array( true, true ),
			array( 'true', true ),
			array( 'Yes', true ),
			array( 1, true ),
			array( false, false ),
			array( 'false', false ),
			array( 'No', false ),
			array( 0, false ),
			array( '', false ),
		);
	}

	/**
	 * A salary range should yield a min, a max, a currency and a unit.
	 */
	public function test_salary_range_parsing() {
		$parsed = Field_Mapper::parse_salary( 'USD 90,000 - 120,000 per year' );

		$this->assertSame( 'USD', $parsed['currency'] );
		$this->assertSame( 'YEAR', $parsed['unit'] );
		$this->assertSame( 90000.0, (float) $parsed['min'] );
		$this->assertSame( 120000.0, (float) $parsed['max'] );
	}

	/**
	 * Lakh notation is common in Indian job postings.
	 */
	public function test_salary_lakh_multiplier() {
		$parsed = Field_Mapper::parse_salary( 'INR 12 LPA' );

		$this->assertSame( 'INR', $parsed['currency'] );
		$this->assertSame( 'YEAR', $parsed['unit'] );
		$this->assertSame( 1200000.0, (float) $parsed['min'] );
	}

	/**
	 * A salary with no numbers must not invent any.
	 */
	public function test_salary_without_numbers_is_empty() {
		$parsed = Field_Mapper::parse_salary( 'Competitive' );

		$this->assertSame( '', $parsed['min'] );
		$this->assertSame( '', $parsed['max'] );
	}

	/**
	 * Description HTML is sanitized, and scripts never survive.
	 */
	public function test_html_transform_strips_scripts() {
		$html = Field_Mapper::transform(
			'<p>Build things.</p><script>alert(1)</script>',
			'html'
		);

		$this->assertStringContainsString( 'Build things.', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * A full record maps into post fields, meta and terms.
	 */
	public function test_map_produces_post_meta_and_terms() {
		$mapper = new Field_Mapper();

		$record = array(
			'id'                    => '4876000000123456',
			'Posting_Title'         => 'Software Developer',
			'Job_Description'       => '<p>Write code.</p>',
			'Job_Opening_ID'        => 'JOB-17',
			'Job_Opening_Status'    => 'In-progress',
			'Department_Name'       => array(
				'id'   => '99',
				'name' => 'Engineering',
			),
			'Job_Type'              => 'Full Time',
			'City'                  => 'Chennai',
			'State'                 => 'Tamil Nadu',
			'Country'               => 'India',
			'Expected_Closing_Date' => '2026-12-31',
		);

		$payload = $mapper->map( $record );

		$this->assertSame( 'Software Developer', $payload['post']['post_title'] );
		$this->assertStringContainsString( 'Write code.', $payload['post']['post_content'] );
		$this->assertSame( 'JOB-17', $payload['meta']['_jszr_job_code'] );
		$this->assertSame( 'Chennai', $payload['meta']['_zoho_recruit_city'] );
		$this->assertSame( '2026-12-31', $payload['meta']['_zoho_recruit_closing_date'] );
		$this->assertSame( array( 'Engineering' ), $payload['terms'][ Post_Type::TAX_DEPARTMENT ] );

		// Location is built as a country > state > city path.
		$this->assertSame(
			array( array( 'India', 'Tamil Nadu', 'Chennai' ) ),
			$payload['terms'][ Post_Type::TAX_LOCATION ]
		);
	}

	/**
	 * A record with no usable title still produces one.
	 */
	public function test_map_falls_back_to_a_title() {
		$mapper = new Field_Mapper();

		$payload = $mapper->map(
			array(
				'id'             => '1',
				'Job_Opening_ID' => 'JOB-9',
			)
		);

		$this->assertSame( 'JOB-9', $payload['post']['post_title'] );
	}

	/**
	 * Mapping rows that point at unknown targets are rejected.
	 */
	public function test_sanitize_mapping_drops_invalid_rows() {
		$clean = Field_Mapper::sanitize_mapping(
			array(
				array(
					'zoho_field' => 'Posting_Title',
					'target'     => 'post_title',
					'transform'  => 'text',
				),
				array(
					'zoho_field' => 'Evil; DROP TABLE',
					'target'     => 'post_title',
					'transform'  => 'text',
				),
				array(
					'zoho_field' => 'City',
					'target'     => 'meta:../../etc/passwd',
					'transform'  => 'text',
				),
			)
		);

		$this->assertCount( 2, $clean );
		$this->assertSame( 'EvilDROPTABLE', $clean[1]['zoho_field'] );
		$this->assertSame( 'post_title', $clean[1]['target'] );
	}

	/**
	 * Exported mapping can be imported again unchanged.
	 */
	public function test_export_import_round_trip() {
		$original = Field_Mapper::get_mapping();
		$json     = Field_Mapper::export();

		Field_Mapper::reset_mapping();

		$this->assertTrue( Field_Mapper::import( $json ) );
		$this->assertSame( $original, Field_Mapper::get_mapping() );
	}
}
