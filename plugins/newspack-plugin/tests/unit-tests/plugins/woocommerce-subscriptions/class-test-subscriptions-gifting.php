<?php
/**
 * Tests the gift-holder helpers on the WooCommerce Subscriptions Gifting integration.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Subscriptions_Gifting;

require_once NEWSPACK_ABSPATH . 'tests/mocks/wcs-gifting-mock.php';

/**
 * Test who holds a gifted subscription: the recipient, not the purchaser.
 *
 * The WCS_Gifting stub is loaded for the whole suite, so the helpers' absent-class
 * path (class_exists guard) is guarded in code rather than exercised here.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscriptions_Gifting extends WP_UnitTestCase {

	/**
	 * Reset the gift store so one test's gifts never leak into the next.
	 */
	public function set_up() {
		parent::set_up();
		WCS_Gifting::$recipients = [];
	}

	/**
	 * Reset the gift store after the last test too.
	 */
	public function tear_down() {
		WCS_Gifting::$recipients = [];
		parent::tear_down();
	}

	/**
	 * A gifted subscription is held by its recipient, even though the purchaser
	 * is still the subscription's customer.
	 */
	public function test_get_recipient_id_returns_recipient_for_gift() {
		$purchaser_id  = $this->factory()->user->create();
		$recipient_id  = $this->factory()->user->create();
		$subscription  = wcs_create_subscription( [ 'customer_id' => $purchaser_id ] );
		WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;

		$this->assertSame( $recipient_id, WooCommerce_Subscriptions_Gifting::get_recipient_id( $subscription ), 'A gift names its recipient.' );
		$this->assertSame( $recipient_id, WooCommerce_Subscriptions_Gifting::get_holder_id( $subscription ), 'The recipient holds a gift.' );
	}

	/**
	 * A subscription that was not a gift has no recipient, so its purchaser holds it.
	 */
	public function test_get_recipient_id_returns_zero_for_non_gift() {
		$purchaser_id = $this->factory()->user->create();
		$subscription = wcs_create_subscription( [ 'customer_id' => $purchaser_id ] );

		$this->assertSame( 0, WooCommerce_Subscriptions_Gifting::get_recipient_id( $subscription ), 'A plain subscription has no recipient.' );
		$this->assertSame( $purchaser_id, WooCommerce_Subscriptions_Gifting::get_holder_id( $subscription ), 'The purchaser holds a plain subscription.' );
	}

	/**
	 * A gift whose recipient account no longer exists falls back to the purchaser,
	 * so the subscription never ends up with no holder.
	 */
	public function test_get_holder_id_falls_back_to_customer_when_recipient_missing() {
		$purchaser_id = $this->factory()->user->create();
		$subscription = wcs_create_subscription( [ 'customer_id' => $purchaser_id ] );
		WCS_Gifting::$recipients[ $subscription->get_id() ] = 999999;

		$this->assertSame( 0, WooCommerce_Subscriptions_Gifting::get_recipient_id( $subscription ), 'A missing recipient user is not a recipient.' );
		$this->assertSame( $purchaser_id, WooCommerce_Subscriptions_Gifting::get_holder_id( $subscription ), 'The purchaser holds the subscription when the recipient is gone.' );
	}
}
