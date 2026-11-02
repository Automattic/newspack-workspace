<?php
/**
 * Tests for the `wp newspack donors backfill` CLI command (NPPD-2527).
 *
 * The command flags readers who donated before the donor flags existed, with the
 * same rules as the Subscribers dashboard's donor badge. These tests check what it
 * writes, what it leaves alone, and the one summary line support reads back.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Donors;
use Newspack\Donations;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;
use Newspack\Reader_Data;
use Newspack\WooCommerce_Products;

/**
 * Test the backfill command against mock donation products, orders, and subscriptions.
 *
 * @group donors-backfill
 * @group WooCommerce_Subscriptions_Integration
 */
class Test_Donors_Backfill extends WP_UnitTestCase {

	/**
	 * The product every donation in a test is bought on.
	 *
	 * @var int
	 */
	private $donation_product_id;

	/**
	 * Load the WooCommerce and WP-CLI mocks and the command.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wcs-gifting-mock.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
		require_once dirname( __DIR__, 3 ) . '/includes/cli/class-donors.php';
	}

	/**
	 * Reset the mock stores, the recorded CLI output, and the lookup caches, and
	 * create the donation product.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];
		\WCS_Gifting::$recipients = [];
		Donations::reset_flagged_donation_product_ids_cache();
		Group_Subscription::reset_cache();
		Group_Subscription_Settings::clear_group_subscription_ids_cache();
		WP_CLI::reset();
		$this->donation_product_id = $this->create_product( 'Donation', true );
	}

	/**
	 * Tear down: reset the mock stores and the donation platform.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];
		\WCS_Gifting::$recipients = [];
		delete_option( Donations::NEWSPACK_READER_REVENUE_PLATFORM );
		Donations::reset_flagged_donation_product_ids_cache();
		Group_Subscription_Settings::clear_group_subscription_ids_cache();
		parent::tear_down();
	}

	/**
	 * Create a product as both a published post and a mock WC_Product.
	 *
	 * @param string $name        The product name.
	 * @param bool   $is_donation Whether to flag it as a donation product.
	 *
	 * @return int The product ID.
	 */
	private function create_product( string $name, bool $is_donation ): int {
		$product_id = self::factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => $name,
				'post_status' => 'publish',
			]
		);
		wc_create_mock_product(
			[
				'id'   => $product_id,
				'name' => $name,
				'type' => 'subscription',
			]
		);
		if ( $is_donation ) {
			update_post_meta( $product_id, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
			Donations::reset_flagged_donation_product_ids_cache();
		}
		return $product_id;
	}

	/**
	 * Create a reader.
	 *
	 * @return int The user ID.
	 */
	private function create_reader(): int {
		return self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	/**
	 * Create an order for a reader and add it to the mock order store.
	 *
	 * @param int    $customer_id The buyer's user ID, or 0 for a guest.
	 * @param int    $product_id  The product the order is for. Defaults to the donation product.
	 * @param string $status      Order status.
	 *
	 * @return WC_Order
	 */
	private function create_order( int $customer_id, int $product_id = 0, string $status = 'completed' ): WC_Order {
		return new WC_Order(
			[
				'customer_id' => $customer_id,
				'status'      => $status,
				'total'       => 25,
				'items'       => [
					new WC_Order_Item_Product(
						[
							'product_id' => $product_id ? $product_id : $this->donation_product_id,
							'quantity'   => 1,
						]
					),
				],
			]
		);
	}

	/**
	 * Create a donation subscription for a reader.
	 *
	 * @param int    $customer_id The subscription holder's user ID.
	 * @param string $status      Subscription status.
	 *
	 * @return WC_Subscription
	 */
	private function create_subscription( int $customer_id, string $status ): WC_Subscription {
		return wcs_create_subscription(
			[
				'customer_id'    => $customer_id,
				'status'         => $status,
				'billing_period' => 'month',
				'products'       => [ $this->donation_product_id ],
				'items'          => [ new WC_Order_Item_Product( [ 'product_id' => $this->donation_product_id ] ) ],
			]
		);
	}

	/**
	 * Run the command and return the one success line it prints.
	 *
	 * @param array $assoc_args The command's named options.
	 *
	 * @return string
	 */
	private function run_backfill( array $assoc_args = [] ): string {
		WP_CLI::reset();
		Donors::backfill( [], $assoc_args );
		$this->assertCount( 1, WP_CLI::$successes, 'A run ends with exactly one success line.' );
		return WP_CLI::$successes[0];
	}

	/**
	 * A dry run reports what it would flag and writes no flag.
	 */
	public function test_dry_run_writes_nothing() {
		$reader_id = $this->create_reader();
		$this->create_order( $reader_id );

		$message = $this->run_backfill( [ 'dry-run' => true ] );

		$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_donor' ) );
		$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_former_donor' ) );
		$this->assertSame( 'Would flag 1 donor(s) and 0 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * A reader with a completed donation order and no flag gets `is_donor`.
	 */
	public function test_flags_an_unflagged_one_time_donor() {
		$reader_id = $this->create_reader();
		$this->create_order( $reader_id );

		$message = $this->run_backfill();

		$this->assertTrue( Reader_Data::get_bool( $reader_id, 'is_donor' ) );
		$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_former_donor' ) );
		$this->assertSame( 'Flagged 1 donor(s) and 0 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * A reader whose only donation subscription ended gets `is_former_donor`.
	 */
	public function test_flags_an_unflagged_holder_of_an_ended_donation_subscription_as_former() {
		$reader_id = $this->create_reader();
		$this->create_subscription( $reader_id, 'cancelled' );

		$message = $this->run_backfill();

		$this->assertTrue( Reader_Data::get_bool( $reader_id, 'is_former_donor' ) );
		$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_donor' ) );
		$this->assertSame( 'Flagged 0 donor(s) and 1 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * A former donor's original gift order must not turn them back into a donor:
	 * the subscription decides, and the order walk skips a reader it already handled.
	 */
	public function test_an_ended_subscription_holder_with_a_donation_order_is_former_only() {
		$reader_id = $this->create_reader();
		$this->create_order( $reader_id );
		$this->create_subscription( $reader_id, 'cancelled' );

		$message = $this->run_backfill();

		$this->assertTrue( Reader_Data::get_bool( $reader_id, 'is_former_donor' ) );
		$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_donor' ) );
		$this->assertSame( 'Flagged 0 donor(s) and 1 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * The dry run tallies the same reader the same way a real run would.
	 */
	public function test_a_dry_run_counts_a_former_donor_with_an_order_once() {
		$reader_id = $this->create_reader();
		$this->create_order( $reader_id );
		$this->create_subscription( $reader_id, 'cancelled' );

		$message = $this->run_backfill( [ 'dry-run' => true ] );

		$this->assertSame( 'Would flag 0 donor(s) and 1 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * A live donation subscription outranks an ended one, so that reader is not a
	 * former donor. The completed order still makes them a donor, as a live
	 * donation would have.
	 */
	public function test_a_live_subscription_outranks_an_ended_one() {
		$reader_id = $this->create_reader();
		$this->create_subscription( $reader_id, 'cancelled' );
		$this->create_subscription( $reader_id, 'active' );
		$this->create_order( $reader_id );

		$message = $this->run_backfill();

		$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_former_donor' ) );
		$this->assertTrue( Reader_Data::get_bool( $reader_id, 'is_donor' ) );
		$this->assertSame( 'Flagged 1 donor(s) and 0 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * A reader who already has a flag keeps it, even with a donation order that
	 * would otherwise flag them the other way.
	 */
	public function test_leaves_already_flagged_readers_alone() {
		$reader_id = $this->create_reader();
		Reader_Data::set_is_former_donor( time(), [ 'user_id' => $reader_id ] );
		$this->create_order( $reader_id );

		$message = $this->run_backfill();

		$this->assertTrue( Reader_Data::get_bool( $reader_id, 'is_former_donor' ) );
		$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_donor' ) );
		$this->assertSame( 'Flagged 0 donor(s) and 0 former donor(s). 1 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * A donation order with no customer can't be matched to a reader, so it is
	 * skipped and counted.
	 */
	public function test_counts_guest_donation_orders() {
		$this->create_order( 0 );

		$message = $this->run_backfill();

		$this->assertStringEndsWith( '1 guest order(s) skipped.', $message );
		$this->assertStringStartsWith( 'Flagged 0 donor(s) and 0 former donor(s).', $message );
	}

	/**
	 * Orders that aren't completed donations flag no one.
	 */
	public function test_ignores_orders_that_are_not_completed_donations() {
		$reader_id        = $this->create_reader();
		$other_product_id = $this->create_product( 'Membership', false );
		$this->create_order( $reader_id, $other_product_id );
		$this->create_order( $reader_id, 0, 'processing' );
		$this->create_order( 0, $other_product_id );

		$message = $this->run_backfill();

		$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_donor' ) );
		$this->assertSame( 'Flagged 0 donor(s) and 0 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * A reader with several donation orders is one donor, not one per order.
	 */
	public function test_counts_a_reader_with_several_donation_orders_once() {
		$reader_id = $this->create_reader();
		$this->create_order( $reader_id );
		$this->create_order( $reader_id );
		$this->create_order( $reader_id );

		$message = $this->run_backfill();

		$this->assertSame( 'Flagged 1 donor(s) and 0 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * A flagged reader with several donation orders is counted once as already flagged.
	 */
	public function test_counts_an_already_flagged_reader_with_several_orders_once() {
		$reader_id = $this->create_reader();
		Reader_Data::set_is_donor( time(), [ 'user_id' => $reader_id ] );
		$this->create_order( $reader_id );
		$this->create_order( $reader_id );

		$message = $this->run_backfill();

		$this->assertSame( 'Flagged 0 donor(s) and 0 former donor(s). 1 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * Every page of orders is read, not only the first.
	 */
	public function test_reads_every_page_of_orders() {
		$reader_ids = [ $this->create_reader(), $this->create_reader(), $this->create_reader() ];
		foreach ( $reader_ids as $reader_id ) {
			$this->create_order( $reader_id );
		}

		$message = $this->run_backfill( [ 'batch-size' => 2 ] );

		foreach ( $reader_ids as $reader_id ) {
			$this->assertTrue( Reader_Data::get_bool( $reader_id, 'is_donor' ) );
		}
		$this->assertSame( 'Flagged 3 donor(s) and 0 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * A repeat run finds everyone already flagged and writes nothing new.
	 */
	public function test_a_second_run_flags_nobody() {
		$reader_id = $this->create_reader();
		$this->create_order( $reader_id );

		$this->run_backfill();
		$second_run = $this->run_backfill();

		$this->assertStringStartsWith( 'Flagged 0 donor(s) and 0 former donor(s). 1 already flagged', $second_run );
	}

	/**
	 * Donor labels only apply to WooCommerce donations, so any other platform stops the run.
	 */
	public function test_errors_when_donations_are_not_woocommerce() {
		update_option( Donations::NEWSPACK_READER_REVENUE_PLATFORM, 'nrh' );
		$reader_id = $this->create_reader();
		$this->create_order( $reader_id );

		$this->expectException( WP_CLI_Mock_Exception::class );
		try {
			Donors::backfill( [], [] );
		} finally {
			$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_donor' ), 'A refused run writes nothing.' );
			$this->assertSame( [], WP_CLI::$successes );
		}
	}

	/**
	 * A batch size under one would read every order at once or loop forever, so it stops the run.
	 */
	public function test_errors_on_a_batch_size_below_one() {
		$this->expectException( WP_CLI_Mock_Exception::class );
		Donors::backfill( [], [ 'batch-size' => 0 ] );
	}
}
