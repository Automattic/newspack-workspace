<?php
/**
 * Tests the Newspack::asset_version() helper.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack;

/**
 * Test asset_version() resolves the content-hashed version emitted by webpack
 * into dist/*.asset.php, with a sensible fallback to NEWSPACK_PLUGIN_VERSION
 * when the file is missing or malformed.
 */
class Newspack_Test_Asset_Version extends WP_UnitTestCase {

	/**
	 * Fixture files created during a test, cleaned up in tear_down().
	 *
	 * @var string[]
	 */
	private $fixture_files = [];

	/**
	 * Clean up fixture files.
	 */
	public function tear_down() {
		foreach ( $this->fixture_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->fixture_files = [];
		parent::tear_down();
	}

	/**
	 * Write a fixture asset file under dist/ and register it for cleanup.
	 *
	 * @param string $name     Asset name (basename under dist/, no suffix).
	 * @param string $contents PHP source for the fixture file.
	 */
	private function write_fixture( $name, $contents ) {
		$path = NEWSPACK_ABSPATH . 'dist/' . $name . '.asset.php';
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		file_put_contents( $path, $contents ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		$this->fixture_files[] = $path;
	}

	/**
	 * It falls back to NEWSPACK_PLUGIN_VERSION when the asset file is malformed:
	 * not returning an array, or returning an array without a version.
	 */
	public function test_falls_back_to_plugin_version_when_malformed() {
		$non_array = 'tmp-test-non-array-' . wp_rand();
		$this->write_fixture( $non_array, '<?php return "not-an-array";' );
		$this->assertSame( NEWSPACK_PLUGIN_VERSION, Newspack::asset_version( $non_array ) );

		$no_version = 'tmp-test-no-version-' . wp_rand();
		$this->write_fixture( $no_version, '<?php return [ "dependencies" => [] ];' );
		$this->assertSame( NEWSPACK_PLUGIN_VERSION, Newspack::asset_version( $no_version ) );
	}

	/**
	 * It returns the version from a well-formed fixture, independent of the
	 * real build output.
	 */
	public function test_returns_version_from_fixture_asset_file() {
		$name = 'tmp-test-valid-' . wp_rand();
		$this->write_fixture( $name, '<?php return [ "dependencies" => [], "version" => "abc123def456" ];' );
		$this->assertSame( 'abc123def456', Newspack::asset_version( $name ) );
	}

	/**
	 * It returns the 'version' value from dist/commons.asset.php for a real
	 * built asset.
	 */
	public function test_returns_version_from_existing_asset_file() {
		if ( ! file_exists( NEWSPACK_ABSPATH . 'dist/commons.asset.php' ) ) {
			$this->markTestSkipped( 'dist/commons.asset.php is not built in this environment.' );
		}

		$expected = ( include NEWSPACK_ABSPATH . 'dist/commons.asset.php' )['version'];
		$this->assertSame( $expected, Newspack::asset_version( 'commons' ) );
		$this->assertNotSame( NEWSPACK_PLUGIN_VERSION, Newspack::asset_version( 'commons' ) );
	}

	/**
	 * It supports nested asset names like 'other-scripts/relative-time'.
	 */
	public function test_supports_nested_dist_paths() {
		if ( ! file_exists( NEWSPACK_ABSPATH . 'dist/other-scripts/relative-time.asset.php' ) ) {
			$this->markTestSkipped( 'dist/other-scripts/relative-time.asset.php is not built in this environment.' );
		}

		$expected = ( include NEWSPACK_ABSPATH . 'dist/other-scripts/relative-time.asset.php' )['version'];
		$this->assertSame( $expected, Newspack::asset_version( 'other-scripts/relative-time' ) );
	}

	/**
	 * It falls back to NEWSPACK_PLUGIN_VERSION when the asset file does not exist.
	 */
	public function test_falls_back_to_plugin_version_when_missing() {
		$this->assertSame(
			NEWSPACK_PLUGIN_VERSION,
			Newspack::asset_version( 'this-asset-definitely-does-not-exist-' . wp_rand() )
		);
	}
}
