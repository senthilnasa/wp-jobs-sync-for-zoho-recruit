<?php
/**
 * Layout, token and sanitization tests.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Layouts;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\Settings;
use JobsSyncForZohoRecruit\Template_Tags;

/**
 * The layout layer lets an administrator write HTML and CSS, which makes its
 * sanitizers a security boundary. These tests hold that boundary.
 */
class JSZR_Layouts_Test extends WP_UnitTestCase {

	/**
	 * Reset settings between tests.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Settings::flush_cache();
	}

	/**
	 * Create a job with predictable values.
	 *
	 * @param array $meta  Extra meta.
	 * @param array $terms Extra terms.
	 * @return int Post ID.
	 */
	private function make_job( array $meta = array(), array $terms = array() ) {
		$result = Job::upsert(
			'LAYOUT1',
			array(
				'post'   => array(
					'post_title'   => 'Senior Developer',
					'post_content' => 'Build things.',
					'post_excerpt' => 'Build things.',
				),
				'meta'   => array_merge(
					array(
						'_jszr_job_code'     => 'JOB-1',
						'_zoho_recruit_city' => 'Chennai',
					),
					$meta
				),
				'terms'  => array_merge(
					array( Post_Type::TAX_DEPARTMENT => array( 'Engineering' ) ),
					$terms
				),
				'mapped' => array(),
			),
			array( 'status' => 'active' )
		);

		return (int) $result['post_id'];
	}

	// ----------------------------------------------------------------------
	// Token rendering
	// ----------------------------------------------------------------------

	/**
	 * Tokens resolve to the job's values.
	 */
	public function test_tokens_are_replaced() {
		$post_id = $this->make_job();

		$markup = Template_Tags::render( '<p>{title} - {job_code} - {department}</p>', $post_id );

		$this->assertSame( '<p>Senior Developer - JOB-1 - Engineering</p>', $markup );
	}

	/**
	 * An unknown token disappears rather than being printed back at a visitor.
	 */
	public function test_unknown_tokens_are_dropped() {
		$post_id = $this->make_job();

		$this->assertSame( '<p></p>', Template_Tags::render( '<p>{not_a_real_token}</p>', $post_id ) );
	}

	/**
	 * A conditional keeps its contents only when the value is there.
	 */
	public function test_conditionals() {
		$post_id = $this->make_job( array( '_zoho_recruit_salary' => '90000 USD' ) );

		$template = '{if:salary}<span>{salary}</span>{/if:salary}{if:client}<b>{client}</b>{/if:client}';

		$this->assertSame( '<span>90000 USD</span>', Template_Tags::render( $template, $post_id ) );
	}

	/**
	 * The negative form is the one that lets a layout say "not stated".
	 */
	public function test_negative_conditionals() {
		$post_id = $this->make_job();

		$template = '{ifnot:salary}<em>Not stated</em>{/ifnot:salary}';

		$this->assertSame( '<em>Not stated</em>', Template_Tags::render( $template, $post_id ) );
	}

	/**
	 * Token values are escaped, so a value carrying HTML syntax is inert.
	 *
	 * A term name is used because it survives storage intact: post meta and
	 * post fields are already sanitized on the way in, which would hide whether
	 * this layer escapes anything at all.
	 */
	public function test_token_values_are_escaped() {
		$post_id = $this->make_job(
			array(),
			array( Post_Type::TAX_CATEGORY => array( 'Tools & <b>Platform</b>' ) )
		);

		$markup = Template_Tags::render( '<p>{category}</p>', $post_id );

		// WordPress strips the tags when the term is stored; this layer is what
		// turns the ampersand that survives into an entity.
		$this->assertStringNotContainsString( '<b>', $markup );
		$this->assertStringContainsString( '&amp;', $markup );
	}

	/**
	 * A value used in an attribute cannot close it and add another.
	 */
	public function test_token_values_are_safe_in_attributes() {
		$post_id = $this->make_job(
			array(),
			array( Post_Type::TAX_CATEGORY => array( 'A" onmouseover="alert(1)' ) )
		);

		$markup = Template_Tags::render( '<span title="{category}">x</span>', $post_id );

		$this->assertStringNotContainsString( 'onmouseover="alert', $markup );
		$this->assertStringContainsString( '&quot;', $markup );
	}

	/**
	 * End to end, a script tag never reaches the page.
	 */
	public function test_scripts_never_reach_the_output() {
		$result = Job::upsert(
			'LAYOUT2',
			array(
				'post'   => array( 'post_title' => 'Dev <script>alert(1)</script>' ),
				'meta'   => array(),
				'terms'  => array(),
				'mapped' => array(),
			),
			array( 'status' => 'active' )
		);

		$markup = Template_Tags::render( '<p>{title}</p>', (int) $result['post_id'] );

		$this->assertStringNotContainsString( '<script', $markup );
		$this->assertStringNotContainsString( '</script', $markup );
	}

