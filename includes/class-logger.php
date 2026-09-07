<?php
/**
 * Logging.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Writes plugin events to a dedicated table.
 *
 * A custom table is used rather than a private post type because log rows are
 * high-churn, append-only and would otherwise bloat wp_posts, wp_postmeta and
 * every "recent content" query on the site.
 *
 * Secrets are scrubbed before anything is written.
 */
class Logger {

	/**
	 * Informational event.
	 */
	const INFO = 'info';

	/**
	 * Recoverable problem.
	 */
	const WARNING = 'warning';

	/**
	 * Failure.
	 */
	const ERROR = 'error';

	/**
	 * Debug detail; only stored when debug logging is enabled.
	 */
	const DEBUG = 'debug';

	/**
	 * Keys whose values are never stored.
	 *
	 * @var string[]
	 */
	private static $secret_keys = array(
		'access_token',
		'refresh_token',
		'client_secret',
		'client_id',
		'code',
		'authorization',
		'password',
		'secret',
		'token',
	);

	/**
	 * Hook registration.
	 */
	public function __construct() {
		add_action( 'jszr_prune_logs', array( __CLASS__, 'prune' ) );
	}

	/**
	 * Fully qualified log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'jszr_sync_logs';
	}

	/**
	 * Create or update the log table.
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL DEFAULT 0,
			level varchar(10) NOT NULL DEFAULT 'info',
			event varchar(60) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY level (level),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Record an informational event.
	 *
	 * @param string $event   Short machine-readable event name.
	 * @param string $message Human-readable message.
	 * @param array  $context Extra detail.
	 * @param int    $run_id  Related sync run ID.
	 * @return void
	 */
	public static function info( $event, $message, array $context = array(), $run_id = 0 ) {
		self::log( self::INFO, $event, $message, $context, $run_id );
	}

	/**
	 * Record a warning.
	 *
	 * @param string $event   Short machine-readable event name.
	 * @param string $message Human-readable message.
	 * @param array  $context Extra detail.
	 * @param int    $run_id  Related sync run ID.
	 * @return void
	 */
	public static function warning( $event, $message, array $context = array(), $run_id = 0 ) {
		self::log( self::WARNING, $event, $message, $context, $run_id );
	}

	/**
	 * Record an error.
	 *
	 * @param string $event   Short machine-readable event name.
	 * @param string $message Human-readable message.
	 * @param array  $context Extra detail.
	 * @param int    $run_id  Related sync run ID.
	 * @return void
	 */
	public static function error( $event, $message, array $context = array(), $run_id = 0 ) {
		self::log( self::ERROR, $event, $message, $context, $run_id );
	}

	/**
	 * Record a debug entry (stored only when debug logging is on).
	 *
	 * @param string $event   Short machine-readable event name.
	 * @param string $message Human-readable message.
	 * @param array  $context Extra detail.
	 * @param int    $run_id  Related sync run ID.
	 * @return void
	 */
	public static function debug( $event, $message, array $context = array(), $run_id = 0 ) {
		if ( ! Settings::get( 'debug_logging', false ) ) {
			return;
		}

		self::log( self::DEBUG, $event, $message, $context, $run_id );
	}

	/**
	 * Write a log row.
	 *
	 * @param string $level   One of the class level constants.
	 * @param string $event   Event name.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 * @param int    $run_id  Related sync run ID.
	 * @return void
	 */
	public static function log( $level, $event, $message, array $context = array(), $run_id = 0 ) {
		global $wpdb;

		$level   = in_array( $level, array( self::INFO, self::WARNING, self::ERROR, self::DEBUG ), true ) ? $level : self::INFO;
		$context = self::scrub( $context );
		$message = self::scrub_string( (string) $message );

		$encoded = empty( $context ) ? null : wp_json_encode( $context );

		if ( is_string( $encoded ) && strlen( $encoded ) > 60000 ) {
			$encoded = wp_json_encode( array( 'truncated' => true ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom log table.
		$wpdb->insert(
			self::table(),
			array(
				'run_id'     => (int) $run_id,
				'level'      => $level,
				'event'      => substr( sanitize_key( $event ), 0, 60 ),
				'message'    => wp_strip_all_tags( $message ),
				'context'    => $encoded,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && Settings::get( 'debug_logging', false ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in debug logging.
			error_log( sprintf( '[jszr] %s %s: %s', strtoupper( $level ), $event, $message ) );
		}
	}

	/**
	 * Fetch log entries.
	 *
	 * @param array $args Query arguments: run_id, level, limit, offset.
	 * @return array<int,object>
	 */
	public static function get_entries( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'run_id' => null,
				'level'  => null,
				'limit'  => 50,
				'offset' => 0,
			)
		);

		$where  = array( '1=1' );
		$params = array( self::table() );

		if ( null !== $args['run_id'] ) {
			$where[]  = 'run_id = %d';
			$params[] = (int) $args['run_id'];
		}

		if ( null !== $args['level'] ) {
			$where[]  = 'level = %s';
			$params[] = (string) $args['level'];
		}

		$params[] = max( 1, min( 500, (int) $args['limit'] ) );
		$params[] = max( 0, (int) $args['offset'] );

		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders built above.
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Delete every log row.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, nothing to cache.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table() ) );
	}

	/**
	 * Apply the retention policy.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		$table = self::table();
		$days  = (int) Settings::get( 'log_retention_days', 30 );
		$max   = (int) Settings::get( 'log_retention_max', 200 );

		if ( $days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, nothing to cache.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff ) );
		}

		if ( $max > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, nothing to cache.
			$threshold = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, $max ) );

			if ( $threshold ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, nothing to cache.
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, (int) $threshold ) );
			}
		}

		Sync_Queue::prune();
	}

	/**
	 * Remove secrets from an array recursively.
	 *
	 * @param array $context Context data.
	 * @param int   $depth   Current recursion depth.
	 * @return array
	 */
	public static function scrub( array $context, $depth = 0 ) {
		if ( $depth > 6 ) {
			return array();
		}

		$clean = array();

		foreach ( $context as $key => $value ) {
			$lower = is_string( $key ) ? strtolower( $key ) : '';

			foreach ( self::$secret_keys as $secret ) {
				if ( '' !== $lower && false !== strpos( $lower, $secret ) ) {
					$clean[ $key ] = '[redacted]';
					continue 2;
				}
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::scrub( $value, $depth + 1 );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = is_string( $value ) ? self::scrub_string( $value ) : $value;
			} else {
				$clean[ $key ] = '[object]';
			}
		}

		return $clean;
	}

	/**
	 * Remove token-looking substrings from free text.
	 *
	 * @param string $text Text to scrub.
	 * @return string
	 */
	public static function scrub_string( $text ) {
		$text = (string) $text;

		// Zoho tokens look like 1000.abcdef0123....
		$text = preg_replace( '/\b1000\.[A-Za-z0-9._-]{20,}/', '[redacted]', $text );

		// Generic long opaque strings following a token-ish label.
		$text = preg_replace( '/((?:access|refresh|client)[_-]?(?:token|secret)\s*[=:]\s*)\S+/i', '$1[redacted]', $text );

		return is_string( $text ) ? $text : '';
	}
}
