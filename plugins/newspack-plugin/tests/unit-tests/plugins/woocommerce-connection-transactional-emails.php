<?php
/**
 * Tests the Receipt and Welcome transactional emails on non-donation orders (NPPM-3213).
 *
 * @package Newspack\Tests
 */

use Newspack\Donations;
use Newspack\Emails;
use Newspack\Reader_Revenue_Emails;
use Newspack\WooCommerce_Connection;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * Both reader-revenue transactional emails used to share a single gate: the
 * order had to contain a Newspack donation product. A membership or
 * subscription purchase matched neither, so the reader fell through to
 * WooCommerce's own Completed Order email and the publisher saw no signal.
 *
 * @group transactional_emails
 */
class Newspack_Test_WooCommerce_Connection_Transactional_Emails extends WP_UnitTestCase {
	/**
	 * Reset the mock registries before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $orders_database, $products_database, $subscriptions_database;
		$orders_database        = [];
		$products_database      = [];
		$subscriptions_database = [];
		reset_phpmailer_instance();
		Emails::reset_email_configs_cache();
		Donations::reset_flagged_donation_product_ids_cache();
	}

	/**
	 * Register the reader-revenue email configs the sender reads, so
	 * Emails::can_send_email() resolves to a published email post.
	 */
	private function enable_reader_revenue_emails() {
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
				$configs[ Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'] ] = [
					'name'        => Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'],
					'label'       => 'Welcome',
					'description' => 'Test welcome email.',
					'template'    => dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-revenue-emails/welcome.php',
					'category'    => 'reader-revenue',
				];
				return $configs;
			}
		);
		Emails::reset_email_configs_cache();
	}

	/**
	 * Remove both reader-revenue configs, so can_send_email() is false for
	 * each — the publisher's "email disabled" state, as far as the sender
	 * can observe it.
	 */
	private function disable_reader_revenue_emails() {
		add_filter(
			'newspack_email_configs',
			function ( $configs ) {
				unset( $configs[ Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'] ] );
				unset( $configs[ Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'] ] );
				return $configs;
			},
			99
		);
		Emails::reset_email_configs_cache();
	}

	/**
	 * *BILLING_FREQUENCY* is an available placeholder rather than part of the
	 * stock template, so a publisher who wants it puts it in their own copy.
	 * Reduce the receipt body to just that placeholder, which is both what the
	 * assertion needs and how the merge field is really used.
	 */
	private function use_billing_frequency_only_receipt_body() {
		// The first send of a request runs the one-time RAS-ACC template
		// migration, which trashes any email post whose modified date still
		// matches its publish date so it regenerates from the source template.
		// A staged body is exactly that shape, so it would be thrown away
		// before the send. Every real site has long since migrated.
		update_option( 'newspack_email_templates_migrated', 'v1' );

		$config = Emails::get_email_config_by_type( Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'] );
		update_post_meta( $config['post_id'], \Newspack_Newsletters::EMAIL_HTML_META, '<p>*BILLING_FREQUENCY*</p>' );
		Emails::reset_email_configs_cache();
	}

	/**
	 * Stage a product in the mock product registry.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $name       Product name.
	 * @param string $type       WC product type, e.g. 'simple' or 'subscription'.
	 * @param string $period     Subscription period meta ('month', 'year'), if any.
	 *
	 * @return WC_Product The staged product.
	 */
	private function stage_product( $product_id, $name, $type = 'simple', $period = '' ) {
		global $products_database;
		$data = [
			'id'   => $product_id,
			'name' => $name,
			'type' => $type,
		];
		if ( $period ) {
			$data['meta'] = [ '_subscription_period' => $period ];
		}
		$products_database[ $product_id ] = new WC_Product( $data );
		return $products_database[ $product_id ];
	}

	/**
	 * Build a completed order carrying a single line item for the given product.
	 *
	 * @param int    $product_id Product ID for the line item.
	 * @param string $name      Line item name.
	 * @param array  $extra     Extra order data, merged last.
	 *
	 * @return WC_Order The staged order.
	 */
	private function stage_order( $product_id, $name, $extra = [] ) {
		return new WC_Order(
			array_merge(
				[
					'status'        => 'completed',
					'customer_id'   => 1,
					'total'         => 120,
					'date_paid'     => '2026-08-26 12:00:00',
					// Not @example.com: the outbound-mail guard suppresses sends
					// to the placeholder domain while reporting success.
					'billing_email' => 'reader@tests.com',
					'items'         => [
						new WC_Order_Item_Product(
							[
								'name'       => $name,
								'product_id' => $product_id,
								'total'      => 120,
							]
						),
					],
				],
				$extra
			)
		);
	}

	/**
	 * The reported bug: a membership or subscription purchase on a site that
	 * also sells donations sent no Newspack receipt at all.
	 */
	public function test_receipt_sends_for_non_donation_subscription_order() {
		$this->enable_reader_revenue_emails();
		$this->stage_product( 810, 'Membership: Yearly', 'subscription', 'year' );
		$order = $this->stage_order( 810, 'Membership: Yearly' );

		self::assertTrue(
			WooCommerce_Connection::send_customizable_receipt_email( $order->get_id() ),
			'A completed membership order should send the customizable receipt.'
		);
		self::assertTrue(
			$order->meta_exists( '_newspack_receipt_email_sent' ),
			'A successful send must write the sent marker.'
		);
	}

	/**
	 * The Welcome email's own editor notice promises it is sent "when they
	 * register an account during a transaction" — with no mention of donations.
	 */
	public function test_welcome_sends_for_non_donation_order_with_checkout_registration() {
		$this->enable_reader_revenue_emails();
		$this->stage_product( 811, 'Membership: Monthly', 'subscription', 'month' );
		$order = $this->stage_order(
			811,
			'Membership: Monthly',
			[ 'meta' => [ '_newspack_checkout_registration_meta' => [ 'email_address' => 'reader@tests.com' ] ] ]
		);

		self::assertTrue(
			WooCommerce_Connection::send_customizable_receipt_email( $order->get_id() ),
			'A membership order that registered an account should send the welcome email.'
		);
		self::assertTrue(
			$order->meta_exists( '_newspack_welcome_email_sent' ),
			'The welcome email, not the receipt, must be the one recorded as sent.'
		);
		self::assertFalse(
			$order->meta_exists( '_newspack_receipt_email_sent' ),
			'Only one of the two emails should be sent for a given order.'
		);
	}

	/**
	 * *BILLING_FREQUENCY* used to be resolved from a map of the Newspack
	 * donation product IDs alone, so any other recurring product fell through
	 * to the "One-time" default and a yearly membership was described wrongly.
	 */
	public function test_billing_frequency_reads_period_from_the_line_item() {
		$this->enable_reader_revenue_emails();
		$this->use_billing_frequency_only_receipt_body();
		$this->stage_product( 812, 'Membership: Yearly', 'subscription', 'year' );
		$order = $this->stage_order( 812, 'Membership: Yearly' );

		WooCommerce_Connection::send_customizable_receipt_email( $order->get_id() );

		self::assertStringContainsString(
			'Yearly',
			tests_retrieve_phpmailer_instance()->get_sent()->body,
			'A yearly subscription line item should render its own billing frequency.'
		);
	}

	/**
	 * A one-off purchase has no subscription period to read, and must not
	 * inherit one from WooCommerce Subscriptions' month-shaped defaults.
	 */
	public function test_billing_frequency_is_one_time_for_a_non_subscription_product() {
		$this->enable_reader_revenue_emails();
		$this->use_billing_frequency_only_receipt_body();
		$this->stage_product( 813, 'Single issue' );
		$order = $this->stage_order( 813, 'Single issue' );

		WooCommerce_Connection::send_customizable_receipt_email( $order->get_id() );

		self::assertStringContainsString(
			'One-time',
			tests_retrieve_phpmailer_instance()->get_sent()->body,
			'A simple product should be described as a one-time payment.'
		);
	}

	/**
	 * The sender describes the order's first line item, so an order with no
	 * items has nothing to describe — and dereferencing the missing item
	 * would be fatal.
	 */
	public function test_no_email_is_sent_for_an_order_with_no_items() {
		$this->enable_reader_revenue_emails();
		$order = new WC_Order(
			[
				'status'        => 'completed',
				'customer_id'   => 1,
				'total'         => 0,
				'billing_email' => 'reader@tests.com',
				'items'         => [],
			]
		);

		self::assertFalse(
			WooCommerce_Connection::send_customizable_receipt_email( $order->get_id() ),
			'An order with no line items should send nothing.'
		);
		self::assertFalse(
			$order->meta_exists( '_newspack_receipt_email_sent' ),
			'No sent marker should be written when nothing was sent.'
		);
	}

	/**
	 * The sender's docblock has always claimed the Newspack email goes out
	 * "instead of WooCommerce's default receipt", but nothing suppressed the
	 * WooCommerce one, so the reader received both.
	 */
	public function test_wc_completed_order_email_is_suppressed_once_newspack_has_sent() {
		$this->enable_reader_revenue_emails();
		$this->stage_product( 814, 'Membership: Yearly', 'subscription', 'year' );
		$order = $this->stage_order( 814, 'Membership: Yearly' );

		self::assertTrue(
			WooCommerce_Connection::send_customizable_receipt_email( $order->get_id() ),
			'Precondition: the Newspack email has to actually go out.'
		);
		self::assertFalse(
			WooCommerce_Connection::disable_duplicate_wc_completed_order_email( true, $order ),
			"WooCommerce's Completed Order email should stand down once Newspack has sent its own."
		);
	}

	/**
	 * The reason the suppression reads the sent marker rather than recomputing
	 * whether an email applies: a send that fails leaves the reader with nothing
	 * at all if WooCommerce has already been waved off.
	 */
	public function test_wc_completed_order_email_survives_a_failed_newspack_send() {
		$this->enable_reader_revenue_emails();
		$this->stage_product( 818, 'Membership: Yearly', 'subscription', 'year' );
		$order = $this->stage_order( 818, 'Membership: Yearly' );

		// Fail delivery the way a broken mailer would, short of the send itself.
		add_filter( 'pre_wp_mail', '__return_false' );
		self::assertFalse(
			WooCommerce_Connection::send_customizable_receipt_email( $order->get_id() ),
			'Precondition: the Newspack send has to fail for this to test anything.'
		);
		self::assertFalse(
			$order->meta_exists( '_newspack_receipt_email_sent' ),
			'A failed send must not leave a sent marker behind.'
		);
		self::assertTrue(
			WooCommerce_Connection::disable_duplicate_wc_completed_order_email( true, $order ),
			'A reader must not be left with no receipt at all.'
		);
	}

	/**
	 * Reading the marker only works because the Newspack send runs first. Both
	 * callbacks sit on the same WooCommerce action, so the ordering has to come
	 * from an explicit priority rather than from registration order.
	 */
	public function test_newspack_send_runs_before_the_woocommerce_completed_order_email() {
		$priority = has_filter(
			'woocommerce_order_status_completed_notification',
			[ WooCommerce_Connection::class, 'send_customizable_receipt_email' ]
		);

		self::assertNotFalse( $priority, 'The Newspack sender must be hooked to the completed-order notification.' );
		self::assertLessThan(
			10,
			$priority,
			'WooCommerce triggers its Completed Order email at priority 10; Newspack has to have sent, and written its marker, before then.'
		);
	}

	/**
	 * With the Newspack emails switched off, nothing sends and no marker is
	 * written, so WooCommerce's own email is the reader's only receipt.
	 */
	public function test_wc_completed_order_email_survives_when_newspack_emails_are_disabled() {
		$this->disable_reader_revenue_emails();
		$this->stage_product( 815, 'Membership: Yearly', 'subscription', 'year' );
		$order = $this->stage_order( 815, 'Membership: Yearly' );

		WooCommerce_Connection::send_customizable_receipt_email( $order->get_id() );

		self::assertTrue(
			WooCommerce_Connection::disable_duplicate_wc_completed_order_email( true, $order ),
			'A reader must not be left with no receipt at all.'
		);
	}

	/**
	 * Another filter callback may already have disabled the email; this one
	 * only ever suppresses, never re-enables.
	 */
	public function test_wc_completed_order_email_suppression_does_not_re_enable() {
		$this->enable_reader_revenue_emails();
		$this->stage_product( 816, 'Membership: Yearly', 'subscription', 'year' );
		$order = $this->stage_order( 816, 'Membership: Yearly' );
		WooCommerce_Connection::send_customizable_receipt_email( $order->get_id() );

		self::assertFalse(
			WooCommerce_Connection::disable_duplicate_wc_completed_order_email( false, $order ),
			'An email already disabled upstream must stay disabled.'
		);
	}

	/**
	 * Passed something that is not an order — WooCommerce fires the enabled
	 * filter from contexts without one, such as the settings screen.
	 */
	public function test_wc_completed_order_email_suppression_ignores_a_missing_order() {
		$this->enable_reader_revenue_emails();

		self::assertTrue(
			WooCommerce_Connection::disable_duplicate_wc_completed_order_email( true, null ),
			'With no order to inspect, the filter should leave the setting alone.'
		);
	}

	/**
	 * The suppression is scoped to the reader's copy. The store owner's
	 * notifications are a different audience with a different purpose, and
	 * Newspack sends nothing that replaces them.
	 */
	public function test_store_owner_notifications_are_never_suppressed() {
		$callback = [ WooCommerce_Connection::class, 'disable_duplicate_wc_completed_order_email' ];

		self::assertNotFalse(
			has_filter( 'woocommerce_email_enabled_customer_completed_order', $callback ),
			"The reader's Completed Order email is the one Newspack replaces."
		);
		foreach ( [ 'new_order', 'recipient_completed_order', 'cancelled_order', 'failed_order' ] as $email_id ) {
			self::assertFalse(
				has_filter( 'woocommerce_email_enabled_' . $email_id, $callback ),
				sprintf( 'The %s email is not the reader\'s receipt and must be left alone.', $email_id )
			);
		}
	}

	/**
	 * The cancellation email carried its own copy of the donation-product
	 * frequency map, and it has never been donation-gated — so a cancelled
	 * yearly membership was already being described as one-time.
	 */
	public function test_cancellation_billing_frequency_reads_period_from_the_line_item() {
		add_filter(
			'newspack_email_configs',
			function ( $configs ) {
				$configs[ Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'] ] = [
					'name'        => Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'],
					'label'       => 'Cancellation',
					'description' => 'Test cancellation email.',
					'template'    => dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-revenue-emails/cancellation.php',
					'category'    => 'reader-revenue',
				];
				return $configs;
			}
		);
		Emails::reset_email_configs_cache();
		update_option( 'newspack_email_templates_migrated', 'v1' );
		$config = Emails::get_email_config_by_type( Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'] );
		update_post_meta( $config['post_id'], \Newspack_Newsletters::EMAIL_HTML_META, '<p>*BILLING_FREQUENCY*</p>' );
		Emails::reset_email_configs_cache();

		$this->stage_product( 817, 'Membership: Yearly', 'subscription', 'year' );
		$subscription = new WC_Subscription(
			[
				'id'            => 9017,
				'status'        => 'cancelled',
				'customer_id'   => 1,
				'total'         => 120,
				'billing_email' => 'reader@tests.com',
				'items'         => [
					new WC_Order_Item_Product(
						[
							'name'       => 'Membership: Yearly',
							'product_id' => 817,
							'total'      => 120,
						]
					),
				],
			]
		);

		WooCommerce_Connection::send_customizable_cancellation_email( $subscription );

		self::assertStringContainsString(
			'Yearly',
			tests_retrieve_phpmailer_instance()->get_sent()->body,
			'A cancelled yearly subscription should be described as yearly.'
		);
	}
}
