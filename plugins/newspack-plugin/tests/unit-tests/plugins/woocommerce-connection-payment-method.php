<?php
/**
 * Tests the *PAYMENT_METHOD* merge field label and receipt email guards (NPPM-2903).
 *
 * @package Newspack\Tests
 */

use Newspack\Emails;
use Newspack\Reader_Revenue_Emails;
use Newspack\WooCommerce_Connection;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * Tests for the payment method label used by reader revenue emails.
 *
 * @group payment_method_label
 */
class Newspack_Test_WooCommerce_Connection_Payment_Method extends WP_UnitTestCase {
	/**
	 * Reset the mock registries before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $orders_database, $products_database, $subscriptions_database;
		$orders_database        = [];
		$products_database      = [];
		$subscriptions_database = [];
		WC_Payment_Tokens::$tokens = [];
	}

	/**
	 * A saved credit card token yields "<Brand> ending in <last4>".
	 */
	public function test_label_uses_token_brand_and_last4() {
		WC_Payment_Tokens::$tokens[101] = new WC_Payment_Token_CC( 'visa', '4242' );
		$order                          = new WC_Order(
			[
				'status'               => 'processing',
				'customer_id'          => 1,
				'total'                => 20,
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit / Debit Card',
				'payment_tokens'       => [ 101 ],
			]
		);
		self::assertSame(
			'Visa ending in 4242',
			WooCommerce_Connection::get_payment_method_label( $order ),
			'Saved-card orders should render the card brand and last four digits.'
		);
	}

	/**
	 * Without a token, the gateway's customer-facing title is used — never the gateway ID slug.
	 */
	public function test_label_falls_back_to_gateway_title() {
		$order = new WC_Order(
			[
				'status'               => 'processing',
				'customer_id'          => 1,
				'total'                => 5,
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit / Debit Card',
			]
		);
		self::assertSame(
			'Credit / Debit Card',
			WooCommerce_Connection::get_payment_method_label( $order ),
			'One-time orders without a saved card should render the gateway title, not the slug.'
		);
	}

	/**
	 * Without a token or a gateway title, a generic label is used.
	 */
	public function test_label_falls_back_to_generic_card() {
		$order = new WC_Order(
			[
				'status'         => 'processing',
				'customer_id'    => 1,
				'total'          => 5,
				'payment_method' => 'stripe',
			]
		);
		self::assertSame(
			'Card',
			WooCommerce_Connection::get_payment_method_label( $order ),
			'Orders with no gateway title should render a generic label.'
		);
	}

	/**
	 * A CC token missing its last4 falls through to the gateway title.
	 */
	public function test_label_ignores_token_without_last4() {
		WC_Payment_Tokens::$tokens[102] = new WC_Payment_Token_CC( 'visa', '' );
		$order                          = new WC_Order(
			[
				'status'               => 'processing',
				'customer_id'          => 1,
				'total'                => 20,
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit / Debit Card',
				'payment_tokens'       => [ 102 ],
			]
		);
		self::assertSame(
			'Credit / Debit Card',
			WooCommerce_Connection::get_payment_method_label( $order ),
			'Tokens without a last4 should not produce a dangling "ending in" label.'
		);
	}

	/**
	 * A CC token with a last4 but no card brand falls through to the gateway title,
	 * instead of rendering a brandless "ending in 4242".
	 */
	public function test_label_ignores_token_without_brand() {
		WC_Payment_Tokens::$tokens[103] = new WC_Payment_Token_CC( '', '4242' );
		$order                          = new WC_Order(
			[
				'status'               => 'processing',
				'customer_id'          => 1,
				'total'                => 20,
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit / Debit Card',
				'payment_tokens'       => [ 103 ],
			]
		);
		self::assertSame(
			'Credit / Debit Card',
			WooCommerce_Connection::get_payment_method_label( $order ),
			'Tokens without a card brand should not produce a brandless label.'
		);
	}

	/**
	 * Regression (env-setup fatal): a completed order with no items must not
	 * crash the receipt email path when no donation products are configured.
	 * Donations::get_order_donation_product_id() returns null in that state,
	 * which the guard's `!== false` comparison let through, and the code then
	 * fataled on `$item->get_product_id()` with an empty items array.
	 */
	public function test_receipt_email_bails_on_order_without_donation_items() {
		// Ensure the receipt email is enabled, so the guard is what stops the send.
		add_filter(
			'newspack_email_configs',
			function ( $configs ) {
				$configs[ Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'] ] = [
					'name'        => Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'],
					'label'       => 'Receipt',
					'description' => 'Test receipt email.',
					'template'    => dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-revenue-emails/receipt.php',
					'category'    => 'reader-revenue',
				];
				return $configs;
			}
		);
		self::assertTrue(
			Emails::can_send_email( Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'] ),
			'Precondition: the receipt email must be sendable so the donation guard is the deciding check.'
		);

		$order = new WC_Order(
			[
				'status'        => 'completed',
				'customer_id'   => 1,
				'total'         => 20,
				'billing_email' => 'reader@example.com',
				'items'         => [],
			]
		);
		self::assertFalse(
			WooCommerce_Connection::send_customizable_receipt_email( $order->get_id() ),
			'Orders without donation items should never send the customizable receipt.'
		);
		self::assertFalse(
			$order->meta_exists( '_newspack_receipt_email_sent' ),
			'No receipt-sent marker should be written for a non-donation order.'
		);
	}
}
