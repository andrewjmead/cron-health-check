<?php
/**
 * Plugin Name:       Cron Health Check
 * Plugin URI:        https://github.com/andrewjmead/cron-health-check
 * Description:       Manually test whether WP-Cron fires on this site and inspect overdue scheduled events.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Andrew Mead
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cron-health-check
 *
 * @package Cron_Health_Check
 */

defined( 'ABSPATH' ) || exit;

define( 'CHC_VERSION', '1.0.0' );
define( 'CHC_TEST_TIMEOUT', 30 );

/**
 * Cron Health Check plugin.
 */
final class Cron_Health_Check {

	/**
	 * Singleton instance.
	 *
	 * @var Cron_Health_Check|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Cron_Health_Check
	 */
	public static function instance(): Cron_Health_Check {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
	}
}
