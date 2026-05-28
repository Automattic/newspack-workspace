<?php
/**
 * Class TestImageBlock
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Image_Block;
use Newspack_Network\Content_Distribution\Incoming_Post;

/**
 * Test the Image_Block class.
 */
class TestImageBlock extends \WP_UnitTestCase {
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
	 * Set up the test.
	 */
	public function set_up() {
		parent::set_up();

		// Set the site URL for the node that receives posts.
		update_option( 'siteurl', $this->node_2 );
		update_option( 'home', $this->node_2 );
	}

	/**
	 * Tear down the test.
	 */
	public function tear_down() {
		parent::tear_down();

		// Remove any filters that might have been added.
		remove_filter( 'render_block_core/image', [ Image_Block::class, 'render_lightbox' ], 16 );
		remove_filter( 'the_content', [ Image_Block::class, 'filter_content_image_attributes' ], PHP_INT_MAX );
	}

	/**
	 * Test hook_incoming_post_filters with non-incoming post.
	 */
	public function test_hook_incoming_post_filters_with_non_incoming_post() {
		// Create a regular post (not incoming).
		$post = $this->factory->post->create_and_get();

		// First, add the filters by simulating an incoming post.
		$payload = get_sample_payload( $this->node_1, $this->node_2 );
		update_post_meta( $post->ID, Incoming_Post::PAYLOAD_META, $payload );
		do_action( 'the_post', $post );

		// Now trigger with a non-incoming post.
		$regular_post = $this->factory->post->create_and_get();
		do_action( 'the_post', $regular_post );

		// Assert that filters are removed.
		$this->assertFalse( has_filter( 'render_block_core/image', [ Image_Block::class, 'render_lightbox' ] ) );
		$this->assertFalse( has_filter( 'the_content', [ Image_Block::class, 'filter_content_image_attributes' ] ) );
	}

	/**
	 * Test render_lightbox when core filter is not present.
	 */
	public function test_render_lightbox_without_core_filter() {
		// Ensure the core filter is not present.
		remove_all_filters( 'render_block_core/image' );

		$block_content = '<figure class="wp-block-image"><img src="test.jpg" alt="Test" /></figure>';
		$block = [
			'attrs' => [],
		];

		$result = Image_Block::render_lightbox( $block_content, $block );

		// Should return the content unchanged.
		$this->assertEquals( $block_content, $result );
	}

	/**
	 * Test render_lightbox when there's no img tag.
	 */
	public function test_render_lightbox_without_img_tag() {
		// Add the core filter to simulate it being present.
		add_filter( 'render_block_core/image', '__return_false', 10 );

		$block_content = '<figure class="wp-block-image">No image here</figure>';
		$block = [
			'attrs' => [],
		];

		$result = Image_Block::render_lightbox( $block_content, $block );

		// Should return the content unchanged.
		$this->assertEquals( $block_content, $result );
	}

	/**
	 * Test render_lightbox with media data from payload.
	 */
	public function test_render_lightbox_with_media_data() {
		// Apply the core filter to simulate it being present.
		add_filter( 'render_block_core/image', 'block_core_image_render_lightbox', 15, 2 );

		// Create a post with payload.
		$post = $this->factory->post->create_and_get();
		$payload = get_sample_payload( $this->node_1, $this->node_2 );
		$media_id = 123;
		$payload['post_data']['media_data'][ $media_id ] = [
			'url'    => 'https://example.com/image.jpg',
			'srcset' => 'https://example.com/image.jpg 1x, https://example.com/image-2x.jpg 2x',
			'width'  => 800,
			'height' => 600,
		];
		update_post_meta( $post->ID, Incoming_Post::PAYLOAD_META, $payload );

		do_action( 'the_post', $post );

		$block_content = '<figure class="wp-block-image"><img src="test.jpg" alt="Test" class="test-class" /></figure>';
		$block = [
			'attrs' => [
				'id' => $media_id,
			],
		];

		$result = Image_Block::render_lightbox( $block_content, $block );

		// Should contain lightbox attributes.
		$this->assertStringContainsString( 'data-wp-init', $result );
	}

