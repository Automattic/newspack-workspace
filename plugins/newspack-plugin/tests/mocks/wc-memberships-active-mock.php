<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, WordPress.NamingConventions.PrefixAllGlobals, Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Classes.DuplicateClassName
/**
 * Minimal stand-in for an active WooCommerce Memberships install, so
 * Newspack\Memberships::is_active() (class_exists + function_exists) returns
 * true. Global namespace on purpose — that is what is_active() checks.
 *
 * Require this only from isolated (separate-process) tests: declaring these for
 * the whole suite would flip is_active() true everywhere and break every test
 * that assumes Memberships is inactive.
 *
 * @package Newspack\Tests
 */

require_once __DIR__ . '/wc-memberships-class-mock.php';

if ( ! function_exists( 'wc_memberships' ) ) {
	function wc_memberships() {
		// The real function returns the plugin instance; a stub object keeps any
		// caller that dereferences it from triggering a fatal error, though
		// is_active() only needs function_exists().
		return new WC_Memberships();
	}
}
