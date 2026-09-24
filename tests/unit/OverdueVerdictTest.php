<?php
/**
 * Tests for apply_overdue_verdict().
 *
 * @package SPCR_Cron_Health_Check
 */

/**
 * Overdue verdict tests.
 */
final class OverdueVerdictTest extends SPCR_Unit_TestCase {

	/**
	 * Base passed record fixture.
	 *
	 * @return array
	 */
	private function passed_test(): array {
		return array(
			'id'       => 'x',
			'started'  => 1000,
			'fired'    => 1002,
			'duration' => 1.8,
			'source'   => 'loopback',
			'status'   => 'passed',
			'reason'   => null,
		);
	}

	/**
	 * Passed with zero overdue stays passed and gains the count fields.
	 */
	public function test_passed_no_overdue() {
		Brain\Monkey\Functions\when( '_n' )->alias(
			function ( $single ) {
				return $single;
			}
		);

		$test = SPCR_Cron_Health_Check::apply_overdue_verdict( $this->passed_test(), 0, 0, 1800 );

		$this->assertSame( 'passed', $test['status'] );
		$this->assertNull( $test['reason'] );
		$this->assertSame( 0, $test['overdue'] );
		$this->assertSame( 0, $test['overdue_oldest'] );
	}

	/**
	 * Passed with overdue events becomes failed/overdue, duration preserved.
	 */
	public function test_passed_with_overdue_fails() {
		Brain\Monkey\Functions\when( '_n' )->alias(
			function ( $single, $plural, $n ) {
				return 1 === $n ? $single : $plural;
			}
		);

		$test = SPCR_Cron_Health_Check::apply_overdue_verdict( $this->passed_test(), 3, 7200, 1800 );

		$this->assertSame( 'failed', $test['status'] );
		$this->assertSame( 'overdue', $test['reason'] );
		$this->assertSame( 3, $test['overdue'] );
		$this->assertSame( 7200, $test['overdue_oldest'] );
		$this->assertStringContainsString( '3', $test['message'] );
		$this->assertStringContainsString( 'WP-Cron has not been keeping up', $test['message'] );
		$this->assertSame( 1.8, $test['duration'] );
		$this->assertSame( 'loopback', $test['source'] );
	}

	/**
	 * A still-running record keeps its status; overdue data is attached but
	 * the verdict waits for a finished test.
	 */
	public function test_running_with_overdue_stays_running() {
		$running = array(
			'id'      => 'x',
			'started' => 1000,
			'fired'   => null,
			'status'  => 'running',
			'reason'  => null,
		);

		$test = SPCR_Cron_Health_Check::apply_overdue_verdict( $running, 1, 3600, 1800 );

		$this->assertSame( 'running', $test['status'] );
		$this->assertNull( $test['reason'] );
		$this->assertSame( 1, $test['overdue'] );
		$this->assertSame( 3600, $test['overdue_oldest'] );
		$this->assertArrayNotHasKey( 'message', $test );
	}

	/**
	 * A running record with no overdue events stays running.
	 */
	public function test_running_no_overdue_stays_running() {
		$running = array(
			'id'      => 'x',
			'started' => 1000,
			'fired'   => null,
			'status'  => 'running',
			'reason'  => null,
		);

		$test = SPCR_Cron_Health_Check::apply_overdue_verdict( $running, 0, 0, 1800 );

		$this->assertSame( 'running', $test['status'] );
		$this->assertNull( $test['reason'] );
		$this->assertSame( 0, $test['overdue'] );
	}

	/**
	 * An already-failed record keeps its reason and gains the counts.
	 */
	public function test_failed_record_keeps_reason() {
		$failed = array(
			'id'       => null,
			'started'  => 1000,
			'fired'    => null,
			'duration' => null,
			'status'   => 'failed',
			'reason'   => 'disabled',
			'message'  => 'WP-Cron is disabled via DISABLE_WP_CRON.',
		);

		$test = SPCR_Cron_Health_Check::apply_overdue_verdict( $failed, 5, 3600, 1800 );

		$this->assertSame( 'failed', $test['status'] );
		$this->assertSame( 'disabled', $test['reason'] );
		$this->assertSame( 'WP-Cron is disabled via DISABLE_WP_CRON.', $test['message'] );
		$this->assertSame( 5, $test['overdue'] );
		$this->assertSame( 3600, $test['overdue_oldest'] );
	}
}
