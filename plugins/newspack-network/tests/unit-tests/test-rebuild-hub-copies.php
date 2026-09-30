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
	 * Without --apply, the command only reports.
	 */
	public function test_dry_run_changes_nothing() {
		Rebuild_Hub_Copies::rebuild( [], [] );

		$this->assertSame( [], Hub_Endpoint::collect( 'alice@example.test', $this->node_id ) );
	}
}
