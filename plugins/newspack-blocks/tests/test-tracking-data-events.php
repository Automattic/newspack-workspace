<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class TrackingDataEventsTest
 *
 * @package Newspack_Blocks
 */

require_once __DIR__ . '/mocks/newspack-plugin-mocks.php';
require_once __DIR__ . '/mocks/newspack-plugin-data-events-utils-mock.php';
require_once __DIR__ . '/class-wc-order-stub.php';

/**
 * Tests for the modal checkout Data Events tracking integration.
 *
 * The suite runs without newspack-plugin or WooCommerce loaded: the recording
 * Data_Events mock captures listener registrations, the Utils mock serves
 * order-data fixtures, and callbacks are invoked directly.
 */
class TrackingDataEventsTest extends WP_UnitTestCase_Blocks {
	/**
	 * Reset mock state.
	 */
	public function set_up() {
		parent::set_up();
		\Newspack\Data_Events::$registered_listeners          = [];
		\Newspack\Data_Events\Utils::$order_data_fixtures     = [];
	}

	/**
	 * Clean up request data.
	 */
	public function tear_down() {
		\Newspack\Data_Events::$registered_listeners      = [];
		\Newspack\Data_Events\Utils::$order_data_fixtures = [];
		unset( $_POST['express_payment_type'], $_REQUEST['modal_checkout'], $_REQUEST['post_data'], $_POST['post_data'], $_SERVER['HTTP_REFERER'] );
		parent::tear_down();
	}

	/**
	 * Order-data fixture shaped like Newspack\Data_Events\Utils::get_order_data().
	 *
	 * @param int   $order_id   Order ID.
	 * @param int[] $product_id Product IDs.
	 *
	 * @return array
	 */
	private function get_order_data_fixture( $order_id, $product_id = [ 55 ] ) {
		return [
			'user_id'         => 7,
			'email'           => 'reader@example.test',
			'amount'          => 10,
			'currency'        => 'USD',
			'recurrence'      => 'once',
			'platform'        => 'wc',
			'referer'         => '',
			'popup_id'        => '',
			'is_renewal'      => false,
			'subscription_id' => '',
			'platform_data'   => [
				'order_id'   => $order_id,
				'product_id' => $product_id,
				'client_id'  => '',
			],
			'user_first_name' => 'Reader',
			'user_last_name'  => 'Example',
		];
	}

	/**
	 * Both WooCommerce checkout pipelines register a modal_checkout_interaction
	 * listener: the classic hook and the Store API hook, each with its own
	 * signature-exact callback.
	 */
	public function test_register_listeners_registers_classic_and_store_api_hooks() {
		\Newspack_Blocks\Tracking\Data_Events::register_listeners();

		$by_hook = [];
		foreach ( \Newspack\Data_Events::$registered_listeners as $listener ) {
			$by_hook[ $listener['hook'] ] = $listener;
		}

		$this->assertArrayHasKey( 'woocommerce_checkout_order_processed', $by_hook );
		$this->assertSame( 'modal_checkout_interaction', $by_hook['woocommerce_checkout_order_processed']['action'] );
		$this->assertSame( [ \Newspack_Blocks\Tracking\Data_Events::class, 'order_status_completed' ], $by_hook['woocommerce_checkout_order_processed']['callable'] );

		$this->assertArrayHasKey( 'woocommerce_store_api_checkout_order_processed', $by_hook );
		$this->assertSame( 'modal_checkout_interaction', $by_hook['woocommerce_store_api_checkout_order_processed']['action'] );
		$this->assertSame( [ \Newspack_Blocks\Tracking\Data_Events::class, 'store_api_order_processed' ], $by_hook['woocommerce_store_api_checkout_order_processed']['callable'] );
	}

	/**
	 * The Store API callback builds the standard payload for a modal-origin
	 * request (express wallet shape: modal referer, JSON body, no params).
	 */
	public function test_store_api_callback_builds_payload_for_modal_origin() {
		$_SERVER['HTTP_REFERER'] = 'https://example.com/checkout/?modal_checkout=1';
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];

		$data = \Newspack_Blocks\Tracking\Data_Events::store_api_order_processed( new WC_Order( 123, 'wc_order_testkey' ) );

		$this->assertIsArray( $data );
		$this->assertSame( 'form_submission_success', $data['action'] );
		$this->assertSame( 'checkout_button', $data['action_type'] );
		$this->assertSame( 'product', $data['product_type'] );
		$this->assertSame( 'once', $data['recurrence'] );
		$this->assertSame( 55, $data['product_id'] );
	}

	/**
	 * The Store API callback returns empty without a modal origin, so a
	 * standard block-based /checkout/ purchase dispatches nothing.
	 */
	public function test_store_api_callback_empty_without_modal_origin() {
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];

		$this->assertEmpty( \Newspack_Blocks\Tracking\Data_Events::store_api_order_processed( new WC_Order( 123, 'wc_order_testkey' ) ) );
	}

	/**
	 * The Store API callback ignores values that are not a WC_Order without
	 * erroring — the two checkout hooks pass differently-shaped arguments,
	 * and a wrong-shape value must never fatal.
	 */
	public function test_store_api_callback_ignores_non_order_values() {
		$_SERVER['HTTP_REFERER'] = 'https://example.com/checkout/?modal_checkout=1';
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];

		$this->assertEmpty( \Newspack_Blocks\Tracking\Data_Events::store_api_order_processed( null ) );
		$this->assertEmpty( \Newspack_Blocks\Tracking\Data_Events::store_api_order_processed( 123 ) );
		$this->assertEmpty( \Newspack_Blocks\Tracking\Data_Events::store_api_order_processed( 'not-an-order' ) );
	}

	/**
	 * The classic callback builds the payload for a classic express-checkout
	 * submission (express_payment_type in POST plus the modal referer, no
	 * modal_checkout param) — the wallet-through-classic shape.
	 */
	public function test_classic_callback_builds_payload_for_express_shape() {
		$_POST['express_payment_type'] = 'apple_pay';
		$_SERVER['HTTP_REFERER']       = 'https://example.com/checkout/?modal_checkout=1';
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];

		$data = \Newspack_Blocks\Tracking\Data_Events::order_status_completed( 123, [], null );

		$this->assertIsArray( $data );
		$this->assertSame( 'form_submission_success', $data['action'] );
		$this->assertSame( 'checkout_button', $data['action_type'] );
	}

	/**
	 * The classic callback still builds the payload for the classic card
	 * shape (modal_checkout request param present) — regression pin.
	 */
	public function test_classic_callback_builds_payload_for_param_shape() {
		$_REQUEST['modal_checkout'] = '1';
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];

		$data = \Newspack_Blocks\Tracking\Data_Events::order_status_completed( 123, [], null );

		$this->assertIsArray( $data );
		$this->assertSame( 'form_submission_success', $data['action'] );
		$this->assertSame( 55, $data['product_id'] );
	}

	/**
	 * The classic callback returns empty when a request carries no modal
	 * signals — regression pin.
	 */
	public function test_classic_callback_empty_without_modal_signals() {
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];

		$this->assertEmpty( \Newspack_Blocks\Tracking\Data_Events::order_status_completed( 123, [], null ) );
	}
}
