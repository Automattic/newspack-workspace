<?php
/**
 * Class TestNode
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Node;

/**
 * Test the Node class.
 */
class TestNode extends WP_UnitTestCase {

	/**
	 * Test constructor
	 */
	public function test_constructor() {
		$post1 = $this->factory->post->create_and_get(
			[
				'post_type' => Nodes::POST_TYPE_SLUG,
			]
		);
		$post2 = $this->factory->post->create_and_get(
			[
				'post_type' => Nodes::POST_TYPE_SLUG,
			]
		);

		$node = new Node( $post1 );
		$this->assertInstanceOf( Node::class, $node );
		$this->assertSame( $post1->ID, $node->get_id() );

		$node = new Node( $post2->ID );
		$this->assertInstanceOf( Node::class, $node );
		$this->assertSame( $post2->ID, $node->get_id() );

		$node = new Node( 999 );
		$this->assertInstanceOf( Node::class, $node );
		$this->assertNull( $node->get_id() );

		$node = new Node( 'asd' );
		$this->assertInstanceOf( Node::class, $node );
		$this->assertNull( $node->get_id() );
	}
	
	/**
	 * Test getters
	 */
	public function test_get_attrs() {
		$post_with    = $this->factory->post->create(
			[
				'post_type' => Nodes::POST_TYPE_SLUG,
			]
		);
		$post_without = $this->factory->post->create(
			[
				'post_type' => Nodes::POST_TYPE_SLUG,
			]
		);

		add_post_meta( $post_with, 'node-url', 'https://example.com' );
		add_post_meta( $post_with, 'secret-key', 'secret-key' );

		$node_with    = new Node( $post_with );
		$node_without = new Node( $post_without );

		$this->assertSame( $post_with, $node_with->get_id() );
		$this->assertSame( 'https://example.com', $node_with->get_url() );
		$this->assertSame( 'secret-key', $node_with->get_secret_key() );

		$this->assertSame( $post_without, $node_without->get_id() );
		$this->assertEmpty( $node_without->get_url() );
		$this->assertEmpty( $node_without->get_secret_key() );
	}

	/**
	 * The decrypt_message is tested in testCrypto class
	 */
}
