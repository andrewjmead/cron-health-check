<?php
/**
 * Tests for summarize_test().
 *
 * @package SPCR_Cron_Health_Check
 */

/**
 * Test summary tests.
 */
final class TestSummaryTest extends SPCR_Unit_TestCase {

	/**
	 * Null test yields the "none" status.
	 */
	public function test_null_test() {
		$summary = SPCR_Cron_Health_Check::summarize_test( null, 1000, 30 );

		$this->assertSame( 'none', $summary['status'] );
		$this->assertNull( $summary['reason'] );
		$this->assertNull( $summary['duration'] );
	}

	/**
	 * A running test inside the timeout stays running.
	 */
	public function test_running() {
		$test = array(
			'id'      => 'x',
			'started' => 1000,
			'fired'   => null,
			'status'  => 'running',
		);

		$summary = SPCR_Cron_Health_Check::summarize_test( $test, 1010, 30 );

		$this->assertSame( 'running', $summary['status'] );
	}

	/**
	 * A passed test reports its duration.
	 */
	public function test_passed() {
		$test = array(
			'id'       => 'x',
			'started'  => 1000,
			'fired'    => 1002,
			'duration' => 2,
			'status'   => 'passed',
			'source'   => 'loopback',
		);

		$summary = SPCR_Cron_Health_Check::summarize_test( $test, 1010, 30 );

		$this->assertSame( 'passed', $summary['status'] );
		$this->assertSame( 2.0, $summary['duration'] );
		$this->assertStringContainsString( 'loopback', $summary['message'] );
	}

	/**
	 * A passed test with a sub-second duration formats it in the message.
	 */
	public function test_passed_subsecond_duration() {
		$test = array(
			'id'       => 'x',
			'started'  => 1000,
			'fired'    => 1000,
			'duration' => 0.7,
			'status'   => 'passed',
			'source'   => 'loopback',
		);

		$summary = SPCR_Cron_Health_Check::summarize_test( $test, 1010, 30 );

		$this->assertSame( 'passed', $summary['status'] );
		$this->assertSame( 0.7, $summary['duration'] );
		$this->assertStringContainsString( '0.7', $summary['message'] );
	}

	/**
	 * A running test past the timeout is reported as failed/timeout.
	 */
	public function test_timeout() {
		$test = array(
			'id'      => 'x',
			'started' => 1000,
			'fired'   => null,
			'status'  => 'running',
		);

		$summary = SPCR_Cron_Health_Check::summarize_test( $test, 1000 + 31, 30 );

		$this->assertSame( 'failed', $summary['status'] );
		$this->assertSame( 'timeout_no_request', $summary['reason'] );
		$this->assertSame( 0, strpos( $summary['reason'], 'timeout' ) );
	}

	/**
	 * A stored disabled failure keeps its reason and message.
	 */
	public function test_disabled() {
		$test = array(
			'id'      => null,
			'started' => 1000,
			'fired'   => null,
			'status'  => 'failed',
			'reason'  => 'disabled',
			'message' => 'WP-Cron is disabled via DISABLE_WP_CRON.',
		);

		$summary = SPCR_Cron_Health_Check::summarize_test( $test, 5000, 30 );

		$this->assertSame( 'failed', $summary['status'] );
		$this->assertSame( 'disabled', $summary['reason'] );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', $summary['message'] );
	}
}
