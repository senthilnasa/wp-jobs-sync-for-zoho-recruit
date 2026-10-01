<?php
/**
 * Listing options added for design-led sites: named card templates, filter
 * styles and labels, the status and location filters, search fields, "load
 * more" pagination, related openings and path-style slugs.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Layouts;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\REST_API;
use JobsSyncForZohoRecruit\Settings;
use JobsSyncForZohoRecruit\Shortcode;
use JobsSyncForZohoRecruit\Template_Tags;

/**
 * Every option here is off by default, so the first thing each test proves is
 * that the default output is unchanged, and the second is what the option adds.
 */
class JSZR_Listing_Options_Test extends WP_UnitTestCase {

	/**
	 * Reset settings and the request between tests.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Settings::flush_cache();
		Settings::update( array( 'cache_ttl' => 0 ) );

		$_GET = array();
	}

	/**
	 * Leave no request state behind.
	 */
	public function tear_down() {
		$_GET = array();

		parent::tear_down();
	}

	/**
	 * Create an active job.
	 *
	 * @param string $zoho_id Zoho record ID.
	 * @param string $title   Title.
	 * @param array  $meta    Extra meta.
	 * @param array  $terms   Terms by taxonomy.
	 * @param string $status  Normalised status.
	 * @return int Post ID.
	 */
	private function make_job( $zoho_id, $title, array $meta = array(), array $terms = array(), $status = 'active' ) {
		$result = Job::upsert(
			$zoho_id,
			array(
				'post'   => array(
					'post_title'   => $title,
					'post_content' => 'Reports to the manager of the unit.',
					'post_excerpt' => 'A role.',
				),
				'meta'   => array_merge( array( '_jszr_job_code' => 'CODE-' . $zoho_id ), $meta ),
				'terms'  => $terms,
				'mapped' => array(),
			),
			array( 'status' => $status )
		);

		return (int) $result['post_id'];
	}

	// ----------------------------------------------------------------------
	// Template tags
	// ----------------------------------------------------------------------

	/**
	 * The relative posting age reads from Zoho's posted date.
	 */
	public function test_posted_ago_tag() {
		$post_id = $this->make_job( 'OPT1', 'Writer', array( '_zoho_recruit_posted_date' => gmdate( 'Y-m-d', time() - ( 21 * DAY_IN_SECONDS ) ) ) );

		$values = Template_Tags::values( $post_id );

		$this->assertSame( '3 weeks ago', $values['posted_ago'] );
	}

	/**
	 * Without a posted date the WordPress publish date stands in, so the tag
	 * is never blank.
	 */
	public function test_posted_ago_falls_back_to_the_publish_date() {
		$post_id = $this->make_job( 'OPT2', 'Writer' );

		$this->assertNotSame( '', Template_Tags::values( $post_id )['posted_ago'] );
	}

	/**
	 * The organisation name comes from the schema settings, then the site.
	 */
	public function test_org_name_tag() {
		$post_id = $this->make_job( 'OPT3', 'Writer' );

		$this->assertSame( get_bloginfo( 'name' ), Template_Tags::values( $post_id )['org_name'] );

		Settings::update( array( 'org_name' => 'Krea University' ) );

		$this->assertSame( 'Krea University', Template_Tags::values( $post_id )['org_name'] );
	}

	/**
	 * The status label is a word a candidate understands.
	 */
	public function test_status_label_tag() {
		$open   = $this->make_job( 'OPT4', 'Open role' );
		$closed = $this->make_job( 'OPT5', 'Closed role', array(), array(), 'closed' );

		$this->assertSame( 'Open', Template_Tags::values( $open )['status_label'] );
		$this->assertSame( 'Closed', Template_Tags::values( $closed )['status_label'] );
	}

	// ----------------------------------------------------------------------
	// Allow-list
	// ----------------------------------------------------------------------

	/**
	 * A design export's SVG arrow and inline list reset survive the filter.
	 */
	public function test_templates_keep_svg_buttons_and_list_style() {
		$template = '<ul style="list-style:none;padding:0"><li>{title}</li></ul>'
			. '<button type="button" class="x">Go</button>'
			. '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"></path></svg>'
			. '<picture><source srcset="a.webp" type="image/webp" /><img src="a.jpg" alt="" /></picture>';

		$clean = Layouts::sanitize_template( $template );

		$this->assertStringContainsString( 'list-style:none', $clean );
		$this->assertStringContainsString( '<button type="button" class="x">', $clean );
		// kses lowercases attribute names; the HTML parser maps viewbox back to
		// viewBox when it builds the SVG, so the icon still scales.
		$this->assertStringContainsString( '<svg viewbox="0 0 24 24"', $clean );
		$this->assertStringContainsString( '<path d="M5 12h14">', $clean );
		$this->assertStringContainsString( '<source srcset="a.webp"', $clean );
		$this->assertStringContainsString( '{title}', $clean );
	}

