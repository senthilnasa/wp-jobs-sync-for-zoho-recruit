<?php
/**
 * Moving the plugin's configuration to another site.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Encryption;
use JobsSyncForZohoRecruit\Field_Mapper;
use JobsSyncForZohoRecruit\Field_Metadata;
use JobsSyncForZohoRecruit\Settings;
use JobsSyncForZohoRecruit\Site_Migration;

/**
 * One file carries the settings, HTML templates, custom CSS, mapping and Zoho
 * field list to a new site, and never a secret.
 */
class JSZR_Site_Migration_Test extends WP_UnitTestCase {

	/**
	 * The address the file pretends to come from.
	 */
	const OLD_SITE = 'https://old-careers.example.test';

	/**
	 * Start every test from a site with nothing configured.
	 */
	public function set_up() {
		parent::set_up();

		$this->reset_site();
	}

	/**
	 * Leave nothing behind for the next test class.
	 */
	public function tear_down() {
		$this->reset_site();

		parent::tear_down();
	}

	/**
	 * Remove every option the migration touches.
	 */
	private function reset_site() {
		delete_option( Settings::OPTION );
		Settings::flush_cache();

		Field_Mapper::reset_mapping();
		delete_option( Field_Mapper::OPTION_CHANGED );

		delete_option( Field_Metadata::OPTION_CACHE );
		delete_option( 'jszr_credentials' );
		delete_option( 'jszr_tokens' );
	}

	/**
	 * A configured careers site, as the old site would have it.
	 */
	private function configure_old_site() {
		Settings::update(
			Settings::sanitize(
				array(
					'card_template'        => '<article class="kc-card"><h3>{title}</h3><a href="' . self::OLD_SITE . '/careers-openings/">All jobs</a></article>',
					'card_templates'       => array(
						array(
							'name' => 'staff',
							'html' => '<div class="kc-staff"><h3>{title}</h3></div>',
						),
					),
					'custom_css'           => '.jszr-filters { background: url(' . self::OLD_SITE . '/wp-content/uploads/bg.png); }',
					'filters_button_label' => 'Search',
					'job_slug'             => 'careers-openings',
					'org_url'              => self::OLD_SITE,
					'notify_email'         => 'hr@old-careers.example.test',
				)
			)
		);

		$mapping = Field_Mapper::default_mapping();

		foreach ( $mapping as $index => $row ) {
			if ( 'Job_Description' === $row['zoho_field'] ) {
				$mapping[ $index ]['transform'] = 'raw_html';
			}
		}

		Field_Mapper::save_mapping( $mapping );

		update_option(
			Field_Metadata::OPTION_CACHE,
			array(
				'School_Name' => array(
					'api_name' => 'School_Name',
					'label'    => 'School',
					'type'     => 'picklist',
					'options'  => array( 'Arts', 'Sciences' ),
				),
			),
			false
		);

		update_option(
			'jszr_credentials',
			array(
				'client_id'     => Encryption::encrypt( 'fake.client.id' ),
				'client_secret' => Encryption::encrypt( 'super-secret-client-value' ),
				'fingerprint'   => Encryption::key_fingerprint(),
			),
			false
		);

		update_option(
			'jszr_tokens',
			array(
				'refresh_token' => Encryption::encrypt( 'super-secret-refresh-value' ),
				'fingerprint'   => Encryption::key_fingerprint(),
			),
			false
		);
	}

	/**
	 * The export as the old site would produce it.
	 *
	 * @return array
	 */
	private function export_from_old_site() {
		$this->configure_old_site();

		$data             = Site_Migration::export();
		$data['site_url'] = self::OLD_SITE;

		return $data;
	}

	/**
	 * The file carries the setup and nothing secret.
	 */
	public function test_export_carries_the_setup_and_no_secrets() {
		$this->configure_old_site();

		$json = Site_Migration::export_json();
		$data = json_decode( $json, true );

		$this->assertSame( 'jobs-sync-for-zoho-recruit', $data['plugin'] );
		$this->assertSame( Site_Migration::FORMAT, $data['format'] );
		$this->assertSame( untrailingslashit( home_url() ), $data['site_url'] );
		$this->assertStringContainsString( 'kc-card', $data['settings']['card_template'] );
		$this->assertCount( 1, $data['settings']['card_templates'] );
		$this->assertStringContainsString( '.jszr-filters', $data['settings']['custom_css'] );
		$this->assertArrayHasKey( 'School_Name', $data['zoho_fields'] );
		$this->assertContains( 'raw_html', wp_list_pluck( $data['mapping'], 'transform' ) );

		$this->assertArrayNotHasKey( 'notify_email', $data['settings'] );
		$this->assertStringNotContainsString( 'hr@old-careers', $json );
		$this->assertStringNotContainsString( 'super-secret', $json );
		$this->assertStringNotContainsString( 'refresh_token', $json );
		$this->assertStringNotContainsString( 'client_secret', $json );
	}

