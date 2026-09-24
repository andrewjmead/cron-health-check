<?php
/**
 * Plugin Name:       Cron Health Check
 * Plugin URI:        https://github.com/andrewjmead/cron-health-check
 * Description:       Check that WP-Cron is working correctly on your WordPress website
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Andrew Mead
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cron-health-check
 *
 * @package CRHC_Cron_Health_Check
 */

defined( 'ABSPATH' ) || exit;

define( 'CRHC_VERSION', '1.0.1' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- mandated short prefix.
define( 'CRHC_TEST_TIMEOUT', 30 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- mandated short prefix.
define( 'CRHC_TEST_TIMEOUT_ALTERNATE', 60 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- mandated short prefix.

/**
 * Cron Health Check plugin.
 */
final class CRHC_Cron_Health_Check {

	/**
	 * Option that stores the last test result.
	 *
	 * @var string
	 */
	const OPTION = 'crhc_test';

	/**
	 * Cron hook used for the test event.
	 *
	 * @var string
	 */
	const TEST_HOOK = 'crhc_test_event';

	/**
	 * How overdue an event must be (seconds) before it counts as overdue.
	 *
	 * @var int
	 */
	const OVERDUE_GRACE = 1800;

	/**
	 * Transient that triggers the post-activation redirect.
	 *
	 * @var string
	 */
	const REDIRECT_TRANSIENT = 'crhc_activation_redirect';

	/**
	 * Singleton instance.
	 *
	 * @var CRHC_Cron_Health_Check|null
	 */
	private static $instance = null;

	/**
	 * Spawn result captured during run_test(), merged into the option afterwards.
	 *
	 * @var array|null
	 */
	private $spawn = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return CRHC_Cron_Health_Check
	 */
	public static function instance(): CRHC_Cron_Health_Check {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'in_admin_header', array( $this, 'remove_admin_notices' ), 1000 );
		add_action( 'admin_init', array( $this, 'maybe_activation_redirect' ) );
		add_action( 'wp_ajax_crhc_start_test', array( $this, 'ajax_start_test' ) );
		add_action( 'wp_ajax_crhc_test_status', array( $this, 'ajax_test_status' ) );
		add_action( 'wp_ajax_crhc_clear_lock', array( $this, 'ajax_clear_lock' ) );
		add_action( self::TEST_HOOK, array( $this, 'on_test_event' ) );
		add_filter( 'site_status_tests', array( $this, 'site_status_tests' ) );
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Add a "Dashboard" link to the plugin row on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function plugin_action_links( array $links ): array {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::page_url() ),
			esc_html__( 'Dashboard', 'cron-health-check' )
		);
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * The raw DISABLE_WP_CRON constant state, for status wording.
	 *
	 * @return string 'undefined'|'true'|'false'
	 */
	private static function disable_wp_cron_state(): string {
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			return 'undefined';
		}
		return DISABLE_WP_CRON ? 'true' : 'false';
	}

	/**
	 * Whether DISABLE_WP_CRON is on. Filterable for tests.
	 *
	 * @return bool
	 */
	public static function is_cron_disabled(): bool {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		/**
		 * Filters whether cron is considered disabled.
		 *
		 * @param bool $disabled Value derived from DISABLE_WP_CRON.
		 */
		return (bool) apply_filters( 'crhc_cron_disabled', $disabled ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mandated short prefix.
	}

	/**
	 * Whether ALTERNATE_WP_CRON is on.
	 *
	 * @return bool
	 */
	public static function is_alternate_cron(): bool {
		return defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;
	}

	/**
	 * Cron lock timeout in seconds.
	 *
	 * @return int
	 */
	public static function lock_timeout(): int {
		return defined( 'WP_CRON_LOCK_TIMEOUT' ) ? (int) WP_CRON_LOCK_TIMEOUT : 60;
	}

	/**
	 * How long the test waits for the event to fire.
	 *
	 * @return int
	 */
	public static function test_timeout(): int {
		return self::is_alternate_cron() ? CRHC_TEST_TIMEOUT_ALTERNATE : CRHC_TEST_TIMEOUT;
	}

	// ---------------------------------------------------------------------
	// Admin page.
	// ---------------------------------------------------------------------

	/**
	 * Register the Tools submenu page.
	 */
	public function admin_menu() {
		add_management_page(
			__( 'Cron Health Check', 'cron-health-check' ),
			__( 'Cron Health Check', 'cron-health-check' ),
			'manage_options',
			'cron-health-check',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Strip every admin notice hook on our screen so nothing renders inside the card.
	 */
	public function remove_admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'tools_page_cron-health-check' !== $screen->id ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Admin page URL.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return admin_url( 'tools.php?page=cron-health-check' );
	}

	/**
	 * Enqueue assets on our screen only.
	 *
	 * @param string $hook Current admin screen hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_cron-health-check' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'cron-health-check',
			plugins_url( 'cron-health-check.css', __FILE__ ),
			array(),
			CRHC_VERSION
		);
		wp_enqueue_script(
			'cron-health-check',
			plugins_url( 'cron-health-check.js', __FILE__ ),
			array(),
			CRHC_VERSION,
			true
		);
		wp_localize_script(
			'cron-health-check',
			'crhcData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'crhc_nonce' ),
				'timeout'   => self::test_timeout(),
				'altCron'   => self::is_alternate_cron(),
				'homeUrl'   => home_url( '/' ),
				'wpVersion' => get_bloginfo( 'version' ),
				'version'   => CRHC_VERSION,
				'i18n'      => array(
					'failed'            => __( 'Failed', 'cron-health-check' ),
					'passedTitle'       => self::result_title( 'passed' ),
					'failedTitle'       => self::result_title( 'failed' ),
					'requestFailed'     => __( 'Request failed. Check your connection and try again.', 'cron-health-check' ),
					'couldNotStart'     => __( 'Failed to schedule a test cron event.', 'cron-health-check' ),
					'timeout'           => __( 'The event was scheduled but never ran.', 'cron-health-check' ),
					/* translators: 1: duration in seconds, 2: trigger source. */
					'firedIn'           => __( 'Cron fired in %1$ss via %2$s.', 'cron-health-check' ),
					/* translators: %s: duration in seconds. */
					'firedInNoSource'   => __( 'Cron fired in %ss.', 'cron-health-check' ),
					'neverRan'          => __( 'The event was scheduled but never ran.', 'cron-health-check' ),
					'viewReport'        => __( 'View report', 'cron-health-check' ),
					'hideReport'        => __( 'Hide report', 'cron-health-check' ),
					'copyReport'        => __( 'Copy report', 'cron-health-check' ),
					'copied'            => __( 'Copied', 'cron-health-check' ),
					'copyFailed'        => __( 'Copy failed', 'cron-health-check' ),
					'statusPassed'      => __( 'Passed', 'cron-health-check' ),
					'statusFailed'      => __( 'Failed', 'cron-health-check' ),
					'statusSkipped'     => __( 'Not run', 'cron-health-check' ),
					/* translators: 1: site URL, 2: WordPress version, 3: plugin version. */
					'reportHeader'      => __( 'Cron Health Check report for %1$s (WordPress %2$s, plugin %3$s)', 'cron-health-check' ),
					'disabledUndefined' => __( 'The option DISABLE_WP_CRON is not defined. WP-Cron is enabled.', 'cron-health-check' ),
					'disabledFalse'     => __( 'The option DISABLE_WP_CRON is set to false. WP-Cron is enabled.', 'cron-health-check' ),
					'disabledTrue'      => __( 'The option DISABLE_WP_CRON is set to true. WP-Cron is disabled and WordPress will not be able to run cron events.', 'cron-health-check' ),
					'overdueNone'       => __( 'No overdue cron events were found.', 'cron-health-check' ),
					/* translators: %s: overdue event count. */
					'overdueSome'       => __( 'There are %s cron event(s) that are more than 30 minutes overdue. WP-Cron is not working.', 'cron-health-check' ),
					'scheduledOk'       => __( 'Scheduled a test cron event.', 'cron-health-check' ),
					'scheduleFailed'    => __( 'Failed to schedule a test cron event.', 'cron-health-check' ),
					/* translators: %s: HTTP status code. */
					'spawnOk'           => __( 'spawn_cron() sent the loopback request to wp-cron.php (HTTP %s).', 'cron-health-check' ),
					'spawnSent'         => __( 'spawn_cron() sent the loopback request to wp-cron.php.', 'cron-health-check' ),
					'spawnAlternate'    => __( 'ALTERNATE_WP_CRON is enabled; cron is triggered by a page redirect instead.', 'cron-health-check' ),
					/* translators: %s: HTTP status code. */
					'spawnHttpError'    => __( 'wp-cron.php responded with HTTP %s.', 'cron-health-check' ),
					/* translators: %s: timeout in seconds. */
					'waitingUpTo'       => __( 'Waiting up to %ss for the event to fire…', 'cron-health-check' ),
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'cron-health-check' ) );
		}

		// The result only lives for the duration of a run; nothing persists across page loads.
		delete_option( self::OPTION );

		$now     = time();
		$summary = self::summarize_test( null, $now, self::test_timeout() );
		$lock    = self::lock_state( self::get_lock(), microtime( true ), self::lock_timeout() );
		?>
		<div class="wrap crhc-wrap">
			<section class="crhc-card crhc-test-panel">
				<header class="crhc-header">
					<h1><?php esc_html_e( 'Cron Health Check', 'cron-health-check' ); ?></h1>
					<p class="crhc-subtitle"><?php esc_html_e( 'Check that WP-Cron is working correctly on your WordPress website', 'cron-health-check' ); ?></p>
				</header>

				<button type="button" id="crhc-run-test" class="crhc-button">
					<?php esc_html_e( 'Run Health Check', 'cron-health-check' ); ?>
				</button>

				<ol class="crhc-stepper" id="crhc-stepper">
					<?php
					$steps = array(
						'enabled'   => array( __( 'Check that WP-Cron is enabled', 'cron-health-check' ), '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>' ),
						'overdue'   => array( __( 'Check for overdue cron events', 'cron-health-check' ), '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>' ),
						'scheduled' => array( __( 'Schedule a test cron event', 'cron-health-check' ), '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>' ),
						'spawning'  => array( __( 'Attempt to run the test cron event', 'cron-health-check' ), '<path d="M6 4.5v15a1 1 0 0 0 1.5.86l12-7.5a1 1 0 0 0 0-1.72l-12-7.5A1 1 0 0 0 6 4.5z"/>' ),
						'waiting'   => array( __( 'Confirm the test cron event fired', 'cron-health-check' ), '<path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/>' ),
					);
					$n     = 0;
					foreach ( $steps as $key => $step ) :
						++$n;
						?>
					<li class="crhc-step" data-step="<?php echo esc_attr( $key ); ?>">
						<span class="crhc-dot"><svg class="crhc-step-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?php echo $step[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG path markup. ?></svg></span>
						<span class="crhc-step-body">
							<span class="crhc-step-num">
							<?php
							/* translators: %d: step number. */
							echo esc_html( sprintf( __( 'Step %d', 'cron-health-check' ), $n ) );
							?>
							</span>
							<span class="crhc-step-label"><?php echo esc_html( $step[0] ); ?></span>
							<span class="crhc-step-status" data-status></span>
						</span>
					</li>
					<?php endforeach; ?>
				</ol>

				<div id="crhc-result" class="crhc-result" aria-live="polite"><?php $this->render_result( $summary ); ?></div>

				<?php if ( 'stale' === $lock['state'] ) : ?>
					<p class="crhc-lock-action">
						<button type="button" id="crhc-clear-lock" class="crhc-button crhc-button-secondary">
							<?php esc_html_e( 'Clear cron lock and retry', 'cron-health-check' ); ?>
						</button>
					</p>
				<?php endif; ?>
			</section>

		</div>
		<?php
	}

	/**
	 * Render the result card contents (also used as the page-load default).
	 *
	 * @param array $summary Result of summarize_test().
	 */
	private function render_result( array $summary ) {
		if ( ! in_array( $summary['status'], array( 'passed', 'failed' ), true ) ) {
			return;
		}
		$passed = 'passed' === $summary['status'];
		?>
		<div class="crhc-result-card crhc-status-<?php echo esc_attr( $summary['status'] ); ?>">
			<span class="crhc-result-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
			<?php if ( $passed ) : ?>
				<path class="crhc-result-mark" d="m4 12 5 5L20 6"/>
			<?php else : ?>
				<path class="crhc-result-mark" d="M18 6 6 18"/><path class="crhc-result-mark" d="m6 6 12 12"/>
			<?php endif; ?>
			</svg></span>
			<strong class="crhc-result-status"><?php echo esc_html( self::result_title( $summary['status'] ) ); ?></strong>
			<?php if ( ! $passed ) : ?>
				<p class="crhc-result-message"><?php echo esc_html( $summary['message'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Heading for the result card.
	 *
	 * @param string $status 'passed' or 'failed'.
	 */
	public static function result_title( string $status ): string {
		return 'passed' === $status
			? __( 'Cron Health Check Passed', 'cron-health-check' )
			: __( 'Cron Health Check Failed', 'cron-health-check' );
	}

	// ---------------------------------------------------------------------
	// Events data.
	// ---------------------------------------------------------------------

	/**
	 * Flattened event rows from _get_cron_array(), excluding our own hook.
	 *
	 * @return array
	 */
	public static function get_event_rows(): array {
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : get_option( 'cron', array() );
		$rows = self::flatten_cron_array( is_array( $cron ) ? $cron : array(), time() );
		return array_values(
			array_filter(
				$rows,
				function ( $row ) {
					return self::TEST_HOOK !== $row['hook'];
				}
			)
		);
	}

	/**
	 * Raw doing_cron lock value (microtime float) or null.
	 *
	 * @return float|null
	 */
	public static function get_lock() {
		$lock = get_transient( 'doing_cron' );
		if ( false === $lock || null === $lock ) {
			return null;
		}
		return (float) $lock;
	}

	// ---------------------------------------------------------------------
	// AJAX handlers.
	// ---------------------------------------------------------------------

	/**
	 * Verify the AJAX nonce and capability, or die.
	 */
	private function verify_ajax() {
		check_ajax_referer( 'crhc_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cron-health-check' ) ), 403 );
		}
	}

	/**
	 * Start a test run.
	 */
	public function ajax_start_test() {
		$this->verify_ajax();
		wp_send_json_success( $this->run_test() );
	}

	/**
	 * Schedule the test event, spawn cron, and merge the spawn result.
	 *
	 * The spawn is blocking, so the event may already have fired (and been
	 * written to the DB by another PHP process) by the time spawn_cron()
	 * returns — the merge step re-reads the option fresh to preserve that.
	 *
	 * @return array The test record.
	 */
	public function run_test(): array {
		$this->spawn = null;

		if ( self::is_cron_disabled() ) {
			$test = array(
				'id'            => null,
				'started'       => time(),
				'fired'         => null,
				'duration'      => null,
				'source'        => null,
				'status'        => 'failed',
				'reason'        => 'disabled',
				'disable_const' => self::disable_wp_cron_state(),
				'spawn'         => null,
				'lock_cleared'  => false,
				'lock_age'      => 0,
				'message'       => __( 'WP-Cron is disabled via DISABLE_WP_CRON. WordPress will never trigger scheduled events itself; a system cron must call wp-cron.php.', 'cron-health-check' ),
			);
			$test = $this->with_overdue( $test );
			update_option( self::OPTION, $test, false );
			return $test;
		}

		wp_clear_scheduled_hook( self::TEST_HOOK );

		$lock_cleared = false;
		$lock_age     = 0;
		$lock         = self::get_lock();
		if ( null !== $lock ) {
			$lock_age = (int) round( microtime( true ) - $lock );
			delete_transient( 'doing_cron' );
			$lock_cleared = true;
		}

		$id   = wp_generate_password( 12, false, false );
		$test = array(
			'id'            => $id,
			'started'       => time(),
			'started_micro' => microtime( true ),
			'fired'         => null,
			'duration'      => null,
			'source'        => null,
			'status'        => 'running',
			'reason'        => null,
			'disable_const' => self::disable_wp_cron_state(),
			'spawn'         => null,
			'lock_cleared'  => $lock_cleared,
			'lock_age'      => $lock_age,
		);
		$test = $this->with_overdue( $test );
		update_option( self::OPTION, $test, false );

		// Overdue events fail the check before anything is scheduled or spawned.
		if ( 'failed' === $test['status'] ) {
			return $test;
		}

		$scheduled = wp_schedule_single_event( time() - 1, self::TEST_HOOK, array( $id ), true );
		if ( true !== $scheduled ) {
			$test['status']  = 'failed';
			$test['reason']  = 'schedule';
			$test['message'] = '' !== $scheduled->get_error_message()
				? sprintf(
					/* translators: %s: error message. */
					__( 'WordPress refused to schedule the test event: %s', 'cron-health-check' ),
					$scheduled->get_error_message()
				)
				: __( 'WordPress refused to schedule the test event. Another plugin may be blocking cron scheduling (pre_schedule_event / schedule_event filters).', 'cron-health-check' );
			update_option( self::OPTION, $test, false );
			return $test;
		}

		if ( self::is_alternate_cron() ) {
			$this->spawn = array( 'alternate' => true );
		} else {
			add_filter( 'cron_request', array( $this, 'filter_cron_request' ) );
			add_action( 'http_api_debug', array( $this, 'capture_spawn' ), 10, 5 );
			spawn_cron();
			remove_filter( 'cron_request', array( $this, 'filter_cron_request' ) );
			remove_action( 'http_api_debug', array( $this, 'capture_spawn' ), 10 );
		}

		return $this->finalize_test( $test );
	}

	/**
	 * Merge the captured spawn result into the test record.
	 *
	 * Re-reads the option with a fresh cache first so a `fired` value written
	 * by the spawned wp-cron.php process is not overwritten.
	 *
	 * @param array $test The test record written before the spawn.
	 * @return array The merged test record.
	 */
	public function finalize_test( array $test ): array {
		wp_cache_delete( self::OPTION, 'options' );
		$fresh = get_option( self::OPTION, null );
		if ( is_array( $fresh ) ) {
			$test = $fresh;
		}
		$test['spawn'] = $this->spawn;

		// A loopback failure that never fired the event fails immediately;
		// an event that fired first still passes.
		if (
			null === ( $test['fired'] ?? null )
			&& is_array( $this->spawn )
			&& ( ! empty( $this->spawn['error'] ) || ( ! empty( $this->spawn['code'] ) && (int) $this->spawn['code'] >= 400 ) )
		) {
			$test['status']  = 'failed';
			$test['reason']  = 'spawn';
			$test['message'] = self::timeout_message( $this->spawn, self::test_timeout() );
		}

		$test = $this->with_overdue( $test );
		update_option( self::OPTION, $test, false );
		return $test;
	}

	/**
	 * Fold the overdue counts into the test record and apply the verdict.
	 *
	 * The counts are snapshotted once, before the test event is scheduled and
	 * spawned, so the spawn itself cannot change what is reported.
	 *
	 * @param array $test Test record.
	 * @return array
	 */
	private function with_overdue( array $test ): array {
		if ( ! isset( $test['overdue'], $test['overdue_oldest'] ) ) {
			$rows                   = self::get_event_rows();
			$parts                  = self::partition_overdue( $rows, time(), self::OVERDUE_GRACE );
			$test['overdue']        = count( $parts['overdue'] );
			$test['overdue_oldest'] = empty( $parts['overdue'] ) ? 0 : time() - (int) $parts['overdue'][0]['timestamp'];
		}
		return self::apply_overdue_verdict( $test, (int) $test['overdue'], (int) $test['overdue_oldest'], self::OVERDUE_GRACE );
	}

	/**
	 * Force the spawn request to be blocking so we can capture a status code.
	 *
	 * @param array $request Cron request args.
	 * @return array
	 */
	public function filter_cron_request( $request ) {
		$request['args']['blocking'] = true;
		$request['args']['timeout']  = 5;
		return $request;
	}

	/**
	 * Capture the wp-cron.php loopback response from spawn_cron().
	 *
	 * @param mixed  $response    HTTP response or WP_Error.
	 * @param string $context     Transport context.
	 * @param string $transport_class Transport class.
	 * @param array  $parsed_args     Request args.
	 * @param string $url             Request URL.
	 */
	public function capture_spawn( $response, $context, $transport_class, $parsed_args, $url ) {
		if ( ! is_string( $url ) || false === strpos( $url, 'wp-cron.php' ) ) {
			return;
		}
		$code  = is_wp_error( $response ) ? null : wp_remote_retrieve_response_code( $response );
		$error = is_wp_error( $response ) ? $response->get_error_message() : null;

		$this->spawn = array(
			'code'  => $code ? $code : null,
			'error' => $error,
			'url'   => $url,
		);
	}

	/**
	 * Poll the current test status.
	 */
	public function ajax_test_status() {
		$this->verify_ajax();

		$test = get_option( self::OPTION, null );
		if ( ! is_array( $test ) ) {
			wp_send_json_success( array( 'status' => 'none' ) );
		}

		if (
			'running' === ( $test['status'] ?? '' )
			&& null === ( $test['fired'] ?? null )
			&& ( time() - (int) $test['started'] ) > self::test_timeout()
		) {
			$spawn           = ( isset( $test['spawn'] ) && is_array( $test['spawn'] ) ) ? $test['spawn'] : null;
			$test['status']  = 'failed';
			$test['reason']  = self::timeout_reason( $spawn );
			$test['message'] = self::timeout_message( $spawn, self::test_timeout() );
			update_option( self::OPTION, $test, false );
		}

		if ( in_array( $test['status'] ?? '', array( 'passed', 'failed' ), true ) ) {
			$with_verdict = $this->with_overdue( $test );
			if ( $with_verdict !== $test ) {
				update_option( self::OPTION, $with_verdict, false );
			}
			$test = $with_verdict;
		}

		wp_send_json_success( $test );
	}

	/**
	 * Delete the doing_cron lock.
	 */
	public function ajax_clear_lock() {
		$this->verify_ajax();
		delete_transient( 'doing_cron' );
		wp_send_json_success( array( 'cleared' => true ) );
	}

	// ---------------------------------------------------------------------
	// Cron callback.
	// ---------------------------------------------------------------------

	/**
	 * Test event callback: mark the stored test as fired.
	 *
	 * @param string $id Test ID passed to wp_schedule_single_event().
	 */
	public function on_test_event( $id ) {
		$test = get_option( self::OPTION, null );
		if ( ! is_array( $test ) || empty( $test['id'] ) || $test['id'] !== $id ) {
			return;
		}

		$test['fired']       = time();
		$test['fired_micro'] = microtime( true );
		$test['duration']    = isset( $test['started_micro'] )
			? round( $test['fired_micro'] - (float) $test['started_micro'], 1 )
			: $test['fired'] - (int) $test['started'];
		$test['status']      = 'passed';
		$test['source']      = self::detect_source();
		unset( $test['message'] );
		update_option( self::OPTION, $test, false );
	}

	/**
	 * Best-effort detection of what triggered the event.
	 *
	 * @return string
	 */
	public static function detect_source(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'wp-cli';
		}
		if ( self::is_alternate_cron() ) {
			return 'alternate';
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' !== $ua && 0 === stripos( $ua, 'WordPress/' ) ) {
			return 'loopback';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of a core cron marker.
		if ( ! empty( $_GET['doing_wp_cron'] ) ) {
			return 'external';
		}
		return '' !== $ua ? 'external' : 'unknown';
	}

	// ---------------------------------------------------------------------
	// Site Health.
	// ---------------------------------------------------------------------

	/**
	 * Register the Site Health test.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function site_status_tests( $tests ) {
		$tests['direct']['cron_health_check'] = array(
			'label' => __( 'Scheduled events are running', 'cron-health-check' ),
			'test'  => array( $this, 'site_health_test' ),
		);
		return $tests;
	}

	/**
	 * Run the Site Health test (overdue count only; never spawns).
	 *
	 * @return array
	 */
	public function site_health_test(): array {
		$rows  = self::get_event_rows();
		$parts = self::partition_overdue( $rows, time(), self::OVERDUE_GRACE );
		$count = count( $parts['overdue'] );

		$result = array(
			'label'       => __( 'Scheduled events are running', 'cron-health-check' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Cron', 'cron-health-check' ),
				'color' => 'blue',
			),
			'description' => __( 'No scheduled events are overdue.', 'cron-health-check' ),
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::page_url() ),
				__( 'Open Cron Health Check', 'cron-health-check' )
			),
			'test'        => 'cron_health_check',
		);

		if ( $count > 0 ) {
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']          = __( 'Scheduled events are overdue', 'cron-health-check' );
			$result['description']    = sprintf(
				/* translators: %d: number of overdue events. */
				__( '%d scheduled events are overdue, which means WP-Cron may not be running on this site.', 'cron-health-check' ),
				$count
			);
		}

		return $result;
	}

	/**
	 * Add debug info to Site Health.
	 *
	 * @param array $info Debug information sections.
	 * @return array
	 */
	public function debug_information( $info ) {
		$rows  = self::get_event_rows();
		$parts = self::partition_overdue( $rows, time(), self::OVERDUE_GRACE );

		$info['cron-health-check'] = array(
			'label'  => __( 'Cron Health Check', 'cron-health-check' ),
			'fields' => array(
				'disable_wp_cron'   => array(
					'label' => 'DISABLE_WP_CRON',
					'value' => self::is_cron_disabled() ? 'true' : 'false',
				),
				'alternate_wp_cron' => array(
					'label' => 'ALTERNATE_WP_CRON',
					'value' => self::is_alternate_cron() ? 'true' : 'false',
				),
				'lock_timeout'      => array(
					'label' => 'WP_CRON_LOCK_TIMEOUT',
					'value' => self::lock_timeout(),
				),
				'overdue_events'    => array(
					'label' => __( 'Overdue events', 'cron-health-check' ),
					'value' => count( $parts['overdue'] ),
				),
			),
		);
		return $info;
	}

	// ---------------------------------------------------------------------
	// Pure helpers (unit-testable without WordPress).
	// ---------------------------------------------------------------------

	/**
	 * Flatten a cron array into rows.
	 *
	 * @param array $cron Cron array as returned by _get_cron_array().
	 * @param int   $now  Current timestamp.
	 * @return array[] Rows: hook, args, timestamp, schedule, interval, overdue_by, orphaned.
	 */
	public static function flatten_cron_array( array $cron, int $now ): array {
		$rows = array();
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) || 'version' === $timestamp ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				foreach ( (array) $events as $event ) {
					$rows[] = array(
						'hook'       => $hook,
						'args'       => isset( $event['args'] ) ? $event['args'] : array(),
						'timestamp'  => (int) $timestamp,
						'schedule'   => isset( $event['schedule'] ) ? $event['schedule'] : false,
						'interval'   => isset( $event['interval'] ) ? $event['interval'] : null,
						'overdue_by' => max( 0, $now - (int) $timestamp ),
						'orphaned'   => ! self::hook_has_action( $hook ),
					);
				}
			}
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $a['timestamp'] <=> $b['timestamp'];
			}
		);
		return $rows;
	}

	/**
	 * Whether a hook has any callbacks registered (mockable seam).
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	protected static function hook_has_action( string $hook ): bool {
		return (bool) has_action( $hook );
	}

	/**
	 * Split flattened rows into overdue and upcoming.
	 *
	 * @param array $rows  Rows from flatten_cron_array().
	 * @param int   $now   Current timestamp.
	 * @param int   $grace Grace seconds before an event counts as overdue.
	 * @return array{overdue: array, upcoming: array}
	 */
	public static function partition_overdue( array $rows, int $now, int $grace = 60 ): array {
		$overdue  = array();
		$upcoming = array();
		foreach ( $rows as $row ) {
			if ( $row['timestamp'] < $now - $grace ) {
				$overdue[] = $row;
			} else {
				$upcoming[] = $row;
			}
		}
		usort(
			$overdue,
			function ( $a, $b ) {
				return $a['timestamp'] <=> $b['timestamp']; // Most overdue first.
			}
		);
		return array(
			'overdue'  => $overdue,
			'upcoming' => $upcoming,
		);
	}

	/**
	 * Derive a display status from a stored test record.
	 *
	 * @param array|null $test    Stored test option.
	 * @param int        $now     Current timestamp.
	 * @param int        $timeout Seconds after which a running test counts as timed out.
	 * @return array{status: string, reason: ?string, message: string, duration: ?float}
	 */
	public static function summarize_test( ?array $test, int $now, int $timeout ): array {
		if ( null === $test ) {
			return array(
				'status'   => 'none',
				'reason'   => null,
				'message'  => __( 'No test has been run yet.', 'cron-health-check' ),
				'duration' => null,
			);
		}

		$fired   = $test['fired'] ?? null;
		$started = isset( $test['started'] ) ? (int) $test['started'] : 0;
		$status  = $test['status'] ?? 'running';
		$reason  = $test['reason'] ?? null;

		if ( 'passed' === $status || null !== $fired ) {
			$duration = isset( $test['duration'] ) ? (float) $test['duration'] : (float) max( 0, (int) $fired - $started );
			return array(
				'status'   => 'passed',
				'reason'   => null,
				'message'  => ! empty( $test['source'] )
					? sprintf(
						/* translators: 1: duration in seconds, 2: trigger source. */
						__( 'Cron fired in %1$ss via %2$s.', 'cron-health-check' ),
						number_format_i18n( $duration, 1 ),
						$test['source']
					)
					: sprintf(
						/* translators: %s: duration in seconds. */
						__( 'Cron fired in %ss.', 'cron-health-check' ),
						number_format_i18n( $duration, 1 )
					),
				'duration' => $duration,
			);
		}

		if ( 'failed' === $status ) {
			$message = $test['message'] ?? '';
			if ( '' === $message ) {
				if ( null !== $reason && 0 === strpos( $reason, 'timeout' ) ) {
					$spawn   = ( isset( $test['spawn'] ) && is_array( $test['spawn'] ) ) ? $test['spawn'] : null;
					$message = self::timeout_message( $spawn, $timeout );
				} else {
					$message = __( 'The test failed.', 'cron-health-check' );
				}
			}
			return array(
				'status'   => 'failed',
				'reason'   => $reason,
				'message'  => $message,
				'duration' => isset( $test['duration'] ) ? (float) $test['duration'] : null,
			);
		}

		if ( $started > 0 && ( $now - $started ) > $timeout ) {
			$spawn = ( isset( $test['spawn'] ) && is_array( $test['spawn'] ) ) ? $test['spawn'] : null;
			return array(
				'status'   => 'failed',
				'reason'   => self::timeout_reason( $spawn ),
				'message'  => self::timeout_message( $spawn, $timeout ),
				'duration' => null,
			);
		}

		return array(
			'status'   => 'running',
			'reason'   => null,
			'message'  => __( 'The test is running.', 'cron-health-check' ),
			'duration' => null,
		);
	}

	/**
	 * Apply the overdue rule: a fired test still fails if events are overdue.
	 *
	 * @param array $test       Test record.
	 * @param int   $overdue    Number of events overdue by more than $grace.
	 * @param int   $oldest_age Seconds the oldest overdue event is late (0 when none).
	 * @param int   $grace      Grace seconds before an event counts as overdue.
	 * @return array
	 */
	public static function apply_overdue_verdict( array $test, int $overdue, int $oldest_age, int $grace ): array {
		$test['overdue']        = $overdue;
		$test['overdue_oldest'] = $oldest_age;

		if ( in_array( $test['status'] ?? '', array( 'passed', 'running' ), true ) && $overdue > 0 ) {
			$oldest_text     = function_exists( 'human_time_diff' )
				? human_time_diff( time() - $oldest_age )
				: sprintf( '%ds', $oldest_age );
			$test['status']  = 'failed';
			$test['reason']  = 'overdue';
			$test['message'] = sprintf(
				/* translators: 1: overdue event count, 2: grace in minutes, 3: how late the oldest event is. */
				_n(
					'%1$d scheduled event is overdue by more than %2$d minutes (oldest: %3$s late). WP-Cron has not been keeping up on this site.',
					'%1$d scheduled events are overdue by more than %2$d minutes (oldest: %3$s late). WP-Cron has not been keeping up on this site.',
					$overdue,
					'cron-health-check'
				),
				$overdue,
				(int) ( $grace / 60 ),
				$oldest_text
			);
		}

		return $test;
	}

	/**
	 * Derive a timeout sub-reason from the captured spawn result.
	 *
	 * @param array|null $spawn Spawn record or null when no request was made.
	 * @return string
	 */
	public static function timeout_reason( ?array $spawn ): string {
		if ( null === $spawn ) {
			return 'timeout_no_request';
		}
		if ( ! empty( $spawn['error'] ) ) {
			return 'timeout_loopback_error';
		}
		if ( ! empty( $spawn['code'] ) ) {
			return (int) $spawn['code'] >= 400 ? 'timeout_http_error' : 'timeout_no_fire';
		}
		if ( ! empty( $spawn['alternate'] ) ) {
			return 'timeout_no_fire';
		}
		return 'timeout_no_request';
	}

	/**
	 * Build a specific timeout failure message from the captured spawn result.
	 *
	 * @param array|null $spawn   Spawn record or null when no request was made.
	 * @param int        $timeout Seconds waited for the event to fire.
	 * @return string
	 */
	public static function timeout_message( ?array $spawn, int $timeout ): string {
		if ( null !== $spawn && ! empty( $spawn['error'] ) ) {
			return sprintf(
				/* translators: %s: HTTP error message. */
				__( 'The loopback request to wp-cron.php failed: %s. WordPress cannot reach its own site URL, so scheduled events never run. Check SSL, HTTP basic auth, DNS, and firewall or loopback blocking for the site URL.', 'cron-health-check' ),
				$spawn['error']
			);
		}
		if ( null !== $spawn && ! empty( $spawn['code'] ) && 500 === (int) $spawn['code'] ) {
			return __( 'The loopback request to wp-cron.php returned HTTP 500. A scheduled callback is probably fatal-erroring (a 500 means PHP crashed mid-run), so events after it never run. Check the PHP error log.', 'cron-health-check' );
		}
		if ( null !== $spawn && ! empty( $spawn['code'] ) && (int) $spawn['code'] >= 400 ) {
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The loopback request to wp-cron.php returned HTTP %d, so scheduled events never run.', 'cron-health-check' ),
				(int) $spawn['code']
			);
		}
		if ( null !== $spawn && ! empty( $spawn['code'] ) ) {
			return sprintf(
				/* translators: 1: HTTP status code, 2: timeout in seconds. */
				__( 'wp-cron.php responded (HTTP %1$d) but the test event never ran within %2$ds. Cron may be very slow, or another plugin may be interfering.', 'cron-health-check' ),
				(int) $spawn['code'],
				$timeout
			);
		}
		if ( null !== $spawn && ! empty( $spawn['alternate'] ) ) {
			return sprintf(
				/* translators: %d: timeout in seconds. */
				__( 'The test event did not run within %ds. ALTERNATE_WP_CRON only fires on front-end page loads — a low-traffic site may need more time.', 'cron-health-check' ),
				$timeout
			);
		}
		return __( 'spawn_cron() did not make a request.', 'cron-health-check' );
	}

	/**
	 * Classify the doing_cron lock.
	 *
	 * @param float|null $lock    Lock value (microtime float) or null if absent.
	 * @param float      $now     Current microtime.
	 * @param int        $timeout Lock timeout in seconds.
	 * @return array{state: string, age: float, timeout: int}
	 */
	public static function lock_state( ?float $lock, float $now, int $timeout ): array {
		if ( null === $lock ) {
			return array(
				'state'   => 'none',
				'age'     => 0.0,
				'timeout' => $timeout,
			);
		}
		$age = $now - $lock;
		return array(
			'state'   => $age > $timeout ? 'stale' : 'fresh',
			'age'     => $age,
			'timeout' => $timeout,
		);
	}

	// ---------------------------------------------------------------------
	// Lifecycle.
	// ---------------------------------------------------------------------

	/**
	 * Activation: flag a one-time redirect to the tool page.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			return;
		}
		set_transient( self::REDIRECT_TRANSIENT, get_current_user_id(), 60 );
	}

	/**
	 * Redirect to the tool page once after a single-plugin activation.
	 */
	public function maybe_activation_redirect() {
		$user_id = get_transient( self::REDIRECT_TRANSIENT );
		if ( false === $user_id ) {
			return;
		}
		delete_transient( self::REDIRECT_TRANSIENT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check for bulk activation.
		if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) || get_current_user_id() !== (int) $user_id || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Deactivation: clear our scheduled event.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::TEST_HOOK );
	}

	/**
	 * Uninstall: remove stored data.
	 */
	public static function uninstall() {
		delete_option( self::OPTION );
		delete_transient( self::REDIRECT_TRANSIENT );
		wp_clear_scheduled_hook( self::TEST_HOOK );
	}
}

register_activation_hook( __FILE__, array( 'CRHC_Cron_Health_Check', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CRHC_Cron_Health_Check', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'CRHC_Cron_Health_Check', 'uninstall' ) );

CRHC_Cron_Health_Check::instance();
