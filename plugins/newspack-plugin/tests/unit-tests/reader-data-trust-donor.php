<?php
/**
 * Tests for recomputing donor status for reader-data rules.
 *
 * @package Newspack\Tests
 * @group reader-data-trust
 */

use Newspack\Reader_Data;
use Newspack\Reader_Data_Trust;

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
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->user_id = $this->factory()->user->create();
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
		update_user_meta( $this->user_id, Reader_Data::get_meta_key_name( 'is_donor' ), $value );
	}

	/**
	 * Skip unless WooCommerce is loaded.
	 */
	private function require_woocommerce() {
		if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
	}

	/**
	 * A one-time donation order makes the user a donor.
	 */
	public function test_one_time_donor_recomputed_true() {
		$this->require_woocommerce();
		$this->set_tracking( true );
		$product = new WC_Product_Simple();
		$product->set_name( 'Donation' );
		$product->save();
		update_post_meta( $product->get_id(), \Newspack\WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		$order = wc_create_order( [ 'customer_id' => $this->user_id ] );
		$order->add_product( $product, 1 );
		$order->set_status( 'completed' );
		$order->save();

		$this->assertTrue( Reader_Data_Trust::compute_is_donor( $this->user_id ) );
	}

	/**
	 * A user with no donation is not a donor.
	 */
	public function test_non_donor_recomputed_false() {
		$this->require_woocommerce();
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
	 */
	public function test_unavailable_lookup_writes_nothing() {
		if ( function_exists( 'wc_get_orders' ) ) {
			$this->markTestSkipped( 'WooCommerce order lookup is available.' );
		}
		$this->set_tracking( true );

		$this->assertNull( Reader_Data_Trust::compute_is_donor( $this->user_id ) );
		$this->assertNull( Reader_Data_Trust::get_trusted_value( $this->user_id, 'is_donor' ) );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, Reader_Data::get_meta_key_name( 'is_donor' ), true ) );
		$this->assertFalse( Reader_Data_Trust::has_valid_mark( $this->user_id, 'is_donor' ) );
	}
}
