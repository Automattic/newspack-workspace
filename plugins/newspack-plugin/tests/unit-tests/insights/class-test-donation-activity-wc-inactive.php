<?php
/**
 * Regression: the Insights wizard boot-config path must not fatal when
 * WooCommerce core is inactive.
 *
 * With NEWSPACK_INSIGHTS_ENABLED true but WooCommerce inactive, loading
 * wp-admin used to fatal with "Call to undefined function wc_get_product()"
 * because the donation-activity gate runs the Donation_Product_Classifier,
 * which threads through Donations::get_donation_product_child_products_ids()
 * -> get_parent_donation_product() -> wc_get_product().
 *
 * These tests run in a separate process that intentionally does NOT load the
 * wc-mocks shim, so wc_get_product() is genuinely undefined — exactly the
 * WooCommerce-core-inactive condition. The fix guards the WooCommerce path
 * and treats "no donation activity" as the safe default.
 *
 * @package Newspack
 */

use Newspack\Donations;
use Newspack\Insights_Wizard;

/**
 * Boot-config degradation with WooCommerce inactive.
 *
 * @group insights
 * @group donations
 */
class Test_Donation_Activity_WC_Inactive extends WP_UnitTestCase {

	/**
	 * The donation-product child lookup degrades to the safe all-false
	 * default — rather than fataling — when WooCommerce is inactive.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_child_products_lookup_without_woocommerce() {
		if ( function_exists( 'wc_get_product' ) ) {
			self::markTestSkipped( 'wc_get_product() is defined; cannot exercise the WooCommerce-inactive path.' );
		}

		// Configure a donation parent ID so the unguarded code path would
		// have reached wc_get_product() and fataled.
		update_option( Donations::DONATION_PRODUCT_ID_OPTION, 123 );

		$children = Donations::get_donation_product_child_products_ids();

		self::assertSame(
			[
				'once'  => false,
				'month' => false,
				'year'  => false,
			],
			$children,
			'With WooCommerce inactive, the donation child-product lookup must return the safe all-false default.'
		);
	}

	/**
	 * The Insights donation-activity gate (the boot-config path that
	 * enqueue_scripts_and_styles() runs on every admin page load) computes
	 * "no donation activity" without fataling when WooCommerce is inactive.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_donation_activity_gate_without_woocommerce() {
		if ( function_exists( 'wc_get_product' ) ) {
			self::markTestSkipped( 'wc_get_product() is defined; cannot exercise the WooCommerce-inactive path.' );
		}

		// No donation product is configured — the realistic state when
		// WooCommerce core is inactive. The unguarded code fataled here
		// regardless, because get_parent_donation_product() called
		// wc_get_product( 0 ) before this method could short-circuit.
		//
		// force_refresh_donation_activity() runs the same classifier ->
		// compute_donation_activity() chain that get_boot_config() relies on.
		// Reaching the assertion at all proves the path did not fatal.
		$has_activity = Insights_Wizard::force_refresh_donation_activity();

		self::assertFalse(
			$has_activity,
			'With WooCommerce inactive there are no donation products, so the activity gate must report no activity.'
		);
	}
}
