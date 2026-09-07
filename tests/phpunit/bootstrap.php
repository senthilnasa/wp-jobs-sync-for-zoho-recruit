<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * @package JobsSyncForZohoRecruit
 */

$jszr_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $jszr_tests_dir ) {
	$jszr_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $jszr_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI bootstrap, no HTML context.
	echo "Could not find the WordPress test suite in {$jszr_tests_dir}." . PHP_EOL;
	echo 'Run: bash bin/install-wp-tests.sh wordpress_test root root localhost latest' . PHP_EOL;
	exit( 1 );
}

require_once $jszr_tests_dir . '/includes/functions.php';

/**
 * Load the plugin before WordPress finishes booting.
 *
 * @return void
 */
function jszr_manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/jobs-sync-for-zoho-recruit.php';
}

tests_add_filter( 'muplugins_loaded', 'jszr_manually_load_plugin' );

require $jszr_tests_dir . '/includes/bootstrap.php';
