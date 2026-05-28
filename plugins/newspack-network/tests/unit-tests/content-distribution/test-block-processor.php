<?php
/**
 * Class TestContentDistributionBlockProcessor
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Blocks;
use Newspack_Network\Content_Distribution\Block_Processor;
use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Content_Distribution\Incoming_Post;

/**
 * Test the Content Distribution Block Processor.
 */
class TestBlockProcessor extends \WP_UnitTestCase {
	/**
	 * Set up the test.
	 */
	public function set_up() {
		parent::set_up();
		Blocks::register_block_processor( 'core/paragraph', [ __CLASS__, 'process_outgoing_paragraph' ], [ __CLASS__, 'process_incoming_paragraph' ] );
	}

	/**
	 * Tear down the test.
	 */
	public function tear_down() {
		parent::tear_down();
		Blocks::reset_block_processors( 'core/paragraph' );
	}

	/**
	 * Process an outgoing paragraph block.
	 *
	 * @param array $block The block to process.
	 *
	 * @return array The processed block.
	 */
	public static function process_outgoing_paragraph( $block ) {
		$block['attrs']['outgoing'] = 'test';
		$block['innerHTML']         = '<p class="wp-block-paragraph">Outgoing Processed</p>';
		$block['innerContent']      = [ '<p class="wp-block-paragraph">Outgoing Processed</p>' ];
		return $block;
	}

	/**
	 * Process an incoming paragraph block.
	 *
	 * @param array $block The block to process.
	 *
	 * @return array The processed block.
	 */
	public static function process_incoming_paragraph( $block ) {
		$block['attrs']['incoming'] = 'test';
		$block['innerHTML']         = '<p class="wp-block-paragraph">Incoming Processed</p>';
		$block['innerContent']      = [ '<p class="wp-block-paragraph">Incoming Processed</p>' ];
		return $block;
	}

	/**
	 * Test registering a block processor.
	 */
	public function test_register_block_processor() {
		$block_processor = Blocks::get_block_processors( 'core/paragraph' );
		$this->assertNotEmpty( $block_processor );
		$this->assertCount( 1, $block_processor );
		$this->assertInstanceOf( Block_Processor::class, $block_processor[0] );
	}

	/**
	 * Test processing an outgoing block.
	 */
	public function test_process_outgoing_block() {
		$block = [ 'blockName' => 'core/paragraph' ];
		$processed_block = Blocks::process_outgoing_block( $block );
		$this->assertEquals( 'test', $processed_block['attrs']['outgoing'] );
		$this->assertEquals( '<p class="wp-block-paragraph">Outgoing Processed</p>', $processed_block['innerHTML'] );
		$this->assertEquals( [ '<p class="wp-block-paragraph">Outgoing Processed</p>' ], $processed_block['innerContent'] );
	}

	/**
	 * Test processing an incoming block.
	 */
	public function test_process_incoming_block() {
		$block = [ 'blockName' => 'core/paragraph' ];
		$processed_block = Blocks::process_incoming_block( $block );
		$this->assertEquals( 'test', $processed_block['attrs']['incoming'] );
		$this->assertEquals( '<p class="wp-block-paragraph">Incoming Processed</p>', $processed_block['innerHTML'] );
		$this->assertEquals( [ '<p class="wp-block-paragraph">Incoming Processed</p>' ], $processed_block['innerContent'] );
	}

	/**
	 * Test processing outgoing and incoming.
	 */
	public function test_process_outgoing_and_incoming_block() {
		$block = [ 'blockName' => 'core/paragraph' ];
		$processed_block = Blocks::process_outgoing_block( $block );
		$processed_block = Blocks::process_incoming_block( $processed_block );
		$this->assertEquals( 'test', $processed_block['attrs']['outgoing'] );
		$this->assertEquals( 'test', $processed_block['attrs']['incoming'] );
	}

	/**
	 * Test processing block with no processors.
	 */
	public function test_process_block_with_no_processors() {
		$block = [ 'blockName' => 'core/heading' ];
		$processed_block = Blocks::process_outgoing_block( $block );
		$processed_block = Blocks::process_incoming_block( $processed_block );
		$this->assertEquals( $block, $processed_block );
	}

	/**
	 * Test Outgoing_Post
	 */
	public function test_outgoing_post() {
		$post = $this->factory->post->create_and_get( [ 'post_content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->' ] );

		$outgoing_post = new Outgoing_Post( $post );
		$payload       = $outgoing_post->get_payload();

		$this->assertEquals( '<p class="wp-block-paragraph">Outgoing Processed</p>', $payload['post_data']['content'] );
		$this->assertEquals( '<!-- wp:paragraph {"outgoing":"test"} --><p class="wp-block-paragraph">Outgoing Processed</p><!-- /wp:paragraph -->', $payload['post_data']['raw_content'] );
	}

	/**
	 * Test Incoming_Post
	 */
	public function test_incoming_post() {
		$post = $this->factory->post->create_and_get( [ 'post_content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->' ] );

		$outgoing_post = new Outgoing_Post( $post );
		$payload       = $outgoing_post->get_payload();

		// Mock distribution for the post.
		$payload['site_url'] = 'https://node1.test';
		$payload['sites']    = [ 'https://node2.test' ];

		update_option( 'siteurl', 'https://node2.test' );
		update_option( 'home', 'https://node2.test' );

		$incoming_post = new Incoming_Post( $payload );
		$post_id       = $incoming_post->insert();

		$this->assertEquals( '<!-- wp:paragraph {"outgoing":"test","incoming":"test"} --><p class="wp-block-paragraph">Incoming Processed</p><!-- /wp:paragraph -->', get_post( $post_id )->post_content );
	}
}
