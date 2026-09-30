<?php
/**
 * Class TestRebuildHubCopies
 *
 * @package Newspack_Network
 */

use Newspack_Network\CLI\Rebuild_Hub_Copies;
use Newspack_Network\Hub\Database\Event_Log as Event_Log_Database;
use Newspack_Network\Hub\Database\Subscriptions as Subscriptions_DB;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Reader_Access_Endpoint as Hub_Endpoint;
use Newspack_Network\Hub\Stores\Event_Log;
use Newspack_Network\Incoming_Events\Group_Members_Changed;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Site_Role;

/**
 * Rewriting the hub's copies from its Event Log, to repair copies written before
 * each site's items got their own copy.
 */
class TestRebuildHubCopies extends WP_UnitTestCase {

	const NODE_URL = 'https://node.example.test';

	/**
	 * The node's ID.
	 *
	 * @var int
	 */
	private $node_id;

	/**
	 * Create the Event Log table.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		Event_Log_Database::get_table_name();
	}

	/**
	 * A hub with one node, holding a copy that two sites' subscription #500 collided
	 * into: created by the hub's own #500 (Alice's), last written by the node's #500 (Bob's).
	 */
	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
		update_option( Site_Role::OPTION_NAME, Site_Role::HUB_ROLE );
		$this->node_id = self::factory()->post->create(
			[
				'post_type'   => Nodes::POST_TYPE_SLUG,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $this->node_id, 'node-url', self::NODE_URL );

		$this->log( get_bloginfo( 'url' ), 'alice@example.test', 'active', 8, 100 );
		$this->log( self::NODE_URL, 'bob@example.test', 'active', 7, 200 );
		$this->log( self::NODE_URL, 'bob@example.test', 'cancelled', 7, 300 );

		$collided = self::factory()->post->create(
			[
				'post_type'   => Subscriptions_DB::POST_TYPE_SLUG,
				'post_status' => Subscriptions_DB::POST_STATUS_PREFIX . 'cancelled',
			]
		);
		update_post_meta( $collided, 'remote_id', 500 );
		update_post_meta( $collided, 'node_id', 0 );
		update_post_meta( $collided, 'user_email', 'alice@example.test' );
		add_post_meta( $collided, 'products', [ 'id' => 7 ] );
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		delete_option( Site_Role::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Record a subscription event for #500 in the Event Log without processing it.
	 *
	 * @param string $site       Site URL.
	 * @param string $email      Customer email.
	 * @param string $status     Status.
	 * @param int    $product_id Product ID.
	 * @param int    $timestamp  Event time.
	 */
	private function log( $site, $email, $status, $product_id, $timestamp ) {
		Event_Log::persist(
			new Subscription_Changed(
				$site,
				[
					'id'           => 500,
					'email'        => $email,
					'status_after' => $status,
					'products'     => [
						$product_id => [ 'id' => $product_id ],
					],
				],
				$timestamp
			)
		);
	}

	/**
	 * Each site's latest event rewrites that site's copy: the collided copy gets its
	 * own site's data back, the other site gets a copy of its own, and both can be
	 * answered again.
	 */
	public function test_rebuild_repairs_collided_copies() {
		Rebuild_Hub_Copies::rebuild( [], [ 'apply' => true ] );

		$alice = Hub_Endpoint::collect( 'alice@example.test', $this->node_id )[ get_bloginfo( 'url' ) ]['subscriptions'][500];
		$this->assertSame( 'active', $alice['status'] );
		$this->assertSame( [ 8 ], array_keys( $alice['products'] ) );

		$bob = Hub_Endpoint::collect( 'bob@example.test', 0 )[ self::NODE_URL ]['subscriptions'][500];
		$this->assertSame( 'cancelled', $bob['status'] );
	}

	/**
	 * Events from a site that has since been removed from the network are skipped:
	 * they must not be replayed as the hub's own, over the hub's copy with the same ID.
	 */
	public function test_rebuild_skips_events_from_removed_sites() {
		foreach ( [ 'wp_delete_post', 'wp_trash_post' ] as $remove ) {
			$removed_node = self::factory()->post->create(
				[
					'post_type'   => Nodes::POST_TYPE_SLUG,
					'post_status' => 'publish',
				]
			);
			update_post_meta( $removed_node, 'node-url', 'https://removed-' . $remove . '.example.test' );
			$this->log( 'https://removed-' . $remove . '.example.test', 'carol@example.test', 'cancelled', 9, 400 );
			$remove( $removed_node );
		}
		// The hub's own event is the newest, so a removed site's event replayed as the
		// hub's would be the last write to the hub's copy.
		$this->log( get_bloginfo( 'url' ), 'alice@example.test', 'active', 8, 500 );

		Rebuild_Hub_Copies::rebuild( [], [ 'apply' => true ] );

		$alice = Hub_Endpoint::collect( 'alice@example.test', $this->node_id )[ get_bloginfo( 'url' ) ]['subscriptions'][500];
		$this->assertSame( 'active', $alice['status'] );
		$this->assertSame( [], Hub_Endpoint::collect( 'carol@example.test', $this->node_id ) );
	}

	/**
	 * An event logged while the rebuild runs is newer than anything the rebuild
	 * replays, so it decides the copy even if the rebuild reaches an older event for
	 * the same item afterwards.
	 */
	public function test_event_logged_during_the_rebuild_wins() {
		$injected = false;
		add_action(
			'added_post_meta',
			function ( $meta_id, $post_id, $meta_key ) use ( &$injected ) {
				if ( $injected || 'remote_id' !== $meta_key ) {
					return;
				}
				$injected = true;
				$live     = new Subscription_Changed(
					get_bloginfo( 'url' ),
					[
						'id'           => 500,
						'email'        => 'alice@example.test',
						'status_after' => 'on-hold',
						'products'     => [
							8 => [ 'id' => 8 ],
						],
					],
					500
				);
				Event_Log::persist( $live );
				$live->always_process_in_hub();
			},
			10,
			3
		);

		Rebuild_Hub_Copies::rebuild( [], [ 'apply' => true ] );

		$this->assertTrue( $injected );
		$alice = Hub_Endpoint::collect( 'alice@example.test', $this->node_id )[ get_bloginfo( 'url' ) ]['subscriptions'][500];
		$this->assertSame( 'on-hold', $alice['status'] );
	}

	/**
	 * Replayed events are decoded the way webhooks decode them, so the copies keep the
	 * array shape the hub's screens read.
	 */
	public function test_rebuild_writes_products_as_arrays() {
		Rebuild_Hub_Copies::rebuild( [], [ 'apply' => true ] );

		$copies = get_posts(
			[
				'post_type'   => Subscriptions_DB::POST_TYPE_SLUG,
				'post_status' => 'any',
				'fields'      => 'ids',
			]
		);
		foreach ( $copies as $copy ) {
			foreach ( get_post_meta( $copy, 'products', false ) as $product ) {
				$this->assertIsArray( $product );
			}
		}
	}

	/**
	 * A subscription's status comes from its status events. A members event logged
	 * later can carry an older status (webhooks retry), so it never decides the status.
	 */
	public function test_status_comes_from_status_events() {
		Event_Log::persist(
			new Group_Members_Changed(
				self::NODE_URL,
				[
					'id'            => 500,
					'email'         => 'bob@example.test',
					'status_after'  => 'active',
					'products'      => [],
					'group_enabled' => true,
					'group_members' => [ 'member@example.test' ],
				],
				250
			)
		);

		Rebuild_Hub_Copies::rebuild( [], [ 'apply' => true ] );

		$bob = Hub_Endpoint::collect( 'bob@example.test', 0 )[ self::NODE_URL ]['subscriptions'][500];
		$this->assertSame( 'cancelled', $bob['status'] );
	}

	/**
	 * Without --apply, the command only reports.
	 */
	public function test_dry_run_changes_nothing() {
		Rebuild_Hub_Copies::rebuild( [], [] );

		$this->assertSame( [], Hub_Endpoint::collect( 'alice@example.test', $this->node_id ) );
	}
}
