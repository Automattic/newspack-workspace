<?php
/**
 * Tests Newspack_Blocks::asset_version().
 *
 * @package Newspack_Blocks
 */

/**
 * Newspack_Blocks::asset_version() reads the content hash the build writes to dist/<name>.asset.php,
 * and falls back to the plugin version when that file is missing, malformed,
 * or the name isn't entry-shaped.
 */
class Test_Newspack_Blocks_Asset_Version extends WP_UnitTestCase {

	/**
	 * Per-test temp dir for fixtures.
	 *
	 * @var string
	 */
	private $fixture_dir;

	/**
	 * Point the helper at a temp dir, so tests never write into dist/ and
	 * don't depend on a build being present.
	 */
	public function set_up() {
		parent::set_up();
		$this->fixture_dir = get_temp_dir() . 'newspack-blocks-asset-version-' . wp_rand() . '/';
		wp_mkdir_p( $this->fixture_dir );
		add_filter( 'newspack_blocks_asset_dist_dir', [ $this, 'filter_dist_dir' ] );
	}

	/**
	 * Remove the fixtures and the filter.
	 */
	public function tear_down() {
		remove_filter( 'newspack_blocks_asset_dist_dir', [ $this, 'filter_dist_dir' ] );
		foreach ( glob( $this->fixture_dir . '*.asset.php' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}
		if ( is_dir( $this->fixture_dir . 'sub' ) ) {
			rmdir( $this->fixture_dir . 'sub' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
		}
		rmdir( $this->fixture_dir ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
		parent::tear_down();
	}

	/**
	 * Filter callback for the helper's dist dir.
	 *
	 * @return string
	 */
	public function filter_dist_dir() {
		return $this->fixture_dir;
	}

	/**
	 * Write a fixture `.asset.php` file and return a unique asset name for it.
	 * Names are unique per call because the helper memoizes per request.
	 *
	 * @param string $contents PHP source for the fixture file.
	 * @return string Asset name.
	 */
	private function write_fixture( $contents ) {
		$name = 'fixture-' . wp_rand();
		file_put_contents( $this->fixture_dir . $name . '.asset.php', $contents ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		return $name;
	}

	/**
	 * The plugin version the helper falls back to.
	 *
	 * @return string
	 */
	private function fallback_version() {
		return NEWSPACK_BLOCKS__VERSION;
	}

	/**
	 * It returns the version from the asset file.
	 */
	public function test_returns_version_from_asset_file() {
		$name = $this->write_fixture( '<?php return [ "dependencies" => [], "version" => "abc123def456" ];' );
		$this->assertSame( 'abc123def456', Newspack_Blocks::asset_version( $name ) );
	}

	/**
	 * It falls back to the plugin version when the asset file doesn't exist.
	 */
	public function test_falls_back_when_missing() {
		$this->assertSame( $this->fallback_version(), Newspack_Blocks::asset_version( 'does-not-exist-' . wp_rand() ) );
	}

	/**
	 * It falls back to the plugin version when the asset file doesn't return
	 * an array with a string version.
	 */
	public function test_falls_back_when_malformed() {
		$malformed = [
			'<?php return "not-an-array";',
			'<?php return [ "dependencies" => [] ];',
			'<?php return [ "version" => [ "not", "a", "string" ] ];',
		];
		foreach ( $malformed as $contents ) {
			$this->assertSame( $this->fallback_version(), Newspack_Blocks::asset_version( $this->write_fixture( $contents ) ), $contents );
		}
	}

	/**
	 * It rejects names that aren't entry-shaped. Each unsafe name points at a
	 * fixture that exists on disk, so only the name check, not a missing file,
	 * can return the fallback.
	 */
	public function test_rejects_unsafe_names() {
		$contents = '<?php return [ "version" => "must-not-be-returned" ];';
		$name     = $this->write_fixture( $contents );
		wp_mkdir_p( $this->fixture_dir . 'sub' );
		file_put_contents( $this->fixture_dir . 'has spaces.asset.php', $contents ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		$unsafe = [
			'sub/../' . $name, // A `..` segment.
			'has spaces',      // A character outside the allowed set.
		];
		foreach ( $unsafe as $unsafe_name ) {
			$this->assertFileExists( $this->fixture_dir . $unsafe_name . '.asset.php', 'The fixture must resolve, or this case proves nothing.' );
			$this->assertSame( $this->fallback_version(), Newspack_Blocks::asset_version( $unsafe_name ), $unsafe_name );
		}
	}
}
