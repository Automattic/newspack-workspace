<?php
/**
 * Tests the *PAYMENT_METHOD* merge field label and receipt email guards (NPPM-2903).
 *
 * @package Newspack\Tests
 */

use Newspack\Donations;
use Newspack\Emails;
use Newspack\Reader_Revenue_Emails;
use Newspack\WooCommerce_Connection;
use Newspack\WooCommerce_Products;

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
		reset_phpmailer_instance();
		Emails::reset_email_configs_cache();
		// The flagged-product list is memoized in a static — reset it so a
		// product staged (and rolled back) by one test can't leak into the next.
		Donations::reset_flagged_donation_product_ids_cache();
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
	 * Stripe saves cards against the customer, not the order — the order carries
	 * only a gateway reference in `_stripe_source_id` meta. The label must
	 * recover the brand and last4 by matching that reference to the customer's
	 * saved tokens.
	 */
	public function test_label_recovers_customer_token_via_stripe_source_id() {
		WC_Payment_Tokens::$tokens[201] = new WC_Payment_Token_CC( 'visa', '4242', 'pm_abc123', 7, 'stripe' );
		$order                          = new WC_Order(
			[
				'status'               => 'processing',
				'customer_id'          => 7,
				'total'                => 20,
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit / Debit Card',
				'meta'                 => [ '_stripe_source_id' => 'pm_abc123' ],
			]
		);
		self::assertSame(
			'Visa ending in 4242',
			WooCommerce_Connection::get_payment_method_label( $order ),
			'Customer-scoped tokens referenced by the order\'s _stripe_source_id should render the card details.'
		);
	}

	/**
	 * Subscription renewal orders carry the copied `_stripe_source_id` but no
	 * gateway title — exactly the shape that previously rendered a bare "Card".
	 * The customer-token recovery must cover them.
	 */
	public function test_label_recovers_customer_token_on_renewal_order() {
		WC_Payment_Tokens::$tokens[202] = new WC_Payment_Token_CC( 'mastercard', '5556', 'pm_renewal9', 8, 'stripe' );
		$order                          = new WC_Order(
			[
				'status'         => 'completed',
				'customer_id'    => 8,
				'total'          => 10,
				'payment_method' => 'stripe',
				'meta'           => [
					'_subscription_renewal' => 12,
					'_stripe_source_id'     => 'pm_renewal9',
				],
			]
		);
		self::assertSame(
			'MasterCard ending in 5556',
			WooCommerce_Connection::get_payment_method_label( $order ),
			'Renewal orders should recover the card details from the customer\'s saved token.'
		);
	}

	/**
	 * A `_stripe_source_id` that matches none of the customer's saved tokens
	 * (e.g. the card was deleted, or the reference belongs to a payment that was
	 * never saved) must fall through to the gateway title.
	 */
	public function test_label_ignores_unmatched_stripe_source_id() {
		WC_Payment_Tokens::$tokens[203] = new WC_Payment_Token_CC( 'visa', '4242', 'pm_other', 7, 'stripe' );
		$order                          = new WC_Order(
			[
				'status'               => 'processing',
				'customer_id'          => 7,
				'total'                => 20,
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit / Debit Card',
				'meta'                 => [ '_stripe_source_id' => 'pm_abc123' ],
			]
		);
		self::assertSame(
			'Credit / Debit Card',
			WooCommerce_Connection::get_payment_method_label( $order ),
			'An unmatched gateway reference should not borrow another saved card\'s details.'
		);
	}

	/**
	 * Guest orders have no customer to look tokens up against — even with a
	 * `_stripe_source_id` present, the label must fall through to the gateway
	 * title rather than matching another customer's token.
	 */
	public function test_label_ignores_stripe_source_id_for_guest_orders() {
		WC_Payment_Tokens::$tokens[204] = new WC_Payment_Token_CC( 'visa', '4242', 'pm_abc123', 7, 'stripe' );
		$order                          = new WC_Order(
			[
				'status'               => 'processing',
				'customer_id'          => 0,
				'total'                => 20,
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit / Debit Card',
				'meta'                 => [ '_stripe_source_id' => 'pm_abc123' ],
			]
		);
		self::assertSame(
			'Credit / Debit Card',
			WooCommerce_Connection::get_payment_method_label( $order ),
			'Guest orders should never resolve card details from saved tokens.'
		);
	}

	/**
	 * The label lands unescaped inside the email template's HTML (the
	 * placeholder substitution is a bare str_replace), so markup sneaking in
	 * through a gateway title or a third-party label filter must be stripped —
	 * the same defense the *AMOUNT* placeholder applies.
	 */
	public function test_label_strips_markup_from_gateway_title() {
		$order = new WC_Order(
			[
				'status'               => 'processing',
				'customer_id'          => 1,
				'total'                => 5,
				'payment_method'       => 'custom_gateway',
				'payment_method_title' => 'Credit Card <img src=x onerror=alert(1)>',
			]
		);
		self::assertSame(
			'Credit Card',
			WooCommerce_Connection::get_payment_method_label( $order ),
			'Markup in a gateway-supplied title must not reach the email HTML.'
		);
	}

	/**
	 * Stripe stores the card brand as "amex", while WooCommerce's label map keys
	 * on "american express" — so the ucwords fallback applies and the receipt
	 * reads "Amex ending in …". Pinned as accepted behavior: "Amex" is the
	 * recognizable brand name, and normalizing gateway slugs would mean
	 * maintaining a mapping of every gateway's vocabulary.
	 */
	public function test_label_renders_stripe_amex_slug_as_amex() {
		WC_Payment_Tokens::$tokens[205] = new WC_Payment_Token_CC( 'amex', '0005' );
		$order                          = new WC_Order(
			[
				'status'               => 'processing',
				'customer_id'          => 1,
				'total'                => 20,
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit / Debit Card',
				'payment_tokens'       => [ 205 ],
			]
		);
		self::assertSame(
			'Amex ending in 0005',
			WooCommerce_Connection::get_payment_method_label( $order ),
			'Stripe\'s "amex" slug falls through WC\'s label map to the ucwords fallback.'
		);
	}

	/**
	 * The full success path: a donation order sends the receipt, reports true —
	 * the docblock contract — writes the sent marker, and the delivered mail
	 * carries the card details recovered from the customer's saved token.
	 */
	public function test_receipt_email_sends_for_donation_order_with_card_details() {
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
		Emails::reset_email_configs_cache();

		$product_id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $product_id, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		Donations::reset_flagged_donation_product_ids_cache();

		WC_Payment_Tokens::$tokens[301] = new WC_Payment_Token_CC( 'visa', '4242', 'pm_success1', 9, 'stripe' );
		// Not @example.com: the outbound-mail guard suppresses sends to the
		// placeholder domain while reporting success, and it is active in the
		// test environment (only local/development env types are exempt).
		$order = new WC_Order(
			[
				'status'               => 'completed',
				'customer_id'          => 9,
				'total'                => 20,
				// The receipt builds a *DATE* placeholder from get_date_created(),
				// which is unguarded against null. A real completed order always
				// has a date; the fixture has to supply one too.
				'date_paid'            => '2026-08-05 12:00:00',
				'billing_email'        => 'donor@tests.com',
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit / Debit Card',
				'meta'                 => [ '_stripe_source_id' => 'pm_success1' ],
				'items'                => [
					new WC_Order_Item_Product(
						[
							'name'       => 'Donate: Monthly',
							'product_id' => $product_id,
							'total'      => 20,
						]
					),
				],
			]
		);

		self::assertTrue(
			WooCommerce_Connection::send_customizable_receipt_email( $order->get_id() ),
			'A successful send must return true, per the docblock.'
		);
		self::assertTrue(
			$order->meta_exists( '_newspack_receipt_email_sent' ),
			'A successful send must write the sent marker.'
		);
		// The marker write must be persisted: the send hook fires after
		// WC_Order::update_status() has already saved, on a freshly hydrated
		// instance, so without an explicit save() the marker is discarded at end
		// of request and the already-sent guard never fires across requests.
		// Asserted via the mock's save counter — the mock aliases instances, so
		// meta_exists() alone cannot distinguish saved from unsaved meta.
		self::assertGreaterThan(
			0,
			$order->save_calls,
			'The sent marker must be persisted with save(), not left on the in-memory order.'
		);
		$mailer = tests_retrieve_phpmailer_instance();
		self::assertStringContainsString(
			'Visa ending in 4242',
			$mailer->get_sent()->body,
			'The delivered receipt must carry the card details recovered from the saved token.'
		);
	}

	/**
	 * With no donation products configured, get_order_donation_product_id() must
	 * return false per its `int|false` docblock — not a bare-return null, which
	 * forces every caller to know about a third state (and let non-donation
	 * orders through `false ===` guards like order_paid()'s).
	 */
	public function test_get_order_donation_product_id_is_false_when_donations_unconfigured() {
		$order = new WC_Order(
			[
				'status'      => 'completed',
				'customer_id' => 1,
				'total'       => 20,
				'items'       => [],
			]
		);
		self::assertFalse(
			Donations::get_order_donation_product_id( $order->get_id() ),
			'A site with no donation products should get the documented false, never null.'
		);
	}

	/**
	 * Regression (env-setup fatal): a completed order with no items must not
	 * crash the receipt email path when no donation products are configured.
	 * Before the fix, Donations::get_order_donation_product_id() bare-returned
	 * null in that state, the guard's old `!== false` comparison let null
	 * through, and the code then fataled on `$item->get_product_id()` with an
	 * empty items array. The helper now returns its documented false and the
	 * guard is a falsy check; this test pins the no-send, no-crash behavior.
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
