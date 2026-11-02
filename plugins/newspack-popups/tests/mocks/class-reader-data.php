<?php
/**
 * Mock of the newspack-plugin Reader_Data class.
 *
 * Only get_matched_segments() is needed: the account-param arrival handler
 * reads a reader's last-known matching segment snapshot through it.
 *
 * Note this class is defined only inside the test files that require it, well
 * after plugin boot — so it cannot retroactively flip
 * Newspack_Popups::$segmentation_enabled, which is computed at boot.
 *
 * @package Newspack_Popups
 */

namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Reader_Data' ) ) {
	/**
	 * Minimal stand-in for the reader-data store.
	 */
	class Reader_Data {
		/**
		 * User ID => matching segment IDs.
		 *
		 * @var array
		 */
		public static $matched_segments = [];

		/**
		 * A reader's last-known matching segment IDs.
		 *
		 * @param int $user_id User ID.
		 * @return string[]
		 */
		public static function get_matched_segments( int $user_id ): array {
			return self::$matched_segments[ $user_id ] ?? [];
		}
	}
}
