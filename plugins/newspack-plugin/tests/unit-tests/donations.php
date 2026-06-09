<?php
/**
 * Tests Donations features.
 *
 * @package Newspack\Tests
 */

use Newspack\Donations;
use Newspack\WooCommerce_Products;

require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * Tests Donations features.
 */
class Newspack_Test_Donations extends WP_UnitTestCase {
	/**
	 * Settings.
	 */
	public function test_donations_settings_wc() {
		$donation_settings = Donations::get_donation_settings();
		self::assertTrue(
			is_wp_error( $donation_settings ),
			'Since WC is the default platform, donations settings return a WP error if WC plugins are not active.'
		);
		self::assertEquals(
			'wc',
			Donations::get_platform_slug(),
			'WC is the default donations platform.'
		);
	}

	/**
	 * Saving the WC platform while WooCommerce is not active must not fatal by
	 * attempting to write WooCommerce donation products. Regression: the platform
	 * chooser saves the 'wc' platform before WooCommerce is installed.
	 */
	public function test_update_payment_settings_wc_without_woocommerce() {
		$wizard  = new \Newspack\Audience_Wizard();
		$request = new \WP_REST_Request( 'POST', '/newspack/v1/wizard/newspack-audience/payment' );
		$request->set_param( 'platform', 'wc' );

		$response = $wizard->api_update_payment_settings( $request );

		self::assertInstanceOf(
			'WP_REST_Response',
			$response,
			'Saving the WC platform without WooCommerce active should return a response, not fatal.'
		);
	}

	/**
	 * Test that is_donation_product returns false for unflagged products.
	 *
	 * @group donations
	 */
	public function test_is_donation_product_unflagged() {
		$product_id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		self::assertFalse(
			Donations::is_donation_product( $product_id ),
			'Unflagged product should not be a donation product.'
		);
	}

	/**
	 * Test that is_donation_product returns true for products with _newspack_is_donation meta.
	 *
	 * @group donations
	 */
	public function test_is_donation_product_flagged() {
		$product_id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $product_id, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		self::assertTrue(
			Donations::is_donation_product( $product_id ),
			'Flagged product should be a donation product.'
		);
	}

	/**
	 * Test that is_donation_product returns false when meta is removed.
	 *
	 * @group donations
	 */
	public function test_is_donation_product_unflagged_after_removal() {
		$product_id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $product_id, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		delete_post_meta( $product_id, WooCommerce_Products::DONATION_FLAG_META_KEY );
		self::assertFalse(
			Donations::is_donation_product( $product_id ),
			'Product should not be a donation product after meta removal.'
		);
	}

	/**
	 * Test that a variation inherits the donation flag from its variable parent.
	 *
	 * @group donations
	 */
	public function test_is_donation_product_variation_inherits_from_parent() {
		$parent_id    = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$variation_id = self::factory()->post->create( [ 'post_type' => 'product_variation' ] );
		update_post_meta( $parent_id, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		wc_create_mock_product(
			[
				'id'        => $variation_id,
				'type'      => 'variation',
				'parent_id' => $parent_id,
			]
		);
		self::assertTrue(
			Donations::is_donation_product( $variation_id ),
			'Variation should inherit the donation flag from its flagged parent.'
		);
	}

	/**
	 * Test that a subscription_variation inherits the donation flag from its variable-subscription parent.
	 *
	 * @group donations
	 */
	public function test_is_donation_product_subscription_variation_inherits_from_parent() {
		$parent_id    = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$variation_id = self::factory()->post->create( [ 'post_type' => 'product_variation' ] );
		update_post_meta( $parent_id, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		wc_create_mock_product(
			[
				'id'        => $variation_id,
				'type'      => 'subscription_variation',
				'parent_id' => $parent_id,
			]
		);
		self::assertTrue(
			Donations::is_donation_product( $variation_id ),
			'Subscription variation should inherit the donation flag from its flagged parent.'
		);
	}

	/**
	 * Test that a variation does not resolve as a donation when its parent is unflagged.
	 *
	 * @group donations
	 */
	public function test_is_donation_product_variation_unflagged_parent() {
		$parent_id    = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$variation_id = self::factory()->post->create( [ 'post_type' => 'product_variation' ] );
		wc_create_mock_product(
			[
				'id'        => $variation_id,
				'type'      => 'variation',
				'parent_id' => $parent_id,
			]
		);
		self::assertFalse(
			Donations::is_donation_product( $variation_id ),
			'Variation with an unflagged parent should not be a donation product.'
		);
	}

	/**
	 * Test get_flagged_donation_product_ids returns flagged product IDs.
	 *
	 * @group donations
	 */
	public function test_get_flagged_donation_product_ids() {
		$product_1 = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$product_2 = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$product_3 = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $product_1, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		update_post_meta( $product_3, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );

		Donations::reset_flagged_donation_product_ids_cache();
		$flagged_ids = Donations::get_flagged_donation_product_ids();
		self::assertContains( $product_1, $flagged_ids, 'Flagged product 1 should be in the list.' );
		self::assertNotContains( $product_2, $flagged_ids, 'Unflagged product 2 should not be in the list.' );
		self::assertContains( $product_3, $flagged_ids, 'Flagged product 3 should be in the list.' );
	}
}
