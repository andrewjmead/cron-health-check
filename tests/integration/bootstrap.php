<?php
/**
 * Integration test bootstrap: loads the WordPress test suite via wp-env.
 *
 * @package CRHC_Cron_Health_Check
 */

// wp-env exposes the tests directory inside the container.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found (WP_TESTS_DIR).\n" );
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__, 2 ) . '/src/spawn-cron-health-check.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
