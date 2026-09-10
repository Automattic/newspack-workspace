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
	 * URLs the stub intercepted, asserted so a source URL change cannot reach the network.
	 *
	 * @var string[]
	 */
	private $stubbed_urls = [];

	/**
	 * Remove the uploads this test wrote.
	 *
	 * Only the files: WP_UnitTestCase_Base::tear_down() restores $GLOBALS['wp_filter']
	 * from its own snapshot, so filters added during a test need no bookkeeping here,
	 * but nothing reverts writes into the uploads directory.
	 */
	public function tear_down() {
		foreach ( $this->written_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			}
		}
		$this->written_files = [];
		parent::tear_down();
	}

	/**
	 * Serve the bundled starter image instead of reaching the image host, writing it to
	 * the stream target download_url() passes so the sideload sees a real file.
	 */
	private function stub_image_download() {
		$filter = function ( $pre, $args, $url ) {
			$this->stubbed_urls[] = $url;
			if ( ! empty( $args['filename'] ) ) {
				copy( NEWSPACK_ABSPATH . 'includes/raw_assets/images/starter-content-featured-image.jpg', $args['filename'] );
			}
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				// download_url() renames the temp file to a real extension from this
				// header, which is the shape the image host actually returns.
				'headers'  => [ 'content-type' => 'image/jpeg' ],
				'body'     => '',
				'cookies'  => [],
				'filename' => $args['filename'] ?? null,
			];
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
	}

	/**
	 * The image sideload lands a file in the uploads directory.
	 *
	 * Guards the by-reference argument to wp_handle_sideload(): passing a literal there
	 * throws rather than returning an upload error, so this fails as an error, not an
	 * assertion.
	 */
	public function test_download_random_image_sideloads_into_uploads() {
		$this->stub_image_download();

		$method = new ReflectionMethod( Starter_Content_Generated::class, 'download_random_image' );
		$method->setAccessible( true );
		$path = $method->invoke( null );

		$this->assertSame(
			[ 'https://picsum.photos/1200/800' ],
			$this->stubbed_urls,
			'The stub intercepted the request. A source URL change would otherwise reach the network.'
		);

		$this->assertIsString( $path, 'The sideload returns a path rather than failing.' );
		$this->written_files[] = $path;

		$this->assertFileExists( $path, 'The sideloaded file is on disk.' );
		$uploads = wp_upload_dir();
		$this->assertStringStartsWith( $uploads['basedir'], $path, 'The file landed in the uploads directory.' );
		$this->assertGreaterThan( 0, filesize( $path ), 'The sideloaded file has content.' );
	}

	/**
	 * A failed download is reported as no image, not as a fatal.
	 *
	 * This passes with the sideload fix reverted — the WP_Error returns above that call.
	 * It is here to pin the null-return contract the one caller branches on, against a
	 * future reordering of the guards, rather than to cover the fix.
	 */
	public function test_download_random_image_returns_null_when_the_request_fails() {
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Offline.' );
			},
			10,
			3
		);

		$method = new ReflectionMethod( Starter_Content_Generated::class, 'download_random_image' );
		$method->setAccessible( true );

		$this->assertNull( $method->invoke( null ), 'An unreachable image host yields no image.' );
	}
}
