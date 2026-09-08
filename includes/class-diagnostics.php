<?php
/**
 * Diagnostic report.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Gathers everything worth knowing when something is not working, runs a live
 * connection check, and renders it as plain text an administrator can read or
 * send on.
 *
 * Every value passes through the logger's scrubber on the way out, so a report
 * can be shared without leaking a token, a secret or a client ID.
 */
class Diagnostics {

	/**
	 * Option holding the most recent report.
	 */
	const OPTION = 'jszr_last_diagnostics';

	/**
	 * Build a report.
	 *
	 * @param bool $probe Whether to make live calls to Zoho.
	 * @return array
	 */
	public static function run( $probe = true ) {
		$auth  = plugin()->auth();
		$api   = plugin()->api();
		$state = plugin()->sync()->get_state();

		$report = array(
			'generated_at' => current_time( 'mysql', true ),
			'checks'       => array(),
			'environment'  => self::environment(),
			'connection'   => self::connection( $auth ),
			'settings'     => self::settings(),
			'mapping'      => Field_Mapper::get_mapping(),
			'jobs'         => Job::counts(),
			'schedule'     => self::schedule(),
			'sync_state'   => $state,
			'runs'         => array(),
			'log'          => array(),
		);

		foreach ( Sync_Queue::get_recent( 10 ) as $run ) {
			$report['runs'][] = Sync_Queue::stats( $run );
		}

		foreach ( Logger::get_entries( array( 'limit' => 60 ) ) as $entry ) {
			$report['log'][] = array(
				'time'    => $entry->created_at,
				'level'   => $entry->level,
				'event'   => $entry->event,
				'message' => $entry->message,
				'context' => (string) $entry->context,
			);
		}

		$report['checks'] = self::checks( $auth, $api, $probe );

		$report = Logger::scrub( $report );

		update_option( self::OPTION, $report, false );

		Logger::info(
			'diagnostics_run',
			'A diagnostic report was generated.',
			array( 'probe' => (bool) $probe )
		);

		return $report;
	}

	/**
	 * The most recent stored report.
	 *
	 * @return array
	 */
	public static function last() {
		$report = get_option( self::OPTION, array() );

		return is_array( $report ) ? $report : array();
	}

	/**
	 * Pass/fail checks, including live calls when asked for.
	 *
	 * @param Zoho_Auth $auth  OAuth handler.
	 * @param Zoho_API  $api   API client.
	 * @param bool      $probe Whether to call Zoho.
	 * @return array<int,array{label:string,status:string,detail:string}>
	 */
	private static function checks( Zoho_Auth $auth, Zoho_API $api, $probe ) {
		$checks = array();

		$checks[] = self::check(
			'Secrets can be encrypted',
			Encryption::is_available(),
			Encryption::is_available() ? 'libsodium or OpenSSL available' : 'no encryption extension or missing security salts'
		);

		$checks[] = self::check(
			'Credentials entered',
			$auth->has_credentials(),
			$auth->has_credentials() ? 'client ID and secret present' : 'missing'
		);

		$checks[] = self::check(
			'Connected to Zoho',
			$auth->is_connected(),
			$auth->is_connected() ? 'refresh token stored' : 'not connected'
		);

		$checks[] = self::check(
			'Security keys unchanged',
			! $auth->keys_changed(),
			$auth->keys_changed() ? 'salts changed since the tokens were stored; reconnect needed' : 'ok'
		);

		$checks[] = self::check(
			'Automatic syncing not paused',
			! $auth->is_circuit_open(),
			$auth->is_circuit_open() ? 'paused after repeated authentication failures' : 'ok'
		);

		$checks[] = self::check(
			'Database tables present',
			self::table_exists( 'jszr_sync_runs' ) && self::table_exists( 'jszr_sync_logs' ),
			self::table_exists( 'jszr_sync_runs' ) ? 'both tables exist' : 'missing; deactivate and reactivate the plugin'
		);

		$frequency = (string) Settings::get( 'sync_frequency', '' );
		$next      = wp_next_scheduled( Cron::SYNC_HOOK );

		$checks[] = self::check(
			'Sync scheduled',
			'disabled' === $frequency || false !== $next,
			'disabled' === $frequency
				? 'scheduling is switched off; manual syncs only'
				: ( $next ? 'next run ' . gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'no event scheduled' )
		);

		$checks[] = self::check(
			'WP-Cron available',
			! Cron::is_wp_cron_disabled(),
			Cron::is_wp_cron_disabled()
				? 'DISABLE_WP_CRON is set; a system cron must call wp-cron.php'
				: 'ok'
		);

		if ( ! $probe || ! $auth->is_connected() ) {
			return $checks;
		}

		// Live calls. These are the ones that actually prove the connection.
		$token = $auth->get_access_token();

		$checks[] = self::check(
			'Access token obtained',
			! is_wp_error( $token ),
			is_wp_error( $token ) ? $token->get_error_message() : 'ok'
		);

		if ( is_wp_error( $token ) ) {
			return $checks;
		}

		$test = $api->test_connection();

		$checks[] = self::check(
			'Job openings readable',
			! is_wp_error( $test ),
			is_wp_error( $test )
				? $test->get_error_message()
				: sprintf( 'module %s reports %d record(s)', $test['module'], (int) $test['count'] )
		);

		$fields = $api->get_fields();

		$checks[] = self::check(
			'Field discovery',
			! is_wp_error( $fields ),
			is_wp_error( $fields )
				? $fields->get_error_message() . ' (optional: the mapping screen falls back to standard field names)'
				: sprintf( '%d field(s) readable', count( (array) $fields ) ),
			is_wp_error( $fields ) ? 'warning' : 'pass'
		);

		return $checks;
	}

