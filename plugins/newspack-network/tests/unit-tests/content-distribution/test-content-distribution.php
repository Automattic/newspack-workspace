<?php
/**
 * Class TestContentDistribution
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

require_once __DIR__ . '/mock-data-events.php';

use Newspack\Data_Events;
use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Hub\Node as Hub_Node;

/**
 * Test the Content_Distribution class.
 */
class TestContentDistribution extends \WP_UnitTestCase {
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

		Data_Events::$mock_dispatch_return = true;
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		Data_Events::$mock_dispatch_return = true;
		parent::tear_down();
	}

	/**
	 * A post set up for distribution to the first node.
	 *
	 * @return Outgoing_Post
	 */
	private function distributed_post() {
		$outgoing_post = new Outgoing_Post( $this->factory->post->create() );
		$outgoing_post->set_distribution( [ $this->network[0]['url'] ] );
		return $outgoing_post;
	}

	/**
	 * Test update distributed post meta.
	 */
	public function test_update_distributed_post_meta() {
		$post_id = $this->factory->post->create();

		// Assert that an empty value is allowed.
		$result = update_post_meta( $post_id, Outgoing_Post::DISTRIBUTED_POST_META, [] );
		$this->assertNotFalse( $result );

		// Assert that you're not allowed to update the meta with a non-network site.
		$result = update_post_meta( $post_id, Outgoing_Post::DISTRIBUTED_POST_META, [ 'http://non-network-site.com' ] );
		$this->assertFalse( $result );

		// Assert that you're allowed to update the meta with a network site.
		$result = update_post_meta( $post_id, Outgoing_Post::DISTRIBUTED_POST_META, [ 'https://node.test' ] );
		$this->assertNotFalse( $result );

		// Assert that you can't remove a site from distribution.
		$result = update_post_meta( $post_id, Outgoing_Post::DISTRIBUTED_POST_META, [ 'https://other-node.test' ] );
		$this->assertFalse( $result );

		// Assert that you can add a site to distribution.
		$result = update_post_meta( $post_id, Outgoing_Post::DISTRIBUTED_POST_META, [ 'https://node.test', 'https://other-node.test' ] );
		$this->assertNotFalse( $result );

		// Assert that an empty value is not allowed if the post is distributed.
		$result = update_post_meta( $post_id, Outgoing_Post::DISTRIBUTED_POST_META, [] );
		$this->assertFalse( $result );
	}

	/**
	 * Test queue post distribution.
	 */
	public function test_queue_post_distribution() {
		$post_id = $this->factory->post->create();

		// Queue post meta for distribution.
		Content_Distribution_Class::queue_post_distribution( $post_id, 'post_meta' );
		$queue = Content_Distribution_Class::get_queued_distributions();
		$this->assertArrayHasKey( $post_id, $queue );
		$this->assertSame( [ 'post_meta' ], $queue[ $post_id ] );

		// Queue full post for distribution.
		Content_Distribution_Class::queue_post_distribution( $post_id );
		$queue = Content_Distribution_Class::get_queued_distributions();
		// Assert that the post is queued for full distribution (= true).
		$this->assertTrue( $queue[ $post_id ] );

		// Queue another attribute for distribution.
		Content_Distribution_Class::queue_post_distribution( $post_id, 'post_meta' );
		$queue = Content_Distribution_Class::get_queued_distributions();
		// Assert that the post is still queued for full distribution.
		$this->assertTrue( $queue[ $post_id ] );
	}

	/**
	 * The stored payload hash is the record of what the receiving site has, so a
	 * dispatch that failed must not leave one behind. Storing it anyway marks the
	 * post as delivered, and every later save then short-circuits on the matching
	 * hash, so the update never reaches the node.
	 */
	public function test_failed_dispatch_does_not_store_the_payload_hash() {
		$outgoing_post = $this->distributed_post();
		$post_id       = $outgoing_post->get_post()->ID;

		Data_Events::$mock_dispatch_return = new \WP_Error( 'dispatch_cancelled', 'Dispatch cancelled.' );

		$result = Content_Distribution_Class::distribute_post( $outgoing_post );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEmpty( get_post_meta( $post_id, Content_Distribution_Class::PAYLOAD_HASH_META, true ) );

		// With the dispatch working again, the same post still distributes.
		Data_Events::$mock_dispatch_return = true;
		Content_Distribution_Class::distribute_post( $outgoing_post );

		$this->assertNotEmpty( get_post_meta( $post_id, Content_Distribution_Class::PAYLOAD_HASH_META, true ) );
	}

	/**
	 * The same holds for a partial distribution.
	 */
	public function test_failed_partial_dispatch_does_not_store_the_payload_hash() {
		$outgoing_post = $this->distributed_post();
		$post_id       = $outgoing_post->get_post()->ID;

		Data_Events::$mock_dispatch_return = new \WP_Error( 'dispatch_cancelled', 'Dispatch cancelled.' );

		$result = Content_Distribution_Class::distribute_post_partial( $outgoing_post, 'post_meta' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEmpty( get_post_meta( $post_id, Content_Distribution_Class::PAYLOAD_HASH_META, true ) );

		Data_Events::$mock_dispatch_return = true;
		Content_Distribution_Class::distribute_post_partial( $outgoing_post, 'post_meta' );

		$this->assertNotEmpty( get_post_meta( $post_id, Content_Distribution_Class::PAYLOAD_HASH_META, true ) );
	}

	/**
	 * An ignored post meta write on a distributed post must not queue a
	 * distribution, while an ordinary meta write must.
	 *
	 * This guards the `updated_post_meta` suppression in
	 * handle_postmeta_update(): without it, every Spectra (UAGB) asset
	 * regeneration would queue a redundant distribution, the reported
	 * high-volume sync problem.
	 */
	public function test_ignored_meta_does_not_queue_distribution() {
		$post_id = $this->factory->post->create();

		// Mark the post as distributed to a network node.
		update_post_meta( $post_id, Outgoing_Post::DISTRIBUTED_POST_META, [ 'https://node.test' ] );

		// Clear the queue populated while marking the post distributed, so we
		// measure only the writes under test.
		$this->reset_distribution_queue();

		// A Spectra asset-meta write must not queue a distribution.
		update_post_meta( $post_id, '_uag_page_assets', 'version-2' );
		$this->assertArrayNotHasKey(
			$post_id,
			Content_Distribution_Class::get_queued_distributions(),
			'A Spectra asset-meta write should not queue a distribution.'
		);

		// A non-ignored meta write on the same post must queue a distribution.
		update_post_meta( $post_id, 'some_meta_key', 'value' );
		$this->assertArrayHasKey(
			$post_id,
			Content_Distribution_Class::get_queued_distributions(),
			'A non-ignored meta write should queue a distribution.'
		);
	}

	/**
	 * Reset the static distribution queue so a test measures only its own writes.
	 */
	private function reset_distribution_queue() {
		$property = new \ReflectionProperty( Content_Distribution_Class::class, 'queued_distributions' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}
}
