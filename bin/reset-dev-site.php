<?php
/**
 * Reset a development site after a scale or lifecycle test.
 *
 * Removes the synthetic jobs those tests create, clears any sync run they left
 * mid-flight, and deletes the fake credentials the scale test writes. Safe to
 * run repeatedly. Development sites only:
 *
 *     wp eval-file bin/reset-dev-site.php
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Logger;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\Sync_Queue;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

global $wpdb;

// 1. Stop anything still in flight, so a stuck run cannot resume mid-cleanup.
foreach ( array( 'jszr_scheduled_sync', 'jszr_check_expired', 'jszr_prune_logs' ) as $jszr_hook ) {
	wp_clear_scheduled_hook( $jszr_hook );
}

$jszr_crons   = _get_cron_array();
$jszr_cleared = 0;

if ( is_array( $jszr_crons ) ) {
	foreach ( $jszr_crons as $jszr_hooks ) {
		foreach ( (array) $jszr_hooks as $jszr_hook => $jszr_events ) {
			if ( 0 !== strpos( (string) $jszr_hook, 'jszr_' ) ) {
				continue;
			}

			foreach ( (array) $jszr_events as $jszr_event ) {
				wp_unschedule_event( 0, $jszr_hook, $jszr_event['args'] ?? array() );
				++$jszr_cleared;
			}

			wp_clear_scheduled_hook( $jszr_hook );
		}
	}
}

Sync_Queue::release_lock();

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dev reset.
$jszr_stuck = (int) $wpdb->query( "UPDATE {$wpdb->prefix}jszr_sync_runs SET state = 'cancelled', finished_at = NOW() WHERE state IN ('pending','running')" );

// 2. Delete the synthetic jobs, in batches so one request can finish the job.
$jszr_deleted = 0;

do {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dev reset.
	$jszr_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value LIKE %s
			WHERE p.post_type = %s
			LIMIT 500",
			'_zoho_recruit_id',
			'SCALE%',
			Post_Type::POST_TYPE
		)
	);

	foreach ( $jszr_ids as $jszr_id ) {
		wp_delete_post( (int) $jszr_id, true );
		++$jszr_deleted;
	}
} while ( ! empty( $jszr_ids ) );

// Also the lifecycle test's fixtures.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dev reset.
$jszr_life = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s",
		Post_Type::POST_TYPE,
		'Lifecycle %'
	)
);

foreach ( $jszr_life as $jszr_id ) {
	wp_delete_post( (int) $jszr_id, true );
	++$jszr_deleted;
}

// 3. Remove the fake credentials the scale test writes.
delete_option( 'jszr_credentials' );
delete_option( 'jszr_tokens' );
delete_option( 'jszr_auth_state' );
delete_option( 'jszr_auth_failures' );
delete_option( 'jszr_sync_state' );

Sync_Queue::clear();
Logger::clear();

// 4. Tidy the empty terms those jobs created.
$jszr_terms = 0;

foreach ( array_keys( Post_Type::taxonomies() ) as $jszr_taxonomy ) {
	$jszr_found = get_terms(
		array(
			'taxonomy'   => $jszr_taxonomy,
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $jszr_found ) ) {
		continue;
	}

	foreach ( $jszr_found as $jszr_term ) {
		if ( 0 === (int) $jszr_term->count ) {
			wp_delete_term( (int) $jszr_term->term_id, $jszr_taxonomy );
			++$jszr_terms;
		}
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dev reset.
$jszr_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Post_Type::POST_TYPE ) );

WP_CLI::log( sprintf( 'Cancelled %d stuck run(s), cleared %d scheduled event(s).', $jszr_stuck, $jszr_cleared ) );
WP_CLI::log( sprintf( 'Deleted %d test job(s) and %d empty term(s).', $jszr_deleted, $jszr_terms ) );
WP_CLI::success( sprintf( '%d job post(s) remain.', $jszr_left ) );
