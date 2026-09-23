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
		wp_clear_scheduled_hook( 'chc_bogus_overdue' );
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
	 * run_test() schedules the event and returns a record with spawn data.
	 */
	public function test_run_test_schedules_event() {
		// The WP test bootstrap defines DISABLE_WP_CRON; the filter is the
		// supported way to override it in tests.
		add_filter( 'chc_cron_disabled', '__return_false' );
		delete_option( Cron_Health_Check::OPTION );
		set_transient( 'doing_cron', microtime( true ) ); // Fresh lock must be cleared too.

		$test = Cron_Health_Check::instance()->run_test();

		remove_filter( 'chc_cron_disabled', '__return_false' );

		$this->assertIsArray( $test );
		$this->assertArrayHasKey( 'spawn', $test );
		$this->assertNotEmpty( $test['id'] );
		$this->assertTrue( $test['lock_cleared'] );
		$this->assertArrayHasKey( 'lock_age', $test );
		$this->assertNotFalse( wp_next_scheduled( Cron_Health_Check::TEST_HOOK, array( $test['id'] ) ) );
		// If the spawned process fired the event before spawn_cron() returned,
		// the merge must have preserved it.
		if ( 'passed' === $test['status'] ) {
			$this->assertNotNull( $test['fired'] );
			$this->assertNotNull( $test['duration'] );
		} else {
			$this->assertSame( 'running', $test['status'] );
		}
	}

	/**
	 * A fired test still fails when an event is overdue by more than 30 minutes.
	 */
	public function test_fired_but_overdue_events_fail_the_test() {
		add_filter( 'chc_cron_disabled', '__return_false' );

		// Schedule a bogus event far past the 30-minute grace before the run
		// snapshots the overdue count.
		wp_schedule_single_event( time() - 2 * HOUR_IN_SECONDS, 'chc_bogus_overdue' );

		$instance = Cron_Health_Check::instance();
		$test     = $instance->run_test();

		// Fire the test event as the spawned process would.
		do_action( Cron_Health_Check::TEST_HOOK, $test['id'] );

		$stored = get_option( Cron_Health_Check::OPTION );
		$this->assertSame( 'passed', $stored['status'] );

		// The verdict is applied when a final record is produced.
		$final = $instance->finalize_test( $stored );

		remove_filter( 'chc_cron_disabled', '__return_false' );
		wp_clear_scheduled_hook( 'chc_bogus_overdue' );

		$this->assertSame( 'failed', $final['status'] );
		$this->assertSame( 'overdue', $final['reason'] );
		$this->assertSame( 1, $final['overdue'] );
		$this->assertGreaterThan( 2 * HOUR_IN_SECONDS - 1, $final['overdue_oldest'] );
	}

	/**
	 * A fired test with no overdue events passes with overdue === 0.
	 */
	public function test_fired_no_overdue_passes() {
		add_filter( 'chc_cron_disabled', '__return_false' );

		$instance = Cron_Health_Check::instance();
		$test     = $instance->run_test();

		do_action( Cron_Health_Check::TEST_HOOK, $test['id'] );
		$stored = get_option( Cron_Health_Check::OPTION );
		$final  = $instance->finalize_test( $stored );

		remove_filter( 'chc_cron_disabled', '__return_false' );

		$expected = count(
			Cron_Health_Check::partition_overdue(
				Cron_Health_Check::get_event_rows(),
				time(),
				Cron_Health_Check::OVERDUE_GRACE
			)['overdue']
		);

		$this->assertSame( $expected, $final['overdue'] );
		if ( 0 === $expected ) {
			$this->assertSame( 'passed', $final['status'] );
			$this->assertSame( 0, $final['overdue'] );
		} else {
			$this->assertSame( 'overdue', $final['reason'] );
		}
	}

	/**
	 * render_page() deletes the stored test so results never persist across loads.
	 */
	public function test_render_page_deletes_option() {
		update_option(
			Cron_Health_Check::OPTION,
			array(
				'id'      => 'x',
				'started' => time(),
				'status'  => 'passed',
			),
			false
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		Cron_Health_Check::instance()->render_page();
		ob_end_clean();

		$this->assertFalse( get_option( Cron_Health_Check::OPTION, false ) );
		wp_set_current_user( 0 );
	}

	/**
	 * The post-spawn merge must not clobber a `fired` value written to the DB
	 * by the spawned wp-cron.php process while this process held a stale
	 * cached copy of the option.
	 */
	public function test_finalize_preserves_fired_written_by_other_process() {
		global $wpdb;

		$instance = Cron_Health_Check::instance();
		$running  = array(
			'id'      => 'x',
			'started' => time(),
			'fired'   => null,
			'status'  => 'running',
		);
		update_option( Cron_Health_Check::OPTION, $running, false );
		get_option( Cron_Health_Check::OPTION ); // Prime the runtime cache.

		// Simulate the spawned process writing the result directly to the DB,
		// leaving this process's cached copy stale (fired=null).
		$fired = array_merge(
			$running,
			array(
				'fired'    => time(),
				'status'   => 'passed',
				'duration' => 1.2,
				'source'   => 'loopback',
			)
		);
		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => maybe_serialize( $fired ) ),
			array( 'option_name' => Cron_Health_Check::OPTION )
		);

		// Stash a spawn payload exactly as capture_spawn() does.
		$instance->capture_spawn(
			array( 'response' => array( 'code' => 200 ) ),
			'',
			'',
			array(),
			home_url( '/wp-cron.php' )
		);

		$merged = $instance->finalize_test( $running );

		$this->assertSame( 'passed', $merged['status'] );
		$this->assertSame( $fired['fired'], $merged['fired'] );
		$this->assertSame( 200, $merged['spawn']['code'] );

		$stored = get_option( Cron_Health_Check::OPTION );
		$this->assertSame( 'passed', $stored['status'] );
		$this->assertSame( 200, $stored['spawn']['code'] );
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
		$this->assertSame( 'timeout_no_request', $summary['reason'] );
	}
}