	/**
	 * Shape one check.
	 *
	 * @param string $label   What was checked.
	 * @param bool   $passed  Result.
	 * @param string $detail  Extra context.
	 * @param string $on_fail Status to use when it did not pass.
	 * @return array
	 */
	private static function check( $label, $passed, $detail = '', $on_fail = 'fail' ) {
		return array(
			'label'  => $label,
			'status' => $passed ? 'pass' : $on_fail,
			'detail' => (string) $detail,
		);
	}

	/**
	 * Whether one of the plugin's tables exists.
	 *
	 * @param string $suffix Table name without the prefix.
	 * @return bool
	 */
	private static function table_exists( $suffix ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostic schema check.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $suffix ) );
	}

	/**
	 * Environment facts.
	 *
	 * @return array
	 */
	private static function environment() {
		global $wpdb;

		return array(
			'plugin_version' => VERSION,
			'data_version'   => (string) get_option( 'jszr_db_version', '' ),
			'wordpress'      => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'mysql'          => $wpdb->db_version(),
			'multisite'      => is_multisite() ? 'yes' : 'no',
			'site_url'       => home_url( '/' ),
			'https_admin'    => 'https' === wp_parse_url( admin_url(), PHP_URL_SCHEME ) ? 'yes' : 'no',
			'locale'         => get_locale(),
			'timezone'       => wp_timezone_string(),
			'memory_limit'   => (string) ini_get( 'memory_limit' ),
			'max_execution'  => (string) ini_get( 'max_execution_time' ),
			'active_theme'   => wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ),
			'block_theme'    => wp_is_block_theme() ? 'yes' : 'no',
			'active_plugins' => self::active_plugins(),
		);
	}

	/**
	 * Names of active plugins, which is usually where a conflict lives.
	 *
	 * @return string[]
	 */
	private static function active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all  = get_plugins();
		$list = array();

		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$list[] = isset( $all[ $file ] )
				? $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version']
				: (string) $file;
		}

		return $list;
	}

	/**
	 * Connection facts, with nothing secret in them.
	 *
	 * @param Zoho_Auth $auth OAuth handler.
	 * @return array
	 */
	private static function connection( Zoho_Auth $auth ) {
		$info = $auth->connection_info();

		return array(
			'connected'      => $info['connected'] ? 'yes' : 'no',
			'data_center'    => $info['data_center'],
			'api_base'       => $info['api_base'],
			'redirect_uri'   => $auth->redirect_uri(),
			'scopes'         => implode( ',', $auth->scopes() ),
			'granted_scope'  => $info['scope'],
			'module'         => plugin()->api()->module(),
			// Named without "token" so the scrubber does not redact it: the
			// expiry time is a timestamp, and one of the most useful things in
			// the report when a refresh is misbehaving.
			'access_expires' => $info['expires_at'] ? gmdate( 'Y-m-d H:i:s', $info['expires_at'] ) . ' UTC' : '',
			'connected_at'   => $info['connected_at'] ? gmdate( 'Y-m-d H:i:s', $info['connected_at'] ) . ' UTC' : '',
			'failures'       => $info['failures'],
			'state'          => $info['state'],
			'keys_changed'   => $info['keys_changed'] ? 'yes' : 'no',
		);
	}

	/**
	 * Settings, minus anything sensitive.
	 *
	 * @return array
	 */
	private static function settings() {
		$settings = Settings::all();

		// The notification address is personal data and irrelevant to a fault.
		unset( $settings['notify_email'] );

		return $settings;
	}

	/**
	 * Scheduled event times.
	 *
	 * @return array<string,string>
	 */
	private static function schedule() {
		$events = array(
			Cron::SYNC_HOOK   => 'scheduled sync',
			Cron::EXPIRY_HOOK => 'expiry check',
			Cron::PRUNE_HOOK  => 'log pruning',
		);

		$out = array();

		foreach ( $events as $hook => $label ) {
			$next = wp_next_scheduled( $hook );

			$out[ $label ] = $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'not scheduled';
		}

		$active = Sync_Queue::get_active();

		$out['running now'] = $active ? 'run #' . (int) $active->id : 'no';
		$out['lock held']   = Sync_Queue::is_locked() ? 'yes' : 'no';
		$out['wp_cron']     = Cron::is_wp_cron_disabled() ? 'disabled by DISABLE_WP_CRON' : 'enabled';

		return $out;
	}

	/**
	 * Render a report as plain text.
	 *
	 * @param array $report Report from run() or last().
	 * @return string
	 */
	public static function to_text( array $report ) {
		$lines = array();

		$lines[] = 'Jobs Sync for Zoho Recruit — diagnostic report';
		$lines[] = 'Generated ' . ( $report['generated_at'] ?? '' ) . ' UTC';
		$lines[] = str_repeat( '=', 70 );
		$lines[] = '';

		if ( ! empty( $report['checks'] ) ) {
			$lines[] = 'CHECKS';
			$lines[] = str_repeat( '-', 70 );

			foreach ( $report['checks'] as $check ) {
				$lines[] = sprintf(
					'  [%s] %s%s',
					strtoupper( str_pad( (string) $check['status'], 4 ) ),
					$check['label'],
					'' !== $check['detail'] ? ' — ' . $check['detail'] : ''
				);
			}

			$lines[] = '';
		}

		$sections = array(
			'ENVIRONMENT' => $report['environment'] ?? array(),
			'CONNECTION'  => $report['connection'] ?? array(),
			'SCHEDULE'    => $report['schedule'] ?? array(),
			'JOB COUNTS'  => $report['jobs'] ?? array(),
			'SETTINGS'    => $report['settings'] ?? array(),
		);

		foreach ( $sections as $title => $values ) {
			$lines[] = $title;
			$lines[] = str_repeat( '-', 70 );

			foreach ( (array) $values as $key => $value ) {
				$lines[] = '  ' . str_pad( (string) $key, 24 ) . self::flatten( $value );
			}

			$lines[] = '';
		}

		if ( ! empty( $report['mapping'] ) ) {
			$lines[] = 'FIELD MAPPING';
			$lines[] = str_repeat( '-', 70 );

			foreach ( $report['mapping'] as $row ) {
				$lines[] = sprintf(
					'  %s → %s (%s)',
					str_pad( (string) ( $row['zoho_field'] ?? '' ), 28 ),
					$row['target'] ?? '',
					$row['transform'] ?? ''
				);
			}

			$lines[] = '';
		}

		if ( ! empty( $report['runs'] ) ) {
			$lines[] = 'RECENT SYNC RUNS';
			$lines[] = str_repeat( '-', 70 );

			foreach ( $report['runs'] as $run ) {
				$lines[] = sprintf(
					'  #%-5s %-12s %-10s started %s',
					$run['run_id'] ?? '',
					$run['type'] ?? '',
					$run['state'] ?? '',
					$run['started_at'] ?? ''
				);
				$lines[] = sprintf(
					'         processed %s, created %s, updated %s, skipped %s, deactivated %s, expired %s, orphaned %s, errors %s',
					$run['processed'] ?? 0,
					$run['created'] ?? 0,
					$run['updated'] ?? 0,
					$run['skipped'] ?? 0,
					$run['deactivated'] ?? 0,
					$run['expired'] ?? 0,
					$run['orphaned'] ?? 0,
					$run['errors'] ?? 0
				);

				if ( ! empty( $run['message'] ) ) {
					$lines[] = '         ' . $run['message'];
				}
			}

			$lines[] = '';
		}

		if ( ! empty( $report['log'] ) ) {
			$lines[] = 'LOG (newest first)';
			$lines[] = str_repeat( '-', 70 );

			foreach ( $report['log'] as $entry ) {
				$lines[] = sprintf(
					'  %s  %-7s %-24s %s',
					$entry['time'] ?? '',
					strtoupper( (string) ( $entry['level'] ?? '' ) ),
					$entry['event'] ?? '',
					$entry['message'] ?? ''
				);

				if ( ! empty( $entry['context'] ) ) {
					$lines[] = '      context: ' . $entry['context'];
				}
			}

			$lines[] = '';
		}

		$lines[] = str_repeat( '=', 70 );
		$lines[] = 'Tokens, secrets and client IDs are removed from this report.';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Render one value for the text report.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function flatten( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( is_array( $value ) ) {
			$parts = array();

			foreach ( $value as $key => $item ) {
				$parts[] = is_int( $key ) ? self::flatten( $item ) : $key . '=' . self::flatten( $item );
			}

			return implode( ', ', $parts );
		}

		return (string) $value;
	}

	/**
	 * A filename for the downloaded report.
	 *
	 * @return string
	 */
	public static function filename() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? sanitize_file_name( $host ) : 'site';

		return sprintf( 'jszr-diagnostics-%s-%s.txt', $host, gmdate( 'Ymd-His' ) );
	}
}
