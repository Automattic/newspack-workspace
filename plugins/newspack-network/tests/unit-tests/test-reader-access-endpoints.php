<?php
/**
 * Class TestReaderAccessEndpoints
 *
 * @package Newspack_Network
 */

use Newspack_Network\Content_Gate\Reader_Access_Envelope;
use Newspack_Network\Crypto;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Reader_Access_Endpoint as Hub_Endpoint;
use Newspack_Network\Hub\Stores\Orders;
use Newspack_Network\Hub\Stores\Subscriptions;
use Newspack_Network\Incoming_Events\Group_Members_Changed;
use Newspack_Network\Incoming_Events\Order_Changed;
use Newspack_Network\Incoming_Events\Product_Updated;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Site_Role;
use Newspack_Network\Utils\Requests;

/**
 * How the hub answers a node's question about what a reader holds on the other network sites.
 */
class TestReaderAccessEndpoints extends WP_UnitTestCase {

	const EMAIL = 'reader@example.test';

	const ASKING = 'https://asking.example.test';

	const OTHER = 'https://other.example.test';

	/**
	 * Node URL => [ id, secret ].
	 *
	 * @var array
	 */
	private $nodes = [];

	/**
	 * Register two nodes on a hub and the products they sell.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Site_Role::OPTION_NAME, Site_Role::HUB_ROLE );
		foreach ( [ self::ASKING, self::OTHER ] as $url ) {
			$secret  = Crypto::generate_secret_key();
			$node_id = self::factory()->post->create(
				[
					'post_type'   => Nodes::POST_TYPE_SLUG,
					'post_status' => 'publish',
				]
			);
			update_post_meta( $node_id, 'node-url', $url );
			update_post_meta( $node_id, 'secret-key', $secret );
			$this->nodes[ $url ] = [ $node_id, $secret ];
		}
		update_option(
			Product_Updated::OPTION_NAME,
			[
				self::OTHER           => [
					7  => [ 'network_id' => 'premium' ],
					30 => [ 'network_id' => 'annual-pass' ],
					31 => [ 'network_id' => 'annual-pass' ],
				],
				get_bloginfo( 'url' ) => [
					8 => [ 'network_id' => 'premium' ],
				],
			]
		);
	}

	/**
	 * Clean up options.
	 */
	public function tear_down() {
		delete_option( Site_Role::OPTION_NAME );
		delete_option( 'newspack_node_secret_key' );
		delete_option( Product_Updated::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Record a subscription the hub was told about.
	 *
	 * @param string $site       Site URL.
	 * @param int    $id         Subscription ID on that site.
	 * @param int    $product_id Product ID on that site.
	 * @param string $status     Status.
	 */
	private function subscription( $site, $id, $product_id, $status = 'active' ) {
		Subscriptions::persist(
			new Subscription_Changed(
				$site,
				[
					'id'           => $id,
					'email'        => self::EMAIL,
					'status_after' => $status,
					'products'     => [
						$product_id => [
							'id'   => $product_id,
							'name' => 'Product ' . $product_id,
						],
					],
				],
				time()
			)
		);
	}

	/**
	 * Record an order the hub was told about.
	 *
	 * @param int    $id           Order ID on the other site.
	 * @param string $status       Status.
	 * @param string $date_created Creation date.
	 * @param int    $product_id   Product ID.
	 * @param bool   $subscription Whether the product is a subscription.
	 */
	private function order( $id, $status, $date_created, $product_id = 30, $subscription = false ) {
		Orders::persist(
			new Order_Changed(
				self::OTHER,
				[
					'id'           => $id,
					'email'        => self::EMAIL,
					'status_after' => $status,
					'date_created' => $date_created,
					'products'     => [
						[
							'id'           => $product_id,
							'variation_id' => 0,
							'name'         => 'Product ' . $product_id,
							'subscription' => $subscription,
						],
					],
				],
				time()
			)
		);
	}

	/**
	 * A request to the hub endpoint signed by the asking node.
	 *
	 * @param string $request_id Request ID.
	 * @return WP_REST_Request
	 */
	private function hub_request( $request_id ) {
		update_option( 'newspack_node_secret_key', $this->nodes[ self::ASKING ][1] );
		$params = Requests::sign_params(
			[
				'site'       => self::ASKING,
				'email'      => self::EMAIL,
				'request_id' => $request_id,
			]
		);
		delete_option( 'newspack_node_secret_key' );
		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/reader-access' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * The hub refuses a request no node signed.
	 */
	public function test_hub_endpoint_rejects_unsigned_request() {
		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/reader-access' );
		$request->set_param( 'site', self::ASKING );
		$request->set_param( 'email', self::EMAIL );
		$request->set_param( 'request_id', 'request-1' );

		$this->assertSame( 403, Hub_Endpoint::handle_request( $request )->get_status() );
	}

	/**
	 * The answer is encrypted for the asking node and names the reader and request
	 * it answers, so it can't be forged or replayed for another reader.
	 */
	public function test_answer_is_sealed_and_bound_to_request() {
		$this->subscription( self::OTHER, 42, 7 );
		$envelope = Hub_Endpoint::handle_request( $this->hub_request( 'request-1' ) )->get_data();
		$secret   = $this->nodes[ self::ASKING ][1];

		$this->assertIsArray( Reader_Access_Envelope::open( $envelope, $secret, self::EMAIL, 'request-1' ) );
		$this->assertInstanceOf( WP_Error::class, Reader_Access_Envelope::open( $envelope, $secret, self::EMAIL, 'request-2' ) );
		$this->assertInstanceOf( WP_Error::class, Reader_Access_Envelope::open( $envelope, $secret, 'someone@example.test', 'request-1' ) );
		$this->assertInstanceOf( WP_Error::class, Reader_Access_Envelope::open( $envelope, Crypto::generate_secret_key(), self::EMAIL, 'request-1' ) );
	}

	/**
	 * Subscriptions are answered from the hub's copies for every site but the one
	 * asking, including the hub's own, with each product's Network ID.
	 */
	public function test_answer_lists_subscriptions_from_every_other_site() {
		$this->subscription( get_bloginfo( 'url' ), 1, 8 );
		$this->subscription( self::OTHER, 42, 7, 'pending-cancel' );
		$this->subscription( self::ASKING, 99, 7 );

		$sites = Hub_Endpoint::collect( self::EMAIL, $this->nodes[ self::ASKING ][0] );

		$this->assertEqualsCanonicalizing( [ get_bloginfo( 'url' ), self::OTHER ], array_keys( $sites ) );
		$subscription = $sites[ self::OTHER ]['subscriptions'][42];
		$this->assertSame( 'pending-cancel', $subscription['status'] );
		$this->assertSame( 'premium', $subscription['products'][7]['network_id'] );
	}

	/**
	 * A seat on another site's group subscription is answered with that subscription's
	 * status and Network IDs.
	 */
	public function test_answer_lists_group_seats() {
		( new Group_Members_Changed(
			self::OTHER,
			[
				'id'            => 900,
				'email'         => 'owner@example.test',
				'status_after'  => 'active',
				'products'      => [
					7 => [ 'id' => 7 ],
				],
				'group_enabled' => true,
				'group_members' => [ 'Reader@example.test' ],
			],
			time()
		) )->always_process_in_hub();

		$sites = Hub_Endpoint::collect( self::EMAIL, $this->nodes[ self::ASKING ][0] );

		$this->assertSame(
			[
				[
					'id'          => 900,
					'status'      => 'active',
					'network_ids' => [ 'premium' ],
				],
			],
			$sites[ self::OTHER ]['groups']
		);
	}

	/**
	 * Only paid orders count, only for their one-time products, and only the newest
	 * order for each Network ID, since an older one can never grant more.
	 */
	public function test_answer_lists_newest_paid_one_time_order_per_network_id() {
		$this->order( 1, 'completed', '2026-01-01T00:00:00' );
		$this->order( 2, 'processing', '2026-03-01T00:00:00' );
		$this->order( 3, 'refunded', '2026-05-01T00:00:00' );
		$this->order( 4, 'pending', '2026-06-01T00:00:00' );
		$this->order( 5, 'completed', '2026-07-01T00:00:00', 31, true );

		$sites = Hub_Endpoint::collect( self::EMAIL, $this->nodes[ self::ASKING ][0] );

		$this->assertSame(
			[
				[
					'id'           => 2,
					'date_created' => strtotime( '2026-03-01T00:00:00Z' ),
					'network_ids'  => [ 'annual-pass' ],
				],
			],
			$sites[ self::OTHER ]['orders']
		);
	}

	/**
	 * When the hub is the site being read, its own copies are left out: its local
	 * access rules already cover them.
	 */
	public function test_hub_reading_for_itself_leaves_out_its_own_copies() {
		$this->subscription( get_bloginfo( 'url' ), 1, 8 );
		$this->subscription( self::OTHER, 42, 7 );

		$this->assertSame( [ self::OTHER ], array_keys( Hub_Endpoint::collect( self::EMAIL, 0 ) ) );
	}
}
