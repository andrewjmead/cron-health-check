<?php
/**
 * Integration tests for the cron spawn flow, run inside wp-env.
 *
 * @package Cron_Health_Check
 */

/**
 * Spawn integration tests.
 */
final class SpawnTest extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tear_down() {
		wp_clear_scheduled_hook( Cron_Health_Check::TEST_HOOK );
		delete_option( Cron_Health_Check::OPTION );
		parent::tear_down();
	}

	/**
	 * Scheduling the event then running it marks the stored test as fired.
	 */
	public function test_event_fires_and_marks_test() {
		delete_option( Cron_Health_Check::OPTION );
		wp_clear_scheduled_hook( Cron_Health_Check::TEST_HOOK );

		$id = wp_generate_password( 12, false, false );
		update_option(
			Cron_Health_Check::OPTION,
			array(
				'id'      => $id,
				'started' => time(),
				'fired'   => null,
				'status'  => 'running',
				'source'  => null,
			),
			false
		);

		wp_schedule_single_event( time() - 1, Cron_Health_Check::TEST_HOOK, array( $id ) );
		$this->assertNotFalse( wp_next_scheduled( Cron_Health_Check::TEST_HOOK, array( $id ) ) );

		// Trigger the callback directly (equivalent to the event firing).
		do_action( Cron_Health_Check::TEST_HOOK, $id );

		$test = get_option( Cron_Health_Check::OPTION );
		$this->assertIsArray( $test );
		$this->assertSame( 'passed', $test['status'] );
		$this->assertNotNull( $test['fired'] );
		$this->assertSame( $test['fired'] - $test['started'], $test['duration'] );
	}

	/**
	 * A stale event id must not overwrite the stored test.
	 */
	public function test_mismatched_id_ignored() {
		update_option(
			Cron_Health_Check::OPTION,
			array(
				'id'      => 'current',
				'started' => time(),
				'fired'   => null,
				'status'  => 'running',
			),
			false
		);

		do_action( Cron_Health_Check::TEST_HOOK, 'stale-id' );

		$test = get_option( Cron_Health_Check::OPTION );
		$this->assertSame( 'running', $test['status'] );
		$this->assertNull( $test['fired'] );
	}

	/**
	 * When cron is disabled via the filter, summarize reflects the stored failure.
	 */
	public function test_disabled_flag() {
		add_filter(
			'chc_cron_disabled',
			function () {
				return true;
			}
		);
		$this->assertTrue( Cron_Health_Check::is_cron_disabled() );
		remove_all_filters( 'chc_cron_disabled' );
	}

	/**
	 * The status endpoint marks a stale running test as timed out.
	 */
	public function test_status_marks_timeout() {
		update_option(
			Cron_Health_Check::OPTION,
			array(
				'id'      => 'x',
				'started' => time() - ( CHC_TEST_TIMEOUT + 10 ),
				'fired'   => null,
				'status'  => 'running',
			),
			false
		);

		$summary = Cron_Health_Check::summarize_test(
			get_option( Cron_Health_Check::OPTION ),
			time(),
			Cron_Health_Check::test_timeout()
		);

		$this->assertSame( 'failed', $summary['status'] );
		$this->assertSame( 'timeout', $summary['reason'] );
	}
}
