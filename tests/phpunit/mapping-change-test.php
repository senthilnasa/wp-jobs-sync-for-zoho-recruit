<?php
/**
 * A changed field mapping reaches every job, not only the ones Zoho changed.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Encryption;
use JobsSyncForZohoRecruit\Field_Mapper;
use JobsSyncForZohoRecruit\Settings;
use JobsSyncForZohoRecruit\Sync_Queue;

/**
 * An incremental sync asks Zoho only for records modified since the last run,
 * so switching the description to Raw HTML never reached a job nobody edited
 * in Zoho. A live site kept plain-text descriptions for exactly that reason.
 * Saving a different mapping now makes the next sync a full one.
 */
class JSZR_Mapping_Change_Test extends WP_UnitTestCase {

	/**
	 * Start from the default mapping and no pending change.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Settings::flush_cache();

		Field_Mapper::reset_mapping();
		delete_option( Field_Mapper::OPTION_CHANGED );
	}

	/**
	 * Remove the fake account and any run a test left behind.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'refuse_http' ), 10 );

		Sync_Queue::cancel_active();
		Sync_Queue::release_lock();

		delete_option( 'jszr_credentials' );
		delete_option( 'jszr_tokens' );
		delete_option( Field_Mapper::OPTION_CHANGED );
		Field_Mapper::reset_mapping();
		delete_option( Field_Mapper::OPTION_CHANGED );

		parent::tear_down();
	}

	/**
	 * Block every outgoing request; nothing here should reach Zoho.
	 *
	 * @return WP_Error
	 */
	public function refuse_http() {
		return new WP_Error( 'http_blocked', 'No network in tests.' );
	}

	/**
	 * The default mapping with the description switched to another transform.
	 *
	 * @param string $transform Transform for the Job_Description row.
	 * @return array
	 */
	private function mapping_with_description( $transform ) {
		$mapping = Field_Mapper::default_mapping();

		foreach ( $mapping as $index => $row ) {
			if ( 'Job_Description' === $row['zoho_field'] ) {
				$mapping[ $index ]['transform'] = $transform;
			}
		}

		return $mapping;
	}

	/**
	 * Switching the description's transform is recorded as a change.
	 */
	public function test_changing_a_transform_marks_the_mapping_changed() {
		$this->assertSame( 0, Field_Mapper::changed_at() );

		Field_Mapper::save_mapping( $this->mapping_with_description( 'raw_html' ) );

		$this->assertGreaterThan( 0, Field_Mapper::changed_at() );
	}

	/**
	 * Saving the mapping unchanged does not force a full sync.
	 */
	public function test_saving_the_same_mapping_is_not_a_change() {
		Field_Mapper::save_mapping( $this->mapping_with_description( 'raw_html' ) );
		delete_option( Field_Mapper::OPTION_CHANGED );

		Field_Mapper::save_mapping( $this->mapping_with_description( 'raw_html' ) );

		$this->assertSame( 0, Field_Mapper::changed_at() );
	}

	/**
	 * Resetting is a change only when the mapping was not already the default.
	 */
	public function test_reset_is_a_change_only_from_a_custom_mapping() {
		Field_Mapper::reset_mapping();
		$this->assertSame( 0, Field_Mapper::changed_at() );

		Field_Mapper::save_mapping( $this->mapping_with_description( 'raw_html' ) );
		delete_option( Field_Mapper::OPTION_CHANGED );

		Field_Mapper::reset_mapping();
		$this->assertGreaterThan( 0, Field_Mapper::changed_at() );
	}

	/**
	 * Only a full sync that started after the change clears it.
	 */
	public function test_only_a_later_full_sync_clears_the_change() {
		update_option( Field_Mapper::OPTION_CHANGED, 1000, false );

		Field_Mapper::clear_changed( 900 );
		$this->assertSame( 1000, Field_Mapper::changed_at(), 'A run that started before the change must not clear it.' );

		Field_Mapper::clear_changed( 1000 );
		$this->assertSame( 1000, Field_Mapper::changed_at(), 'A run that started the same second may have missed it.' );

		Field_Mapper::clear_changed( 1001 );
		$this->assertSame( 0, Field_Mapper::changed_at() );
	}

	/**
	 * After a mapping change, "Sync now" re-maps every job instead of asking
	 * Zoho only for the records modified since the last run.
	 */
	public function test_incremental_sync_runs_full_after_a_mapping_change() {
		$this->fake_connected_account();

		// A previous successful sync, so an incremental run would send
		// If-Modified-Since.
		update_option( 'jszr_sync_state', array( 'last_success' => time() - HOUR_IN_SECONDS ), false );

		Field_Mapper::save_mapping( $this->mapping_with_description( 'raw_html' ) );

		$run_id = JobsSyncForZohoRecruit\plugin()->sync()->start( 'incremental', array( 'trigger' => 'admin' ) );

		$this->assertIsInt( $run_id );

		$run = Sync_Queue::get( $run_id );

		$this->assertSame( 'full', (string) $run->type );
		$this->assertSame( '', (string) $run->modified_since );
	}

	/**
	 * With no mapping change an incremental sync stays incremental.
	 */
	public function test_incremental_sync_stays_incremental_without_a_change() {
		$this->fake_connected_account();

		update_option( 'jszr_sync_state', array( 'last_success' => time() - HOUR_IN_SECONDS ), false );

		$run_id = JobsSyncForZohoRecruit\plugin()->sync()->start( 'incremental', array( 'trigger' => 'admin' ) );

		$this->assertIsInt( $run_id );

		$run = Sync_Queue::get( $run_id );

		$this->assertSame( 'incremental', (string) $run->type );
		$this->assertNotSame( '', (string) $run->modified_since );
	}

	/**
	 * Store credentials and a refresh token so the sync believes it is connected.
	 */
	private function fake_connected_account() {
		update_option(
			'jszr_credentials',
			array(
				'client_id'     => Encryption::encrypt( 'fake.client.id' ),
				'client_secret' => Encryption::encrypt( 'fake-client-secret' ),
				'fingerprint'   => Encryption::key_fingerprint(),
			),
			false
		);

		update_option(
			'jszr_tokens',
			array(
				'refresh_token' => Encryption::encrypt( 'fake-refresh-token' ),
				'access_token'  => '',
				'expires_at'    => 0,
				'api_domain'    => 'https://recruit.zoho.com',
				'fingerprint'   => Encryption::key_fingerprint(),
				'connected_at'  => time(),
			),
			false
		);

		delete_option( 'jszr_auth_failures' );

		add_filter( 'pre_http_request', array( $this, 'refuse_http' ), 10 );
	}
}
