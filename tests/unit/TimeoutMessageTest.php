<?php
/**
 * Tests for timeout_reason() and timeout_message().
 *
 * @package CRHC_Cron_Health_Check
 */

/**
 * Timeout message tests.
 */
final class TimeoutMessageTest extends CRHC_Unit_TestCase {

	/**
	 * A loopback WP_Error yields the loopback failure message and reason.
	 */
	public function test_loopback_error() {
		$spawn = array(
			'code'  => null,
			'error' => 'cURL error 7: Failed to connect',
			'url'   => 'http://localhost:8888/wp-cron.php',
		);

		$this->assertSame( 'timeout_loopback_error', CRHC_Cron_Health_Check::timeout_reason( $spawn ) );
		$message = CRHC_Cron_Health_Check::timeout_message( $spawn, 30 );
		$this->assertStringContainsString( 'cURL error 7', $message );
		$this->assertStringContainsString( 'loopback', $message );
	}

	/**
	 * An HTTP error response yields the HTTP failure message and reason.
	 */
	public function test_http_error() {
		$spawn = array(
			'code'  => 500,
			'error' => null,
			'url'   => 'https://example.com/wp-cron.php',
		);

		$this->assertSame( 'timeout_http_error', CRHC_Cron_Health_Check::timeout_reason( $spawn ) );
		$this->assertStringContainsString( 'HTTP 500', CRHC_Cron_Health_Check::timeout_message( $spawn, 30 ) );
	}

	/**
	 * A good response that never fired yields the slow/interference message.
	 */
	public function test_no_fire() {
		$spawn = array(
			'code'  => 200,
			'error' => null,
			'url'   => 'https://example.com/wp-cron.php',
		);

		$this->assertSame( 'timeout_no_fire', CRHC_Cron_Health_Check::timeout_reason( $spawn ) );
		$message = CRHC_Cron_Health_Check::timeout_message( $spawn, 30 );
		$this->assertStringContainsString( 'HTTP 200', $message );
		$this->assertStringContainsString( '30s', $message );
	}

	/**
	 * No spawn record at all means spawn_cron() never made a request.
	 */
	public function test_no_request() {
		$this->assertSame( 'timeout_no_request', CRHC_Cron_Health_Check::timeout_reason( null ) );
		$this->assertStringContainsString(
			'did not make a request',
			CRHC_Cron_Health_Check::timeout_message( null, 30 )
		);
	}

	/**
	 * Timeout reasons keep the "timeout" prefix for prefix checks.
	 */
	public function test_reason_prefix() {
		foreach ( array( null, array( 'error' => 'x' ), array( 'code' => 500 ), array( 'code' => 200 ) ) as $spawn ) {
			$this->assertSame( 0, strpos( CRHC_Cron_Health_Check::timeout_reason( $spawn ), 'timeout' ) );
		}
	}
}
