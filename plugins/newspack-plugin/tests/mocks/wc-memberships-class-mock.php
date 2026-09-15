<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.ClassComment.Missing, WordPress.NamingConventions.PrefixAllGlobals
/**
 * The `WC_Memberships` class alone, in the global namespace, which is where
 * Newspack\Memberships::is_active() looks for it.
 *
 * Two mock files need it and neither can hold it: `wc-memberships-mocks.php`
 * brings a whole membership store with it, and `wc-memberships-active-mock.php`
 * is deliberately minimal for isolated tests. Declaring it in both is a
 * duplicate class name, which PHPCS reports and CI treats as a failure.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'WC_Memberships' ) ) {
	class WC_Memberships {}
}
