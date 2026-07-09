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
 */
class Admin_List_Table_Layout {

	/**
	 * Screens treated by default. Each entry is a post_type, taxonomy, or screen id.
	 * Overridable (add or remove) via the `newspack_admin_autolayout_screens` filter.
	 */
	const DEFAULT_SCREENS = [ 'post', 'page' ];

	/**
	 * Default primary-column min-width floor. `35ch` ≈ 300px at the default admin
	 * font and scales with it. Overridable via `newspack_admin_primary_column_min_width`.
	 */
	const DEFAULT_MIN_WIDTH = '35ch';

	/**
	 * Allowed min-width syntax: a positive, non-zero integer plus a length unit.
	 * Zero is excluded so a `0px`/`0ch` filter value can't defeat the floor;
	 * percentage is excluded (ignored on table cells); the `D` flag anchors the
	 * end so a trailing newline is rejected too.
	 */
	const MIN_WIDTH_PATTERN = '/^[1-9]\d*(?:px|ch|rem)$/D';

	/**
	 * Extra screens registered on top of DEFAULT_SCREENS.
	 *
	 * @var string[]
	 */
	private static $registered = [];

	/**
	 * Hook style output onto list-table screens.
	 */
	public static function init() {
		add_action( 'admin_head-edit.php', [ __CLASS__, 'render_styles' ] );
		add_action( 'admin_head-edit-tags.php', [ __CLASS__, 'render_styles' ] );
	}

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

	/**
	 * Resolve the validated primary-column min-width floor.
	 *
	 * @return string A valid CSS length (px|ch|rem); the default on invalid input.
	 */
	public static function get_min_width() {
		/**
		 * Filters the primary (Title) column min-width floor on treated screens.
		 *
		 * @param string $min_width A CSS length: px, ch, or rem (default '35ch').
		 */
		$min_width = apply_filters( 'newspack_admin_primary_column_min_width', self::DEFAULT_MIN_WIDTH );
		return preg_match( self::MIN_WIDTH_PATTERN, (string) $min_width ) ? (string) $min_width : self::DEFAULT_MIN_WIDTH;
	}

	/**
	 * Build the CSS for a screen. Empty string unless the screen is treated.
	 *
	 * @param \WP_Screen $screen Screen to build CSS for.
	 * @return string CSS block (no <style> wrapper); '' when not treated.
	 */
	public static function get_styles_for_screen( $screen ) {
		if ( ! self::screen_matches( $screen ) ) {
			return '';
		}
		$min_width = self::get_min_width();
		return "@media screen and (min-width: 783px) {\n"
			. "\t.wp-list-table.fixed { table-layout: auto; }\n"
			. "\t.wp-list-table th.column-primary,\n"
			. "\t.wp-list-table td.column-primary { min-width: " . $min_width . "; }\n"
			. '}';
	}

	/**
	 * Echo the scoped <style> block for the current screen.
	 */
	public static function render_styles() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$styles = self::get_styles_for_screen( $screen );
		if ( '' === $styles ) {
			return;
		}
		// Fixed literal CSS plus an allowlist-validated length (get_min_width) — safe.
		echo "<style id=\"newspack-admin-list-table-layout\">\n" . $styles . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
Admin_List_Table_Layout::init();
