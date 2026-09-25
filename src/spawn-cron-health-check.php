<?php
/**
 * Plugin Name:       Spawn – Cron Health Check
 * Plugin URI:        https://github.com/andrewjmead/cron-health-check
 * Description:       Check that WP-Cron is working correctly on your WordPress website
 * Version:           1.0.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Andrew Mead
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spawn-cron-health-check
 *
 * @package SPCR_Cron_Health_Check
 */

defined( 'ABSPATH' ) || exit;

define( 'SPCR_VERSION', '1.0.4' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- mandated short prefix.
define( 'SPCR_TEST_TIMEOUT', 30 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- mandated short prefix.
define( 'SPCR_TEST_TIMEOUT_ALTERNATE', 60 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- mandated short prefix.

/**
 * Cron Health Check plugin.
 */
final class SPCR_Cron_Health_Check {

	/**
	 * Option that stores the last test result.
	 *
	 * @var string
	 */
	const OPTION = 'spcr_test';

	/**
	 * Cron hook used for the test event.
	 *
	 * @var string
	 */
	const TEST_HOOK = 'spcr_test_event';

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
	const REDIRECT_TRANSIENT = 'spcr_activation_redirect';

	/**
	 * Singleton instance.
	 *
	 * @var SPCR_Cron_Health_Check|null
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
	 * @return SPCR_Cron_Health_Check
	 */
	public static function instance(): SPCR_Cron_Health_Check {
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
		add_action( 'wp_ajax_spcr_start_test', array( $this, 'ajax_start_test' ) );
		add_action( 'wp_ajax_spcr_test_status', array( $this, 'ajax_test_status' ) );
		add_action( 'wp_ajax_spcr_clear_lock', array( $this, 'ajax_clear_lock' ) );
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
			esc_html__( 'Dashboard', 'spawn-cron-health-check' )
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
		return (bool) apply_filters( 'spcr_cron_disabled', $disabled ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mandated short prefix.
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
		return self::is_alternate_cron() ? SPCR_TEST_TIMEOUT_ALTERNATE : SPCR_TEST_TIMEOUT;
	}

	// ---------------------------------------------------------------------
	// Admin page.
	// ---------------------------------------------------------------------

	/**
	 * Register the Tools submenu page.
	 */
	public function admin_menu() {
		add_management_page(
			__( 'Cron Health Check', 'spawn-cron-health-check' ),
			__( 'Cron Health Check', 'spawn-cron-health-check' ),
			'manage_options',
			'spawn-cron-health-check',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Strip every admin notice hook on our screen so nothing renders inside the card.
	 */
	public function remove_admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'tools_page_spawn-cron-health-check' !== $screen->id ) {
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
		return admin_url( 'tools.php?page=spawn-cron-health-check' );
	}

	/**
	 * Enqueue assets on our screen only.
	 *
	 * @param string $hook Current admin screen hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_spawn-cron-health-check' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'spawn-cron-health-check',
			plugins_url( 'spawn-cron-health-check.css', __FILE__ ),
			array(),
			SPCR_VERSION
		);
		wp_enqueue_script(
			'spawn-cron-health-check',
			plugins_url( 'spawn-cron-health-check.js', __FILE__ ),
			array(),
			SPCR_VERSION,
			true
		);
		wp_localize_script(
			'spawn-cron-health-check',
			'spcrData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'spcr_nonce' ),
				'timeout'   => self::test_timeout(),
				'grace'     => (int) ( self::OVERDUE_GRACE / 60 ),
				'timezone'  => wp_timezone_string(),
				'altCron'   => self::is_alternate_cron(),
				'homeUrl'   => home_url( '/' ),
				'wpVersion' => get_bloginfo( 'version' ),
				'version'   => SPCR_VERSION,
				'i18n'      => array(
					'failed'               => __( 'Failed', 'spawn-cron-health-check' ),
					'requestFailed'        => __( 'Request failed. Check your connection and try again.', 'spawn-cron-health-check' ),
					'couldNotStart'        => __( 'Failed to schedule a test cron event.', 'spawn-cron-health-check' ),
					'timeout'              => __( 'The event was scheduled but never ran.', 'spawn-cron-health-check' ),
					'notRun'               => __( 'Not run', 'spawn-cron-health-check' ),
					'bannerPassed'         => __( 'WP-Cron is working correctly', 'spawn-cron-health-check' ),
					'bannerFailed'         => __( 'WP-Cron is not working correctly', 'spawn-cron-health-check' ),
					'whyMeans'             => __( 'What this usually means', 'spawn-cron-health-check' ),
					'whyChecks'            => __( 'Things to check', 'spawn-cron-health-check' ),
					'r1Pass'               => __( 'WP-Cron is enabled', 'spawn-cron-health-check' ),
					'r1Undefined'          => __( 'DISABLE_WP_CRON is not defined, so WordPress triggers scheduled events itself whenever the site gets a visitor.', 'spawn-cron-health-check' ),
					'r1False'              => __( 'DISABLE_WP_CRON is set to false, so WordPress triggers scheduled events itself whenever the site gets a visitor.', 'spawn-cron-health-check' ),
					'r1Fail'               => __( 'WP-Cron is disabled', 'spawn-cron-health-check' ),
					'r1FailDetail'         => __( 'DISABLE_WP_CRON is set to true in wp-config.php, so WordPress will not run scheduled events on its own.', 'spawn-cron-health-check' ),
					'r1Means'              => __( 'Either you or your host added this line to wp-config.php. That is fine when a real server cron job calls wp-cron.php on a schedule — many hosts set this up for performance. If nothing is calling wp-cron.php, scheduled tasks will never run.', 'spawn-cron-health-check' ),
					'r1Check1'             => __( 'Look for define( \'DISABLE_WP_CRON\', true ) in wp-config.php. If you did not add it, your host probably did.', 'spawn-cron-health-check' ),
					'r1Check2'             => __( 'Ask your host whether they run a server cron job for wp-cron.php. If they do not, either remove the line or set one up.', 'spawn-cron-health-check' ),
					'r1Check3'             => __( 'The overdue events step below shows whether scheduled events are actually being run.', 'spawn-cron-health-check' ),
					'r2Pass'               => __( 'Test cron event scheduled', 'spawn-cron-health-check' ),
					'r2PassDetail'         => __( 'WordPress accepted a one-time test event and added it to the cron queue.', 'spawn-cron-health-check' ),
					'r2Fail'               => __( 'Test cron event could not be scheduled', 'spawn-cron-health-check' ),
					/* translators: %s: error message. */
					'r2FailDetail'         => __( 'WordPress refused to add the test event to the cron queue: %s', 'spawn-cron-health-check' ),
					'r2Means'              => __( 'WordPress itself rejected the event. This is almost always another plugin hooking into cron scheduling, or a corrupted cron option in the database.', 'spawn-cron-health-check' ),
					'r2Check1'             => __( 'Deactivate plugins that manage or replace WP-Cron and run the check again.', 'spawn-cron-health-check' ),
					'r2Check2'             => __( 'Check the PHP error log for errors during this request.', 'spawn-cron-health-check' ),
					'r2Check3'             => __( 'If the cron option in wp_options is corrupted, a cron management plugin can rebuild it.', 'spawn-cron-health-check' ),
					/* translators: %s: error message. */
					'r2StartDetail'        => __( 'The request to start the check did not return a valid response: %s', 'spawn-cron-health-check' ),
					'r2StartMeans'         => __( 'The check could not begin, so nothing was tested yet.', 'spawn-cron-health-check' ),
					'r2StartCheck1'        => __( 'Check the PHP error log for a fatal error or memory limit hit.', 'spawn-cron-health-check' ),
					'r2StartCheck2'        => __( 'A security or firewall plugin may be blocking requests to admin-ajax.php.', 'spawn-cron-health-check' ),
					'r2StartCheck3'        => __( 'Run the check again.', 'spawn-cron-health-check' ),
					'r3Pass'               => __( 'WordPress can reach itself', 'spawn-cron-health-check' ),
					/* translators: %s: HTTP status code. */
					'r3PassDetail'         => __( 'WordPress requested its own wp-cron.php and the server answered with HTTP %s. This "loopback" request is how WP-Cron runs in the background, so it needs to work.', 'spawn-cron-health-check' ),
					'r3PassDetailNoCode'   => __( 'WordPress requested its own wp-cron.php and the server accepted the request. This "loopback" request is how WP-Cron runs in the background, so it needs to work.', 'spawn-cron-health-check' ),
					'r3Alternate'          => __( 'ALTERNATE_WP_CRON is enabled, so WordPress triggers cron with a page redirect instead of a loopback request.', 'spawn-cron-health-check' ),
					'r3Fail'               => __( 'WordPress cannot reach itself', 'spawn-cron-health-check' ),
					/* translators: %s: HTTP error message. */
					'r3ErrDetail'          => __( 'WordPress tried to trigger the test by requesting its own wp-cron.php, but the request failed: %s. Nothing scheduled will run until this request works.', 'spawn-cron-health-check' ),
					'r3ErrMeans'           => __( 'Something between your server and your own domain is blocking the connection. This is almost always a hosting or server configuration issue, not a WordPress issue.', 'spawn-cron-health-check' ),
					'r3ErrCheck1'          => __( 'Your host\'s firewall or security rules block loopback requests — ask your host to allow the server to reach its own site URL.', 'spawn-cron-health-check' ),
					'r3ErrCheck2'          => __( 'The site is behind HTTP basic auth, a maintenance password, or an IP allowlist that also blocks the server itself.', 'spawn-cron-health-check' ),
					'r3ErrCheck3'          => __( 'DNS for your domain does not resolve from inside the server, or the SSL certificate is invalid or self-signed.', 'spawn-cron-health-check' ),
					'r3ErrCheck4'          => __( 'As a workaround, many hosts recommend disabling WP-Cron and running wp-cron.php from a real server cron job instead.', 'spawn-cron-health-check' ),
					/* translators: %s: HTTP status code. */
					'r3HttpDetail'         => __( 'WordPress requested its own wp-cron.php, but the server responded with HTTP %s.', 'spawn-cron-health-check' ),
					'r3Http500Means'       => __( 'A 500 means PHP crashed while running wp-cron.php. Usually a scheduled callback from a plugin or theme is fatal-erroring, so every event after it never runs.', 'spawn-cron-health-check' ),
					'r3Http500Check1'      => __( 'Check the PHP error log for the fatal error and which plugin or theme it comes from.', 'spawn-cron-health-check' ),
					'r3Http500Check2'      => __( 'Deactivate recently added or updated plugins and run the check again.', 'spawn-cron-health-check' ),
					'r3Http500Check3'      => __( 'The overdue events check above shows which scheduled events are being blocked.', 'spawn-cron-health-check' ),
					'r3HttpMeans'          => __( 'The server is refusing or failing to serve wp-cron.php, so WordPress cannot run its scheduled events.', 'spawn-cron-health-check' ),
					'r3HttpCheck1'         => __( 'A security plugin, firewall, or server rule may be blocking wp-cron.php — ask your host or check your security plugin\'s logs.', 'spawn-cron-health-check' ),
					'r3HttpCheck2'         => __( 'HTTP basic auth or a maintenance mode password on the site also blocks the server\'s own requests.', 'spawn-cron-health-check' ),
					'r3HttpCheck3'         => __( 'Check the server error log for requests to wp-cron.php.', 'spawn-cron-health-check' ),
					'r4Pass'               => __( 'Test cron event fired', 'spawn-cron-health-check' ),
					/* translators: 1: duration in seconds, 2: trigger source. */
					'r4PassDetail'         => __( 'The test event ran %1$s seconds after it was scheduled (triggered via %2$s). Scheduled tasks on this site are being executed.', 'spawn-cron-health-check' ),
					/* translators: %s: duration in seconds. */
					'r4PassDetailNoSource' => __( 'The test event ran %s seconds after it was scheduled. Scheduled tasks on this site are being executed.', 'spawn-cron-health-check' ),
					'r4Fail'               => __( 'Test cron event did not fire', 'spawn-cron-health-check' ),
					/* translators: %s: timeout in seconds. */
					'r4FailDetail'         => __( 'WordPress reached wp-cron.php, but the test event had not run after %s seconds.', 'spawn-cron-health-check' ),
					'r4Means'              => __( 'Cron is being triggered but is not getting through its queue. Usually one earlier event has a callback that hangs or crashes, which stops everything scheduled after it.', 'spawn-cron-health-check' ),
					'r4Check1'             => __( 'Look at the overdue events above — the oldest one is the most likely culprit; the hook name usually tells you which plugin owns it.', 'spawn-cron-health-check' ),
					'r4Check2'             => __( 'Check the PHP error log for errors during wp-cron.php requests.', 'spawn-cron-health-check' ),
					'r4Check3'             => __( 'Run the check again — a cron run may simply have been in progress.', 'spawn-cron-health-check' ),
					'r4AltMeans'           => __( 'ALTERNATE_WP_CRON only fires on front-end page loads, so a quiet site may need more time.', 'spawn-cron-health-check' ),
					'r4AltCheck1'          => __( 'Open the front end of the site in another tab, then run the check again.', 'spawn-cron-health-check' ),
					'r5Pass'               => __( 'No overdue cron events', 'spawn-cron-health-check' ),
					/* translators: 1: total scheduled events, 2: grace period in minutes. */
					'r5PassDetail'         => __( 'All %1$s scheduled events are on time. Nothing is more than %2$s minutes past its scheduled run.', 'spawn-cron-health-check' ),
					/* translators: %s: grace period in minutes. */
					'r5PassDetailOne'      => __( 'The 1 scheduled event is on time. Nothing is more than %s minutes past its scheduled run.', 'spawn-cron-health-check' ),
					'r5FailOne'            => __( '1 overdue cron event', 'spawn-cron-health-check' ),
					/* translators: %s: overdue event count. */
					'r5FailMany'           => __( '%s overdue cron events', 'spawn-cron-health-check' ),
					/* translators: %s: grace period in minutes. */
					'r5FailDetail'         => __( 'These scheduled events are more than %s minutes past their scheduled time and still have not run.', 'spawn-cron-health-check' ),
					'colEvent'             => __( 'Event', 'spawn-cron-health-check' ),
					'colScheduled'         => __( 'Scheduled for', 'spawn-cron-health-check' ),
					'colOverdue'           => __( 'Overdue by', 'spawn-cron-health-check' ),
					/* translators: %s: number of overdue events not shown. */
					'r5More'               => __( '…and %s more.', 'spawn-cron-health-check' ),
					'r5Means'              => __( 'WP-Cron only runs when your site is visited. If the site has been quiet, run this check again — if the events clear, nothing is wrong.', 'spawn-cron-health-check' ),
					'r5MeansFailed'        => __( ' If they stay overdue, WordPress is queuing work but never getting to run it, which matches the failed cron test below.', 'spawn-cron-health-check' ),
					'r5MeansPassed'        => __( ' If they stay overdue even though the test event fired, something is stopping WordPress from getting through its queue — often one event whose callback crashes or hangs.', 'spawn-cron-health-check' ),
					'r5Check1'             => __( 'Run the check again after visiting the front end of the site.', 'spawn-cron-health-check' ),
					'r5Check2'             => __( 'The oldest overdue event is the most likely culprit; its hook name usually tells you which plugin owns it.', 'spawn-cron-health-check' ),
					'r5Check3'             => __( 'Check the PHP error log for errors during wp-cron.php requests.', 'spawn-cron-health-check' ),
					/* translators: %s: number of days. */
					'dayOne'               => __( '%s day', 'spawn-cron-health-check' ),
					/* translators: %s: number of days. */
					'dayMany'              => __( '%s days', 'spawn-cron-health-check' ),
					/* translators: %s: number of hours. */
					'hourOne'              => __( '%s hour', 'spawn-cron-health-check' ),
					/* translators: %s: number of hours. */
					'hourMany'             => __( '%s hours', 'spawn-cron-health-check' ),
					/* translators: %s: number of minutes. */
					'minuteOne'            => __( '%s minute', 'spawn-cron-health-check' ),
					/* translators: %s: number of minutes. */
					'minuteMany'           => __( '%s minutes', 'spawn-cron-health-check' ),
					/* translators: separator between duration units. */
					'durJoin'              => __( ', ', 'spawn-cron-health-check' ),
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'spawn-cron-health-check' ) );
		}

		// The result only lives for the duration of a run; nothing persists across page loads.
		delete_option( self::OPTION );

		$lock = self::lock_state( self::get_lock(), microtime( true ), self::lock_timeout() );
		?>
		<div class="wrap spcr-wrap">
			<section class="spcr-card spcr-test-panel">
				<header class="spcr-header">
					<h1><?php esc_html_e( 'Cron Health Check', 'spawn-cron-health-check' ); ?></h1>
					<p class="spcr-subtitle"><?php esc_html_e( 'Check that WP-Cron is working correctly on your WordPress website', 'spawn-cron-health-check' ); ?></p>
				</header>

				<button type="button" id="spcr-run-test" class="spcr-button">
					<?php esc_html_e( 'Run Health Check', 'spawn-cron-health-check' ); ?>
				</button>

				<?php
				$groups  = array(
					__( 'Environment', 'spawn-cron-health-check' ) => array(
						'enabled' => __( 'Check that WP-Cron is enabled', 'spawn-cron-health-check' ),
						'overdue' => __( 'Check for overdue cron events', 'spawn-cron-health-check' ),
					),
					__( 'Cron test', 'spawn-cron-health-check' ) => array(
						'scheduled' => __( 'Schedule a test cron event', 'spawn-cron-health-check' ),
						'spawning'  => __( 'Attempt to run the test cron event', 'spawn-cron-health-check' ),
						'waiting'   => __( 'Confirm the test cron event fired', 'spawn-cron-health-check' ),
					),
				);
				$marksvg = '<svg viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path class="spcr-ok" d="M5 9.2l2.6 2.6L13 6.4"/><path class="spcr-x" d="M6 6l6 6M12 6l-6 6"/></svg>';
				$chevsvg = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>';
				?>
				<div id="spcr-report" class="spcr-report" aria-live="polite">
					<div class="spcr-banner-wrap"><div>
						<div class="spcr-report-banner">
							<span class="spcr-mark"><?php echo $marksvg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup. ?></span>
							<span class="spcr-report-title"></span>
							<span class="spcr-report-date"></span>
						</div>
					</div></div>
					<?php foreach ( $groups as $heading => $group ) : ?>
						<h3 class="spcr-group-heading"><?php echo esc_html( $heading ); ?></h3>
						<div class="spcr-report-list">
							<?php foreach ( $group as $key => $label ) : ?>
							<div class="spcr-report-item" data-step="<?php echo esc_attr( $key ); ?>" data-idle="<?php echo esc_attr( $label ); ?>">
								<button type="button" class="spcr-report-row" aria-expanded="false" aria-controls="spcr-panel-<?php echo esc_attr( $key ); ?>" disabled>
									<span class="spcr-mark"><?php echo $marksvg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup. ?></span>
									<span class="spcr-report-name"><?php echo esc_html( $label ); ?></span>
									<span class="spcr-chevron"><?php echo $chevsvg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup. ?></span>
								</button>
								<div class="spcr-report-panel" id="spcr-panel-<?php echo esc_attr( $key ); ?>"><div class="spcr-report-inner"><div class="spcr-report-body"></div></div></div>
							</div>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ( 'stale' === $lock['state'] ) : ?>
					<p class="spcr-lock-action">
						<button type="button" id="spcr-clear-lock" class="spcr-button spcr-button-secondary">
							<?php esc_html_e( 'Clear cron lock and retry', 'spawn-cron-health-check' ); ?>
						</button>
					</p>
				<?php endif; ?>
			</section>
		</div>
		<?php
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
		check_ajax_referer( 'spcr_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'spawn-cron-health-check' ) ), 403 );
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
				'message'       => __( 'WP-Cron is disabled via DISABLE_WP_CRON. WordPress will never trigger scheduled events itself; a system cron must call wp-cron.php.', 'spawn-cron-health-check' ),
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

		$scheduled = wp_schedule_single_event( time() - 1, self::TEST_HOOK, array( $id ), true );
		if ( true !== $scheduled ) {
			$test['status']  = 'failed';
			$test['reason']  = 'schedule';
			$test['message'] = '' !== $scheduled->get_error_message()
				? sprintf(
					/* translators: %s: error message. */
					__( 'WordPress refused to schedule the test event: %s', 'spawn-cron-health-check' ),
					$scheduled->get_error_message()
				)
				: __( 'WordPress refused to schedule the test event. Another plugin may be blocking cron scheduling (pre_schedule_event / schedule_event filters).', 'spawn-cron-health-check' );
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
			$rows  = self::get_event_rows();
			$parts = self::partition_overdue( $rows, time(), self::OVERDUE_GRACE );

			$test['overdue']        = count( $parts['overdue'] );
			$test['overdue_oldest'] = empty( $parts['overdue'] ) ? 0 : time() - (int) $parts['overdue'][0]['timestamp'];
			$test['total_events']   = count( $rows );
			$test['overdue_events'] = array_map(
				function ( $row ) {
					return array(
						'hook'       => $row['hook'],
						'timestamp'  => (int) $row['timestamp'],
						'overdue_by' => (int) $row['overdue_by'],
					);
				},
				array_slice( $parts['overdue'], 0, 50 )
			);
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
			'label' => __( 'Scheduled events are running', 'spawn-cron-health-check' ),
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
			'label'       => __( 'Scheduled events are running', 'spawn-cron-health-check' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Cron', 'spawn-cron-health-check' ),
				'color' => 'blue',
			),
			'description' => __( 'No scheduled events are overdue.', 'spawn-cron-health-check' ),
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::page_url() ),
				__( 'Open Cron Health Check', 'spawn-cron-health-check' )
			),
			'test'        => 'cron_health_check',
		);

		if ( $count > 0 ) {
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']          = __( 'Scheduled events are overdue', 'spawn-cron-health-check' );
			$result['description']    = sprintf(
				/* translators: %d: number of overdue events. */
				__( '%d scheduled events are overdue, which means WP-Cron may not be running on this site.', 'spawn-cron-health-check' ),
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

		$info['spawn-cron-health-check'] = array(
			'label'  => __( 'Cron Health Check', 'spawn-cron-health-check' ),
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
					'label' => __( 'Overdue events', 'spawn-cron-health-check' ),
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
				'message'  => __( 'No test has been run yet.', 'spawn-cron-health-check' ),
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
						__( 'Cron fired in %1$ss via %2$s.', 'spawn-cron-health-check' ),
						number_format_i18n( $duration, 1 ),
						$test['source']
					)
					: sprintf(
						/* translators: %s: duration in seconds. */
						__( 'Cron fired in %ss.', 'spawn-cron-health-check' ),
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
					$message = __( 'The test failed.', 'spawn-cron-health-check' );
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
			'message'  => __( 'The test is running.', 'spawn-cron-health-check' ),
			'duration' => null,
		);
	}

	/**
	 * Apply the overdue rule: overdue events flip a passed test to failed but
	 * never change a running test or an already-failed record's reason.
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

		if ( 'passed' === ( $test['status'] ?? '' ) && $overdue > 0 ) {
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
					'spawn-cron-health-check'
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
				__( 'The loopback request to wp-cron.php failed: %s. WordPress cannot reach its own site URL, so scheduled events never run. Check SSL, HTTP basic auth, DNS, and firewall or loopback blocking for the site URL.', 'spawn-cron-health-check' ),
				$spawn['error']
			);
		}
		if ( null !== $spawn && ! empty( $spawn['code'] ) && 500 === (int) $spawn['code'] ) {
			return __( 'The loopback request to wp-cron.php returned HTTP 500. A scheduled callback is probably fatal-erroring (a 500 means PHP crashed mid-run), so events after it never run. Check the PHP error log.', 'spawn-cron-health-check' );
		}
		if ( null !== $spawn && ! empty( $spawn['code'] ) && (int) $spawn['code'] >= 400 ) {
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The loopback request to wp-cron.php returned HTTP %d, so scheduled events never run.', 'spawn-cron-health-check' ),
				(int) $spawn['code']
			);
		}
		if ( null !== $spawn && ! empty( $spawn['code'] ) ) {
			return sprintf(
				/* translators: 1: HTTP status code, 2: timeout in seconds. */
				__( 'wp-cron.php responded (HTTP %1$d) but the test event never ran within %2$ds. Cron may be very slow, or another plugin may be interfering.', 'spawn-cron-health-check' ),
				(int) $spawn['code'],
				$timeout
			);
		}
		if ( null !== $spawn && ! empty( $spawn['alternate'] ) ) {
			return sprintf(
				/* translators: %d: timeout in seconds. */
				__( 'The test event did not run within %ds. ALTERNATE_WP_CRON only fires on front-end page loads — a low-traffic site may need more time.', 'spawn-cron-health-check' ),
				$timeout
			);
		}
		return __( 'spawn_cron() did not make a request.', 'spawn-cron-health-check' );
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

register_activation_hook( __FILE__, array( 'SPCR_Cron_Health_Check', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SPCR_Cron_Health_Check', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'SPCR_Cron_Health_Check', 'uninstall' ) );

SPCR_Cron_Health_Check::instance();
