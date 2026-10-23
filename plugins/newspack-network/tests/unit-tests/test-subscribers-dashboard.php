<?php
/**
 * Class Test_Subscribers_Dashboard
 *
 * @package Newspack_Network
 */

use Newspack_Network\Hub\Node as Hub_Node;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Site_Role;
use Newspack_Network\Utils\Sites;
use Newspack_Network\Utils\Users;

/**
 * Test the network data the Subscribers dashboard reads through its filters.
 */
class Test_Subscribers_Dashboard extends WP_UnitTestCase {

	/**
	 * The other node used in the fixtures.
	 *
	 * @var string
	 */
	const NODE_URL = 'http://node-b.example.test';

	/**
	 * Set up a node that knows about a hub and one other node.
	 */
	public function set_up() {
		parent::set_up();

		update_option( Site_Role::OPTION_NAME, Site_Role::NODE_ROLE );
		update_option( 'newspack_node_hub_url', 'https://hub.example.test' );
		update_option( 'newspack_network_hub_name', 'Hub Gazette' );
		update_option(
			Hub_Node::HUB_NODES_SYNCED_OPTION,
			[
				[
					'id'    => 2,
					'title' => 'Node Daily',
					'url'   => self::NODE_URL,
				],
			]
		);

		Sites::reset_cache();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		delete_option( Site_Role::OPTION_NAME );
		delete_option( 'newspack_node_hub_url' );
		delete_option( 'newspack_network_hub_name' );
		delete_option( Hub_Node::HUB_NODES_SYNCED_OPTION );
		Sites::reset_cache();
		parent::tear_down();
	}

