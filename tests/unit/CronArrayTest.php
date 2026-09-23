<?php
/**
 * Tests for flatten_cron_array() and partition_overdue().
 *
 * @package Cron_Health_Check
 */

/**
 * Cron array tests.
 */
final class CronArrayTest extends CHC_Unit_TestCase {

	/**
	 * A cron array fixture with two events.
	 *
	 * @return array
	 */
	private function fixture(): array {
		return array(
			1000 => array(
				'hook_a' => array(
					'abc123' => array(
						'schedule' => 'hourly',
						'args'     => array( 1, 2 ),
						'interval' => 3600,
					),
				),
			),
			2000 => array(
				'hook_b' => array(
					'def456' => array(
						'schedule' => false,
						'args'     => array(),
						'interval' => null,
					),
				),
			),
		);
	}

	/**
	 * Flattening produces one row per event with expected fields.
	 */
	public function test_flatten_produces_rows() {
		Brain\Monkey\Functions\when( 'has_action' )->justReturn( true );

		$rows = Cron_Health_Check::flatten_cron_array( $this->fixture(), 3000 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'hook_a', $rows[0]['hook'] );
		$this->assertSame( array( 1, 2 ), $rows[0]['args'] );
		$this->assertSame( 1000, $rows[0]['timestamp'] );
		$this->assertSame( 'hourly', $rows[0]['schedule'] );
		$this->assertSame( 3600, $rows[0]['interval'] );
		$this->assertSame( 2000, $rows[0]['overdue_by'] );
		$this->assertFalse( $rows[0]['orphaned'] );
		$this->assertSame( 1000, $rows[1]['overdue_by'] );
	}

	/**
	 * Rows are sorted by timestamp ascending.
	 */
	public function test_flatten_sorts_by_timestamp() {
		Brain\Monkey\Functions\when( 'has_action' )->justReturn( true );

		$cron = array(
			5000 => array( 'late'  => array( 'x' => array( 'args' => array() ) ) ),
			100  => array( 'early' => array( 'y' => array( 'args' => array() ) ) ),
		);
		$rows = Cron_Health_Check::flatten_cron_array( $cron, 6000 );

		$this->assertSame( 'early', $rows[0]['hook'] );
		$this->assertSame( 'late', $rows[1]['hook'] );
	}

	/**
	 * Orphan flag is set when no action is registered for the hook.
	 */
	public function test_orphan_flag() {
		Brain\Monkey\Functions\when( 'has_action' )->alias(
			function ( $hook ) {
				return 'hook_a' === $hook ? 1 : false;
			}
		);

		$rows = Cron_Health_Check::flatten_cron_array( $this->fixture(), 3000 );

		$this->assertFalse( $rows[0]['orphaned'] );
		$this->assertTrue( $rows[1]['orphaned'] );
	}

	/**
	 * The version meta entry is skipped.
	 */
	public function test_version_key_skipped() {
		Brain\Monkey\Functions\when( 'has_action' )->justReturn( true );

		$cron = array(
			'version' => 2,
			100       => array( 'h' => array( 'k' => array( 'args' => array() ) ) ),
		);
		$rows = Cron_Health_Check::flatten_cron_array( $cron, 1000 );

		$this->assertCount( 1, $rows );
	}

	/**
	 * partition_overdue splits on the grace boundary.
	 */
	public function test_partition_grace_boundary() {
		$now  = 10000;
		$rows = array(
			array( 'hook' => 'old', 'timestamp' => $now - 120 ),
			array( 'hook' => 'edge_ok', 'timestamp' => $now - 60 ),  // Exactly at grace: not overdue.
			array( 'hook' => 'edge_bad', 'timestamp' => $now - 61 ), // One second past grace: overdue.
			array( 'hook' => 'future', 'timestamp' => $now + 500 ),
		);

		$parts = Cron_Health_Check::partition_overdue( $rows, $now, 60 );

		$this->assertCount( 2, $parts['overdue'] );
		$this->assertCount( 2, $parts['upcoming'] );
		$this->assertSame( 'old', $parts['overdue'][0]['hook'] );
		$this->assertSame( 'edge_bad', $parts['overdue'][1]['hook'] );
	}

	/**
	 * Overdue rows are sorted most-overdue first regardless of input order.
	 */
	public function test_overdue_sorted_most_first() {
		$now  = 10000;
		$rows = array(
			array( 'hook' => 'b', 'timestamp' => $now - 500 ),
			array( 'hook' => 'a', 'timestamp' => $now - 5000 ),
		);

		$parts = Cron_Health_Check::partition_overdue( $rows, $now, 60 );

		$this->assertSame( 'a', $parts['overdue'][0]['hook'] );
	}
}
