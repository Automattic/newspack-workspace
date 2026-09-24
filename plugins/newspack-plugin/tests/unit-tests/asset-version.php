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
	 * Per-test temp dir for fixtures (created lazily on first write_fixture()).
	 *
	 * @var string|null
	 */
	private $fixture_dir = null;

	/**
	 * Fixture files created during a test, cleaned up in tear_down().
	 *
	 * @var string[]
	 */
	private $fixture_files = [];

	/**
	 * Remove fixtures, drop the temp dir, drop the filter, and reset the
	 * helper's per-request cache so each test starts from clean state.
	 */
	public function tear_down() {
		remove_filter( 'newspack_asset_dist_dir', [ $this, 'filter_dist_dir' ] );
		foreach ( $this->fixture_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			}
		}
		$this->fixture_files = [];
		if ( null !== $this->fixture_dir && is_dir( $this->fixture_dir ) ) {
			rmdir( $this->fixture_dir ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
		}
		$this->fixture_dir = null;
		Newspack::asset_version_reset_cache();
		parent::tear_down();
	}

	/**
	 * Filter callback that redirects the helper's dist dir at the temp fixture
	 * dir for this test. Registered on first write_fixture() call.
	 *
	 * @return string
	 */
	public function filter_dist_dir() {
		return $this->fixture_dir;
	}

	/**
	 * Create the per-test fixture dir and register the dist-dir filter.
	 *
	 * @return void
	 */
	private function init_fixture_dir() {
		if ( null !== $this->fixture_dir ) {
			return;
		}
		$this->fixture_dir = get_temp_dir() . 'newspack-asset-version-fixtures-' . wp_rand() . '/';
		wp_mkdir_p( $this->fixture_dir );
		add_filter( 'newspack_asset_dist_dir', [ $this, 'filter_dist_dir' ] );
	}

	/**
	 * Write a fixture asset file under the per-test temp dir and register it
	 * for cleanup. Tests that call this implicitly opt into the filter that
	 * points `asset_version()` at the temp dir.
	 *
	 * @param string $name     Asset name (basename, no `.asset.php` suffix).
	 * @param string $contents PHP source for the fixture file.
	 */
	private function write_fixture( $name, $contents ) {
		$this->init_fixture_dir();
		$path = $this->fixture_dir . $name . '.asset.php';
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

	/**
	 * It rejects unsafe names by character class — absolute paths, spaces,
	 * backslashes, etc. fall back to NEWSPACK_PLUGIN_VERSION.
	 */
	public function test_falls_back_to_plugin_version_when_name_is_unsafe() {
		$unsafe = [
			'/absolute/path',
			'has spaces',
			'name;with;semicolons',
			'name\\with\\backslashes',
		];
		foreach ( $unsafe as $name ) {
			$this->assertSame(
				NEWSPACK_PLUGIN_VERSION,
				Newspack::asset_version( $name ),
				"Unsafe name should fall back to plugin version: {$name}"
			);
		}
	}

	/**
	 * It rejects `..` segments even when the resolved path would point at a
	 * real fixture. Without this guard a name like `foo/../foo` would resolve
	 * on disk to `foo` and bypass the character-class regex (which allows `.`
	 * inside segments). The fixture is intentionally resolvable to prove the
	 * rejection branch — not the missing-file fallback — is what's working.
	 */
	public function test_rejects_dot_dot_segments_even_when_resolvable() {
		$name    = 'tmp-test-traversal-' . wp_rand();
		$version = 'fixture-version-that-should-never-be-returned';
		$this->write_fixture( $name, '<?php return [ "version" => "' . $version . '" ];' );

		// Sanity check: the fixture itself resolves cleanly.
		$this->assertSame( $version, Newspack::asset_version( $name ) );

		// Each of these would filesystem-resolve to the fixture above if the
		// `..` guard weren't doing its job. They must all fall back instead.
		$traversals = [
			'..',
			'../' . $name,
			$name . '/../' . $name,
			'../../' . $name,
		];
		foreach ( $traversals as $traversal ) {
			Newspack::asset_version_reset_cache();
			$this->assertSame(
				NEWSPACK_PLUGIN_VERSION,
				Newspack::asset_version( $traversal ),
				"Traversal should fall back, not resolve via filesystem: {$traversal}"
			);
		}
	}

	/**
	 * It falls back when an asset.php returns a non-string `version` (e.g. a
	 * webpack regression that emits an array). The helper's return type is
	 * `string`; this guard keeps the contract airtight.
	 */
	public function test_falls_back_when_version_is_not_a_string() {
		$name = 'tmp-test-non-string-version-' . wp_rand();
		$this->write_fixture( $name, '<?php return [ "version" => [ "not", "a", "string" ] ];' );
		$this->assertSame( NEWSPACK_PLUGIN_VERSION, Newspack::asset_version( $name ) );
	}

	/**
	 * Reset clears memoized entries so a subsequent call re-reads the file.
	 * Without the reset, the helper would return the previously-cached value
	 * even after the underlying fixture changed.
	 */
	public function test_reset_cache_drops_memoized_entries() {
		$name = 'tmp-test-reset-' . wp_rand();
		$this->write_fixture( $name, '<?php return [ "version" => "first" ];' );
		$this->assertSame( 'first', Newspack::asset_version( $name ) );

		// Rewrite the same fixture with a new version, then reset.
		file_put_contents( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			$this->fixture_dir . $name . '.asset.php',
			'<?php return [ "version" => "second" ];'
		);
		Newspack::asset_version_reset_cache();
		$this->assertSame( 'second', Newspack::asset_version( $name ) );
	}
}
