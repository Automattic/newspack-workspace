<?php
/**
 * Class TestNodes
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Node;

/**
 * Test the Node class.
 */
class TestNodes extends WP_UnitTestCase {

	/**
	 * Test get by url
	 */
	public function test_get_by_url() {
		$post1 = $this->factory->post->create(
			[
				'post_type'   => Nodes::POST_TYPE_SLUG,
				'post_status' => 'publish',
			]
		);
		$post2 = $this->factory->post->create(
			[
				'post_type'   => Nodes::POST_TYPE_SLUG,
				'post_status' => 'publish',
			]
		);

		add_post_meta( $post1, 'node-url', 'https://example.com' );
		add_post_meta( $post2, 'node-url', 'https://example2.com' );

		$node = Nodes::get_node_by_url( 'https://example.com' );
		$this->assertInstanceOf( Node::class, $node );
		$this->assertSame( $post1, $node->get_id() );

		$node = Nodes::get_node_by_url( 'https://example2.com' );
		$this->assertInstanceOf( Node::class, $node );
		$this->assertSame( $post2, $node->get_id() );

		$node = Nodes::get_node_by_url( 'asdasdasd' );
		$this->assertNull( $node );
	}
}
