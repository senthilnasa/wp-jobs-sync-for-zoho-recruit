<?php
/**
 * Self-healing rewrite rules.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\Settings;

/**
 * A live site lost the job rules: /jobs/ still answered from a page cache
 * while /jobs/?jszr_page=2 and every single job returned the theme's 404.
 * The plugin now notices and regenerates the rules itself.
 */
class JSZR_Rewrite_Heal_Test extends WP_UnitTestCase {

	/**
	 * Pretty permalinks with the plugin's rules in place.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Settings::flush_cache();
		delete_transient( 'jszr_rewrite_heal' );

		$this->set_permalink_structure( '/%postname%/' );
		Post_Type::register();
		flush_rewrite_rules( false );
	}

	/**
	 * Back to plain permalinks for the rest of the suite.
	 */
	public function tear_down() {
		$this->set_permalink_structure( '' );
		delete_transient( 'jszr_rewrite_heal' );

		parent::tear_down();
	}

	/**
	 * Whether the stored rules carry the job post type.
	 *
	 * @return bool
	 */
	private function has_job_rules() {
		foreach ( array_keys( (array) get_option( 'rewrite_rules' ) ) as $pattern ) {
			if ( 0 === strpos( (string) $pattern, 'jobs/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Strip every job rule, as a stale option would have it.
	 */
	private function remove_job_rules() {
		$rules = (array) get_option( 'rewrite_rules' );

		foreach ( array_keys( $rules ) as $pattern ) {
			if ( 0 === strpos( (string) $pattern, 'jobs/' ) ) {
				unset( $rules[ $pattern ] );
			}
		}

		update_option( 'rewrite_rules', $rules );
	}

	/**
	 * Missing rules come back on the next request.
	 */
	public function test_missing_rules_are_regenerated() {
		$this->assertTrue( $this->has_job_rules(), 'precondition: rules exist after a flush' );

		$this->remove_job_rules();
		$this->assertFalse( $this->has_job_rules(), 'precondition: rules removed' );

		Post_Type::heal_rewrite_rules();

		$this->assertTrue( $this->has_job_rules() );
		$this->assertNotFalse( get_transient( 'jszr_rewrite_heal' ) );
	}

	/**
	 * A flush happens at most once an hour, so a site that filters the rules
	 * away on purpose is not made to flush on every page load.
	 */
	public function test_heal_is_rate_limited() {
		$this->remove_job_rules();
		set_transient( 'jszr_rewrite_heal', 1, HOUR_IN_SECONDS );

		Post_Type::heal_rewrite_rules();

		$this->assertFalse( $this->has_job_rules() );
	}

	/**
	 * Nothing happens when the rules are already there.
	 */
	public function test_present_rules_are_left_alone() {
		$before = get_option( 'rewrite_rules' );

		Post_Type::heal_rewrite_rules();

		$this->assertSame( $before, get_option( 'rewrite_rules' ) );
		$this->assertFalse( get_transient( 'jszr_rewrite_heal' ) );
	}

	/**
	 * Plain permalinks need no rules, so nothing is flushed.
	 */
	public function test_plain_permalinks_are_ignored() {
		$this->set_permalink_structure( '' );
		update_option( 'rewrite_rules', array( 'foo/?$' => 'index.php?pagename=foo' ) );

		Post_Type::heal_rewrite_rules();

		$this->assertSame( array( 'foo/?$' => 'index.php?pagename=foo' ), get_option( 'rewrite_rules' ) );
	}
}
