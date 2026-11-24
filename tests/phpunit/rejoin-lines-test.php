<?php
/**
 * Rejoining lines a word processor wrapped.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Field_Mapper;
use JobsSyncForZohoRecruit\Settings;

/**
 * A description pasted into Zoho from Word carries a hard break at the end of
 * every line of the original document. These cover telling those apart from the
 * breaks that separate labels and bullets, which have to survive.
 */
class JSZR_Rejoin_Lines_Test extends WP_UnitTestCase {

	/**
	 * Start from defaults.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Settings::flush_cache();
	}

	/**
	 * Turn the setting on.
	 */
	private function enable() {
		update_option( Settings::OPTION, array( 'unwrap_soft_breaks' => true ) );
		Settings::flush_cache();
	}

	/**
	 * A sentence broken mid-phrase is put back together.
	 */
	public function test_a_mid_sentence_break_is_removed() {
		$html = '<p>The Senior<br /> Executive will support day-to-day procurement<br /> activities.</p>';

		$this->assertSame(
			'<p>The Senior Executive will support day-to-day procurement activities.</p>',
			Field_Mapper::rejoin_wrapped_lines( $html )
		);
	}

	/**
	 * A break inside a bold run is still a wrap. This is the "Job / Summary:"
	 * case: the heading itself was split across two lines of the original.
	 */
	public function test_a_break_inside_a_bold_run_is_removed() {
		$this->assertSame(
			'<p><b>Job Summary:</b></p>',
			Field_Mapper::rejoin_wrapped_lines( '<p><b>Job<br /> Summary:</b></p>' )
		);
	}

	/**
	 * A break before the next bold label is deliberate and stays.
	 */
	public function test_a_label_line_survives() {
		$html = '<p><b>Job Title:</b> Senior Executive<br /> <b>Department:</b> Procurement</p>';

		$this->assertSame( $html, Field_Mapper::rejoin_wrapped_lines( $html ) );
	}

	/**
	 * A break after a finished sentence is deliberate and stays.
	 */
	public function test_a_break_after_a_sentence_survives() {
		$html = '<p>Close the order.<br />Raise the invoice.</p>';

		$this->assertSame( $html, Field_Mapper::rejoin_wrapped_lines( $html ) );
	}

	/**
	 * Two breaks together are a blank line somebody wanted.
	 */
	public function test_a_blank_line_survives() {
		$html = '<p>One line<br /><br />Another line</p>';

		$this->assertSame( $html, Field_Mapper::rejoin_wrapped_lines( $html ) );
	}

	/**
	 * A break after a pasted bullet glyph is deliberate and stays.
	 */
	public function test_a_bullet_glyph_survives() {
		$html = '<p>&bull;<br />Handle procurement</p>';

		$this->assertStringContainsString( '<br />', Field_Mapper::rejoin_wrapped_lines( $html ) );
	}

	/**
	 * A break against a block boundary is structural, not a wrap.
	 */
	public function test_a_break_before_a_block_survives() {
		$html = '<p>Trailing<br /></p><ul><li>One</li></ul>';

		$this->assertSame( $html, Field_Mapper::rejoin_wrapped_lines( $html ) );
	}

	/**
	 * Markup with no breaks at all comes back untouched.
	 */
	public function test_markup_without_breaks_is_untouched() {
		$html = '<p>Nothing to do here.</p>';

		$this->assertSame( $html, Field_Mapper::rejoin_wrapped_lines( $html ) );
	}

	/**
	 * The real thing: the shape a live Krea description arrives in.
	 */
	public function test_a_real_description_keeps_its_labels_and_loses_its_wraps() {
		$html = '<p style="line-height:150%"><b><span>Job Title:</span></b>'
			. '<span> Senior Executive<br /> <b>Department:</b> Procurement<br />'
			. ' <b>Reporting To:</b> Chief of People and Operations</span></p>'
			. '<p><b><span>Job<br /> Summary:</span></b></p>'
			. '<p><span>The Senior<br /> Executive will support and manage day-to-day procurement<br />'
			. ' activities, including vendor identification, quotation management, purchase<br />'
			. ' order coordination.</span></p>';

		$out = Field_Mapper::rejoin_wrapped_lines( $html );

		// The two label lines stay.
		$this->assertSame( 2, substr_count( $out, '<br />' ), $out );
		$this->assertStringContainsString( '<b>Department:</b>', $out );
		$this->assertStringContainsString( '<b>Reporting To:</b>', $out );

		// The wrapped fragments are whole sentences again.
		$this->assertStringContainsString( 'Job Summary:', $out );
		$this->assertStringContainsString( 'The Senior Executive will support', $out );
		$this->assertStringContainsString( 'quotation management, purchase order coordination.', $out );
	}

	/**
	 * Nothing happens unless the setting is on.
	 */
	public function test_the_transform_leaves_breaks_alone_by_default() {
		$html = '<p>The Senior<br /> Executive will support procurement.</p>';

		$this->assertStringContainsString( '<br />', Field_Mapper::transform( $html, 'raw_html' ) );
		$this->assertStringContainsString( '<br />', Field_Mapper::transform( $html, 'html' ) );
	}

	/**
	 * With the setting on, both HTML transforms rejoin.
	 */
	public function test_both_html_transforms_rejoin_when_enabled() {
		$this->enable();

		$html = '<p>The Senior<br /> Executive will support procurement.</p>';

		$this->assertStringNotContainsString( '<br', Field_Mapper::transform( $html, 'raw_html' ) );
		$this->assertStringNotContainsString( '<br', Field_Mapper::transform( $html, 'html' ) );
	}

	/**
	 * The filter can override the setting either way.
	 */
	public function test_the_filter_overrides_the_setting() {
		$html = '<p>The Senior<br /> Executive will support procurement.</p>';

		add_filter( 'jszr_rejoin_wrapped_lines', '__return_true' );
		$this->assertStringNotContainsString( '<br', Field_Mapper::transform( $html, 'raw_html' ) );
		remove_filter( 'jszr_rejoin_wrapped_lines', '__return_true' );

		$this->enable();

		add_filter( 'jszr_rejoin_wrapped_lines', '__return_false' );
		$this->assertStringContainsString( '<br', Field_Mapper::transform( $html, 'raw_html' ) );
		remove_filter( 'jszr_rejoin_wrapped_lines', '__return_false' );
	}
}
