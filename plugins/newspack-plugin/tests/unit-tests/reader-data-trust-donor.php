<?php
/**
 * Tests for recomputing donor status for reader-data rules.
 *
 * @package Newspack\Tests
 * @group reader-data-trust
 */

use Newspack\Reader_Data;
use Newspack\Reader_Data_Trust;
use Newspack\WooCommerce_Products;

require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * Tests for Reader_Data_Trust::compute_is_donor() and its use in get_trusted_value().
 *
 * @group reader-data-trust
 */
class Newspack_Test_Reader_Data_Trust_Donor extends WP_UnitTestCase {

	/**
	 * User ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Donation product ID.
	 *
	 * @var int
	 */
	private $donation_product_id;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->user_id             = $this->factory()->user->create();
		$this->donation_product_id = $this->factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $this->donation_product_id, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		Reader_Data_Trust::reset_cache();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_has_server_side_donor_tracking' );
		remove_all_filters( 'newspack_reader_data_compute_is_donor' );
		Reader_Data_Trust::reset_cache();
		parent::tear_down();
	}

	/**
	 * Turn server-side donor tracking on or off.
	 *
	 * @param bool $on Whether tracking is on.
	 */
	private function set_tracking( $on ) {
		add_filter( 'newspack_has_server_side_donor_tracking', $on ? '__return_true' : '__return_false' );
		Reader_Data_Trust::reset_cache();
	}

	/**
	 * Store a value the server did not write.
	 *
	 * @param string $value Raw value.
	 */
	private function store_unmarked( $value ) {
		Reader_Data::update_item( $this->user_id, 'is_donor', $value, false );
	}

	/**
	 * Create a completed order for the donation product.
	 *
	 * @param array $meta Order meta.
	 *
	 * @return WC_Order
	 */
	private function create_donation_order( $meta = [] ) {
		return wc_create_order(
			[
				'customer_id' => $this->user_id,
				'status'      => 'completed',
				'items'       => [ new WC_Order_Item_Product( [ 'product_id' => $this->donation_product_id ] ) ],
				'meta'        => $meta,
			]
		);
	}

	/**
	 * Create a recurring donation subscription with its parent and renewal orders.
	 *
	 * @param string $status Subscription status.
	 *
	 * @return WC_Subscription
	 */
	private function create_recurring_donation( $status ) {
		$parent       = $this->create_donation_order();
		$subscription = wcs_create_subscription(
			[
				'customer_id' => $this->user_id,
				'status'      => $status,
				'parent_id'   => $parent->get_id(),
				'items'       => [ new WC_Order_Item_Product( [ 'product_id' => $this->donation_product_id ] ) ],
			]
		);
		$this->create_donation_order( [ '_subscription_renewal' => $subscription->get_id() ] );
		return $subscription;
	}

	/**
	 * Seed the per-request donor memo, as if the order lookup had run.
	 *
	 * @param bool|null $is_donor Lookup result.
	 */
	private function seed_donor_memo( $is_donor ) {
		$memo = new ReflectionProperty( Reader_Data_Trust::class, 'donor_cache' );
		$memo->setAccessible( true );
		$memo->setValue( null, [ $this->user_id => $is_donor ] );
	}

	/**
	 * A one-time donation order makes the user a donor.
	 */
	public function test_one_time_donor_recomputed_true() {
		$this->set_tracking( true );
		$this->create_donation_order();

		$this->assertTrue( Reader_Data_Trust::compute_is_donor( $this->user_id ) );
	}

	/**
	 * An active recurring donation makes the user a donor.
	 */
	public function test_active_recurring_donor_recomputed_true() {
		$this->set_tracking( true );
		$this->create_recurring_donation( 'active' );

		$this->assertTrue( Reader_Data_Trust::compute_is_donor( $this->user_id ) );
	}

	/**
	 * A cancelled recurring donation's parent and renewal orders do not count.
	 */
	public function test_former_recurring_donor_recomputed_false() {
		$this->set_tracking( true );
		$this->create_recurring_donation( 'cancelled' );

		$this->assertFalse( Reader_Data_Trust::compute_is_donor( $this->user_id ) );
	}

	/**
	 * A completed switch order for a cancelled recurring donation does not count.
	 */
	public function test_former_recurring_donor_with_switch_order_recomputed_false() {
		$this->set_tracking( true );
		$subscription = $this->create_recurring_donation( 'cancelled' );
		$this->create_donation_order( [ '_subscription_switch' => $subscription->get_id() ] );

		$this->assertFalse( Reader_Data_Trust::compute_is_donor( $this->user_id ) );
	}

	/**
	 * A completed resubscribe order for a cancelled recurring donation does not count.
	 */
	public function test_former_recurring_donor_with_resubscribe_order_recomputed_false() {
		$this->set_tracking( true );
		$subscription = $this->create_recurring_donation( 'cancelled' );
		$this->create_donation_order( [ '_subscription_resubscribe' => $subscription->get_id() ] );

		$this->assertFalse( Reader_Data_Trust::compute_is_donor( $this->user_id ) );
	}

	/**
	 * A user with no orders is not a donor.
	 */
	public function test_user_without_orders_recomputed_false() {
		$this->set_tracking( true );

		$this->assertFalse( Reader_Data_Trust::compute_is_donor( $this->user_id ) );
	}

	/**
	 * Without server-side tracking there is nothing to compute.
	 */
	public function test_no_server_tracking_returns_null() {
		$this->set_tracking( false );

		$this->assertNull( Reader_Data_Trust::compute_is_donor( $this->user_id ) );
	}

	/**
	 * A second evaluation in the same request reuses the first lookup.
	 */
	public function test_lookup_runs_once_per_request() {
		global $wc_mocks_get_orders_calls;
		$this->set_tracking( true );
		$this->create_donation_order();
		$wc_mocks_get_orders_calls = 0;

		Reader_Data_Trust::compute_is_donor( $this->user_id );
		Reader_Data_Trust::compute_is_donor( $this->user_id );

		$this->assertSame( 1, $wc_mocks_get_orders_calls );
	}

	/**
	 * An unmarked value is recomputed and written with a mark.
	 */
	public function test_recompute_writes_marked_value() {
		$this->set_tracking( true );
		add_filter( 'newspack_reader_data_compute_is_donor', '__return_true' );
		$this->store_unmarked( 'false' );

		$this->assertSame( 'true', Reader_Data_Trust::get_trusted_value( $this->user_id, 'is_donor' ) );
		$this->assertTrue( Reader_Data_Trust::has_valid_mark( $this->user_id, 'is_donor' ) );
	}

	/**
	 * An unmarked `true` from outside WooCommerce stays in place when no donation is found.
	 */
	public function test_unmarked_true_is_kept_when_recompute_finds_no_donation() {
		$this->set_tracking( true );
		add_filter( 'newspack_reader_data_compute_is_donor', '__return_false' );
		$this->store_unmarked( 'true' );

		$this->assertNull( Reader_Data_Trust::get_trusted_value( $this->user_id, 'is_donor' ) );
		$this->assertSame( 'true', get_user_meta( $this->user_id, Reader_Data::get_meta_key_name( 'is_donor' ), true ) );
		$this->assertFalse( Reader_Data_Trust::has_valid_mark( $this->user_id, 'is_donor' ) );
	}

	/**
	 * An unset value is recomputed too.
	 */
	public function test_unset_value_is_recomputed() {
		$this->set_tracking( true );
		add_filter( 'newspack_reader_data_compute_is_donor', '__return_false' );

		$this->assertSame( 'false', Reader_Data_Trust::get_trusted_value( $this->user_id, 'is_donor' ) );
		$this->assertTrue( Reader_Data_Trust::has_valid_mark( $this->user_id, 'is_donor' ) );
	}

	/**
	 * A marked value is returned as stored, with no recompute.
	 */
	public function test_marked_value_is_not_recomputed() {
		$this->set_tracking( true );
		Reader_Data::update_item( $this->user_id, 'is_donor', true );
		add_filter( 'newspack_reader_data_compute_is_donor', '__return_false' );

		$this->assertSame( 'true', Reader_Data_Trust::get_trusted_value( $this->user_id, 'is_donor' ) );
	}

	/**
	 * Without server tracking the key is not trusted, and an unmarked value stays unwritten.
	 */
	public function test_no_tracking_leaves_unmarked_value() {
		$this->set_tracking( false );
		add_filter( 'newspack_reader_data_compute_is_donor', '__return_true' );
		$this->store_unmarked( 'true' );

		$this->assertNull( Reader_Data_Trust::get_trusted_value( $this->user_id, 'is_donor' ) );
		$this->assertFalse( Reader_Data_Trust::has_valid_mark( $this->user_id, 'is_donor' ) );
	}

	/**
	 * User 0 gets no value and nothing is written.
	 */
	public function test_user_zero_returns_null_and_writes_nothing() {
		global $wpdb;
		$this->set_tracking( true );
		add_filter( 'newspack_reader_data_compute_is_donor', '__return_true' );

		$this->assertNull( Reader_Data_Trust::compute_is_donor( 0 ) );
		$this->assertNull( Reader_Data_Trust::get_trusted_value( 0, 'is_donor' ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = 0" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A lookup that could not be made writes nothing.
	 *
	 * The mocks define wc_get_orders() for the whole suite, so the lookup's
	 * null result is seeded in the per-request memo instead.
	 */
	public function test_unavailable_lookup_writes_nothing() {
		$this->set_tracking( true );
		$this->seed_donor_memo( null );

		$this->assertNull( Reader_Data_Trust::compute_is_donor( $this->user_id ) );
		$this->assertNull( Reader_Data_Trust::get_trusted_value( $this->user_id, 'is_donor' ) );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, Reader_Data::get_meta_key_name( 'is_donor' ), true ) );
		$this->assertFalse( Reader_Data_Trust::has_valid_mark( $this->user_id, 'is_donor' ) );
	}
}
