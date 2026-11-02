<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stubs for Jetpack's paywall.
 *
 * The newspack-blocks test suite runs without Jetpack loaded. These stubs let
 * the excerpt tests mark a post as gated through post meta alone.
 *
 * @package Newspack_Blocks
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test stub deliberately impersonates Jetpack's paywall callback.
namespace Automattic\Jetpack\Extensions\Subscriptions {
	if ( ! function_exists( __NAMESPACE__ . '\add_paywall' ) ) {
		/**
		 * Pass-through stand-in for Jetpack's the_content paywall. Core's own
		 * excerpt filter runs the_content, so a test that hooks this needs it to exist.
		 *
		 * @param string $content Post content.
		 * @return string Unchanged content.
		 */
		function add_paywall( $content ) {
			return $content;
		}
	}
}

namespace {
	if ( ! class_exists( 'Jetpack_Memberships' ) ) {
		/**
		 * Minimal stub of Jetpack's Jetpack_Memberships class.
		 */
		class Jetpack_Memberships {
			/**
			 * Mirror of the real get_post_access_level(): an empty or non-string value
			 * means the post is open to everybody.
			 *
			 * @param int $post_id Post ID.
			 * @return string Access level.
			 */
			public static function get_post_access_level( $post_id ) {
				$level = get_post_meta( $post_id, '_jetpack_newsletter_access', true );
				return ( ! empty( $level ) && is_string( $level ) ) ? $level : 'everybody';
			}
		}
	}
}
