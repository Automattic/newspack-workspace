<?php
/**
 * Admin list-table layout legibility.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps Newspack's admin list-table screens legible when many plugins add
 * columns and over-subscribe the fixed-layout table (collapsing Title to 0).
 * Switches those screens to `table-layout: auto` and floors the primary column.
 *
 * See plans/2026-07-07-admin-column-widths-autolayout-design.md.
 */
class Admin_List_Table_Layout {

	/**
	 * Screens treated by default. Each entry is a post_type, taxonomy, or screen id.
	 * Overridable (add or remove) via the `newspack_admin_autolayout_screens` filter.
	 *
	 * @var string[]
	 */
	const DEFAULT_SCREENS = [ 'post', 'page' ];

	/**
	 * Default primary-column min-width floor. `35ch` ≈ 300px at the default admin
	 * font and scales with it. Overridable via `newspack_admin_primary_column_min_width`.
	 */
	const DEFAULT_MIN_WIDTH = '35ch';

	/**
	 * Allowed min-width syntax: a positive integer plus a length unit. Percentage
	 * is intentionally excluded — it is ignored on table cells.
	 */
	const MIN_WIDTH_PATTERN = '/^\d+(?:px|ch|rem)$/';

	/**
	 * Extra screens registered on top of DEFAULT_SCREENS.
	 *
	 * @var string[]
	 */
	private static $registered = [];

	/**
	 * Register an additional screen for the auto-layout treatment.
	 *
	 * @param string $key A post_type, taxonomy, or screen id.
	 */
	public static function register_screen( $key ) {
		if ( is_string( $key ) && '' !== $key ) {
			self::$registered[] = $key;
		}
	}

	/**
	 * The full set of treated screen keys: defaults + registered, then filtered.
	 *
	 * @return string[]
	 */
	public static function get_screens() {
		$screens = array_merge( self::DEFAULT_SCREENS, self::$registered );

		/**
		 * Filters the admin list-table screens that receive the auto-layout
		 * treatment. May add or remove entries, including the `post`/`page`
		 * defaults.
		 *
		 * @param string[] $screens Screen keys (post_type, taxonomy, or screen id).
		 */
		$screens = apply_filters( 'newspack_admin_autolayout_screens', array_values( array_unique( $screens ) ) );

		return array_values( array_filter( (array) $screens ) );
	}

	/**
	 * Whether a screen is treated. Base-gated: a `post` key matches the Posts
	 * list (`edit.php`, base `edit`) but never the Categories/Tags term screens
	 * (`edit-tags.php`, base `edit-tags`) which also carry `post_type=post`.
	 *
	 * @param \WP_Screen $screen Screen to test.
	 * @return bool
	 */
	public static function screen_matches( $screen ) {
		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}
		$keys = self::get_screens();
		if ( empty( $keys ) ) {
			return false;
		}
		$candidates = [ isset( $screen->id ) ? $screen->id : '' ];
		if ( 'edit' === $screen->base ) {
			$candidates[] = isset( $screen->post_type ) ? $screen->post_type : '';
		} elseif ( 'edit-tags' === $screen->base ) {
			$candidates[] = isset( $screen->taxonomy ) ? $screen->taxonomy : '';
		}
		$candidates = array_filter( $candidates );
		return (bool) array_intersect( $keys, $candidates );
	}
}
