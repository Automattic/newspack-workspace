<?php
/**
 * Class TestContentGateAccessGrants
 *
 * @package Newspack_Network
 */

use Newspack_Network\Content_Gate\Access;
use Newspack_Network\Incoming_Events\Access_Grant_Changed;
use Newspack_Network\Incoming_Events\Product_Updated;
use Newspack_Network\Woocommerce\Product_Admin;

/**
 * Access granted by a seat on another site's group subscription or a paid one-time order there.
 */
class TestContentGateAccessGrants extends WP_UnitTestCase {

	const SITE = 'https://other.example.test';

	/**
	 * Reader under test.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Local subscription product tagged 'premium'.
	 *
	 * @var int
	 */
	private $premium_product;

	/**
	 * Local one-time product tagged 'annual-pass'.
	 *
	 * @var int
	 */
	private $pass_product;

	/**
	 * A reader, two tagged local products, and the other site's synced products.
	 */
	public function set_up() {
		parent::set_up();
		$this->user_id         = self::factory()->user->create();
		$this->premium_product = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$this->pass_product    = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $this->premium_product, Product_Admin::NETWORK_ID_META_KEY, 'premium' );
		update_post_meta( $this->pass_product, Product_Admin::NETWORK_ID_META_KEY, 'annual-pass' );
		update_option(
			Product_Updated::OPTION_NAME,
			[
				self::SITE => [
					7  => [ 'network_id' => 'premium' ],
					30 => [ 'network_id' => 'annual-pass' ],
					31 => [ 'network_id' => '' ],
				],
			],
			false
		);
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		delete_option( Product_Updated::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Store a grant for the reader.
	 *
	 * @param string $key    Grant key.
	 * @param array  $record Record.
	 */
	private function grant( $key, $record ) {
		$grants                       = Access_Grant_Changed::get_user_grants( $this->user_id );
		$grants[ self::SITE ][ $key ] = $record;
		update_user_meta( $this->user_id, Access_Grant_Changed::USER_GRANTS_META_KEY, $grants );
	}

	/**
	 * A seat record.
	 *
	 * @param string $status     Status.
	 * @param int    $product_id Product on the other site.
	 */
	private function seat( $status, $product_id = 7 ) {
		$this->grant(
			'group:90',
			[
				'type'     => 'group',
				'id'       => 90,
				'status'   => $status,
				'products' => [ $product_id => [ 'id' => $product_id ] ],
			]
		);
	}

	/**
	 * A purchase record.
	 *
	 * @param int    $days_ago   Age in days.
	 * @param string $status     Status.
	 * @param int    $product_id Product on the other site.
	 */
	private function purchase( $days_ago, $status = 'completed', $product_id = 30 ) {
		$this->grant(
			'order:86',
			[
				'type'         => 'purchase',
				'id'           => 86,
				'status'       => $status,
				'purchased_at' => time() - $days_ago * DAY_IN_SECONDS,
				'products'     => [ $product_id => [ 'id' => $product_id ] ],
			]
		);
	}

	/**
	 * Whether the subscription rule passes.
	 *
	 * @param bool $strict Strict.
	 * @return bool
	 */
	private function subscription_passes( $strict = false ) {
		return Access::check_network_subscriptions( false, $this->user_id, [ $this->premium_product ], $strict );
	}

	/**
	 * Whether the one-time rule passes.
	 *
	 * @param int    $duration_value Duration.
	 * @param string $duration_unit  Unit.
	 * @return bool
	 */
	private function one_time_passes( $duration_value, $duration_unit ) {
		return Access::check_network_one_time_purchases(
			false,
			$this->user_id,
			[
				'product_ids'    => [ $this->pass_product ],
				'duration_value' => $duration_value,
				'duration_unit'  => $duration_unit,
			]
		);
	}

	/**
	 * An active or pending-cancel seat grants access; an ended one doesn't.
	 */
	public function test_seat_status_decides_access() {
		$this->seat( 'active' );
		$this->assertTrue( $this->subscription_passes() );
		$this->seat( 'pending-cancel' );
		$this->assertTrue( $this->subscription_passes() );
		$this->seat( 'cancelled' );
		$this->assertFalse( $this->subscription_passes() );
	}

	/**
	 * A seat for a product with another Network ID grants nothing.
	 */
	public function test_seat_with_other_network_id_denies_access() {
		$this->seat( 'active', 31 );
		$this->assertFalse( $this->subscription_passes() );
	}

	/**
	 * A strict check counts only subscriptions the reader owns, so access attribution
	 * can label a seat as group access.
	 */
	public function test_strict_check_ignores_seats() {
		$this->seat( 'active' );
		$this->assertFalse( $this->subscription_passes( true ) );
	}

	/**
	 * The registration's argument count is what carries `$strict` to the callback.
	 */
	public function test_strict_check_ignores_seats_through_the_filter() {
		$this->seat( 'active' );
		$this->assertFalse( apply_filters( 'newspack_access_rules_has_active_subscription', false, $this->user_id, [ $this->premium_product ], true ) );
		$this->assertTrue( apply_filters( 'newspack_access_rules_has_active_subscription', false, $this->user_id, [ $this->premium_product ], false ) );
	}

	/**
	 * A paid order grants access within the rule's duration, counted from the purchase.
	 */
	public function test_purchase_within_duration_grants_access() {
		$this->purchase( 10 );
		$this->assertTrue( $this->one_time_passes( 30, 'days' ) );
		$this->assertFalse( $this->one_time_passes( 5, 'days' ) );
	}

	/**
	 * Month durations and lifetime access behave as the local rule does.
	 */
	public function test_purchase_month_and_forever_durations() {
		$this->purchase( 300 );
		$this->assertTrue( $this->one_time_passes( 12, 'months' ) );
		$this->assertFalse( $this->one_time_passes( 6, 'months' ) );
		$this->assertTrue( $this->one_time_passes( 0, 'forever' ) );
	}

	/**
	 * A misconfigured duration fails closed.
	 */
	public function test_purchase_with_misconfigured_duration_denies_access() {
		$this->purchase( 1 );
		$this->assertFalse( $this->one_time_passes( 0, 'days' ) );
		$this->assertFalse( $this->one_time_passes( 5, 'years' ) );
	}

	/**
	 * An order that is no longer paid (refunded, cancelled, pending) grants nothing.
	 */
	public function test_unpaid_order_grant_denies_access() {
		foreach ( [ 'refunded', 'cancelled', 'pending', 'trash' ] as $status ) {
			$this->purchase( 1, $status );
			$this->assertFalse( $this->one_time_passes( 30, 'days' ), $status );
		}
	}

	/**
	 * A purchase whose product has no synced Network ID grants nothing.
	 */
	public function test_purchase_grant_without_synced_network_id_denies_access() {
		$this->purchase( 1, 'completed', 31 );
		$this->assertFalse( $this->one_time_passes( 30, 'days' ) );
	}

	/**
	 * A purchase never satisfies a subscription rule, and a seat never satisfies a one-time rule.
	 */
	public function test_grant_types_are_not_interchangeable() {
		$this->purchase( 1, 'completed', 7 );
		$this->assertFalse( $this->subscription_passes() );
		$this->seat( 'active', 30 );
		$this->assertFalse( $this->one_time_passes( 30, 'days' ) );
	}

	/**
	 * A local result that already passed is left alone.
	 */
	public function test_local_result_passes_through() {
		$this->assertTrue( Access::check_network_one_time_purchases( true, $this->user_id, [ 'product_ids' => [ $this->pass_product ] ] ) );
	}

	/**
	 * Both filters are hooked where newspack-plugin evaluates the rules.
	 */
	public function test_filters_are_hooked() {
		$this->assertSame( 10, has_filter( 'newspack_access_rules_has_active_subscription', [ Access::class, 'check_network_subscriptions' ] ) );
		$this->assertSame( 10, has_filter( 'newspack_access_rules_has_one_time_purchase', [ Access::class, 'check_network_one_time_purchases' ] ) );
	}
}
