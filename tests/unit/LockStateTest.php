<?php
/**
 * Tests for lock_state().
 *
 * @package Cron_Health_Check
 */

/**
 * Lock state tests.
 */
final class LockStateTest extends CHC_Unit_TestCase {

	/**
	 * No lock yields state "none".
	 */
	public function test_no_lock() {
		$state = Cron_Health_Check::lock_state( null, 1000.0, 60 );

		$this->assertSame( 'none', $state['state'] );
		$this->assertSame( 0.0, $state['age'] );
	}

	/**
	 * A lock younger than the timeout is fresh.
	 */
	public function test_fresh_lock() {
		$state = Cron_Health_Check::lock_state( 990.0, 1000.0, 60 );

		$this->assertSame( 'fresh', $state['state'] );
		$this->assertSame( 10.0, $state['age'] );
	}

	/**
	 * A lock older than the timeout is stale.
	 */
	public function test_stale_lock() {
		$state = Cron_Health_Check::lock_state( 900.0, 1000.0, 60 );

		$this->assertSame( 'stale', $state['state'] );
		$this->assertSame( 100.0, $state['age'] );
	}

	/**
	 * A lock exactly at the timeout boundary is fresh (not yet stale).
	 */
	public function test_boundary_is_fresh() {
		$state = Cron_Health_Check::lock_state( 940.0, 1000.0, 60 );

		$this->assertSame( 'fresh', $state['state'] );
	}
}
