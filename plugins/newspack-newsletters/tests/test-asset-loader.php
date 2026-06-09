<?php
/**
 * Class Test Asset Loader
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Asset_Loader;

/**
 * Tests the shared `@wordpress/scripts` bundle enqueue helper.
 *
 * Webpack emits bundles keyed on the entry name (e.g. `admin-shell`),
 * while WP enqueues conventionally use a plugin-prefixed handle (e.g.
 * `newspack-newsletters-admin-shell`). Conflating the two was a P1
 * regression on this helper's first cut — the `handle !== basename`
 * test below guards against it.
 */
class Asset_Loader_Test extends WP_UnitTestCase {
	/**
	 * Per-test fixture directory created under sys_get_temp_dir().
	 *
	 * @var string|null
	 */
	private $build_dir;

	/**
	 * Allocate a clean fixture dir per test.
	 */
	public function set_up() {
		parent::set_up();
		$this->build_dir = trailingslashit( sys_get_temp_dir() ) . 'asset-loader-' . uniqid();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir -- Test fixture lives under sys_get_temp_dir(); VIP's no-disk-writes rule doesn't apply to test scaffolding.
		mkdir( $this->build_dir, 0777, true );
	}

	/**
	 * Wipe the fixture dir + dequeue anything the test left registered.
	 * `WP_UnitTestCase` doesn't reset `wp_scripts` / `wp_styles` between
	 * tests, so without an explicit reset a handle registered by one
	 * test would short-circuit subsequent `wp_enqueue_script` calls
	 * with different dependencies.
	 */
	public function tear_down() {
		if ( $this->build_dir && is_dir( $this->build_dir ) ) {
			foreach ( glob( trailingslashit( $this->build_dir ) . '*' ) as $f ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Per-test fixture file under sys_get_temp_dir().
				unlink( $f );
			}
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Cleaning up the per-test fixture dir created in set_up().
			rmdir( $this->build_dir );
		}
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		parent::tear_down();
	}

	/**
	 * Write a fixture `<basename>.asset.php` carrying the given
	 * dependencies + version.
	 *
	 * @param string $basename     Bundle file stem.
	 * @param array  $dependencies Script dependencies declared by webpack.
	 * @param string $version      Content hash / version string.
	 */
	private function write_asset_file( $basename, $dependencies = [], $version = 'test-v1' ) {
		$path     = trailingslashit( $this->build_dir ) . $basename . '.asset.php';
		$deps_php = '[' . implode(
			', ',
			array_map(
				static function ( $dep ) {
					return "'" . $dep . "'";
				},
				$dependencies
			)
		) . ']';
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Per-test fixture asset.php under sys_get_temp_dir().
		file_put_contents(
			$path,
			'<?php return [ \'dependencies\' => ' . $deps_php . ', \'version\' => \'' . $version . '\' ];'
		);
	}

	/**
	 * Touch a sibling fixture file (`.js` / `.css`) next to an asset.php.
	 *
	 * @param string $basename Bundle file stem.
	 * @param string $ext      Extension without the leading dot.
	 */
	private function touch_bundle_file( $basename, $ext ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Per-test fixture js/css under sys_get_temp_dir().
		file_put_contents( trailingslashit( $this->build_dir ) . $basename . '.' . $ext, '' );
	}

	/**
	 * REGRESSION: the handle and the bundle basename are independent.
	 * The first cut of this helper used `$handle` as the file stem,
	 * so any caller passing a plugin-prefixed WP handle would silently
	 * skip the entire enqueue once dist/ existed in production.
	 */
	public function test_enqueues_script_and_style_from_basename_under_handle() {
		$this->write_asset_file( 'my-bundle', [ 'wp-element' ], 'test-v1' );
		$this->touch_bundle_file( 'my-bundle', 'js' );
		$this->touch_bundle_file( 'my-bundle', 'css' );

		$asset = Asset_Loader::enqueue_bundle(
			'my-prefix-my-bundle',
			'my-bundle',
			$this->build_dir,
			'https://example.test/dist'
		);

		$this->assertSame( [ 'wp-element' ], $asset['dependencies'] );
		$this->assertSame( 'test-v1', $asset['version'] );
		$this->assertTrue( wp_script_is( 'my-prefix-my-bundle', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'my-prefix-my-bundle', 'enqueued' ) );
		// And the inverse: nothing registered under the basename alone.
		$this->assertFalse( wp_script_is( 'my-bundle', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'my-bundle', 'enqueued' ) );
	}

	/**
	 * No asset.php → return null, no enqueues happen, caller can early-out.
	 */
	public function test_returns_null_and_skips_enqueue_when_asset_missing() {
		$asset = Asset_Loader::enqueue_bundle(
			'my-prefix-my-bundle',
			'my-bundle',
			$this->build_dir,
			'https://example.test/dist'
		);

		$this->assertNull( $asset );
		$this->assertFalse( wp_script_is( 'my-prefix-my-bundle', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'my-prefix-my-bundle', 'enqueued' ) );
	}

	/**
	 * Malformed asset.php returning a non-array → bail without enqueueing.
	 */
	public function test_returns_null_when_asset_php_returns_non_array() {
		$path = trailingslashit( $this->build_dir ) . 'my-bundle.asset.php';
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Per-test fixture asset.php under sys_get_temp_dir().
		file_put_contents( $path, '<?php return false;' );
		$this->touch_bundle_file( 'my-bundle', 'js' );

		$asset = Asset_Loader::enqueue_bundle(
			'my-prefix-my-bundle',
			'my-bundle',
			$this->build_dir,
			'https://example.test/dist'
		);

		$this->assertNull( $asset );
		$this->assertFalse( wp_script_is( 'my-prefix-my-bundle', 'enqueued' ) );
	}

