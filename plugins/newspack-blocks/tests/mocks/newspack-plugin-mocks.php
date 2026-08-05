<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing

namespace Newspack;

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
