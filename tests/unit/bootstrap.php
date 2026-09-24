<?php
/**
 * Unit test bootstrap: Brain Monkey, no WordPress required.
 *
 * @package SPCR_Cron_Health_Check
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/wordpress/' );

Brain\Monkey\setUp();

// Stub functions the plugin file calls at load time.
Brain\Monkey\Functions\when( 'register_activation_hook' )->justReturn();
Brain\Monkey\Functions\when( 'register_deactivation_hook' )->justReturn();
Brain\Monkey\Functions\when( 'register_uninstall_hook' )->justReturn();
Brain\Monkey\Functions\when( 'plugin_basename' )->returnArg();

require_once dirname( __DIR__, 2 ) . '/src/spawn-cron-health-check.php';

/**
 * Base test case wiring Brain Monkey into the PHPUnit lifecycle.
 */
abstract class SPCR_Unit_TestCase extends Yoast\PHPUnitPolyfills\TestCases\TestCase {

	/**
	 * Set up Brain Monkey.
	 */
	protected function set_up() {
		parent::set_up();
		Brain\Monkey\setUp();

		Brain\Monkey\Functions\when( '__' )->alias(
			function ( $text ) {
				return $text;
			}
		);
		Brain\Monkey\Functions\when( 'number_format_i18n' )->alias(
			function ( $number, $decimals = 0 ) {
				return number_format( (float) $number, (int) $decimals );
			}
		);
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tear_down() {
		Brain\Monkey\tearDown();
		parent::tear_down();
	}
}