	/**
	 * Test filter_content_image_attributes with media data.
	 */
	public function test_filter_content_image_attributes_with_media_data() {
		// Create a post with payload.
		$post = $this->factory->post->create_and_get();
		$payload = get_sample_payload( $this->node_1, $this->node_2 );
		$media_id = 456;
		$payload['post_data']['media_data'][ $media_id ] = [
			'url'         => 'https://example.com/image.jpg',
			'srcset'      => 'https://example.com/image.jpg 1x, https://example.com/image-2x.jpg 2x',
			'width'       => 800,
			'height'      => 600,
			'title'       => 'Image Title',
			'description' => 'Image Description',
			'caption'     => 'Image Caption',
			'metadata'    => [
				'image_meta' => [
					'aperture'          => '2.8',
					'credit'            => 'Photographer',
					'camera'            => 'Canon',
					'caption'           => 'Image Caption',
					'created_timestamp' => '1234567890',
					'copyright'         => 'Copyright',
					'focal_length'      => '50',
					'iso'               => '400',
					'shutter_speed'     => '1/60',
					'title'             => 'Image Title',
					'orientation'       => '1',
					'keywords'          => 'keyword1, keyword2', // Should be removed.
				],
			],
		];
		update_post_meta( $post->ID, Incoming_Post::PAYLOAD_META, $payload );

		// Set up the post payload in Image_Block.
		$incoming_post = new Incoming_Post( $post->ID );
		$reflection = new \ReflectionClass( Image_Block::class );
		$property = $reflection->getProperty( 'post_payload' );
		$property->setAccessible( true );
		$property->setValue( null, $incoming_post->get_post_payload() );

		$content = '<img src="test.jpg" data-id="' . $media_id . '" alt="Test" />';

		$result = Image_Block::filter_content_image_attributes( $content );

		// Should contain distributed post attributes.
		$this->assertStringContainsString( 'srcset=', $result );
		$this->assertStringContainsString( 'data-permalink=', $result );
		$this->assertStringContainsString( 'data-orig-file=', $result );
		$this->assertStringContainsString( 'data-orig-size=', $result );
		$this->assertStringContainsString( 'data-image-meta=', $result );
		$this->assertStringContainsString( 'data-image-title=', $result );
		$this->assertStringContainsString( 'data-image-description=', $result );
		$this->assertStringContainsString( 'data-image-caption=', $result );
		$this->assertStringContainsString( 'https://example.com/image.jpg', $result );
		$this->assertStringContainsString( '800,600', $result ); // orig-size format.

		// Keywords should not be in the image meta JSON.
		$this->assertStringNotContainsString( 'keywords', $result );
	}

	/**
	 * Test filter_content_image_attributes without data-id attribute.
	 */
	public function test_filter_content_image_attributes_without_data_id() {
		// Create a post with payload.
		$post = $this->factory->post->create_and_get();
		$payload = get_sample_payload( $this->node_1, $this->node_2 );
		update_post_meta( $post->ID, Incoming_Post::PAYLOAD_META, $payload );

		// Set up the post payload in Image_Block.
		$incoming_post = new Incoming_Post( $post->ID );
		$reflection = new \ReflectionClass( Image_Block::class );
		$property = $reflection->getProperty( 'post_payload' );
		$property->setAccessible( true );
		$property->setValue( null, $incoming_post->get_post_payload() );

		$content = '<img src="test.jpg" alt="Test" />';

		$result = Image_Block::filter_content_image_attributes( $content );

		// Should return unchanged since there's no data-id.
		$this->assertEquals( $content, $result );
	}

	/**
	 * Test filter_content_image_attributes with non-existent media ID.
	 */
	public function test_filter_content_image_attributes_with_non_existent_media_id() {
		// Create a post with payload.
		$post = $this->factory->post->create_and_get();
		$payload = get_sample_payload( $this->node_1, $this->node_2 );
		update_post_meta( $post->ID, Incoming_Post::PAYLOAD_META, $payload );

		// Set up the post payload in Image_Block.
		$incoming_post = new Incoming_Post( $post->ID );
		$reflection = new \ReflectionClass( Image_Block::class );
		$property = $reflection->getProperty( 'post_payload' );
		$property->setAccessible( true );
		$property->setValue( null, $incoming_post->get_post_payload() );

		$content = '<img src="test.jpg" data-id="999" alt="Test" />';

		$result = Image_Block::filter_content_image_attributes( $content );

		// Should return unchanged since media ID doesn't exist in payload.
		$this->assertEquals( $content, $result );
	}

