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

		// Future migrations are added here, guarded by version_compare( $from, 'x', '<' ).

		Post_Type::register();
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