	/**
	 * Widening the list for templates must not widen it for post content.
	 */
	public function test_css_widening_does_not_leak_outside_templates() {
		Layouts::sanitize_template( '<p style="list-style:none">x</p>' );

		$this->assertStringNotContainsString( 'list-style', wp_kses_post( '<p style="list-style:none">x</p>' ) );
	}

	/**
	 * The dangerous things stay out.
	 */
	public function test_templates_still_strip_scripts_and_handlers() {
		$clean = Layouts::sanitize_template(
			'<svg onload="alert(1)"><script>alert(1)</script><use href="x.svg#a"></use></svg><button onclick="alert(1)">x</button>'
		);

		$this->assertStringNotContainsString( 'onload', $clean );
		$this->assertStringNotContainsString( '<script', $clean );
		$this->assertStringNotContainsString( '<use', $clean );
		$this->assertStringNotContainsString( 'onclick', $clean );
	}

	// ----------------------------------------------------------------------
	// Named templates
	// ----------------------------------------------------------------------

	/**
	 * Rows posted by the form become a keyed, sanitized map.
	 */
	public function test_card_templates_are_sanitized_from_rows() {
		$clean = Settings::sanitize_card_templates(
			array(
				array(
					'name' => 'Staff Card',
					'html' => '<p class="s">{title}<script>x</script></p>',
				),
				array(
					'name' => '',
					'html' => '<p>no name</p>',
				),
				array(
					'name' => 'empty',
					'html' => '',
				),
			)
		);

		$this->assertSame( array( 'staff_card' ), array_keys( $clean ) );

		// The script element goes; kses leaves its text behind, as it does
		// everywhere else in WordPress.
		$this->assertSame( '<p class="s">{title}x</p>', $clean['staff_card'] );
		$this->assertStringNotContainsString( '<script', $clean['staff_card'] );
	}

	/**
	 * A shortcode picks a named template; the default output is untouched.
	 */
	public function test_shortcode_template_attribute_selects_a_named_card() {
		$this->make_job( 'OPT6', 'Named Template Job' );

		Settings::update(
			array(
				'card_templates' => array( 'staff' => '<article class="krea-staff">{title} / {job_code}</article>' ),
			)
		);

		$default = Shortcode::render(
			array(
				'show_filters' => 'false',
				'show_search'  => 'false',
			)
		);
		$named   = Shortcode::render(
			array(
				'template'     => 'staff',
				'show_filters' => 'false',
				'show_search'  => 'false',
			)
		);

		$this->assertStringNotContainsString( 'krea-staff', $default );
		$this->assertStringContainsString( '<article class="krea-staff">Named Template Job / CODE-OPT6</article>', $named );
	}

	/**
	 * An unknown template name falls back to the configured layout.
	 */
	public function test_unknown_template_name_is_ignored() {
		$this->assertSame( '', Layouts::listing_template( 'default', 'missing' ) );
		$this->assertStringContainsString( 'jszr-job-card--compact', Layouts::listing_template( 'compact', 'missing' ) );
	}

	// ----------------------------------------------------------------------
	// Filters
	// ----------------------------------------------------------------------

	/**
	 * Filter labels can be renamed for visitors without touching taxonomies.
	 */
	public function test_filter_labels_override_the_taxonomy_name() {
		$this->make_job( 'OPT7', 'Labelled', array(), array( Post_Type::TAX_DEPARTMENT => array( 'IT & Systems' ) ) );

		$html = Shortcode::render( array( 'show_filters' => 'true' ) );

		$this->assertStringContainsString( 'Department', $html );
		$this->assertStringNotContainsString( 'Functional area', $html );

		Settings::update( array( 'filter_labels' => array( 'department' => 'Functional area' ) ) );

		$html = Shortcode::render( array( 'show_filters' => 'true' ) );

		$this->assertStringContainsString( 'Functional area', $html );
		$this->assertSame( 'Functional area', Settings::filter_label( 'department', 'Department' ) );
		$this->assertSame( 'Location', Settings::filter_label( 'location', 'Location' ) );
	}

