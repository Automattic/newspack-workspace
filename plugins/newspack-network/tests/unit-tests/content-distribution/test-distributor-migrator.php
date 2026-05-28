<?php
/**
 * Class TestDistributorMigrator
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Distributor_Migrator;
use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Hub\Node as Hub_Node;

/**
 * Test the Distributor_Migrator class.
 */
class TestDistributorMigrator extends \WP_UnitTestCase {
	/**
	 * "Mocked" network nodes.
	 *
	 * @var array
	 */
	protected $network = [
		[
			'id'    => 1234,
			'title' => 'Test Node',
			'url'   => 'https://node.test',
		],
		[
			'id'    => 5678,
			'title' => 'Test Node 2',
			'url'   => 'https://other-node.test',
		],
	];

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		// "Mock" the network node(s).
		update_option( Hub_Node::HUB_NODES_SYNCED_OPTION, $this->network );
	}

	/**
	 * Test that get_network_url correctly matches URLs to network nodes.
	 */
	public function test_get_network_url() {
		// Test direct match.
		$this->assertEquals(
			'https://node.test',
			$this->call_protected_method( 'get_network_url', 'https://node.test/some/path' )
		);

		// Test subdirectory match.
		$this->assertEquals(
			'https://node.test',
			$this->call_protected_method( 'get_network_url', 'https://node.test/wp-admin/post.php?post=123' )
		);

		// Test no match.
		$this->assertFalse(
			$this->call_protected_method( 'get_network_url', 'https://external.test/some/path' )
		);
	}

	/**
	 * Test that get_distributor_subscriptions returns subscription post IDs.
	 */
	public function test_get_distributor_subscriptions() {
		// Create some subscription posts.
		$subscription_1 = $this->factory->post->create( [ 'post_type' => 'dt_subscription' ] );
		$subscription_2 = $this->factory->post->create( [ 'post_type' => 'dt_subscription' ] );
		$regular_post = $this->factory->post->create();

		$subscriptions = Distributor_Migrator::get_distributor_subscriptions();

		$this->assertCount( 2, $subscriptions );
		$this->assertContains( $subscription_1, $subscriptions );
		$this->assertContains( $subscription_2, $subscriptions );
		$this->assertNotContains( $regular_post, $subscriptions );
	}

	/**
	 * Test can_migrate_subscription validation.
	 */
	public function test_can_migrate_subscription() {
		// Create a post and subscription.
		$post_id = $this->factory->post->create();
		$subscription_id = $this->factory->post->create( [ 'post_type' => 'dt_subscription' ] );

		// Test with missing post ID.
		$result = Distributor_Migrator::can_migrate_subscription( $subscription_id );
		$this->assertWPError( $result );
		$this->assertEquals( 'post_not_found', $result->get_error_code() );

		// Add post ID but no target URL.
		update_post_meta( $subscription_id, 'dt_subscription_post_id', $post_id );
		$result = Distributor_Migrator::can_migrate_subscription( $subscription_id );
		$this->assertWPError( $result );
		$this->assertEquals( 'target_url_not_found', $result->get_error_code() );

		// Add target URL but not networked.
		update_post_meta( $subscription_id, 'dt_subscription_target_url', 'https://external.test' );
		$result = Distributor_Migrator::can_migrate_subscription( $subscription_id );
		$this->assertWPError( $result );
		$this->assertEquals( 'target_url_not_networked', $result->get_error_code() );

		// Add valid networked target URL.
		update_post_meta( $subscription_id, 'dt_subscription_target_url', 'https://node.test' );
		$result = Distributor_Migrator::can_migrate_subscription( $subscription_id );
		$this->assertTrue( $result );
	}

	/**
	 * Test can_migrate_outgoing_post validation.
	 */
	public function test_can_migrate_outgoing_post() {
		$post_id = $this->factory->post->create();

		// Test with no connection map.
		$result = Distributor_Migrator::can_migrate_outgoing_post( $post_id );
		$this->assertWPError( $result );
		$this->assertEquals( 'no_connection_map', $result->get_error_code() );

		// Test with internal connections (not supported).
		update_post_meta(
			$post_id,
			'dt_connection_map',
			[
				'internal' => [ 'some_connection' ],
				'external' => [ 'external_connection' => [ 'post_id' => 123 ] ],
			]
		);
		$result = Distributor_Migrator::can_migrate_outgoing_post( $post_id );
		$this->assertWPError( $result );
		$this->assertEquals( 'internal_connection', $result->get_error_code() );

		// Test with external connections but no subscriptions.
		update_post_meta(
			$post_id,
			'dt_connection_map',
			[
				'external' => [ 'external_connection' => [ 'post_id' => 123 ] ],
			]
		);
		$result = Distributor_Migrator::can_migrate_outgoing_post( $post_id );
		$this->assertWPError( $result );
		$this->assertEquals( 'subscriptions_not_found', $result->get_error_code() );

		// Test with valid setup.
		$subscription_id = $this->factory->post->create( [ 'post_type' => 'dt_subscription' ] );
		update_post_meta( $subscription_id, 'dt_subscription_post_id', $post_id );
		update_post_meta( $subscription_id, 'dt_subscription_target_url', 'https://node.test' );
		update_post_meta( $post_id, 'dt_subscriptions', [ $subscription_id ] );

		$result = Distributor_Migrator::can_migrate_outgoing_post( $post_id );
		$this->assertTrue( $result );
	}

	/**
	 * Test that migration data meta is added during subscription migration.
	 */
	public function test_migration_data_meta_tracking() {
		// Create post and subscription.
		$post_id = $this->factory->post->create();
		$subscription_id = $this->factory->post->create( [ 'post_type' => 'dt_subscription' ] );

		// Set up subscription meta.
		update_post_meta( $subscription_id, 'dt_subscription_post_id', $post_id );
		update_post_meta( $subscription_id, 'dt_subscription_target_url', 'https://node.test' );
		update_post_meta( $subscription_id, 'dt_subscription_remote_post_id', 456 );

		// Add required post meta for migration.
		update_post_meta( $post_id, 'dt_subscriptions', [ $subscription_id ] );
		update_post_meta(
			$post_id,
			'dt_connection_map',
			[
				'external' => [
					'external_connection' => [ 'post_id' => 456 ],
				],
			]
		);

		// Mock Data_Events class to prevent actual dispatch.
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			// Create a simple mock class instead of using Mockery.
			if ( ! class_exists( 'Newspack\Data_Events' ) ) {
				eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged
					'
					namespace Newspack {
						class Data_Events {
							public static function dispatch( $event, $data = [] ) {
								return true;
							}
						}
					}
				'
				);
			}
		}

		// Migrate the subscription.
		$result = Distributor_Migrator::migrate_subscription( $subscription_id, false );

		// Verify migration data was added.
		$migration_data = get_post_meta( $post_id, Distributor_Migrator::MIGRATION_DATA_META, true );
		$this->assertIsArray( $migration_data );
		$this->assertArrayHasKey( 'timestamp', $migration_data );
		$this->assertArrayHasKey( 'subscription_id', $migration_data );
		$this->assertArrayHasKey( 'remote_post_id', $migration_data );
		$this->assertArrayHasKey( 'target_url', $migration_data );

		$this->assertEquals( $subscription_id, $migration_data['subscription_id'] );
		$this->assertEquals( 456, $migration_data['remote_post_id'] );
		$this->assertEquals( 'https://node.test', $migration_data['target_url'] );
	}

	/**
	 * Test that distributor meta is properly cleaned up during migration.
	 */
	public function test_distributor_meta_cleanup() {
		// Create post and subscription.
		$post_id = $this->factory->post->create();
		$subscription_id = $this->factory->post->create( [ 'post_type' => 'dt_subscription' ] );

		// Set up subscription meta.
		update_post_meta( $subscription_id, 'dt_subscription_post_id', $post_id );
		update_post_meta( $subscription_id, 'dt_subscription_target_url', 'https://node.test' );
		update_post_meta( $subscription_id, 'dt_subscription_remote_post_id', 456 );

		// Add distributor meta to post.
		update_post_meta( $post_id, 'dt_subscriptions', [ $subscription_id ] );
		update_post_meta(
			$post_id,
			'dt_connection_map',
			[
				'external' => [
					'connection_1' => [ 'post_id' => 456 ],
				],
			]
		);

		// Mock Data_Events class.
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			// Create a simple mock class instead of using Mockery.
			if ( ! class_exists( 'Newspack\Data_Events' ) ) {
				eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged
					'
					namespace Newspack {
						class Data_Events {
							public static function dispatch( $event, $data = [] ) {
								return true;
							}
						}
					}
				'
				);
			}
		}

		// Migrate the subscription.
		$result = Distributor_Migrator::migrate_subscription( $subscription_id, false );

		// Verify subscription meta was cleaned up.
		$remaining_subscriptions = get_post_meta( $post_id, 'dt_subscriptions', true );
		$this->assertEmpty( $remaining_subscriptions );

		// Verify connection map was cleaned up.
		$remaining_connection_map = get_post_meta( $post_id, 'dt_connection_map', true );
		$this->assertEmpty( $remaining_connection_map );

		// Verify subscription post was deleted.
		$this->assertNull( get_post( $subscription_id ) );
	}

	/**
	 * Call a protected method on the Distributor_Migrator class.
	 *
	 * @param string $method The method name.
	 * @param mixed  ...$args The method arguments.
	 * @return mixed The method result.
	 */
	private function call_protected_method( $method, ...$args ) {
		$reflection = new \ReflectionClass( Distributor_Migrator::class );
		$method_reflection = $reflection->getMethod( $method );
		$method_reflection->setAccessible( true );
		return $method_reflection->invoke( null, ...$args );
	}
}
