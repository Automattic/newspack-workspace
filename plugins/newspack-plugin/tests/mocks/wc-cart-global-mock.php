<?php // phpcs:ignoreFile
/**
 * A global-namespace WC() stub with a real cart, for the one test that needs
 * WC()->cart to hold actual product instances rather than the ids the
 * newspack_subscriber_discounts_cart_product_ids filter otherwise supplies.
 *
 * Declared with the bracketed `namespace { }` form so the function lands in the
 * global namespace even though it is require_once'd from a namespaced test
 * file — production code calls the bare `WC()`, which resolves to the global
 * function, not one declared inside the caller's own namespace.
 *
 * Only ever require this from a test annotated `@runInSeparateProcess`:
 * defining WC() in the main suite process would flip every other test's
 * `function_exists( 'WC' )` gate for the rest of the run.
 *
 * @package Newspack\Tests
 */

namespace {
	if ( ! function_exists( 'WC' ) ) {
		/**
		 * Mock WC() function returning a controllable container.
		 *
		 * @return object
		 */
		function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce global, isolated to a separate test process.
			global $newspack_test_wc;
			if ( empty( $newspack_test_wc ) ) {
				$newspack_test_wc = new class() {
					/**
					 * Cart double, set by tests.
					 *
					 * @var object|null
					 */
					public $cart = null;
				};
			}
			return $newspack_test_wc;
		}
	}
}
