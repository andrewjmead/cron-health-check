<?php
/**
 * Plugin Name: Dev: preview host (wp-env only)
 * Description: Lets the wp-env site be browsed through a reverse-proxy preview URL (e.g. Devin's *.preview.devinapps.com). The proxy forwards Host: localhost and X-Forwarded-Proto: http, so WordPress would otherwise emit http://localhost links and break login. Uses X-Forwarded-Host as the site URL; requests without it (WP-Cron loopback, WP-CLI, local curl) are untouched. Not part of the shipped plugin.
 */

if ( empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

define( 'CHC_PREVIEW_HOST', $_SERVER['HTTP_X_FORWARDED_HOST'] );

$_SERVER['HTTPS']     = 'on';
$_SERVER['HTTP_HOST'] = CHC_PREVIEW_HOST;

$chc_dev_url = function () {
	return 'https://' . CHC_PREVIEW_HOST;
};

add_filter( 'option_home', $chc_dev_url );
add_filter( 'option_siteurl', $chc_dev_url );
add_filter( 'pre_option_home', $chc_dev_url );
add_filter( 'pre_option_siteurl', $chc_dev_url, 20 );

// WP_CONTENT_URL is computed from the DB siteurl before mu-plugins load, so rewrite asset URLs too.
$chc_dev_rehost = function ( $url ) {
	return preg_replace( '#^https?://localhost(:\d+)?#', 'https://' . CHC_PREVIEW_HOST, $url );
};
add_filter( 'plugins_url', $chc_dev_rehost );
add_filter( 'content_url', $chc_dev_rehost );
add_filter( 'includes_url', $chc_dev_rehost );
add_filter( 'upload_dir', function ( $dirs ) use ( $chc_dev_rehost ) {
	$dirs['url']     = $chc_dev_rehost( $dirs['url'] );
	$dirs['baseurl'] = $chc_dev_rehost( $dirs['baseurl'] );
	return $dirs;
} );

add_filter( 'cron_request', function ( $request ) {
	$request['url'] = preg_replace( '#^https?://[^/]+#', 'http://localhost', $request['url'] );
	return $request;
} );
