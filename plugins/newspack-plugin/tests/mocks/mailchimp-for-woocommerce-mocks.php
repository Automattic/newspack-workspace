<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Stand-in for the Mailchimp for WooCommerce class that renders and saves the My Account
 * opt-in. Carries the real class name and singleton, so the integration removes the same
 * callback the plugin hooks.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'MailChimp_WooCommerce_Public' ) ) {
	/**
	 * Mailchimp for WooCommerce's public-facing class.
	 */
	class MailChimp_WooCommerce_Public {
		/**
		 * Singleton, as in the plugin.
		 *
		 * @var MailChimp_WooCommerce_Public|null
		 */
		private static $instance = null;

		/**
		 * Get the singleton.
		 */
		public static function instance() {
			if ( ! self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Renders the opt-in radio in the plugin.
		 */
		public function user_my_account_opt_in() {}
	}
}
