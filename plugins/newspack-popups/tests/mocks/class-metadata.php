<?php
/**
 * Mock of the newspack-plugin reader-activation sync Metadata class.
 *
 * Holds the catalog of fields available to sync. Tests set $keys to control
 * which raw keys exist, mirroring the difference between the current schema
 * (raw key 'Account') and the legacy one (raw key 'account').
 *
 * @package Newspack_Popups
 */

namespace Newspack\Reader_Activation\Sync;

if ( ! class_exists( __NAMESPACE__ . '\Metadata' ) ) {
	/**
	 * Minimal stand-in for the reader-activation sync metadata helper.
	 */
	class Metadata {
		/**
		 * Raw key => field name, unprefixed.
		 *
		 * @var array
		 */
		public static $keys = [ 'Account' => 'Account' ];

		/**
		 * The catalog of fields available to sync.
		 *
		 * @return array Raw key => field name.
		 */
		public static function get_keys() {
			return self::$keys;
		}
	}
}