	/**
	 * Pills render radio buttons instead of a select, and only when asked.
	 */
	public function test_pills_filter_style() {
		$this->make_job( 'OPT8', 'Pilled', array(), array( Post_Type::TAX_EMPLOYMENT_TYPE => array( 'Full time' ) ) );

		$html = Shortcode::render( array( 'show_filters' => 'true' ) );

		$this->assertStringContainsString( '<select id="jszr-filter-employment_type"', $html );
		$this->assertStringNotContainsString( 'jszr-filters__pills', $html );

		Settings::update( array( 'filters_style' => 'pills' ) );

		$html = Shortcode::render( array( 'show_filters' => 'true' ) );

		$this->assertStringContainsString( 'jszr-filters--pills', $html );
		$this->assertStringContainsString( 'jszr-filters__pills', $html );
		$this->assertStringContainsString( 'type="radio" name="jszr_employment_type"', $html );
		$this->assertStringNotContainsString( '<select id="jszr-filter-employment_type"', $html );
	}

	/**
	 * The status filter appears only when enabled, and a crafted URL cannot
	 * list closed jobs on a site that keeps it off.
	 */
	public function test_status_filter_is_opt_in() {
		$this->make_job( 'OPT9', 'Open role' );
		$this->make_job( 'OPT10', 'Closed role', array(), array(), 'closed' );

		$_GET['jszr_status'] = 'closed';

		$html = Shortcode::render( array( 'show_filters' => 'true' ) );

		$this->assertStringNotContainsString( 'name="jszr_status"', $html );
		$this->assertStringContainsString( 'Open role', $html );
		$this->assertStringNotContainsString( 'Closed role', $html );

		Settings::update( array( 'show_status_filter' => true ) );

		$html = Shortcode::render( array( 'show_filters' => 'true' ) );

		$this->assertStringContainsString( 'name="jszr_status"', $html );
		$this->assertStringContainsString( 'Closed role', $html );
		$this->assertStringNotContainsString( 'Open role', $html );
	}

	// ----------------------------------------------------------------------
	// Search
	// ----------------------------------------------------------------------

	/**
	 * Keyword search honours the configured fields.
	 */
	public function test_search_fields_setting_narrows_the_match() {
		$this->make_job( 'OPT11', 'Counsellor' );

		// "manager" only appears in the description.
		$with = jszr_get_jobs( array( 'search' => 'manager' ) );
		$this->assertSame( 1, $with['total'] );

		Settings::update( array( 'search_fields' => array( 'title', 'job_code' ) ) );

		$without = jszr_get_jobs( array( 'search' => 'manager' ) );
		$this->assertSame( 0, $without['total'] );

		$by_title = jszr_get_jobs( array( 'search' => 'counsellor' ) );
		$this->assertSame( 1, $by_title['total'] );
	}

	/**
	 * An empty field list cannot switch search off.
	 */
	public function test_search_fields_never_empty() {
		Settings::update( array( 'search_fields' => array() ) );

		$this->assertSame( array( 'title', 'excerpt', 'content', 'job_code' ), Settings::search_fields() );
	}

	/**
	 * The location box matches terms and the city, state and country meta.
	 */
	public function test_location_search() {
		$this->make_job( 'OPT12', 'In Sri City', array( '_zoho_recruit_city' => 'Sri City' ), array( Post_Type::TAX_LOCATION => array( 'Andhra Pradesh' ) ) );
		$this->make_job( 'OPT13', 'In Chennai', array( '_zoho_recruit_city' => 'Chennai' ) );

		$this->assertSame( 1, jszr_get_jobs( array( 'location_search' => 'sri' ) )['total'] );
		$this->assertSame( 1, jszr_get_jobs( array( 'location_search' => 'andhra' ) )['total'] );
		$this->assertSame( 1, jszr_get_jobs( array( 'location_search' => 'chennai' ) )['total'] );
		$this->assertSame( 0, jszr_get_jobs( array( 'location_search' => 'mumbai' ) )['total'] );
	}

	/**
	 * The location box is rendered and read only when enabled.
	 */
	public function test_location_search_box_is_opt_in() {
		$this->make_job( 'OPT14', 'In Sri City', array( '_zoho_recruit_city' => 'Sri City' ) );
		$this->make_job( 'OPT15', 'In Chennai', array( '_zoho_recruit_city' => 'Chennai' ) );

		$_GET['jszr_location_q'] = 'chennai';

		$html = Shortcode::render( array( 'show_search' => 'true' ) );

		$this->assertStringNotContainsString( 'name="jszr_location_q"', $html );
		$this->assertStringContainsString( 'In Sri City', $html );

		Settings::update( array( 'show_location_search' => true ) );

		$html = Shortcode::render( array( 'show_search' => 'true' ) );

		$this->assertStringContainsString( 'name="jszr_location_q"', $html );
		$this->assertStringContainsString( 'In Chennai', $html );
		$this->assertStringNotContainsString( 'In Sri City', $html );
	}

