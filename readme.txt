=== Cron Health Check ===
Contributors: andrewmead
Tags: cron, wp-cron, scheduled events, debugging, site health
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manually test whether WP-Cron actually fires on your site, and see which scheduled events are overdue.

== Description ==

Cron Health Check gives you a manual, one-time test that proves whether WP-Cron works:

* Schedules a test event that is immediately due and spawns cron.
* Reports whether the event fired, how long it took, and what triggered it (loopback, WP-CLI, external, alternate).
* Detects common blockers: `DISABLE_WP_CRON`, a stale `doing_cron` lock, failed loopback requests, `ALTERNATE_WP_CRON`.
* Lists overdue scheduled events, sorted most-overdue first, with orphan-hook badges.
* Adds a Site Health check and debug information.

No background monitoring, no alerts, no settings — just a test you run when you need it.

== Installation ==

1. Upload `cron-health-check.php`, `cron-health-check.js`, and `cron-health-check.css` to `/wp-content/plugins/cron-health-check/`, or install the zip via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to Tools → Cron Health Check and click "Run test".

== Frequently Asked Questions ==

= Does the plugin run anything automatically? =

No. The only automated behavior is WordPress core running your scheduled events. The test only runs when you click "Run test".

= What does the "Clear cron lock" button do? =

It deletes the `doing_cron` transient. WordPress uses this lock to prevent concurrent cron spawns; if a previous spawn died, the lock can go stale and block new ones until it expires.

== Changelog ==

= 1.0.0 =
* Initial release.
