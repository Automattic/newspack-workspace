<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing

namespace Newspack;

if ( ! class_exists( Data_Events::class ) ) {
	/**
	 * Recording mock of Newspack\Data_Events (newspack-plugin), used when
	 * newspack-plugin is not loaded in the test environment.
	 *
	 * register_handler() exists so the method_exists() gate in the blocks
	 * tracking integration passes; register_listener() records instead of
	 * wiring hooks, so tests can assert what was registered and invoke the
	 * recorded callables directly.
	 */
	class Data_Events {
		/**
		 * Recorded register_listener() calls, in order.
		 *
		 * @var array
		 */
		public static $registered_listeners = [];

		/**
		 * Gate-passing no-op matching the real static signature.
		 *
		 * @param callable $handler     Handler.
		 * @param string   $action_name Action name.
		 */
		public static function register_handler( $handler, $action_name = null ) {}

		/**
		 * Record a listener registration.
		 *
		 * @param string   $hook_name   Hook name.
		 * @param string   $action_name Data event action name.
		 * @param callable $callable    Listener callable.
		 */
		public static function register_listener( $hook_name, $action_name, $callable = null ) {
			self::$registered_listeners[] = [
				'hook'     => $hook_name,
				'action'   => $action_name,
				'callable' => $callable,
			];
		}
	}
}

if ( ! class_exists( WooCommerce_My_Account::class ) ) {
	/**
	 * Minimal mock of Newspack\WooCommerce_My_Account (newspack-plugin), used when
	 * newspack-plugin is not loaded in the test environment.
	 *
	 * The $is_from_my_account flag defaults to false so that code paths guarded by
	 * method_exists + is_from_my_account() behave the same as when the class is
	 * absent. Tests that need a My Account request set the flag and the shared
	 * set_up() resets it.
	 */
	class WooCommerce_My_Account {
		public static $is_from_my_account = false;
		public static function is_from_my_account() {
			return self::$is_from_my_account;
		}
	}
}
