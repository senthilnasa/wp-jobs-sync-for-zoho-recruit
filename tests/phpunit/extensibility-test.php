<?php
/**
 * Extension point tests.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Field_Mapper;
use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\REST_API;
use JobsSyncForZohoRecruit\SEO;
use JobsSyncForZohoRecruit\Settings;

/**
 * Covers the promises the plugin makes to other developers: a taxonomy added
 * through the filter really is filterable everywhere, and the title and
 * description an SEO plugin would read are the ones the plugin would use.
 */
class JSZR_Extensibility_Test extends WP_UnitTestCase {

	/**
	 * Reset shared state.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Settings::flush_cache();
		Field_Mapper::flush_cache();
	}

	/**
	 * Remove anything a test registered.
	 */
	public function tear_down() {
		remove_all_filters( 'jszr_taxonomies' );
		remove_all_filters( 'jszr_job_title' );
		remove_all_filters( 'jszr_meta_description' );

		parent::tear_down();
	}

	/**
	 * Register an extra taxonomy the way a third-party plugin would.
	 *
	 * @return void
	 */
	private function register_extra_taxonomy() {
		add_filter(
			'jszr_taxonomies',
			static function ( $taxonomies ) {
				$taxonomies['zoho_job_shift'] = array(
					'label' => 'Shift',
					'args'  => array(
						'hierarchical' => false,
						'public'       => true,
						'show_ui'      => true,
					),
				);

				return $taxonomies;
			}
		);
	}

	/**
	 * A taxonomy added through the filter becomes a REST and shortcode filter
	 * without the developer wiring it up a second time.
	 */
	public function test_custom_taxonomy_becomes_a_filter_parameter() {
		$this->register_extra_taxonomy();

		$map = REST_API::filter_map();

		$this->assertArrayHasKey( 'shift', $map );
		$this->assertSame( 'zoho_job_shift', $map['shift'] );

		// The built-in five keep their documented short names.
		$this->assertSame( Post_Type::TAX_DEPARTMENT, $map['department'] );
		$this->assertSame( Post_Type::TAX_EMPLOYMENT_TYPE, $map['employment_type'] );
	}

	/**
	 * The same taxonomy also becomes a shortcode attribute.
	 */
	public function test_custom_taxonomy_becomes_a_shortcode_attribute() {
		$this->register_extra_taxonomy();

		$defaults = \JobsSyncForZohoRecruit\Shortcode::defaults();

		$this->assertArrayHasKey( 'shift', $defaults );
		$this->assertSame( '', $defaults['shift'] );
	}

	/**
	 * And a mapping target, so Zoho values can reach it.
	 */
	public function test_custom_taxonomy_becomes_a_mapping_target() {
		$this->register_extra_taxonomy();

		$targets = Field_Mapper::targets();

		$this->assertArrayHasKey( 'tax:zoho_job_shift', $targets );
	}

	/**
	 * A taxonomy name that is not prefixed keeps its own name as the parameter.
	 */
	public function test_unprefixed_taxonomy_keeps_its_name() {
		$this->assertSame( 'shift', REST_API::filter_param_for( 'zoho_job_shift' ) );
		$this->assertSame( 'other_plugin_tax', REST_API::filter_param_for( 'other_plugin_tax' ) );
	}

	/**
	 * Migrations must not run before init.
	 *
	 * Upgrader::run() flushes rewrite rules, and $wp_rewrite does not exist on
	 * plugins_loaded. Running it there fataled the whole site the first time a
	 * data version bump actually triggered the upgrade path.
	 */
	public function test_upgrade_runs_on_init_not_plugins_loaded() {
		$plugin = \JobsSyncForZohoRecruit\plugin();

		$this->assertNotFalse(
			has_action( 'init', array( $plugin, 'maybe_upgrade' ) ),
			'migrations are hooked to init'
		);

		$this->assertFalse(
			has_action( 'plugins_loaded', array( $plugin, 'maybe_upgrade' ) ),
			'and never to plugins_loaded, where registering a post type is fatal'
		);
	}

	/**
	 * The job openings scope uses Zoho's singular module name.
	 *
	 * Zoho's documentation shows the plural in its examples and the singular in
	 * its scope-name table. Only the singular is accepted, so this is pinned.
	 */
	public function test_job_opening_scope_is_singular() {
		$this->assertSame(
			'ZohoRecruit.modules.jobopening.READ',
			\JobsSyncForZohoRecruit\Zoho_Auth::required_scope()
		);

		$this->assertNotContains(
			'ZohoRecruit.modules.jobopenings.READ',
			\JobsSyncForZohoRecruit\Zoho_Auth::default_scopes(),
			'the plural form Zoho rejects must not come back'
		);
	}

	/**
	 * An upgrade rewrites a stored plural scope, which would never connect.
	 */
	public function test_upgrade_corrects_a_stored_plural_scope() {
		Settings::update(
			array( 'oauth_scopes' => 'ZohoRecruit.modules.jobopenings.READ,ZohoRecruit.settings.fields.READ' )
		);

		\JobsSyncForZohoRecruit\Upgrader::run( '1' );

		Settings::flush_cache();

		$this->assertSame(
			'ZohoRecruit.modules.jobopening.READ,ZohoRecruit.settings.fields.READ',
			Settings::get( 'oauth_scopes' )
		);
	}

	/**
	 * A site that never customised its scopes is left alone by that upgrade.
	 */
	public function test_upgrade_leaves_an_unset_scope_alone() {
		Settings::update( array( 'oauth_scopes' => '' ) );

		\JobsSyncForZohoRecruit\Upgrader::run( '1' );

		Settings::flush_cache();

		$this->assertSame( '', Settings::get( 'oauth_scopes' ) );
	}

