<?php
/**
 * Stub for the Newspack plugin's Reader_Activation class used in tests.
 *
 * @package Newspack_Network
 */

namespace Newspack;

if ( ! class_exists( 'Newspack\Reader_Activation' ) ) {
	/**
	 * Stub for Reader_Activation.
	 */
	class Reader_Activation {
		const REGISTRATION_METHOD = 'np_reader_registration_method';

		/**
		 * Stub for get_reader_roles.
		 *
		 * @return string[]
		 */
		public static function get_reader_roles() {
			return [ 'subscriber' ];
		}
	}
}
