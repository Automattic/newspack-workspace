<?php
/**
 * Mock of the newspack-plugin reader-activation sync Metadata class.
 *
 * Resolves an unprefixed metadata raw key to the prefixed ESP field name.
 * Tests set $keys to control which raw keys resolve, mirroring the difference
 * between the v1 schema (raw key 'Account') and legacy (raw key 'account').
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
		 * Raw key => prefixed ESP field name.
		 *
		 * @var array
		 */
		public static $keys = [ 'Account' => 'NP_Account' ];

		/**
		 * Resolve a raw metadata key to its prefixed field name.
		 *
		 * @param string $key Raw metadata key.
		 * @return string|false Prefixed field name, or false when unknown.
		 */
		public static function get_key( $key ) {
			return self::$keys[ $key ] ?? false;
		}
	}
}
