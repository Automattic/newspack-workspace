<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests the My Account view-order customer details for gifted subscriptions.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account_UI_V1;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test file mixes WooCommerce stubs with the test class.

require_once __DIR__ . '/../../mocks/wc-mocks.php';
require_once __DIR__ . '/../../mocks/wcs-gifting-mock.php';

if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	/**
	 * Stub for WooCommerce is_wc_endpoint_url(): the endpoint a test sets.
	 *
	 * @param string $endpoint Endpoint.
	 * @return bool
	 */
	function is_wc_endpoint_url( $endpoint = false ) {
		return $endpoint === ( $GLOBALS['newspack_test_wc_endpoint'] ?? null );
	}
}

if ( ! class_exists( 'WCSG_Recipient_Addresses' ) ) {
	/**
	 * Stub for WooCommerce Subscriptions 9.4's WCSG_Recipient_Addresses: an
	 * order's shipping address is withheld from the users listed for it.
	 */
	class WCSG_Recipient_Addresses {
		/**
		 * Map of order ID => user IDs the shipping address is withheld from.
		 *
		 * @var array
		 */
		public static $withheld = [];

		/**
		 * Whether the order's shipping address is withheld from the user.
		 *
		 * @param object $order   Order.
		 * @param int    $user_id User ID.
		 * @return bool
		 */
		public static function is_shipping_address_withheld( $order, $user_id ) {
			return in_array( (int) $user_id, self::$withheld[ $order->get_id() ] ?? [], true );
		}
	}
}

/**
 * WooCommerce Subscriptions swaps the view-order customer details template
 * for gifted subscriptions, and from 9.4 hides the recipient's address from
 * the purchaser that way. Newspack's own template must not replace it.
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
		WCS_Gifting::$recipients                   = [];
		WCSG_Recipient_Addresses::$withheld        = [];
		$GLOBALS['newspack_test_wc_endpoint']      = 'view-order';
	}

	/**
	 * Reset the shared stubs for later test classes.
	 */
	public function tear_down() {
		WCS_Gifting::$recipients            = [];
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
	 * A gifted subscription's order keeps Gifting's template.
	 */
	public function test_gifted_order_keeps_gifting_template() {
		$order = new WC_Order(
			[
				'id'     => 7121,
				'status' => 'completed',
			] 
		);
		WCS_Gifting::$recipients[ $order->get_id() ] = 5;

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
