<?php
/**
 * Mock of the newspack-plugin reader-activation Integrations registry.
 *
 * Tests set $integrations to the integrations that are enabled and set up,
 * which is what the real registry's accessor returns.
 *
 * @package Newspack_Popups
 */

namespace Newspack\Reader_Activation;

if ( ! class_exists( __NAMESPACE__ . '\Integrations' ) ) {
	/**
	 * Minimal stand-in for the integrations registry.
	 */
	class Integrations {
		/**
		 * Enabled, set-up integrations, keyed by ID.
		 *
		 * @var object[]
		 */
		public static $integrations = [];

		/**
		 * The integrations that are enabled and set up.
		 *
		 * @return object[]
		 */
		public static function get_active_configured_integrations() {
			return self::$integrations;
		}
	}
}
