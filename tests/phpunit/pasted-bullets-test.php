<?php
/**
 * Rebuilding lists that a word processor pasted as bullet characters.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Field_Mapper;
use JobsSyncForZohoRecruit\Settings;

/**
 * Word pastes one paragraph per item with a Symbol-font bullet in front of it,
 * not a list. These cover turning those back into one, and leaving everything
 * that is not a pasted bullet alone.
 */
class JSZR_Pasted_Bullets_Test extends WP_UnitTestCase {

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
		update_option( Settings::OPTION, array( 'list_from_bullets' => true ) );
		Settings::flush_cache();
	}

	/**
	 * One paragraph per item becomes one list.
	 */
	public function test_consecutive_bullets_become_one_list() {
		$html = '<p>&middot; Handle procurement</p><p>&middot; Review requisitions</p>';

		$out = Field_Mapper::lists_from_pasted_bullets( html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) );

		$this->assertSame( 1, substr_count( $out, '<ul>' ), $out );
		$this->assertSame( 2, substr_count( $out, '<li>' ), $out );
		$this->assertStringContainsString( '<li>Handle procurement</li>', $out );
		$this->assertStringContainsString( '<li>Review requisitions</li>', $out );
	}

	/**
	 * The bullet character itself does not survive into the item.
	 */
	public function test_the_marker_is_removed() {
		$out = Field_Mapper::lists_from_pasted_bullets( "<p>\xC2\xB7 Handle procurement</p>" );

		$this->assertStringNotContainsString( "\xC2\xB7", $out );
	}

	/**
	 * A paragraph that is not a bullet is left exactly as it was.
	 */
	public function test_ordinary_paragraphs_are_untouched() {
		$html = '<p>The Senior Executive will support procurement.</p>';

		$this->assertSame( $html, Field_Mapper::lists_from_pasted_bullets( $html ) );
	}

	/**
	 * A middle dot inside a sentence is not a bullet.
	 */
	public function test_a_mid_sentence_dot_is_not_a_bullet() {
		$html = "<p>Engineering \xC2\xB7 Chennai \xC2\xB7 Full time</p>";

		$this->assertSame( $html, Field_Mapper::lists_from_pasted_bullets( $html ) );
	}

	/**
	 * A glyph with no words after it is not a list item.
	 */
	public function test_a_lone_glyph_is_not_a_list() {
		$html = "<p>\xC2\xB7</p>";

		$this->assertSame( $html, Field_Mapper::lists_from_pasted_bullets( $html ) );
	}

	/**
	 * Real content between two runs of bullets splits them into two lists.
	 */
	public function test_content_between_runs_splits_the_lists() {
		$html = "<p>\xC2\xB7 One</p><p>Heading</p><p>\xC2\xB7 Two</p>";

		$out = Field_Mapper::lists_from_pasted_bullets( $html );

		$this->assertSame( 2, substr_count( $out, '<ul>' ), $out );
		$this->assertStringContainsString( '</ul><p>Heading</p><ul>', $out );
	}

	/**
	 * The real thing: Word's markup, exactly as it arrives from Zoho.
	 */
	public function test_the_real_word_markup() {
		$item = '<p class="MsoListParagraphCxSpFirst">'
			. '<span class="font" style="font-family:Symbol;line-height:150%"><span>'
			. "\xC2\xB7"
			. '<span class="font" style="font-family:&quot;Times New Roman&quot;">'
			. '<span class="size" style="font-size:7pt">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; </span>'
			. '</span></span></span>'
			. '<span style="line-height:150%">Handle end-to-end procurement activities.</span></p>';

		$out = Field_Mapper::lists_from_pasted_bullets( $item . $item );

		$this->assertSame( 1, substr_count( $out, '<ul>' ), $out );
		$this->assertSame( 2, substr_count( $out, '<li>' ), $out );
		$this->assertStringNotContainsString( "\xC2\xB7", $out );
		$this->assertStringNotContainsString( '&nbsp;', $out );
		$this->assertStringContainsString( 'Handle end-to-end procurement activities.', $out );

		// The text's own formatting survives; only the marker went.
		$this->assertStringContainsString( 'line-height:150%', $out );
	}

	/**
	 * Nothing happens unless the setting is on.
	 */
	public function test_nothing_happens_by_default() {
		$html = "<p>\xC2\xB7 Handle procurement</p>";

		$this->assertStringNotContainsString( '<li>', Field_Mapper::transform( $html, 'raw_html' ) );
	}

	/**
	 * With the setting on, both HTML transforms rebuild the list.
	 */
	public function test_both_html_transforms_rebuild_when_enabled() {
		$this->enable();

		$html = "<p>\xC2\xB7 Handle procurement</p><p>\xC2\xB7 Review requisitions</p>";

		$this->assertStringContainsString( '<li>', Field_Mapper::transform( $html, 'raw_html' ) );
		$this->assertStringContainsString( '<li>', Field_Mapper::transform( $html, 'html' ) );
	}

	/**
	 * The filter overrides the setting either way.
	 */
	public function test_the_filter_overrides_the_setting() {
		$html = "<p>\xC2\xB7 Handle procurement</p>";

		add_filter( 'jszr_lists_from_pasted_bullets', '__return_true' );
		$this->assertStringContainsString( '<li>', Field_Mapper::transform( $html, 'raw_html' ) );
		remove_filter( 'jszr_lists_from_pasted_bullets', '__return_true' );

		$this->enable();

		add_filter( 'jszr_lists_from_pasted_bullets', '__return_false' );
		$this->assertStringNotContainsString( '<li>', Field_Mapper::transform( $html, 'raw_html' ) );
		remove_filter( 'jszr_lists_from_pasted_bullets', '__return_false' );
	}

	/**
	 * A description that already uses real lists is not disturbed.
	 */
	public function test_a_real_list_is_untouched() {
		$html = '<ul><li>Handle procurement</li><li>Review requisitions</li></ul>';

		$this->assertSame( $html, Field_Mapper::lists_from_pasted_bullets( $html ) );
	}
}
