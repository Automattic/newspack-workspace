<?php
/**
 * Class TestReaderAccessCollector
 *
 * @package Newspack_Network
 */

use Newspack_Network\Content_Gate\Reader_Access_Collector;
use Newspack_Network\Woocommerce\Product_Admin;

/**
 * What a site reports about a reader's own purchases when another site asks.
 */
class TestReaderAccessCollector extends WP_UnitTestCase {

	/**
	 * A product post, optionally tagged with a Network ID, as a stand-in for a
	 * WooCommerce product; the suite doesn't load WooCommerce.
	 *
	 * @param string $network_id Network ID, or '' for none.
	 * @param string $type       Product type.
	 * @return object
	 */
	private function product( $network_id, $type = 'simple' ) {
		$id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		if ( $network_id ) {
			update_post_meta( $id, Product_Admin::NETWORK_ID_META_KEY, $network_id );
		}
		return new class( $id, $type ) {
			/**
			 * Constructor.
			 *
			 * @param int    $id   Product ID.
			 * @param string $type Product type.
			 */
			public function __construct( public $id, public $type ) {}

			/**
			 * Product ID.
			 *
			 * @return int
			 */
			public function get_id() {
				return $this->id;
			}

			/**
			 * Product name.
			 *
			 * @return string
			 */
			public function get_name() {
				return 'Product ' . $this->id;
			}

			/**
			 * Product slug.
			 *
			 * @return string
			 */
			public function get_slug() {
				return 'product-' . $this->id;
			}

			/**
			 * Whether the product is of one of the given types.
			 *
			 * @param string|string[] $types Types to check.
			 * @return bool
			 */
			public function is_type( $types ) {
				return in_array( $this->type, (array) $types, true );
			}
		};
	}

	/**
	 * A line item for a product, or for one that no longer exists.
	 *
	 * @param object|false $product Product.
	 * @return object
	 */
	private function item( $product ) {
		return new class( $product ) {
			/**
			 * Constructor.
			 *
			 * @param object|false $product Product.
			 */
			public function __construct( public $product ) {}

			/**
			 * The item's product.
			 *
			 * @return object|false
			 */
			public function get_product() {
				return $this->product;
			}
		};
	}

	/**
	 * A stand-in for a WooCommerce order or subscription.
	 *
	 * @param int    $id      ID.
	 * @param array  $items   Line items.
	 * @param string $status  Status.
	 * @param int    $created Creation time.
	 * @return object
	 */
	private function order( $id, $items, $status = 'completed', $created = 0 ) {
		return new class( $id, $items, $status, $created ) {
			/**
			 * Constructor.
			 *
			 * @param int    $id      ID.
			 * @param array  $items   Line items.
			 * @param string $status  Status.
			 * @param int    $created Creation time.
			 */
			public function __construct( public $id, public $items, public $status, public $created ) {}

			/**
			 * ID.
			 *
			 * @return int
			 */
			public function get_id() {
				return $this->id;
			}

			/**
			 * Status.
			 *
			 * @return string
			 */
			public function get_status() {
				return $this->status;
			}

			/**
			 * Line items.
			 *
			 * @return array
			 */
			public function get_items() {
				return $this->items;
			}

			/**
			 * Creation date.
			 *
			 * @return DateTime
			 */
			public function get_date_created() {
				return new DateTime( '@' . $this->created );
			}
		};
	}

	/**
	 * Without WooCommerce there is nothing to report, and nothing breaks.
	 */
	public function test_collect_without_woocommerce_reports_nothing() {
		self::factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		$this->assertSame(
			[
				'subscriptions' => [],
				'groups'        => [],
				'orders'        => [],
			],
			Reader_Access_Collector::collect( 'reader@example.test' )
		);
	}

	/**
	 * A subscription is reported in the shape subscription events record, plus
	 * each product's Network ID, so the reading site needs no product sync to match it.
	 */
	public function test_format_subscription_names_product_network_ids() {
		$tagged       = $this->product( 'premium', 'subscription' );
		$untagged     = $this->product( '', 'subscription' );
		$subscription = $this->order( 42, [ $this->item( $tagged ), $this->item( $untagged ), $this->item( false ) ], 'on-hold' );

		$record = Reader_Access_Collector::format_subscription( $subscription );

		$this->assertSame( 42, $record['id'] );
		$this->assertSame( 'on-hold', $record['status'] );
		$this->assertSame( [ $tagged->id, $untagged->id ], array_keys( $record['products'] ) );
		$this->assertSame( 'premium', $record['products'][ $tagged->id ]['network_id'] );
		$this->assertSame( '', $record['products'][ $untagged->id ]['network_id'] );
	}

	/**
	 * A group seat reports the group subscription's status and its products' Network IDs.
	 */
	public function test_format_group_seat() {
		$subscription = $this->order( 900, [ $this->item( $this->product( 'premium', 'subscription' ) ) ], 'pending-cancel' );

		$this->assertSame(
			[
				'id'          => 900,
				'status'      => 'pending-cancel',
				'network_ids' => [ 'premium' ],
			],
			Reader_Access_Collector::format_group_seat( $subscription )
		);
	}

	/**
	 * An order reports only its one-time products' Network IDs: a subscription product
	 * sharing a Network ID must not turn a renewal into a one-time purchase.
	 */
	public function test_format_order_counts_one_time_products_only() {
		$created = time() - DAY_IN_SECONDS;
		$order   = $this->order(
			700,
			[
				$this->item( $this->product( 'annual-pass' ) ),
				$this->item( $this->product( 'premium', 'subscription' ) ),
				$this->item( $this->product( '' ) ),
			],
			'completed',
			$created
		);

		$this->assertSame(
			[
				'id'           => 700,
				'date_created' => $created,
				'network_ids'  => [ 'annual-pass' ],
			],
			Reader_Access_Collector::format_order( $order )
		);
	}

	/**
	 * Only the newest order per Network ID can decide access, so older ones are
	 * dropped, as are orders with no Network ID at all. Input is newest first.
	 */
	public function test_keep_newest_order_per_network_id() {
		$orders = [
			[
				'id'           => 3,
				'date_created' => 300,
				'network_ids'  => [ 'annual-pass' ],
			],
			[
				'id'           => 2,
				'date_created' => 200,
				'network_ids'  => [],
			],
			[
				'id'           => 1,
				'date_created' => 100,
				'network_ids'  => [ 'annual-pass', 'monthly-pass' ],
			],
			[
				'id'           => 0,
				'date_created' => 50,
				'network_ids'  => [ 'monthly-pass' ],
			],
		];

		$kept = Reader_Access_Collector::keep_newest_order_per_network_id( $orders );

		$this->assertSame( [ 3, 1 ], wp_list_pluck( $kept, 'id' ) );
		$this->assertSame( [ 'monthly-pass' ], $kept[1]['network_ids'] );
	}
}
