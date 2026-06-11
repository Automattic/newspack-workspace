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
