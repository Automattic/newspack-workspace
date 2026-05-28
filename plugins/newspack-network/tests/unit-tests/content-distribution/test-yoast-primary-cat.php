<?php
/**
 * Class TestOutgoingPost
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Content_Distribution\Incoming_Post;
use Newspack_Network\Content_Distribution\Yoast_Primary_Cat;

require_once __DIR__ . '/mock-yoast-primary-term.php';

/**
 * Test the Outgoing_Post class.
 */
class TestYoastPrimaryCat extends \WP_UnitTestCase {

	/**
	 * Test category slug used in the test. It's part of the sample payload.
	 */
	const TEST_CATEGORY_NAME = 'category-2';

	/**
	 * Test the outgoing post.
	 */
	public function test_outgoing_post() {
		$post_id = self::factory()->post->create();

		$category_id = self::factory()->category->create( [ 'name' => self::TEST_CATEGORY_NAME ] );

		$primary_term = new \WPSEO_Primary_Term( 'category', $post_id );
		$primary_term->set_primary_term( $category_id );

		$outgoing_post = new Outgoing_Post( $post_id );

		$outgoing_meta = $outgoing_post->get_payload()['post_data']['post_meta'];

		$meta_name = Yoast_Primary_Cat::PRIMARY_CAT_NAME_META_NAME;

		$this->assertArrayHasKey( $meta_name, $outgoing_meta );
		$this->assertEquals( self::TEST_CATEGORY_NAME, $outgoing_meta[ $meta_name ][0], 'The primary category name should be part of the outgoing post meta' );
	}

	/**
	 * Test the incoming post.
	 */
	public function test_incoming_post() {
		$payload = get_sample_payload( 'https://node1.test', 'https://node2.test' );

		// Set the site URL for the node that receives posts.
		update_option( 'siteurl', 'https://node2.test' );
		update_option( 'home', 'https://node2.test' );

		$meta_name = Yoast_Primary_Cat::PRIMARY_CAT_NAME_META_NAME;

		$payload['post_data']['post_meta'][ $meta_name ] = [ self::TEST_CATEGORY_NAME ];

		$incoming_post = new Incoming_Post( $payload );

		$post_id = $incoming_post->insert();

		$primary_term = new \WPSEO_Primary_Term( 'category', $post_id );
		$primary_term_id = $primary_term->get_primary_term();

		$primary_term_category = get_term( $primary_term_id, 'category' );

		$this->assertEquals( self::TEST_CATEGORY_NAME, $primary_term_category->name, 'The primary category should be correctly set in the created post' );
	}
}