	// ----------------------------------------------------------------------
	// Pagination
	// ----------------------------------------------------------------------

	/**
	 * "Load more" replaces the page numbers with one link to the next page.
	 */
	public function test_load_more_pagination() {
		$this->make_job( 'OPT16', 'One' );
		$this->make_job( 'OPT17', 'Two' );
		$this->make_job( 'OPT18', 'Three' );

		$atts = array(
			'per_page'     => 2,
			'show_filters' => 'false',
			'show_search'  => 'false',
		);

		$html = Shortcode::render( $atts );

		$this->assertStringContainsString( 'jszr-pagination__list', $html );
		$this->assertStringNotContainsString( 'data-jszr-append', $html );

		Settings::update( array( 'pagination_style' => 'load_more' ) );

		$html = Shortcode::render( $atts );

		$this->assertStringContainsString( 'data-jszr-append="true"', $html );
		$this->assertStringContainsString( 'jszr_page=2', $html );
		$this->assertStringContainsString( 'Load more listings', $html );
		$this->assertStringNotContainsString( 'jszr-pagination__list', $html );

		// On the last page there is nothing more to load.
		$_GET['jszr_page'] = '2';

		$this->assertStringNotContainsString( 'data-jszr-append', Shortcode::render( $atts ) );
	}

	// ----------------------------------------------------------------------
	// Related and excluded jobs
	// ----------------------------------------------------------------------

	/**
	 * `exclude` leaves posts out; `related` copies the current job's terms.
	 */
	public function test_related_and_exclude() {
		$current = $this->make_job( 'OPT19', 'Current', array(), array( Post_Type::TAX_DEPARTMENT => array( 'Finance' ) ) );
		$sibling = $this->make_job( 'OPT20', 'Sibling', array(), array( Post_Type::TAX_DEPARTMENT => array( 'Finance' ) ) );
		$this->make_job( 'OPT21', 'Elsewhere', array(), array( Post_Type::TAX_DEPARTMENT => array( 'Design' ) ) );

		$excluded = jszr_get_jobs( array( 'exclude' => (string) $current ) );

		$this->assertSame( 2, $excluded['total'] );

		$this->go_to( get_permalink( $current ) );

		$html = Shortcode::render(
			array(
				'related'      => 'department',
				'show_filters' => 'false',
				'show_search'  => 'false',
			)
		);

		$this->assertStringContainsString( 'Sibling', $html );
		$this->assertStringNotContainsString( 'Elsewhere', $html );
		$this->assertStringNotContainsString( '>Current<', $html );
		$this->assertGreaterThan( 0, $sibling );
	}

	/**
	 * Outside a job page `related` is ignored rather than breaking the list.
	 */
	public function test_related_outside_a_job_page_lists_everything() {
		$this->make_job( 'OPT22', 'Anywhere' );

		$this->go_to( home_url( '/' ) );

		$html = Shortcode::render(
			array(
				'related'      => 'department',
				'show_filters' => 'false',
				'show_search'  => 'false',
			)
		);

		$this->assertStringContainsString( 'Anywhere', $html );
	}

	// ----------------------------------------------------------------------
	// Slugs
	// ----------------------------------------------------------------------

	/**
	 * A slug may now be a path, so jobs can live under /careers/openings/.
	 */
	public function test_slug_paths_are_kept() {
		$this->assertSame( 'careers/openings', Settings::sanitize_slug_path( '/careers/openings/' ) );
		$this->assertSame( 'careers', Settings::sanitize_slug_path( '//careers///' ) );
		$this->assertSame( 'jobs', Settings::sanitize_slug_path( 'Jobs' ) );
		$this->assertSame( '', Settings::sanitize_slug_path( '///' ) );

		$clean = Settings::sanitize( array( 'job_slug' => 'careers/openings' ) );

		$this->assertSame( 'careers/openings', $clean['job_slug'] );
	}

	// ----------------------------------------------------------------------
	// REST
	// ----------------------------------------------------------------------

	/**
	 * The new parameters are part of the schema, so they validate.
	 */
	public function test_rest_schema_accepts_the_new_parameters() {
		$api  = new REST_API( JobsSyncForZohoRecruit\plugin()->sync(), JobsSyncForZohoRecruit\plugin()->queue() );
		$args = $api->collection_args();

		$this->assertArrayHasKey( 'exclude', $args );
		$this->assertArrayHasKey( 'location_search', $args );
		$this->assertArrayHasKey( 'template', $api->render_args() );
	}
}