	/**
	 * The default scope list starts with the one scope that is required.
	 */
	public function test_default_scopes_include_the_required_one() {
		$defaults = \JobsSyncForZohoRecruit\Zoho_Auth::default_scopes();

		$this->assertContains( \JobsSyncForZohoRecruit\Zoho_Auth::required_scope(), $defaults );
	}

	/**
	 * A site can narrow the scopes when its Zoho account refuses one.
	 *
	 * Zoho rejects the whole authorization request if any single scope is
	 * unrecognised, and its error does not say which, so this has to be
	 * changeable without editing code.
	 */
	public function test_scopes_can_be_narrowed_from_settings() {
		Settings::update( array( 'oauth_scopes' => 'ZohoRecruit.modules.jobopening.READ' ) );

		$scopes = \JobsSyncForZohoRecruit\plugin()->auth()->scopes();

		$this->assertSame( array( 'ZohoRecruit.modules.jobopening.READ' ), $scopes );
	}

	/**
	 * An empty or unusable setting falls back rather than asking for nothing.
	 */
	public function test_empty_scope_setting_falls_back_to_defaults() {
		Settings::update( array( 'oauth_scopes' => '   ' ) );

		$this->assertSame(
			\JobsSyncForZohoRecruit\Zoho_Auth::default_scopes(),
			\JobsSyncForZohoRecruit\plugin()->auth()->scopes()
		);
	}

	/**
	 * Anything not shaped like a scope is dropped before it reaches Zoho.
	 */
	public function test_malformed_scopes_are_dropped() {
		$parsed = \JobsSyncForZohoRecruit\Zoho_Auth::parse_scopes(
			"ZohoRecruit.modules.jobopening.READ\nnot a scope, <script>, ZohoRecruit.settings.ALL, ZohoRecruit.modules.jobopening.READ"
		);

		$this->assertSame(
			array( 'ZohoRecruit.modules.jobopening.READ', 'ZohoRecruit.settings.ALL' ),
			$parsed,
			'malformed entries dropped and duplicates collapsed'
		);
	}

	/**
	 * The authorization URL carries exactly the configured scopes.
	 */
	public function test_authorization_url_carries_the_configured_scopes() {
		$auth = \JobsSyncForZohoRecruit\plugin()->auth();

		$auth->save_credentials( 'test.client.id', 'test-secret' );

		Settings::update( array( 'oauth_scopes' => 'ZohoRecruit.modules.jobopening.READ' ) );

		$url = $auth->authorization_url();

		$this->assertNotWPError( $url );

		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'ZohoRecruit.modules.jobopening.READ', $query['scope'] );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'offline', $query['access_type'] );
		$this->assertNotEmpty( $query['state'], 'the CSRF state is always present' );

		delete_option( 'jszr_credentials' );
	}

	/**
	 * The meta description falls back through excerpt, then content.
	 */
	public function test_meta_description_falls_back_to_content() {
		$result = Job::upsert(
			'700',
			array(
				'post'   => array(
					'post_title'   => 'Software Developer',
					'post_content' => '<p>We are looking for someone to build and maintain our internal tooling.</p>',
					'post_excerpt' => '',
				),
				'meta'   => array(),
				'terms'  => array(),
				'mapped' => array(),
			),
			array( 'status' => 'active' )
		);

		$description = SEO::meta_description( $result['post_id'] );

		$this->assertStringContainsString( 'internal tooling', $description );
		$this->assertStringNotContainsString( '<p>', $description );
	}

	/**
	 * An excerpt wins over the content when one exists.
	 */
	public function test_meta_description_prefers_the_excerpt() {
		$result = Job::upsert(
			'701',
			array(
				'post'   => array(
					'post_title'   => 'Software Developer',
					'post_content' => 'The long description.',
					'post_excerpt' => 'The short summary.',
				),
				'meta'   => array(),
				'terms'  => array(),
				'mapped' => array(),
			),
			array( 'status' => 'active' )
		);

		$this->assertSame( 'The short summary.', SEO::meta_description( $result['post_id'] ) );
	}

	/**
	 * Both values are filterable, which is how an SEO plugin integrates.
	 */
	public function test_title_and_description_are_filterable() {
		$result = Job::upsert(
			'702',
			array(
				'post'   => array( 'post_title' => 'Software Developer' ),
				'meta'   => array(),
				'terms'  => array(),
				'mapped' => array(),
			),
			array( 'status' => 'active' )
		);

		add_filter( 'jszr_job_title', static fn() => 'Replaced title' );
		add_filter( 'jszr_meta_description', static fn() => 'Replaced description' );

		$this->assertSame( 'Replaced title', SEO::job_title( $result['post_id'] ) );
		$this->assertSame( 'Replaced description', SEO::meta_description( $result['post_id'] ) );
	}

	/**
	 * The suggested title includes the most specific location term.
	 */
	public function test_job_title_includes_the_location() {
		$result = Job::upsert(
			'703',
			array(
				'post'   => array( 'post_title' => 'Software Developer' ),
				'meta'   => array(),
				'terms'  => array(
					Post_Type::TAX_LOCATION => array( array( 'India', 'Tamil Nadu', 'Chennai' ) ),
				),
				'mapped' => array(),
			),
			array( 'status' => 'active' )
		);

		$this->assertSame( 'Software Developer - Chennai', SEO::job_title( $result['post_id'] ) );
	}
}
