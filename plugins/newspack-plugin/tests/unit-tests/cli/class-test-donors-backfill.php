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
	 * The flags don't record what set them, so a real run lists each reader it
	 * flags and support can keep the output. A dry run lists no one, and a reader
	 * who already has a flag isn't listed.
	 */
	public function test_a_real_run_lists_each_reader_it_flags() {
		$donor_id = $this->create_reader();
		$this->create_order( $donor_id );
		$former_id = $this->create_reader();
		$this->create_subscription( $former_id, 'cancelled' );
		$already_id = $this->create_reader();
		Reader_Data::set_is_donor( time(), [ 'user_id' => $already_id ] );
		$this->create_order( $already_id );

		$this->run_backfill( [ 'dry-run' => true ] );
		$this->assertSame( [], WP_CLI::$logs, 'A dry run lists no one.' );

		$this->run_backfill();
		$this->assertSame(
			[
				sprintf( 'Flagged user %d: is_former_donor', $former_id ),
				sprintf( 'Flagged user %d: is_donor', $donor_id ),
			],
			WP_CLI::$logs
		);
		$this->assertSame(
			WP_CLI::$logs,
			array_column( array_filter( WP_CLI::$messages, fn( $message ) => 'line' === $message[0] ), 1 ),
			'The list is written with line(), which prints even under --quiet.'
		);
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
	 * Checking a lapsed donor's flags loads their user meta, so a store with many of
	 * them would hold every record until the order walk began. The run clears the
	 * cache after each batch of readers, before reading the next one.
	 */
	public function test_clears_the_cache_between_batches_of_ended_holders() {
		$reader_ids = [];
		for ( $i = 0; $i < 4; $i++ ) {
			$reader_id    = $this->create_reader();
			$reader_ids[] = $reader_id;
			$this->create_subscription( $reader_id, 'cancelled' );
		}
		// An already-flagged reader still had their flags checked, so still counts
		// toward the batch: on a repeat run every lapsed donor is already flagged.
		Reader_Data::set_is_former_donor( time(), [ 'user_id' => $reader_ids[1] ] );

		// The mock appends 'clear' to this log; the filter appends the reader whose
		// reader-data keys are being read, which every flag check does first.
		$GLOBALS['wp_cli_mock_cache_log'] = [];
		$log_reads                        = function ( $value, $user_id, $meta_key ) {
			if ( 'newspack_reader_data_keys' === $meta_key ) {
				$GLOBALS['wp_cli_mock_cache_log'][] = (int) $user_id;
			}
			return $value;
		};
		add_filter( 'get_user_metadata', $log_reads, 10, 3 );
		try {
			$this->run_backfill(
				[
					'dry-run'    => true,
					'batch-size' => 2,
				]
			);
		} finally {
			remove_filter( 'get_user_metadata', $log_reads, 10 );
			$log = $GLOBALS['wp_cli_mock_cache_log'];
			unset( $GLOBALS['wp_cli_mock_cache_log'] );
		}

		// Each reader is read more than once in a row; keep one entry per run of reads.
		$sequence = [];
		foreach ( $log as $entry ) {
			if ( end( $sequence ) !== $entry ) {
				$sequence[] = $entry;
			}
		}
		$first = array_search( $reader_ids[0], $sequence, true );
		$last  = array_search( $reader_ids[3], $sequence, true );
		$this->assertSame(
			[ $reader_ids[0], $reader_ids[1], 'clear', $reader_ids[2], $reader_ids[3] ],
			array_slice( $sequence, $first, $last - $first + 1 )
		);
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
	 * Run the command in a real run with every reader's data capped at $max_items keys.
	 *
	 * `update_item()` refuses a new key once a reader holds that many, and the
	 * Reader_Data setters drop the refusal, so this is how a full reader looks to the
	 * command.
	 *
	 * @param int   $max_items  The per-reader key cap.
	 * @param array $assoc_args The command's named options.
	 *
	 * @return string The success line.
	 */
	private function run_backfill_with_item_cap( int $max_items, array $assoc_args = [] ): string {
		$cap = function () use ( $max_items ) {
			return $max_items;
		};
		add_filter( 'newspack_reader_data_max_items', $cap );
		try {
			return $this->run_backfill( $assoc_args );
		} finally {
			remove_filter( 'newspack_reader_data_max_items', $cap );
		}
	}

	/**
	 * A reader whose data is full can't take the flag. The run says so and doesn't
	 * report a flag it didn't write.
	 */
	public function test_warns_and_does_not_count_a_donor_whose_flag_cannot_be_written() {
		$reader_id = $this->create_reader();
		$this->create_order( $reader_id );

		$message = $this->run_backfill_with_item_cap( 0 );

		$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_donor' ) );
		$this->assertCount( 1, WP_CLI::$warnings );
		$this->assertStringContainsString( (string) $reader_id, WP_CLI::$warnings[0] );
		$this->assertSame( [], WP_CLI::$logs, 'A reader whose flag was refused is not listed as flagged.' );
		$this->assertSame( 'Flagged 0 donor(s) and 0 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * With room for one more key, the setter writes `is_donor = false` and then has
	 * no room for `is_former_donor = true`. The reader ends with neither flag, so the
	 * run warns and doesn't count them.
	 */
	public function test_warns_and_does_not_count_a_former_donor_whose_flag_is_half_written() {
		$reader_id = $this->create_reader();
		$this->create_subscription( $reader_id, 'cancelled' );

		$message = $this->run_backfill_with_item_cap( 1 );

		$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_former_donor' ) );
		$this->assertFalse( Reader_Data::get_bool( $reader_id, 'is_donor' ) );
		$this->assertCount( 1, WP_CLI::$warnings );
		$this->assertStringContainsString( (string) $reader_id, WP_CLI::$warnings[0] );
		$this->assertSame( [], WP_CLI::$logs, 'A reader whose flag was half written is not listed as flagged.' );
		$this->assertSame( 'Flagged 0 donor(s) and 0 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * The donor flag is what a donor needs. If it is written and only the clearing
	 * of `is_former_donor` is refused, the reader is flagged and nothing warns.
	 */
	public function test_counts_a_donor_whose_flag_was_written_when_only_the_reset_was_refused() {
		$reader_id = $this->create_reader();
		$this->create_order( $reader_id );

		$message = $this->run_backfill_with_item_cap( 1 );

		$this->assertTrue( Reader_Data::get_bool( $reader_id, 'is_donor' ) );
		$this->assertSame( [], WP_CLI::$warnings );
		$this->assertSame( 'Flagged 1 donor(s) and 0 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
	}

	/**
	 * A dry run writes nothing, so it can't know which writes would be refused. It
	 * reports every reader it would try.
	 */
	public function test_a_dry_run_counts_a_reader_whose_data_is_full_without_warning() {
		$reader_id = $this->create_reader();
		$this->create_order( $reader_id );

		$message = $this->run_backfill_with_item_cap( 0, [ 'dry-run' => true ] );

		$this->assertSame( [], WP_CLI::$warnings );
		$this->assertSame( 'Would flag 1 donor(s) and 0 former donor(s). 0 already flagged; 0 guest order(s) skipped.', $message );
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
