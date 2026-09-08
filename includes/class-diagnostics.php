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

		/*
		 * Every Zoho account renames and re-purposes fields, and the plugin's
		 * defaults are only the common names. A mapping that points at a field
		 * this account does not have fails silently -- the sync succeeds, the
		 * job appears, and one value is quietly empty forever. That is a
		 * miserable thing to debug from the outside, so it is checked here
		 * against the field list read from the account itself.
		 */
		$known = self::known_zoho_fields();

		if ( ! empty( $known ) ) {

			$missing = array();

			foreach ( Field_Mapper::get_mapping() as $row ) {
				$field = isset( $row['zoho_field'] ) ? (string) $row['zoho_field'] : '';

				if ( '' !== $field && ! in_array( $field, $known, true ) ) {
					$missing[] = $field;
				}
			}

			$missing = array_values( array_unique( $missing ) );

			$checks[] = self::check(
				'Mapped fields exist in Zoho',
				empty( $missing ),
				empty( $missing )
					? 'every mapped field exists on this account'
					: sprintf(
						'not seen on this account: %s. Anything mapped from these stays empty on every job. Pick the right field on the Field Mapping screen.',
						implode( ', ', $missing )
					),
				'warning'
			);

			/*
			 * The publish flag is not part of the mapping, so it needs its own
			 * line. A field missing from a record counts as "no opinion" rather
			 * than as unpublished -- deliberate, so an account that does not use
			 * the flag still syncs -- which means a wrong name silently disables
			 * the filter and unpublished jobs reach the website.
			 */
			$flag = (string) Settings::get( 'published_field', '' );

			if ( Settings::get( 'only_published', true ) && '' !== $flag ) {
				$checks[] = self::check(
					'Publish flag field exists',
					in_array( $flag, $known, true ),
					in_array( $flag, $known, true )
						? sprintf( '%s found on this account', $flag )
						: sprintf(
							'%s is not a field on this account, so "only sync published jobs" is having no effect and jobs that are not on your career site are being synced anyway.',
							$flag
						),
					'warning'
				);
			}
		}

		// An empty apply button is invisible on the frontend: the job renders,
		// the candidate reads it, and there is simply nothing to click. Worth a
		// line in the report rather than leaving it to be noticed.
		$missing = self::jobs_without_apply_url();

		$checks[] = self::check(
			'Apply links present',
			0 === $missing,
			0 === $missing
				? 'every active job has an application link'
				: sprintf(
					'%d active job(s) have no application link, so no apply button is shown. Zoho does not send one: set the career site address on the Frontend settings screen.',
					$missing
				),
			'warning'
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

		$detail = sprintf( '%d field(s) readable', count( (array) $fields ) );

		if ( is_wp_error( $fields ) ) {
			$detail = $fields->get_error_message();

			/*
			 * "Reconnect to grant the requested permissions" is unhelpful when
			 * the permission was never in the request. If the fields scope is
			 * not in the configured list, reconnecting will fail the same way,
			 * so say what actually has to change.
			 */
			$fields_scope = 'ZohoRecruit.settings.fields.READ';

			if ( ! in_array( $fields_scope, $auth->scopes(), true ) ) {
				$detail = sprintf(
					'%s is not in the requested scopes, so reconnecting alone will not fix it. Add it under Settings > Connection > OAuth scopes, then disconnect and reconnect.',
					$fields_scope
				);
			}

			$detail .= ' (optional: the mapping screen falls back to standard field names)';
		}

		$checks[] = self::check(
			'Field discovery',
			! is_wp_error( $fields ),
			$detail,
			is_wp_error( $fields ) ? 'warning' : 'pass'
		);

		return $checks;
	}

	/**
	 * Every Zoho field name this site has actually seen.
	 *
	 * Two sources, because neither alone is complete. The field metadata lists
	 * what is on the module layout, and the last synced record shows what the
	 * records API really returns -- which is not the same set. Posting_Title,
	 * for one, comes back on every record while being absent from the layout
	 * list, so checking against the layout alone would report a mapping that
	 * demonstrably works as broken.
	 *
	 * Neither call touches the network.
	 *
	 * @return string[] Empty when this site has never seen either.
	 */
	private static function known_zoho_fields() {
		$known = array_keys( plugin()->metadata()->cached_fields() );

		$recent = get_posts(
			array(
				'post_type'        => Post_Type::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Diagnostic, one row.
				'meta_key'         => Job::META_RAW,
			)
		);

		if ( ! empty( $recent ) ) {
			$raw = json_decode( (string) get_post_meta( (int) $recent[0], Job::META_RAW, true ), true );

			if ( is_array( $raw ) ) {
				$known = array_merge( $known, array_keys( $raw ) );
			}
		}

		return array_values( array_unique( $known ) );
	}

	/**
	 * How many active jobs would render without an apply button.
	 *
	 * @return int
	 */
	private static function jobs_without_apply_url() {
		$query = new \WP_Query(
			array(
				'post_type'              => Post_Type::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 50,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Diagnostic, capped at 50 rows.
				'meta_query'             => array(
					array(
						'key'     => Job::META_STATUS,
						'value'   => Job::active_statuses(),
						'compare' => 'IN',
					),
				),
			)
		);

		$missing = 0;

		foreach ( $query->posts as $post ) {
			if ( '' === Job::get_apply_url( $post->ID ) ) {
				++$missing;
			}
		}

		return $missing;
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