	/**
	 * Create a user with a subscription recorded on the other node.
	 *
	 * @param int $subscription_id The subscription's ID on the node.
	 *
	 * @return int The user ID.
	 */
	private function create_user_with_subscription( $subscription_id ) {
		$user_id = $this->factory()->user->create();
		update_user_meta(
			$user_id,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				self::NODE_URL => [
					$subscription_id => [
						'id'       => $subscription_id,
						'status'   => 'active',
						'products' => [],
					],
				],
			]
		);
		return $user_id;
	}

	/**
	 * Every recorded subscription comes back in the shape the dashboard expects.
	 */
	public function test_records_for_user() {
		$user_id = $this->factory()->user->create();
		update_user_meta(
			$user_id,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				self::NODE_URL => [
					500 => [
						'id'       => 500,
						'status'   => 'active',
						'products' => [
							[
								'id'   => 7,
								'name' => 'Fan Membership',
							],
						],
					],
					501 => [
						'id'       => 501,
						'status'   => 'cancelled',
						'products' => [],
					],
				],
			]
		);

		$records = apply_filters( 'newspack_subscribers_network_subscriptions', [], $user_id );

		$this->assertCount( 2, $records );
		$this->assertSame(
			[
				'id'        => 500,
				'site_url'  => self::NODE_URL,
				'site_name' => 'Node Daily',
				'status'    => 'active',
				'plan'      => 'Fan Membership',
				'edit_url'  => 'http://node-b.example.test/wp-admin/post.php?post=500&action=edit',
			],
			$records[0]
		);
		$this->assertSame( '', $records[1]['plan'] );
		$this->assertSame( 'cancelled', $records[1]['status'] );
	}

	/**
	 * Records from the filter's earlier callbacks are kept.
	 */
	public function test_records_are_added_to_the_ones_passed_in() {
		$user_id  = $this->create_user_with_subscription( 500 );
		$existing = [ [ 'id' => 1 ] ];

		$records = apply_filters( 'newspack_subscribers_network_subscriptions', $existing, $user_id );

		$this->assertCount( 2, $records );
		$this->assertSame( $existing[0], $records[0] );
		$this->assertSame( 500, $records[1]['id'] );
	}

	/**
	 * A subscription's plan joins its product names and skips blank ones.
	 */
	public function test_plan_joins_product_names() {
		$user_id = $this->factory()->user->create();
		update_user_meta(
			$user_id,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				self::NODE_URL => [
					500 => [
						'id'       => 500,
						'status'   => 'pending-cancel',
						'products' => [
							[ 'name' => 'Fan Membership' ],
							[ 'name' => '' ],
							[ 'id' => 9 ],
							'not a product',
							[ 'name' => 'Weekend Digest' ],
						],
					],
				],
			]
		);

		$records = Newspack_Network\Woocommerce_Subscriptions\Subscribers_Dashboard::get_records_for_user( $user_id );

		$this->assertCount( 1, $records );
		$this->assertSame( 'Fan Membership, Weekend Digest', $records[0]['plan'] );
		$this->assertSame( 'pending-cancel', $records[0]['status'] );
	}

	/**
	 * Entries for this site and malformed entries are left out without any warning, and the valid entries beside them are kept.
	 */
	public function test_current_site_and_malformed_entries_skipped() {
		$user_id = $this->factory()->user->create();
		update_user_meta(
			$user_id,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				get_bloginfo( 'url' )   => [
					600 => [
						'id'       => 600,
						'status'   => 'active',
						'products' => [],
					],
				],
				'http://x.example.test' => 'garbage',
				'http://y.example.test' => [
					700 => [
						'id'       => 700,
						'products' => [],
					],
					701 => 'garbage',
					702 => [
						'id'     => 'abc',
						'status' => 'active',
					],
					703 => [
						'id'     => 703,
						'status' => [ 'active' ],
					],
					704 => [
						'id'     => 0,
						'status' => 'active',
					],
					705 => [
						'id'       => 705,
						'status'   => 'active',
						'products' => [],
					],
				],
			]
		);

		$records = apply_filters( 'newspack_subscribers_network_subscriptions', [], $user_id );

		$this->assertSame( [ 705 ], wp_list_pluck( $records, 'id' ) );
	}

	/**
	 * Meta that isn't an array, or isn't there, gives no records.
	 */
	public function test_unusable_meta_gives_no_records() {
		$user_id = $this->factory()->user->create();
		$this->assertSame( [], apply_filters( 'newspack_subscribers_network_subscriptions', [], $user_id ) );

		update_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, 'garbage' );
		$this->assertSame( [], apply_filters( 'newspack_subscribers_network_subscriptions', [], $user_id ) );
	}

	/**
	 * The index lists records for the users that have them, each tagged with its user, up to the cap.
	 */
	public function test_index_returns_rows_with_user_ids_and_respects_cap() {
		$user_ids = [
			$this->create_user_with_subscription( 501 ),
			$this->create_user_with_subscription( 502 ),
			$this->create_user_with_subscription( 503 ),
		];
		// A user with no network subscriptions never shows up.
		$this->factory()->user->create();

		$rows = apply_filters( 'newspack_subscribers_network_subscription_index', [], 2 );

		$this->assertCount( 2, $rows );
		$indexed_user_ids = array_unique( wp_list_pluck( $rows, 'user_id' ) );
		$this->assertCount( 2, $indexed_user_ids );
		foreach ( $rows as $row ) {
			$this->assertContains( $row['user_id'], $user_ids );
			$this->assertSame( 'Node Daily', $row['site_name'] );
			$this->assertSame( 'active', $row['status'] );
		}

		$this->assertCount( 3, apply_filters( 'newspack_subscribers_network_subscription_index', [], 10 ) );
		$this->assertSame( [], apply_filters( 'newspack_subscribers_network_subscription_index', [], 0 ) );
	}

	/**
	 * Every record of an indexed user is listed, and the rows passed in are kept.
	 */
	public function test_index_keeps_incoming_rows_and_all_records_of_a_user() {
		$user_id = $this->create_user_with_subscription( 500 );
		$meta    = get_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true );

		$meta[ self::NODE_URL ][501] = [
			'id'       => 501,
			'status'   => 'on-hold',
			'products' => [],
		];
		update_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, $meta );

		$incoming = [ [ 'id' => 1 ] ];
		$rows     = apply_filters( 'newspack_subscribers_network_subscription_index', $incoming, 5 );

		$this->assertCount( 3, $rows );
		$this->assertSame( $incoming[0], $rows[0] );
		$this->assertSame( [ 500, 501 ], wp_list_pluck( array_slice( $rows, 1 ), 'id' ) );
		$this->assertSame( [ $user_id, $user_id ], wp_list_pluck( array_slice( $rows, 1 ), 'user_id' ) );
	}

	/**
	 * The index reads only the subscriptions key: a user's other meta, even meta shaped like it, adds nothing,
	 * and the scan leaves the user meta cache empty for the readers it passes.
	 */
	public function test_index_reads_only_the_subscriptions_key() {
		$user_id = $this->factory()->user->create();
		update_user_meta(
			$user_id,
			'newspack_network_test_unrelated',
			[
				self::NODE_URL => [
					900 => [
						'id'     => 900,
						'status' => 'active',
					],
				],
			]
		);
		update_user_meta(
			$user_id,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				self::NODE_URL => [
					500 => [
						'id'       => 500,
						'status'   => 'active',
						'products' => [],
					],
				],
			]
		);

		// Adding meta fires listeners that may fill the cache, so clear it right before the scan.
		wp_cache_delete( $user_id, 'user_meta' );

		$rows = apply_filters( 'newspack_subscribers_network_subscription_index', [], 10 );

		$this->assertSame( [ 500 ], wp_list_pluck( $rows, 'id' ) );
		$this->assertSame( [ $user_id ], wp_list_pluck( $rows, 'user_id' ) );
		$this->assertFalse( wp_cache_get( $user_id, 'user_meta' ), 'The scan must not load the reader\'s meta into the object cache.' );
	}

	/**
	 * A user with only this site's entries doesn't use up a place under the cap.
	 */
	public function test_index_cap_counts_only_users_with_records() {
		$local_only = $this->factory()->user->create();
		update_user_meta(
			$local_only,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				get_bloginfo( 'url' ) => [
					600 => [
						'id'     => 600,
						'status' => 'active',
					],
				],
			]
		);
		$user_id = $this->create_user_with_subscription( 500 );

		$rows = apply_filters( 'newspack_subscribers_network_subscription_index', [], 1 );

		$this->assertSame( [ $user_id ], wp_list_pluck( $rows, 'user_id' ) );
	}

	/**
	 * A reader is a network reader when they were created from another site, whatever the filter said before.
	 */
	public function test_is_network_reader() {
		$remote_user = $this->factory()->user->create();
		update_user_meta( $remote_user, Users::USER_META_REMOTE_SITE, self::NODE_URL );
		$local_user = $this->factory()->user->create();

		$this->assertTrue( apply_filters( 'newspack_subscribers_is_network_reader', false, $remote_user ) );
		$this->assertFalse( apply_filters( 'newspack_subscribers_is_network_reader', false, $local_user ) );
		$this->assertTrue( apply_filters( 'newspack_subscribers_is_network_reader', true, $local_user ) );
	}
}
