<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Newspack_Network_Hub
 */

$newspack_network_hub_test_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $newspack_network_hub_test_dir ) {
	$newspack_network_hub_test_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$newspack_network_hub_test_dir}/includes/functions.php" ) ) {
	echo "Could not find {$newspack_network_hub_test_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

define( 'IS_TEST_ENV', 1 );

// Give access to tests_add_filter() function.
require_once "{$newspack_network_hub_test_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function newspack_network_hub_manually_load_plugin() {
	require dirname( __DIR__ ) . '/newspack-network.php';
}

tests_add_filter( 'muplugins_loaded', 'newspack_network_hub_manually_load_plugin' );

require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Lets tests call the WP-CLI command classes directly.
require_once __DIR__ . '/class-wp-cli-halt.php';
require_once __DIR__ . '/class-wp-cli.php';
require_once __DIR__ . '/wp-cli-utils.php';

// Start up the WP testing environment.
require "{$newspack_network_hub_test_dir}/includes/bootstrap.php";

/**
 * Serve a bundled image instead of fetching the one test payloads point at.
 *
 * Content distribution fixtures reference an image on picsum.photos. Sideloading
 * it makes the suite depend on that host being reachable, so requests for it are
 * answered with the image in tests/fixtures/ instead.
 *
 * @param false|array|WP_Error $response    Preempted response.
 * @param array                $parsed_args HTTP request arguments.
 * @param string               $url         Request URL.
 *
 * @return false|array Response carrying the fixture image, or the passed response.
 */
function newspack_network_serve_fixture_image( $response, $parsed_args, $url ) {
	if ( false === strpos( $url, 'picsum.photos' ) ) {
		return $response;
	}

	$fixture = __DIR__ . '/fixtures/image.jpg';
	$body    = '';

	// download_url() asks for a streamed response, so write the bytes to its temporary file.
	if ( ! empty( $parsed_args['stream'] ) && ! empty( $parsed_args['filename'] ) ) {
		copy( $fixture, $parsed_args['filename'] );
	} else {
		$body = file_get_contents( $fixture ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reading a bundled fixture, not a remote file.
	}

	return [
		'headers'  => [ 'content-type' => 'image/jpeg' ],
		'body'     => $body,
		'response' => [
			'code'    => 200,
			'message' => 'OK',
		],
		'cookies'  => [],
		'filename' => isset( $parsed_args['filename'] ) ? $parsed_args['filename'] : null,
	];
}

tests_add_filter( 'pre_http_request', 'newspack_network_serve_fixture_image', 10, 3 );
