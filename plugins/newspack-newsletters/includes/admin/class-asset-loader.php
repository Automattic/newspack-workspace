<?php
/**
 * Asset enqueue helper.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shared `@wordpress/scripts` bundle enqueue helper — reads
 * `<basename>.asset.php` for deps + version, then enqueues `.js` and
 * (when present) `.css` under the given handle.
 */
class Asset_Loader {
	/**
	 * Read `<build_dir>/<basename>.asset.php` and enqueue the matching
	 * `<basename>.js` + `<basename>.css` under the given handle.
	 *
	 * The WP handle and the on-disk basename are independent — webpack
	 * keys bundles by entry name, WP wants a plugin-prefixed handle.
	 *
	 * @param string $handle            WP script + style handle.
	 * @param string $basename          File stem matching the webpack entry.
	 * @param string $build_dir         Filesystem path to the dist directory.
	 * @param string $url_dir           Public URL prefix matching `$build_dir`.
	 * @param array  $extra_script_deps Handles to merge into the script deps.
	 * @param array  $extra_style_deps  Handles to merge into the style deps.
	 * @return array|null Asset metadata, or null when `asset.php` is missing
	 *                   or malformed (non-array, or missing/non-array
	 *                   `dependencies` / `version`).
	 */
	public static function enqueue_bundle(
		string $handle,
		string $basename,
		string $build_dir,
		string $url_dir,
		array $extra_script_deps = [],
		array $extra_style_deps = []
	): ?array {
		$asset_path = trailingslashit( $build_dir ) . $basename . '.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return null;
		}
		$asset = require $asset_path;

		// Bail on malformed asset.php rather than fatal in array_merge() or feed wp_enqueue_* an invalid version.
		if (
			! is_array( $asset )
			|| ! isset( $asset['dependencies'], $asset['version'] )
			|| ! is_array( $asset['dependencies'] )
			|| ! is_string( $asset['version'] )
		) {
			return null;
		}

		$script_deps = array_values(
			array_unique( array_merge( $asset['dependencies'], $extra_script_deps ) )
		);

		wp_enqueue_script(
			$handle,
			trailingslashit( $url_dir ) . $basename . '.js',
			$script_deps,
			$asset['version'],
			true
		);

		$style_path = trailingslashit( $build_dir ) . $basename . '.css';
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				$handle,
				trailingslashit( $url_dir ) . $basename . '.css',
				$extra_style_deps,
				$asset['version']
			);
		}

		return $asset;
	}
}