	/**
	 * Test filter_content_image_attributes with multiple images.
	 */
	public function test_filter_content_image_attributes_with_multiple_images() {
		// Create a post with payload.
		$post = $this->factory->post->create_and_get();
		$payload = get_sample_payload( $this->node_1, $this->node_2 );
		$media_id_1 = 111;
		$media_id_2 = 222;
		$payload['post_data']['media_data'][ $media_id_1 ] = [
			'url'    => 'https://example.com/image1.jpg',
			'srcset' => 'https://example.com/image1.jpg 1x',
			'width'  => 400,
			'height' => 300,
		];
		$payload['post_data']['media_data'][ $media_id_2 ] = [
			'url'    => 'https://example.com/image2.jpg',
			'srcset' => 'https://example.com/image2.jpg 1x',
			'width'  => 500,
			'height' => 400,
		];
		update_post_meta( $post->ID, Incoming_Post::PAYLOAD_META, $payload );

		// Set up the post payload in Image_Block.
		$incoming_post = new Incoming_Post( $post->ID );
		$reflection = new \ReflectionClass( Image_Block::class );
		$property = $reflection->getProperty( 'post_payload' );
		$property->setAccessible( true );
		$property->setValue( null, $incoming_post->get_post_payload() );

		$content = '<img src="test1.jpg" data-id="' . $media_id_1 . '" alt="Test 1" /><img src="test2.jpg" data-id="' . $media_id_2 . '" alt="Test 2" />';

		$result = Image_Block::filter_content_image_attributes( $content );

		// Both images should have attributes.
		$this->assertStringContainsString( 'data-id="' . $media_id_1 . '"', $result );
		$this->assertStringContainsString( 'data-id="' . $media_id_2 . '"', $result );
		$this->assertStringContainsString( 'https://example.com/image1.jpg', $result );
		$this->assertStringContainsString( 'https://example.com/image2.jpg', $result );
		$this->assertStringContainsString( '400,300', $result );
		$this->assertStringContainsString( '500,400', $result );
	}

	/**
	 * Test filter_content_image_attributes with empty image meta.
	 */
	public function test_filter_content_image_attributes_with_empty_image_meta() {
		// Create a post with payload.
		$post = $this->factory->post->create_and_get();
		$payload = get_sample_payload( $this->node_1, $this->node_2 );
		$media_id = 789;
		$payload['post_data']['media_data'][ $media_id ] = [
			'url'      => 'https://example.com/image.jpg',
			'srcset'   => 'https://example.com/image.jpg 1x',
			'width'    => 800,
			'height'   => 600,
			'metadata' => [],
		];
		update_post_meta( $post->ID, Incoming_Post::PAYLOAD_META, $payload );

		do_action( 'the_post', $post );

		$content = '<img src="test.jpg" data-id="' . $media_id . '" alt="Test" />';

		$result = apply_filters( 'the_content', $content );

		// Should still have attributes, but image-meta should be empty JSON object.
		$this->assertStringContainsString( 'data-image-meta=', $result );
		$this->assertStringContainsString( 'data-orig-size=', $result );
	}

	/**
	 * Test filter_content_image_attributes with missing width/height.
	 */
	public function test_filter_content_image_attributes_without_dimensions() {
		// Create a post with payload.
		$post = $this->factory->post->create_and_get();
		$payload = get_sample_payload( $this->node_1, $this->node_2 );
		$media_id = 999;
		$payload['post_data']['media_data'][ $media_id ] = [
			'url'    => 'https://example.com/image.jpg',
			'srcset' => 'https://example.com/image.jpg 1x',
		];
		update_post_meta( $post->ID, Incoming_Post::PAYLOAD_META, $payload );

		do_action( 'the_post', $post );

		$content = '<img src="test.jpg" data-id="' . $media_id . '" alt="Test" />';

		$result = apply_filters( 'the_content', $content );

		// Should have attributes, but orig-size should be empty.
		$this->assertStringContainsString( 'data-orig-size=""', $result );
		$this->assertStringContainsString( 'data-permalink=', $result );
	}
}
