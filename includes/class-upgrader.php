<?php
/**
 * Versioned data upgrades.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Runs data migrations once per version change.
 *
 * Routines must be idempotent and must never overwrite an administrator's
 * settings; an upgrade only adds what is missing.
 */
class Upgrader {

	/**
	 * Run every upgrade newer than the stored version.
	 *
	 * @param string $from Stored data version, empty on first install.
	 * @return void
	 */
	public static function run( $from ) {
		// Tables are created with dbDelta, which is safe to re-run.
		Logger::install_table();
		Sync_Queue::install_table();

		Plugin::add_capability();

		if ( '' === $from ) {
			self::first_install();
		}

		if ( '' !== $from && version_compare( $from, '2', '<' ) ) {
			self::fix_job_opening_scope();
		}

		// Future migrations are added here, guarded by version_compare( $from, 'x', '<' ).

		// The post type is already registered by the time this runs, so only the
		// rules need rebuilding -- a migration may have changed a slug.
		flush_rewrite_rules( false );

		Logger::info(
			'plugin_upgraded',
			'Plugin data structures checked.',
			array(
				'from' => '' === $from ? 'new install' : $from,
				'to'   => DB_VERSION,
			)
		);
	}

	/**
	 * Correct a stored OAuth scope that Zoho does not accept.
	 *
	 * Versions before this one asked for ZohoRecruit.modules.jobopenings.READ,
	 * copied from a worked example in Zoho's documentation. The scope-name table
	 * on the same page says modules.jobopening, singular, and that is what the
	 * authorization server accepts; the plural is refused with "Invalid OAuth
	 * Scope". A site that saved the plural value keeps failing to connect until
	 * it is rewritten, so it is rewritten here rather than left for someone to
	 * notice.
	 *
	 * @return void
	 */
	private static function fix_job_opening_scope() {
		$stored = (string) Settings::get( 'oauth_scopes', '' );

		if ( '' === trim( $stored ) || false === strpos( $stored, 'modules.jobopenings' ) ) {
			return;
		}

		$fixed = str_replace( 'modules.jobopenings', 'modules.jobopening', $stored );

		Settings::update( array( 'oauth_scopes' => $fixed ) );

		Logger::info(
			'scope_corrected',
			'Corrected the stored Zoho job openings scope to the singular module name.',
			array(
				'before' => $stored,
				'after'  => $fixed,
			)
		);
	}

	/**
	 * Seed a brand new installation.
	 *
	 * @return void
	 */
	private static function first_install() {
		if ( false === get_option( Settings::OPTION, false ) ) {
			update_option( Settings::OPTION, Settings::defaults(), false );
		}

		if ( false === get_option( Field_Mapper::OPTION, false ) ) {
			update_option( Field_Mapper::OPTION, Field_Mapper::default_mapping(), false );
		}

		if ( false === get_option( Webhook::OPTION_SECRET, false ) ) {
			update_option( Webhook::OPTION_SECRET, wp_generate_password( 40, false, false ), false );
		}

		Cron::schedule();
	}
}
