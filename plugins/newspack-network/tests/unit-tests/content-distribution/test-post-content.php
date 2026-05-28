<?php
/**
 * Class TestPostContent
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Content_Distribution\Incoming_Post;

/**
 * Test the Incoming_Post class.
 */
class TestPostContent extends \WP_UnitTestCase {
	/**
	 * URL for node that distributes posts.
	 *
	 * @var string
	 */
	protected $node_1 = 'https://node1.test';

	/**
	 * URL for node that receives posts.
	 *
	 * @var string
	 */
	protected $node_2 = 'https://node2.test';

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		// Set the site URL for the node that receives posts.
		update_option( 'siteurl', $this->node_2 );
		update_option( 'home', $this->node_2 );
	}

	/**
	 * Get outgoing post payload with content.
	 *
	 * @param string $content The post content.
	 *
	 * @return array The outgoing post payload.
	 */
	private function get_outgoing_post_payload_with_content( $content ) {
		$outgoing_post = $this->factory->post->create_and_get( [ 'post_content' => $content ] );
		$payload       = ( new Outgoing_Post( $outgoing_post->ID ) )->get_payload();

		// Mock distribution for the post.
		$payload['site_url'] = $this->node_1;
		$payload['sites']    = [ $this->node_2 ];

		return $payload;
	}

	/**
	 * Data provider for content.
	 */
	public function content() {
		$files = scandir( __DIR__ . '/post-content' );
		$files = array_diff( $files, [ '.', '..' ] );
		return array_map(
			function ( $file ) {
				return [ pathinfo( $file, PATHINFO_FILENAME ) ];
			},
			$files
		);
	}

	/**
	 * Assert that two contents are equal.
	 *
	 * @param string $expected The expected content.
	 * @param string $actual   The actual content.
	 */
	private function assertEqualContent( $expected, $actual ) {
		$expected = trim( $expected );
		$actual   = trim( $actual );

		/**
		 * Remove classes from tags to make comparison easier for blocks that uses
		 * wp_unique_id().
		 */
		$expected = preg_replace( '/ class="[^"]+"/', '', $expected );
		$actual   = preg_replace( '/ class="[^"]+"/', '', $actual );

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Test classic editor content.
	 *
	 * @param string $type The content type.
	 *
	 * @dataProvider content
	 */
	public function test_content( $type ) {
		if ( 'classic' === $type ) {
			add_filter( 'use_block_editor_for_post_type', '__return_false' );
		}

		$content = file_get_contents( __DIR__ . '/post-content/' . $type . '.html' );
		$payload = $this->get_outgoing_post_payload_with_content( $content );

		$incoming_post = new Incoming_Post( $payload );
		$post_id       = $incoming_post->insert();

		$this->assertEqualContent(
			apply_filters( 'the_content', get_post_field( 'post_content', $payload['post_id'] ) ),
			apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) )
		);

		if ( 'classic' === $type ) {
			remove_filter( 'use_block_editor_for_post_type', '__return_false' );
		}
	}
}