	/**
	 * Importing on a fresh site restores templates, CSS, settings, mapping and
	 * the field list, with links pointed at the new site.
	 */
	public function test_import_restores_the_setup_on_a_new_site() {
		$data = $this->export_from_old_site();

		$this->reset_site();

		$report = Site_Migration::import( $data );

		$this->assertIsArray( $report );

		$new = untrailingslashit( home_url() );

		$this->assertSame( 'Search', Settings::get( 'filters_button_label' ) );
		$this->assertSame( 'careers-openings', Settings::get( 'job_slug' ) );
		$this->assertStringContainsString( $new . '/careers-openings/', Settings::get( 'card_template' ) );
		$this->assertStringNotContainsString( self::OLD_SITE, Settings::get( 'card_template' ) );
		$this->assertStringContainsString( $new . '/wp-content/uploads/bg.png', Settings::get( 'custom_css' ) );
		$this->assertSame( $new, Settings::get( 'org_url' ) );
		$this->assertStringContainsString( 'kc-staff', Settings::get( 'card_templates' )['staff'] ?? '' );
		$this->assertSame( '', Settings::get( 'notify_email' ) );

		$this->assertContains( 'raw_html', wp_list_pluck( Field_Mapper::get_mapping(), 'transform' ) );
		$this->assertArrayHasKey( 'School_Name', get_option( Field_Metadata::OPTION_CACHE ) );

		$this->assertSame( 3, $report['urls_replaced'] );
		$this->assertSame( 2, $report['templates'] );
		$this->assertTrue( $report['custom_css'] );
		$this->assertSame( 1, $report['fields'] );

		// Secrets were never in the file, so the new site is not connected.
		$this->assertFalse( get_option( 'jszr_tokens' ) );
	}

	/**
	 * Links can be left alone, for a move where the address does not change.
	 */
	public function test_import_can_keep_old_links() {
		$data = $this->export_from_old_site();

		$this->reset_site();

		$report = Site_Migration::import( $data, array( 'replace_urls' => false ) );

		$this->assertSame( 0, $report['urls_replaced'] );
		$this->assertStringContainsString( self::OLD_SITE . '/careers-openings/', Settings::get( 'card_template' ) );
	}

	/**
	 * A site that already loaded its own Zoho field list keeps it.
	 */
	public function test_import_keeps_a_field_list_the_site_already_has() {
		$data = $this->export_from_old_site();

		$this->reset_site();

		$own = array(
			'Posting_Title' => array(
				'api_name' => 'Posting_Title',
				'label'    => 'Posting Title',
				'type'     => 'text',
				'options'  => array(),
			),
		);

		update_option( Field_Metadata::OPTION_CACHE, $own, false );

		$report = Site_Migration::import( $data );

		$this->assertTrue( $report['fields_kept'] );
		$this->assertSame( $own, get_option( Field_Metadata::OPTION_CACHE ) );
	}

	/**
	 * Files from the older settings export still import.
	 */
	public function test_older_settings_export_still_imports() {
		$report = Site_Migration::import(
			array(
				'plugin'   => 'jobs-sync-for-zoho-recruit',
				'version'  => '1.0.1',
				'exported' => '2026-09-20T10:00:00+00:00',
				'settings' => array( 'filters_button_label' => 'Search Jobs' ),
				'mapping'  => Field_Mapper::default_mapping(),
			)
		);

		$this->assertIsArray( $report );
		$this->assertSame( 'Search Jobs', Settings::get( 'filters_button_label' ) );
	}

	/**
	 * Another plugin's file, or a file with nothing in it, is refused.
	 */
	public function test_foreign_or_empty_files_are_refused() {
		$this->assertWPError(
			Site_Migration::import(
				array(
					'plugin'   => 'something-else',
					'settings' => array(),
				)
			)
		);
		$this->assertWPError( Site_Migration::import( array( 'plugin' => 'jobs-sync-for-zoho-recruit' ) ) );
		$this->assertWPError( Site_Migration::import_json( 'not json' ) );
	}

	/**
	 * Imported HTML is sanitized exactly like HTML saved on the settings screen.
	 */
	public function test_imported_templates_are_sanitized() {
		Site_Migration::import(
			array(
				'plugin'   => 'jobs-sync-for-zoho-recruit',
				'settings' => array(
					'card_template' => '<div onclick="alert(1)">{title}<script>alert(2)</script></div>',
				),
			)
		);

		$template = Settings::get( 'card_template' );

		$this->assertStringContainsString( '{title}', $template );
		$this->assertStringNotContainsString( 'onclick', $template );
		$this->assertStringNotContainsString( '<script', $template );
	}

	/**
	 * Only the old site's own address is rewritten, never a longer host that
	 * starts the same way, and a scheme-less link stays scheme-less.
	 */
	public function test_url_rewrite_respects_host_boundaries() {
		$count = 0;
		$out   = Site_Migration::replace_site_url(
			array(
				'a' => 'https://old.example/jobs/ and http://old.example?x=1',
				'b' => 'https://old.example.com.au/ and https://old.examples/',
				'c' => 'url(//old.example/bg.png)',
				'd' => 42,
			),
			'https://old.example',
			'https://new.example',
			$count
		);

		$this->assertSame( 'https://new.example/jobs/ and https://new.example?x=1', $out['a'] );
		$this->assertSame( 'https://old.example.com.au/ and https://old.examples/', $out['b'] );
		$this->assertSame( 'url(//new.example/bg.png)', $out['c'] );
		$this->assertSame( 42, $out['d'] );
		$this->assertSame( 3, $count );
	}

	/**
	 * A field list from a file is cleaned before it is stored.
	 */
	public function test_imported_field_list_is_sanitized() {
		$clean = Site_Migration::sanitize_fields(
			array(
				'Good_Field' => array(
					'label'   => '<b>Label</b>',
					'type'    => 'Pick List',
					'options' => array( '<i>One</i>', array( 'nested' ) ),
				),
				'bad name!'  => 'not an array',
			)
		);

		$this->assertSame( array( 'Good_Field' ), array_keys( $clean ) );
		$this->assertSame( 'Label', $clean['Good_Field']['label'] );
		$this->assertSame( 'picklist', $clean['Good_Field']['type'] );
		$this->assertSame( array( 'One' ), $clean['Good_Field']['options'] );
	}
}
