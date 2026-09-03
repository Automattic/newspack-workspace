<?php
/**
 * Tests generated starter content.
 *
 * @package Newspack\Tests
 */

use Newspack\Starter_Content_Generated;

/**
 * Tests generated starter content.
 */
class Newspack_Test_Starter_Content_Generated extends WP_UnitTestCase {

	/**
	 * Files written into the uploads directory by a test, removed afterwards.
	 *
	 * @var string[]
	 */
	private $written_files = [];

	/**
	 * Filters this test added, removed individually in tear_down().
	 *
	 * Removing them by name rather than clearing the hook: the WordPress test suite
	 * installs its own pre_http_request handlers, and remove_all_filters() takes those
	 * with it and breaks every later test that makes a request.
	 *
	 * @var callable[]
	 */
	private $http_filters = [];

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		foreach ( $this->written_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			}
		}
		$this->written_files = [];
		foreach ( $this->http_filters as $filter ) {
			remove_filter( 'pre_http_request', $filter, 10 );
		}
		$this->http_filters = [];
		parent::tear_down();
	}

	/**
	 * Serve the bundled starter image instead of reaching the image host, writing it to
	 * the stream target download_url() passes so the sideload sees a real file.
	 */
	private function stub_image_download() {
		$filter = function ( $pre, $args, $url ) {
			if ( false === strpos( $url, 'picsum.photos' ) ) {
				return $pre;
			}
			if ( ! empty( $args['filename'] ) ) {
				copy( NEWSPACK_ABSPATH . 'includes/raw_assets/images/starter-content-featured-image.jpg', $args['filename'] );
			}
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'headers'  => [],
				'body'     => '',
				'cookies'  => [],
				'filename' => $args['filename'] ?? null,
			];
		};

		$this->http_filters[] = $filter;
		add_filter( 'pre_http_request', $filter, 10, 3 );
	}

	/**
	 * The image sideload lands a file in the uploads directory.
	 *
	 * Passing the sideload array inline is a fatal on PHP 8 rather than a failed upload,
	 * because wp_handle_sideload() takes its first argument by reference. Every
	 * starter-content post outside E2E died there before it could get a featured image.
	 */
	public function test_download_random_image_sideloads_into_uploads() {
		$this->stub_image_download();

		$method = new ReflectionMethod( Starter_Content_Generated::class, 'download_random_image' );
		$method->setAccessible( true );
		$path = $method->invoke( null );

		$this->assertIsString( $path, 'The sideload returns a path rather than failing.' );
		$this->written_files[] = $path;

		$this->assertFileExists( $path, 'The sideloaded file is on disk.' );
		$uploads = wp_upload_dir();
		$this->assertStringStartsWith( $uploads['basedir'], $path, 'The file landed in the uploads directory.' );
		$this->assertGreaterThan( 0, filesize( $path ), 'The sideloaded file has content.' );
	}

	/**
	 * A failed download is reported as no image, not as a fatal.
	 */
	public function test_download_random_image_returns_null_when_the_request_fails() {
		$filter = function () {
			return new WP_Error( 'http_request_failed', 'Offline.' );
		};
		$this->http_filters[] = $filter;
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$method = new ReflectionMethod( Starter_Content_Generated::class, 'download_random_image' );
		$method->setAccessible( true );

		$this->assertNull( $method->invoke( null ), 'An unreachable image host yields no image.' );
	}
}
