<?php
/**
 * Tests the My Account view-order customer details for gifted subscriptions.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account_UI_V1;

require_once __DIR__ . '/../../mocks/wc-mocks.php';
require_once __DIR__ . '/../../mocks/wcsg-recipient-addresses-mock.php';

/**
 * From 9.4, WooCommerce Subscriptions hides a gift recipient's address from
 * the purchaser by swapping the view-order customer details template.
 * Newspack's own template must not replace it.
 *
 * @group My_Account
 */
class Newspack_Test_My_Account_Gifted_Order_Details extends WP_UnitTestCase {
	const GIFTING_TEMPLATE = '/wcs/templates/gifting/purchaser-order-details-customer.php';

	/**
	 * Reset the stubs.
	 */
	public function set_up() {
		parent::set_up();
		WCSG_Recipient_Addresses::$withheld   = [];
		$GLOBALS['newspack_test_wc_endpoint'] = 'view-order';
	}

	/**
	 * Reset the shared stubs for later test classes.
	 */
	public function tear_down() {
		WCSG_Recipient_Addresses::$withheld = [];
		unset( $GLOBALS['newspack_test_wc_endpoint'] );
		parent::tear_down();
	}

	/**
	 * Resolve the customer details template for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function resolve( $order ) {
		return My_Account_UI_V1::wc_get_template( self::GIFTING_TEMPLATE, 'order/order-details-customer.php', [ 'order' => $order ] );
	}

	/**
	 * A renewal whose address is withheld from the purchaser keeps Gifting's template.
	 */
	public function test_withheld_address_keeps_gifting_template() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$order = new WC_Order(
			[
				'id'     => 7120,
				'status' => 'completed',
			] 
		);
		WCSG_Recipient_Addresses::$withheld[ $order->get_id() ] = [ $user_id ];

		self::assertSame( self::GIFTING_TEMPLATE, $this->resolve( $order ) );
	}

	/**
	 * Any other order on view-order gets Newspack's template.
	 */
	public function test_other_orders_use_newspack_template() {
		$order = new WC_Order(
			[
				'id'     => 7122,
				'status' => 'completed',
			] 
		);

		self::assertStringEndsWith( '/templates/v1/order-details-customer.php', $this->resolve( $order ) );
	}
}
