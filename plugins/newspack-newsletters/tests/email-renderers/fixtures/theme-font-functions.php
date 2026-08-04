<?php
/**
 * Stand-in newspack-theme typography functions for tests.
 *
 * Mirrors themes/newspack-theme/newspack-theme/inc/typography.php so the Fonts
 * resolver's theme branch can be exercised when the real theme is not loaded in
 * the PHPUnit environment. Guarded by function_exists so requiring this file is
 * idempotent and so it never clobbers the real theme's functions.
 *
 * @package Newspack_Newsletters
 */

if ( ! function_exists( 'newspack_get_font_stacks' ) ) {
	/**
	 * Fallback font stacks (subset of the real theme's).
	 *
	 * @return array
	 */
	function newspack_get_font_stacks() {
		return [
			'serif'      => [ 'fonts' => [ 'Georgia', 'serif' ] ],
			'sans_serif' => [ 'fonts' => [ 'Helvetica', 'sans-serif' ] ],
		];
	}
}

if ( ! function_exists( 'newspack_font_stack' ) ) {
	/**
	 * Build a font-family stack from a primary font and a fallback id.
	 *
	 * @param string $primary     Primary font name.
	 * @param string $fallback_id Fallback stack id.
	 * @return string
	 */
	function newspack_font_stack( $primary, $fallback_id ) {
		$stacks = newspack_get_font_stacks();
		$fonts  = isset( $stacks[ $fallback_id ] ) ? $stacks[ $fallback_id ]['fonts'] : [];
		array_unshift( $fonts, $primary );
		foreach ( $fonts as &$font ) {
			$font = '"' . $font . '"';
		}
		return implode( ',', $fonts );
	}
}