	// ----------------------------------------------------------------------
	// Template sanitization
	// ----------------------------------------------------------------------

	/**
	 * Structural HTML survives being saved.
	 */
	public function test_sanitize_template_keeps_structural_html() {
		$template = '<article class="x"><h3><a href="{permalink}">{title}</a></h3></article>';

		$this->assertSame( $template, Layouts::sanitize_template( $template ) );
	}

	/**
	 * Conditional tags are not HTML and must survive the HTML filtering.
	 */
	public function test_sanitize_template_keeps_conditionals() {
		$template = '{if:salary}<span>{salary}</span>{/if:salary}';

		$this->assertSame( $template, Layouts::sanitize_template( $template ) );
	}

	/**
	 * A script tag in a template is removed on save, not at render time.
	 */
	public function test_sanitize_template_strips_scripts() {
		$clean = Layouts::sanitize_template( '<p>{title}</p><script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script', $clean );
		$this->assertStringContainsString( '{title}', $clean );
	}

	/**
	 * Event handler attributes are removed too.
	 */
	public function test_sanitize_template_strips_event_handlers() {
		$clean = Layouts::sanitize_template( '<div onclick="alert(1)">{title}</div>' );

		$this->assertStringNotContainsString( 'onclick', $clean );
	}

	/**
	 * A form or iframe has no business in a job card.
	 */
	public function test_sanitize_template_strips_forms_and_frames() {
		$clean = Layouts::sanitize_template( '<iframe src="//evil"></iframe><form><input /></form><p>{title}</p>' );

		$this->assertStringNotContainsString( '<iframe', $clean );
		$this->assertStringNotContainsString( '<form', $clean );
		$this->assertStringNotContainsString( '<input', $clean );
	}

	// ----------------------------------------------------------------------
	// CSS sanitization
	// ----------------------------------------------------------------------

	/**
	 * Ordinary CSS is stored unchanged.
	 */
	public function test_sanitize_css_keeps_rules() {
		$css = '.jszr-job-card { border-color: #0b5cff; padding: 1rem; }';

		$this->assertSame( $css, Layouts::sanitize_css( $css ) );
	}

	/**
	 * Nothing may close the style element the CSS is printed inside.
	 */
	public function test_sanitize_css_cannot_break_out_of_style() {
		$clean = Layouts::sanitize_css( '.a{color:red}</style><script>alert(1)</script>' );

		$this->assertStringNotContainsString( '</style', $clean );
		$this->assertStringNotContainsString( '<script', $clean );
		$this->assertStringNotContainsString( '<', $clean );
	}

	/**
	 * Executable and remote-fetching CSS is stripped.
	 *
	 * @dataProvider dangerous_css_provider
	 *
	 * @param string $css      Input.
	 * @param string $unwanted Substring that must not survive.
	 */
	public function test_sanitize_css_strips_dangerous_constructs( $css, $unwanted ) {
		$this->assertStringNotContainsString(
			$unwanted,
			strtolower( Layouts::sanitize_css( $css ) )
		);
	}

	/**
	 * Data provider for CSS sanitization.
	 *
	 * @return array
	 */
	public function dangerous_css_provider() {
		return array(
			array( '.a { width: expression(alert(1)); }', 'expression(' ),
			array( '@import url("//evil.test/x.css");', '@import' ),
			array( '.a { background: url(javascript:alert(1)); }', 'javascript:' ),
			array( '.a { behavior: url(evil.htc); }', 'behavior:' ),
		);
	}

	// ----------------------------------------------------------------------
	// Layout selection
	// ----------------------------------------------------------------------

	/**
	 * The default layout has no token template, so the PHP card renders.
	 */
	public function test_default_layout_uses_the_php_template() {
		$this->assertSame( '', Layouts::listing_template( 'default' ) );
	}

	/**
	 * A preset returns its built-in template.
	 */
	public function test_preset_layout_returns_a_template() {
		$this->assertStringContainsString( '{title}', Layouts::listing_template( 'compact' ) );
	}

	/**
	 * An empty custom template falls back rather than rendering an empty list.
	 */
	public function test_empty_custom_template_falls_back() {
		Settings::update(
			array(
				'listing_layout' => 'custom',
				'card_template'  => '   ',
			)
		);

		$this->assertSame( '', Layouts::listing_template() );
	}

