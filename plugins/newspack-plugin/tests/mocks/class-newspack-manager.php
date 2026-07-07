<?php
/**
 * Test-only stand-in for `Newspack_Manager`, which lives in the separate
 * (non-monorepo) newspack-manager-admin plugin. `BigQuery_Proxy_Client`
 * resolves its hub URL/API key against this class (in the global namespace)
 * when no explicit endpoint/key are injected into its constructor.
 *
 * Defaults to "not connected" — `authenticate_manager_admin_url()` returns
 * `false`, matching the real class's contract for an unauthenticated site, so
 * `BigQuery_Proxy_Client::is_configured()` stays false and every test that
 * doesn't explicitly arm this stub keeps exercising the (already-handled)
 * not-configured code paths. Call `enable_stub_connection()` /
 * `disable_stub_connection()` around the one or two tests per file that need
 * a live-looking hub round-trip.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( '\Newspack_Manager' ) ) {
	/**
	 * Minimal Newspack_Manager stub, off (not connected) by default.
	 */
	class Newspack_Manager {

		/**
		 * Whether the stub reports as connected. Toggled per-test.
		 *
		 * @var bool
		 */
		private static $connected = false;

		/**
		 * Arm the stub: subsequent calls report a fixed hub URL + key.
		 *
		 * @return void
		 */
		public static function enable_stub_connection() {
			self::$connected = true;
		}

		/**
		 * Disarm the stub: subsequent calls report "not connected" (matches the
		 * default / real class's contract when unauthenticated).
		 *
		 * @return void
		 */
		public static function disable_stub_connection() {
			self::$connected = false;
		}

		/**
		 * Stubbed hub URL, or false when not "connected".
		 *
		 * @param string $path Route path appended to the stub host.
		 * @return string|false
		 */
		public static function authenticate_manager_admin_url( $path ) {
			if ( ! self::$connected ) {
				return false;
			}
			return 'https://hub.example.com' . $path . '?api_key=test-key';
		}

		/**
		 * Stubbed API key, empty when not "connected".
		 *
		 * @return string
		 */
		public static function get_manager_admin_api_key() {
			return self::$connected ? 'test-key' : '';
		}
	}
}