	/**
	 * Missing `dependencies` key → bail without enqueueing.
	 */
	public function test_returns_null_when_dependencies_key_missing() {
		$path = trailingslashit( $this->build_dir ) . 'my-bundle.asset.php';
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Per-test fixture asset.php under sys_get_temp_dir().
		file_put_contents( $path, "<?php return [ 'version' => 'v1' ];" );
		$this->touch_bundle_file( 'my-bundle', 'js' );

		$asset = Asset_Loader::enqueue_bundle(
			'my-prefix-my-bundle',
			'my-bundle',
			$this->build_dir,
			'https://example.test/dist'
		);

		$this->assertNull( $asset );
		$this->assertFalse( wp_script_is( 'my-prefix-my-bundle', 'enqueued' ) );
	}

	/**
	 * Non-array `dependencies` value → bail without enqueueing.
	 */
	public function test_returns_null_when_dependencies_value_is_not_array() {
		$path = trailingslashit( $this->build_dir ) . 'my-bundle.asset.php';
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Per-test fixture asset.php under sys_get_temp_dir().
		file_put_contents( $path, "<?php return [ 'dependencies' => null, 'version' => 'v1' ];" );
		$this->touch_bundle_file( 'my-bundle', 'js' );

		$asset = Asset_Loader::enqueue_bundle(
			'my-prefix-my-bundle',
			'my-bundle',
			$this->build_dir,
			'https://example.test/dist'
		);

		$this->assertNull( $asset );
		$this->assertFalse( wp_script_is( 'my-prefix-my-bundle', 'enqueued' ) );
	}

	/**
	 * Non-string `version` value → bail; wp_enqueue_* can't use it safely.
	 */
	public function test_returns_null_when_version_value_is_not_string() {
		$path = trailingslashit( $this->build_dir ) . 'my-bundle.asset.php';
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Per-test fixture asset.php under sys_get_temp_dir().
		file_put_contents( $path, "<?php return [ 'dependencies' => [], 'version' => [ 'v1' ] ];" );
		$this->touch_bundle_file( 'my-bundle', 'js' );

		$asset = Asset_Loader::enqueue_bundle(
			'my-prefix-my-bundle',
			'my-bundle',
			$this->build_dir,
			'https://example.test/dist'
		);

		$this->assertNull( $asset );
		$this->assertFalse( wp_script_is( 'my-prefix-my-bundle', 'enqueued' ) );
	}

	/**
	 * Caller-supplied script deps merge with the asset.php's; CSS gets
	 * the explicit style deps. Duplicates collapse via array_unique so
	 * `wp-element` doesn't appear twice.
	 */
	public function test_merges_extra_deps_with_asset_dependencies() {
		$this->write_asset_file( 'my-bundle', [ 'wp-element', 'wp-i18n' ] );
		$this->touch_bundle_file( 'my-bundle', 'js' );
		$this->touch_bundle_file( 'my-bundle', 'css' );

		Asset_Loader::enqueue_bundle(
			'my-prefix-my-bundle',
			'my-bundle',
			$this->build_dir,
			'https://example.test/dist',
			[ 'wp-data', 'wp-element' ], // wp-element is a duplicate of the asset.php dep.
			[ 'wp-edit-blocks' ]
		);

		$script = wp_scripts()->registered['my-prefix-my-bundle'];
		$this->assertSame( [ 'wp-element', 'wp-i18n', 'wp-data' ], $script->deps );

		$style = wp_styles()->registered['my-prefix-my-bundle'];
		$this->assertSame( [ 'wp-edit-blocks' ], $style->deps );
	}

	/**
	 * No `<basename>.css` next to the asset.php → script enqueues,
	 * style skips entirely (no empty style registered).
	 */
	public function test_skips_style_when_css_missing() {
		$this->write_asset_file( 'my-bundle' );
		$this->touch_bundle_file( 'my-bundle', 'js' );

		Asset_Loader::enqueue_bundle(
			'my-prefix-my-bundle',
			'my-bundle',
			$this->build_dir,
			'https://example.test/dist'
		);

		$this->assertTrue( wp_script_is( 'my-prefix-my-bundle', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'my-prefix-my-bundle', 'registered' ) );
	}

	/**
	 * Public URLs are composed from `<url_dir>/<basename>.js`/.css —
	 * independent of the WP handle.
	 */
	public function test_composes_urls_from_basename_not_handle() {
		$this->write_asset_file( 'my-bundle' );
		$this->touch_bundle_file( 'my-bundle', 'js' );
		$this->touch_bundle_file( 'my-bundle', 'css' );

		Asset_Loader::enqueue_bundle(
			'my-prefix-my-bundle',
			'my-bundle',
			$this->build_dir,
			'https://example.test/dist'
		);

		$this->assertSame(
			'https://example.test/dist/my-bundle.js',
			wp_scripts()->registered['my-prefix-my-bundle']->src
		);
		$this->assertSame(
			'https://example.test/dist/my-bundle.css',
			wp_styles()->registered['my-prefix-my-bundle']->src
		);
	}
}
