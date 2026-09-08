<?php
/**
 * Diagnostic report and forced-run tests.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Diagnostics;
use JobsSyncForZohoRecruit\Settings;
use JobsSyncForZohoRecruit\Sync_Queue;

/**
 * Covers the "check everything" report and the forced sync flag, neither of
 * which may reach the network from a test.
 */
class JSZR_Diagnostics_Test extends WP_UnitTestCase {

	/**
	 * A client ID shaped like a real one, so the scrubber has something to bite.
	 */
	const FAKE_ID = '1000.ABCDEFGHIJKLMNOPQRSTUVWXYZ0123';

	/**
	 * A client secret shaped like a real one.
	 */
	const FAKE_SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

	/**
	 * Start each test from a clean slate.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		delete_option( Diagnostics::OPTION );
		Settings::flush_cache();
	}

	/**
	 * A report without probing gathers the static checks and stores itself.
	 */
	public function test_report_is_built_and_stored() {
		$report = Diagnostics::run( false );

		$this->assertNotEmpty( $report['checks'] );
		$this->assertNotEmpty( $report['environment'] );
		$this->assertArrayHasKey( 'connection', $report );
		$this->assertArrayHasKey( 'schedule', $report );

		foreach ( $report['checks'] as $check ) {
			$this->assertArrayHasKey( 'label', $check );
			$this->assertContains( $check['status'], array( 'pass', 'warning', 'fail' ) );
		}

		$this->assertSame( $report, Diagnostics::last() );
	}

	/**
	 * Without a connection the report must not try to call Zoho, so the live
	 * checks are absent rather than failing.
	 */
	public function test_probe_is_skipped_when_not_connected() {
		$labels = wp_list_pluck( Diagnostics::run( true )['checks'], 'label' );

		$this->assertNotContains( 'Access token obtained', $labels );
	}

	/**
	 * The whole point of the download: it can be sent to someone else safely.
	 */
	public function test_report_never_contains_credentials() {
		JobsSyncForZohoRecruit\plugin()->auth()->save_credentials( self::FAKE_ID, self::FAKE_SECRET );

		$text = Diagnostics::to_text( Diagnostics::run( false ) );

		$this->assertStringNotContainsString( self::FAKE_ID, $text );
		$this->assertStringNotContainsString( self::FAKE_SECRET, $text );
	}

	/**
	 * The token expiry is a timestamp, not a secret. It used to be called
	 * token_expires, which the scrubber redacted on the key name alone and took
	 * away the most useful line in the report.
	 */
	public function test_expiry_time_is_not_redacted() {
		$report = Diagnostics::run( false );

		$this->assertArrayHasKey( 'access_expires', $report['connection'] );
		$this->assertNotSame( '[redacted]', $report['connection']['access_expires'] );
	}

	/**
	 * The text rendering is what an administrator actually reads.
	 */
	public function test_text_report_has_readable_sections() {
		$text = Diagnostics::to_text( Diagnostics::run( false ) );

		$this->assertStringContainsString( 'CHECKS', $text );
		$this->assertStringContainsString( 'ENVIRONMENT', $text );
		$this->assertStringContainsString( 'CONNECTION', $text );
		$this->assertStringContainsString( 'SETTINGS', $text );
	}

	/**
	 * An empty report renders rather than fataling, because the download handler
	 * can be reached before a check has ever run.
	 */
	public function test_text_report_survives_an_empty_report() {
		$this->assertStringContainsString( 'diagnostic report', Diagnostics::to_text( array() ) );
	}

	/**
	 * The filename identifies the site and is safe on every filesystem.
	 */
	public function test_filename_is_a_safe_text_file() {
		$name = Diagnostics::filename();

		$this->assertStringEndsWith( '.txt', $name );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9._-]+$/', $name );
	}

	/**
	 * Sync Now records that it was forced, so the run can be recognised later.
	 */
	public function test_forced_run_is_recorded() {
		$forced = Sync_Queue::create( 'full', array( 'force' => true ) );
		$normal = Sync_Queue::create( 'full' );

		$this->assertTrue( Sync_Queue::stats( Sync_Queue::get( $forced ) )['force'] );
		$this->assertFalse( Sync_Queue::stats( Sync_Queue::get( $normal ) )['force'] );
	}
}
