<?php
/**
 * Serve image requests from a local fixture instead of the network.
 *
 * Content distribution payloads carry absolute image URLs, and importing one
 * sideloads the image. Without this the suite fetches those URLs over the public
 * internet, so someone else's outage turns into a red build: a failed sideload
 * returns attachment ID 0, which is what the incoming-post assertions check.
 *
 * Requests for anything that is not an image are left alone, so a test that wants
 * to mock an API response still can. A path under /http-503/ is answered with a
 * 503 instead of the fixture, for tests that pin what a failed sideload leaves
 * behind.
 *
 * @package Newspack
 */

namespace Test\Content_Distribution;

add_filter(
	'pre_http_request',
	/**
	 * Answer image requests with the bundled fixture.
	 *
	 * @param false|array|\WP_Error $preempt Response to short-circuit with, or false to continue.
	 * @param array                 $args    Request arguments.
	 * @param string                $url     Requested URL.
	 *
	 * @return false|array|\WP_Error
	 */
	function ( $preempt, $args, $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( false !== strpos( $path, '/http-503/' ) ) {
			return [
				'headers'  => [],
				'body'     => 'Service Unavailable',
				'response' => [
					'code'    => 503,
					'message' => 'Service Unavailable',
				],
				'cookies'  => [],
				'filename' => null,
			];
		}

		if ( ! preg_match( '/\.(jpe?g|png|gif|webp)$/i', $path ) ) {
			return $preempt;
		}

		$fixture = __DIR__ . '/mock-remote-image.jpg';
		if ( ! is_readable( $fixture ) ) {
			return new \WP_Error( 'mock_remote_image_missing', "The mock remote image fixture is missing or unreadable: {$fixture}" );
		}
		$image = file_get_contents( $fixture ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reads a fixture that ships with these tests.

		$response = [
			'headers'  => [ 'content-type' => 'image/jpeg' ],
			'body'     => $image,
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];

		// download_url() streams to a temp file and reads it back, so the bytes go
		// there and the body stays empty, the way a real streamed response behaves.
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			if ( false === file_put_contents( $args['filename'], $image ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Writes the temp file download_url() allocated via wp_tempnam().
				return new \WP_Error( 'mock_remote_image_unwritable', 'The mock remote image could not be written to the stream target.' );
			}
			$response['body']     = '';
			$response['filename'] = $args['filename'];
		}

		return $response;
	},
	10,
	3
);
