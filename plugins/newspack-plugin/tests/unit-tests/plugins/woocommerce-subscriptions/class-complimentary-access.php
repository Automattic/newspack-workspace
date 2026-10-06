<?php
/**
 * Tests complimentary access products and the stamp their subscriptions carry.
 *
 * @package Newspack\Tests
 */

use Newspack\Complimentary_Access;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';
require_once __DIR__ . '/../../../../includes/plugins/woocommerce/class-woocommerce-store-api-free-products.php';
require_once __DIR__ . '/../../../../includes/plugins/woocommerce-subscriptions/class-complimentary-access.php';

/**
 * A comp product is flagged; subscriptions and orders holding it are stamped from that
 * flag, and the product can't be bought. The fixture is a variable subscription
 * selling a $0 comp variation beside a paid one, the case where a flag on the wrong
 * level would mark paying readers as comps.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Complimentary_Access extends WP_UnitTestCase {
	const PARENT_ID = 100;
	const COMP_ID   = 101;
	const PAID_ID   = 102;

	/**
	 * Register the variable product and its two variations.
	 */
	public function set_up() {
		parent::set_up();
		global $products_database, $subscriptions_database, $orders_database;
		$products_database      = [];
		$subscriptions_database = [];
		$orders_database        = [];
		wc_create_mock_product(
			[
				'id'       => self::PARENT_ID,
				'type'     => 'variable-subscription',
				'children' => [ self::COMP_ID, self::PAID_ID ],
			]
		);
		wc_create_mock_product(
			[
				'id'        => self::COMP_ID,
				'type'      => 'subscription_variation',
				'parent_id' => self::PARENT_ID,
				'price'     => '0',
				'meta'      => [ Complimentary_Access::META_KEY => 'yes' ],
			]
		);
		wc_create_mock_product(
			[
				'id'        => self::PAID_ID,
				'type'      => 'subscription_variation',
				'parent_id' => self::PARENT_ID,
				'price'     => '10',
			]
		);
	}

	/**
	 * A subscription to one of the variations, with an optional renewal order.
	 *
	 * @param int    $variation_id Variation on the line item.
	 * @param string $created_via  How the subscription was created.
	 * @param array  $renewals     Renewal orders.
	 * @return WC_Subscription
	 */
	private function subscribe( $variation_id, $created_via = 'manual migration', $renewals = [] ) {
		return wcs_create_subscription(
			[
				'created_via'    => $created_via,
				'items'          => [ $this->line_item( $variation_id ) ],
				'related_orders' => [ 'renewal' => $renewals ],
			]
		);
	}

	/**
	 * A line item for a variation.
	 *
	 * @param int $variation_id Variation ID.
	 * @return WC_Order_Item_Product
	 */
	private function line_item( $variation_id ) {
		return new WC_Order_Item_Product(
			[
				'product_id'   => self::PARENT_ID,
				'variation_id' => $variation_id,
			]
		);
	}

	/**
	 * The stamp follows the variation's own flag, and is cleared when the flag goes.
	 */
	public function test_stamp_follows_the_variation_flag() {
		$comp = $this->subscribe( self::COMP_ID );
		$paid = $this->subscribe( self::PAID_ID );

		Complimentary_Access::stamp( $comp );
		Complimentary_Access::stamp( $paid );
		$this->assertTrue( Complimentary_Access::is_complimentary_order( $comp ) );
		$this->assertFalse( $paid->meta_exists( Complimentary_Access::META_KEY ) );

		wc_get_product( self::COMP_ID )->update_meta_data( Complimentary_Access::META_KEY, 'no' );
		Complimentary_Access::stamp( $comp );
		$this->assertFalse( $comp->meta_exists( Complimentary_Access::META_KEY ) );
	}

	/**
	 * Deleting a comp variation keeps the stamps on its subscriptions: with the variation
	 * gone there is nothing to recompute from, and clearing them would count those
	 * readers as paying subscribers. WooCommerce reads such a line item back as the
	 * variable parent, with no variation.
	 */
	public function test_stamp_survives_variation_deletion() {
		global $products_database;
		$comp = $this->subscribe( self::COMP_ID );
		Complimentary_Access::stamp( $comp );

		unset( $products_database[ self::COMP_ID ] );
		$comp->data['items'] = [ new WC_Order_Item_Product( [ 'product_id' => self::PARENT_ID ] ) ];
		Complimentary_Access::stamp( $comp );

		$this->assertTrue( Complimentary_Access::is_complimentary_order( $comp ) );
	}

	/**
	 * Restamping a flagged product's subscriptions reaches their renewal orders too, and
	 * persists only the meta, so the reader isn't re-synced as a changed subscription.
	 */
	public function test_sync_stamps_subscription_and_renewals() {
		$renewal      = new WC_Order(
			[
				'status' => 'completed',
				'items'  => [ $this->line_item( self::COMP_ID ) ],
			]
		);
		$subscription = $this->subscribe( self::COMP_ID, 'manual migration', [ $renewal ] );

		Complimentary_Access::sync_subscriptions( [ $subscription->get_id() ] );

		foreach ( [ $subscription, $renewal ] as $order ) {
			$this->assertTrue( Complimentary_Access::is_complimentary_order( $order ) );
			$this->assertSame( 1, $order->save_meta_data_calls );
		}
		$this->assertSame( 0, $renewal->save_calls );
	}

	/**
	 * A flagged product can't be bought, and a flagged variation isn't offered on its
	 * product page. The paid variation beside it is untouched.
	 */
	public function test_flagged_product_is_not_sold() {
		$comp = wc_get_product( self::COMP_ID );
		$paid = wc_get_product( self::PAID_ID );
		$this->assertFalse( Complimentary_Access::filter_is_purchasable( true, $comp ) );
		$this->assertFalse( Complimentary_Access::filter_variation_is_visible( true, self::COMP_ID, self::PARENT_ID, $comp ) );
		$this->assertTrue( Complimentary_Access::filter_is_purchasable( true, $paid ) );
		$this->assertTrue( Complimentary_Access::filter_variation_is_visible( true, self::PAID_ID, self::PARENT_ID, $paid ) );
	}

	/**
	 * Restamping is queued only for a real flag change. Every subscription product save
	 * writes the option, mostly as a first "no", and deleting a comp product removes its
	 * flag but must leave the stamps.
	 */
	public function test_restamp_is_queued_only_for_product_flag_changes() {
		$product_id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		wc_create_mock_product(
			[
				'id'   => $product_id,
				'type' => 'subscription',
			]
		);
		wcs_create_subscription( [ 'items' => [ new WC_Order_Item_Product( [ 'product_id' => $product_id ] ) ] ] );
		$queued_batches = [];
		$capture        = function ( $pre, $hook, $args ) use ( &$queued_batches ) {
			$queued_batches[] = $args[0];
			return 1;
		};
		add_filter( 'pre_as_enqueue_async_action', $capture, 10, 3 );

		Complimentary_Access::handle_flag_added( 1, $product_id, Complimentary_Access::META_KEY, 'no' );
		$this->assertSame( [], $queued_batches );

		Complimentary_Access::handle_flag_added( 1, $product_id, Complimentary_Access::META_KEY, 'yes' );
		$this->assertCount( 1, $queued_batches );

		Complimentary_Access::handle_product_deletion( $product_id );
		Complimentary_Access::handle_flag_changed( [ 1 ], $product_id, Complimentary_Access::META_KEY );
		$this->assertCount( 1, $queued_batches );

		remove_filter( 'pre_as_enqueue_async_action', $capture, 10 );
	}

	/**
	 * The REST filter's `exclude` matches a missing stamp, so it relies on a cleared stamp
	 * being deleted rather than stored as "no".
	 */
	public function test_rest_filter_adds_meta_clause() {
		$request = new WP_REST_Request( 'GET', '/wc/v3/subscriptions' );
		$request->set_param( 'complimentary', 'only' );
		$only = Complimentary_Access::filter_rest_query( [], $request );
		$request->set_param( 'complimentary', 'exclude' );
		$exclude = Complimentary_Access::filter_rest_query( [ 'meta_query' => [ [ 'key' => '_customer_user' ] ] ], $request ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		$this->assertSame(
			[
				[
					'key'   => Complimentary_Access::META_KEY,
					'value' => 'yes',
				],
			],
			$only['meta_query']
		);
		$this->assertSame(
			[
				'key'     => Complimentary_Access::META_KEY,
				'compare' => 'NOT EXISTS',
			],
			end( $exclude['meta_query'] )
		);
		$this->assertCount( 2, $exclude['meta_query'] );
	}

	/**
	 * A $0 product qualifies for the flag only while every subscription on it was granted
	 * by an admin. Any other source counts as a reader's purchase, including the block
	 * checkout's `store-api`. Pre-marking also needs at least one grant; a migrator, told
	 * which product is the comp target, doesn't. A paid product on a $0 sale isn't free.
	 */
	public function test_only_granted_free_products_qualify_for_the_flag() {
		$free = wc_get_product( self::COMP_ID );
		$this->assertFalse( Complimentary_Access::qualifies_for_flag( $free ) );
		$this->assertTrue( Complimentary_Access::qualifies_for_flag( $free, false ) );

		$this->subscribe( self::COMP_ID, 'manual migration' );
		$this->assertTrue( Complimentary_Access::qualifies_for_flag( $free ) );

		$this->subscribe( self::COMP_ID, 'store-api' );
		$this->assertFalse( Complimentary_Access::qualifies_for_flag( $free, false ) );

		$this->assertFalse( Complimentary_Access::qualifies_for_flag( wc_get_product( self::PAID_ID ), false ) );
		$on_free_sale = wc_create_mock_product(
			[
				'id'            => 300,
				'type'          => 'subscription',
				'regular_price' => '10',
				'price'         => '0',
			]
		);
		$this->assertFalse( Complimentary_Access::qualifies_for_flag( $on_free_sale, false ) );
	}
}
