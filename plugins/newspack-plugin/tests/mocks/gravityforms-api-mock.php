<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- mirrors the Gravity Forms class name.
/**
 * Stand-in for the Gravity Forms feed lookup, which is not part of the test
 * environment. Answers with the feeds a test has registered per form and
 * add-on, so feed-dependent handlers can be exercised without the plugin.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'GFAPI' ) ) {
	/**
	 * Minimal GFAPI.
	 */
	class GFAPI {
		/**
		 * Active feeds, keyed by form ID, then add-on slug.
		 *
		 * @var array
		 */
		public static $feeds = [];

		/**
		 * Active feeds for a form and add-on. Gravity Forms answers a WP_Error
		 * when none match, so this does too.
		 *
		 * @param mixed  $feed_ids   Unused.
		 * @param int    $form_id    Form ID.
		 * @param string $addon_slug Add-on slug.
		 * @param bool   $is_active  Unused.
		 *
		 * @return array|WP_Error
		 */
		public static function get_feeds( $feed_ids = null, $form_id = null, $addon_slug = null, $is_active = true ) {
			if ( empty( self::$feeds[ $form_id ][ $addon_slug ] ) ) {
				return new WP_Error( 'not_found', 'Feed not found' );
			}
			return self::$feeds[ $form_id ][ $addon_slug ];
		}
	}
}
