<?php
/**
 * Class TestHubWooStores
 *
 * @package Newspack_Network
 */

use Newspack_Network\Hub\Database\Orders as Orders_DB;
use Newspack_Network\Hub\Database\Subscriptions as Subscriptions_DB;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Stores\Orders;
use Newspack_Network\Hub\Stores\Subscriptions;
use Newspack_Network\Incoming_Events\Order_Changed;
use Newspack_Network\Incoming_Events\Subscription_Changed;

/**
 * How the hub keeps its copies of the network's subscriptions and orders.
 */
class TestHubWooStores extends WP_UnitTestCase {

	const NODE_URL = 'https://node.example.test';

	/**
	 * The registered node's ID.
	 *
	 * @var int
	 */
	private $node_id;

	/**
	 * Register a node.
	 */
	public function set_up() {
		parent::set_up();
		$this->node_id = self::factory()->post->create(
			[
				'post_type'   => Nodes::POST_TYPE_SLUG,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $this->node_id, 'node-url', self::NODE_URL );
	}

	/**
	 * A subscription event from a site.
	 *
	 * @param string $site  Site URL.
	 * @param string $email Customer email.
	 * @return Subscription_Changed
	 */
	private function subscription_event( $site, $email ) {
		return new Subscription_Changed(
			$site,
			[
				'id'           => 500,
				'email'        => $email,
				'status_after' => 'active',
				'products'     => [],
			],
			time()
		);
	}

	/**
	 * The hub's copies of subscription #500.
	 *
	 * @return int[]
	 */
	private function copies_of_500() {
		return get_posts(
			[
				'post_type'   => Subscriptions_DB::POST_TYPE_SLUG,
				'post_status' => 'any',
				'meta_key'    => 'remote_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => 500, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'      => 'ids',
			]
		);
	}

	/**
	 * Two sites' subscriptions that share an ID get a copy each, each naming its own site and customer.
	 */
	public function test_same_id_on_two_sites_gets_two_copies() {
		Subscriptions::persist( $this->subscription_event( get_bloginfo( 'url' ), 'hub-reader@example.test' ) );
		Subscriptions::persist( $this->subscription_event( self::NODE_URL, 'node-reader@example.test' ) );

		$copies = $this->copies_of_500();
		$this->assertCount( 2, $copies );
		$emails_by_node = [];
		foreach ( $copies as $copy ) {
			$emails_by_node[ (int) get_post_meta( $copy, 'node_id', true ) ] = get_post_meta( $copy, 'user_email', true );
		}
		ksort( $emails_by_node );
		$this->assertSame(
			[
				0              => 'hub-reader@example.test',
				$this->node_id => 'node-reader@example.test',
			],
			$emails_by_node
		);
	}

	/**
	 * A later event for the same subscription updates its copy's customer email, so a
	 * reader who changed their email is found under the new one.
	 */
	public function test_later_event_updates_customer_email() {
		Subscriptions::persist( $this->subscription_event( self::NODE_URL, 'old@example.test' ) );
		Subscriptions::persist( $this->subscription_event( self::NODE_URL, 'new@example.test' ) );

		$copies = $this->copies_of_500();
		$this->assertCount( 1, $copies );
		$this->assertSame( 'new@example.test', get_post_meta( $copies[0], 'user_email', true ) );
	}

	/**
	 * An order's copy keeps its creation date and line items, which cross-site
	 * one-time access needs.
	 */
	public function test_order_copy_keeps_date_and_products() {
		$products = [
			[
				'id'           => 30,
				'variation_id' => 0,
				'name'         => 'Annual pass',
				'subscription' => false,
			],
			[
				'id'           => 31,
				'variation_id' => 32,
				'name'         => 'Monthly',
				'subscription' => true,
			],
		];
		$order    = new Order_Changed(
			self::NODE_URL,
			[
				'id'           => 700,
				'email'        => 'reader@example.test',
				'status_after' => 'completed',
				'date_created' => '2026-01-15T10:00:00',
				'products'     => $products,
			],
			time()
		);

		$copy = Orders::persist( $order );

		$this->assertSame( Orders_DB::POST_STATUS_PREFIX . 'completed', get_post_status( $copy ) );
		$this->assertSame( '2026-01-15T10:00:00', get_post_meta( $copy, 'date_created', true ) );
		$this->assertSame( $products, get_post_meta( $copy, 'products', false ) );
	}
}
