<?php
/**
 * Stand-in for newspack-theme's `newspack_sanitize_post_subtitle()`, which is theme
 * code and is not loaded here. Shared by the `backfill-post-subtitles` CLI test, which
 * is about which rows the command reads, writes, and reports — not about what the
 * sanitizer does to a given string, so a minimal stub with the theme's own allow-list
 * stands in for it.
 *
 * @package Newspack\Tests
 */

if ( ! function_exists( 'newspack_sanitize_post_subtitle' ) ) {
	/**
	 * Limits a subtitle to the same inline tags newspack-theme allows.
	 *
	 * @param string $subtitle Raw subtitle.
	 * @return string Subtitle limited to the allowed inline tags.
	 */
	function newspack_sanitize_post_subtitle( $subtitle ) {
		return wp_kses(
			(string) $subtitle,
			[
				'b'      => true,
				'strong' => true,
				'i'      => true,
				'em'     => true,
				'mark'   => true,
				'u'      => true,
				'small'  => true,
				'sub'    => true,
				'sup'    => true,
				'a'      => [
					'href'   => true,
					'target' => true,
					'rel'    => true,
				],
			]
		);
	}
}