	/**
	 * A stored custom template is what gets rendered.
	 */
	public function test_custom_template_is_used() {
		Settings::update(
			array(
				'listing_layout' => 'custom',
				'card_template'  => '<p class="mine">{title}</p>',
			)
		);

		$post_id = $this->make_job();

		$markup = Template_Tags::render( Layouts::listing_template(), $post_id );

		$this->assertSame( '<p class="mine">Senior Developer</p>', $markup );
	}

	/**
	 * Saving through the settings sanitizer cleans the template and the CSS.
	 */
	public function test_settings_sanitize_cleans_layout_input() {
		$clean = Settings::sanitize(
			array(
				'card_template' => '<p>{title}</p><script>alert(1)</script>',
				'custom_css'    => '.a{color:red}</style>',
			)
		);

		$this->assertStringNotContainsString( '<script', $clean['card_template'] );
		$this->assertStringContainsString( '{title}', $clean['card_template'] );
		$this->assertStringNotContainsString( '</style', $clean['custom_css'] );
	}

	/**
	 * The chosen filters are stored in the map's order, not the form's.
	 */
	public function test_filter_fields_keep_map_order() {
		$clean = Settings::sanitize(
			array( 'filter_fields' => array( 'experience', 'department', 'not_a_filter' ) ),
		);

		$this->assertSame( array( 'department', 'experience' ), $clean['filter_fields'] );
	}

	/**
	 * CSS is sanitized on the way out as well as on the way in.
	 *
	 * The settings form is not the only thing that writes this option, and the
	 * value ends up inside a style element.
	 */
	public function test_custom_css_is_sanitized_on_output() {
		// Written past the settings sanitizer, the way an import or a direct
		// option update would.
		Settings::update( array( 'custom_css' => '.a{color:red}</style><script>x()</script>' ) );

		$css = Layouts::custom_css();

		$this->assertStringContainsString( 'color:red', $css );
		$this->assertStringNotContainsString( '<', $css );
		$this->assertStringNotContainsString( '</style', $css );
	}

	/**
	 * A filter cannot reintroduce markup either.
	 */
	public function test_custom_css_filter_output_is_sanitized() {
		add_filter( 'jszr_custom_css', static fn() => '.b{}</style><script>y()</script>' );

		$css = Layouts::custom_css();

		remove_all_filters( 'jszr_custom_css' );

		$this->assertStringNotContainsString( '<', $css );
	}

	/**
	 * The apply link opens a new tab by default, and says so.
	 */
	public function test_apply_link_opens_a_new_tab_by_default() {
		$post_id = $this->make_job( array( '_zoho_recruit_application_url' => 'https://example.com/apply' ) );

		$markup = \JobsSyncForZohoRecruit\Templates::apply_link( $post_id );

		$this->assertStringContainsString( 'target="_blank"', $markup );
		$this->assertStringContainsString( 'rel="noopener nofollow"', $markup );
		$this->assertStringContainsString( 'opens in a new tab', $markup );
	}

	/**
	 * Same-tab mode drops the target, and the note that would then be a lie.
	 */
	public function test_apply_link_can_open_in_the_same_tab() {
		Settings::update( array( 'apply_target' => 'same_tab' ) );

		$post_id = $this->make_job( array( '_zoho_recruit_application_url' => 'https://example.com/apply' ) );

		$markup = \JobsSyncForZohoRecruit\Templates::apply_link( $post_id );

		$this->assertStringNotContainsString( 'target=', $markup );
		$this->assertStringContainsString( 'rel="nofollow"', $markup );
		$this->assertStringNotContainsString( 'opens in a new tab', $markup );
	}

	/**
	 * A job with no application URL produces no button at all.
	 */
	public function test_apply_link_is_empty_without_a_url() {
		$this->assertSame( '', \JobsSyncForZohoRecruit\Templates::apply_link( $this->make_job() ) );
	}

	/**
	 * The {apply_button} token and the shortcode agree, because both come from
	 * the same builder.
	 */
	public function test_apply_token_matches_the_shortcode() {
		$post_id = $this->make_job( array( '_zoho_recruit_application_url' => 'https://example.com/apply' ) );

		$token = Template_Tags::render( '{apply_button}', $post_id );
		$short = \JobsSyncForZohoRecruit\Shortcode::render_apply( array( 'id' => $post_id ) );

		$this->assertSame( $token, $short );
		$this->assertStringContainsString( 'https://example.com/apply', $token );
	}

	/**
	 * The preview renders without a job, so a fresh install can still use it.
	 */
	public function test_preview_works_with_no_jobs() {
		$markup = Layouts::render_preview( Layouts::listing_preset( 'compact' ) );

		$this->assertStringContainsString( 'jszr-job-card', $markup );
		$this->assertNotSame( '', trim( wp_strip_all_tags( $markup ) ) );
	}
}
