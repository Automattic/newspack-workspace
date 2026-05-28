<?php
/**
 * Class TestOutgoingPost
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Hub\Node as Hub_Node;
use WP_User;

/**
 * Test the Outgoing_Post class.
 */
class TestOutgoingPostMediaData extends \WP_UnitTestCase {
	/**
	 * Test get content attachments with no images.
	 */
	public function test_get_content_attachments_no_images() {
		$content = 'This is a test post with no images.';
		$attachments = Outgoing_Post::get_content_attachments( $content );
		$this->assertEmpty( $attachments );
	}

	/**
	 * Test get content attachments with wp-image class.
	 */
	public function test_get_content_attachments_wp_image_class() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create_object(
			'test-image.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			]
		);

		$content = '<img src="https://example.com/test-image.jpg" class="wp-image-' . $attachment_id . '" alt="Test Image">';
		$attachments = Outgoing_Post::get_content_attachments( $content );

		$this->assertCount( 1, $attachments );
		$this->assertEquals( $attachment_id, $attachments[0]->ID );
		$this->assertEquals( 'attachment', $attachments[0]->post_type );
	}

	/**
	 * Test get content attachments with data-attachment-id attribute.
	 */
	public function test_get_content_attachments_data_attachment_id() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create_object(
			'test-image.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			]
		);

		$content = '<img src="https://example.com/test-image.jpg" data-attachment-id="' . $attachment_id . '" alt="Test Image">';
		$attachments = Outgoing_Post::get_content_attachments( $content );

		$this->assertCount( 1, $attachments );
		$this->assertEquals( $attachment_id, $attachments[0]->ID );
	}

	/**
	 * Test get content attachments with data-id attribute.
	 */
	public function test_get_content_attachments_data_id() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create_object(
			'test-image.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			]
		);

		$content = '<img src="https://example.com/test-image.jpg" data-id="' . $attachment_id . '" alt="Test Image">';
		$attachments = Outgoing_Post::get_content_attachments( $content );

		$this->assertCount( 1, $attachments );
		$this->assertEquals( $attachment_id, $attachments[0]->ID );
	}

	/**
	 * Test get content attachments with id attribute.
	 */
	public function test_get_content_attachments_id_attribute() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create_object(
			'test-image.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			]
		);

		$content = '<img src="https://example.com/test-image.jpg" id="' . $attachment_id . '" alt="Test Image">';
		$attachments = Outgoing_Post::get_content_attachments( $content );

		$this->assertCount( 1, $attachments );
		$this->assertEquals( $attachment_id, $attachments[0]->ID );
	}

	/**
	 * Test get content attachments with multiple images.
	 */
	public function test_get_content_attachments_multiple_images() {
		// Create test attachments.
		$attachment_id_1 = $this->factory->attachment->create_object(
			'test-image-1.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			]
		);
		$attachment_id_2 = $this->factory->attachment->create_object(
			'test-image-2.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			]
		);

		$content = '
			<p>Some text here.</p>
			<img src="https://example.com/test-image-1.jpg" class="wp-image-' . $attachment_id_1 . '" alt="Test Image 1">
			<p>More text here.</p>
			<img src="https://example.com/test-image-2.jpg" data-attachment-id="' . $attachment_id_2 . '" alt="Test Image 2">
			<p>Final text.</p>
		';
		$attachments = Outgoing_Post::get_content_attachments( $content );

		$this->assertCount( 2, $attachments );
		$this->assertEquals( $attachment_id_1, $attachments[0]->ID );
		$this->assertEquals( $attachment_id_2, $attachments[1]->ID );
	}

	/**
	 * Test get content attachments with non-existent attachment ID.
	 */
	public function test_get_content_attachments_nonexistent_id() {
		$content = '<img src="https://example.com/test-image.jpg" class="wp-image-99999" alt="Test Image">';
		$attachments = Outgoing_Post::get_content_attachments( $content );

		$this->assertEmpty( $attachments );
	}

	/**
	 * Test get content attachments with image without attachment ID.
	 */
	public function test_get_content_attachments_no_attachment_id() {
		$content = '<img src="https://example.com/test-image.jpg" alt="Test Image">';
		$attachments = Outgoing_Post::get_content_attachments( $content );

		$this->assertEmpty( $attachments );
	}

	/**
	 * Test get content attachments with mixed valid and invalid images.
	 */
	public function test_get_content_attachments_mixed_valid_invalid() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create_object(
			'test-image.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			]
		);

		$content = '
			<img src="https://example.com/test-image.jpg" alt="No attachment ID">
			<img src="https://example.com/test-image.jpg" class="wp-image-' . $attachment_id . '" alt="Valid attachment">
			<img src="https://example.com/test-image.jpg" class="wp-image-99999" alt="Invalid attachment ID">
		';
		$attachments = Outgoing_Post::get_content_attachments( $content );

		$this->assertCount( 1, $attachments );
		$this->assertEquals( $attachment_id, $attachments[0]->ID );
	}

	/**
	 * Test get content attachments with different attribute orders.
	 */
	public function test_get_content_attachments_different_attribute_orders() {
		// Create test attachments.
		$attachment_id_1 = $this->factory->attachment->create_object(
			'test-image-1.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			]
		);
		$attachment_id_2 = $this->factory->attachment->create_object(
			'test-image-2.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			]
		);

		$content = '
			<img alt="Test Image 1" src="https://example.com/test-image-1.jpg" class="wp-image-' . $attachment_id_1 . '">
			<img data-attachment-id="' . $attachment_id_2 . '" alt="Test Image 2" src="https://example.com/test-image-2.jpg">
		';
		$attachments = Outgoing_Post::get_content_attachments( $content );

		$this->assertCount( 2, $attachments );
		$this->assertEquals( $attachment_id_1, $attachments[0]->ID );
		$this->assertEquals( $attachment_id_2, $attachments[1]->ID );
	}

	/**
	 * Test get content attachments with case insensitive matching.
	 */
	public function test_get_content_attachments_case_insensitive() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create_object(
			'test-image.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			]
		);

		$content = '<img src="https://example.com/test-image.jpg" class="WP-IMAGE-' . $attachment_id . '" alt="Test Image">';
		$attachments = Outgoing_Post::get_content_attachments( $content );

		$this->assertCount( 1, $attachments );
		$this->assertEquals( $attachment_id, $attachments[0]->ID );
	}

	/**
	 * Test get content attachments with self-closing img tags.
	 */
	public function test_get_content_attachments_self_closing_tags() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create_object(
			'test-image.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			]
		);

		$content = '<img src="https://example.com/test-image.jpg" class="wp-image-' . $attachment_id . '" alt="Test Image" />';
		$attachments = Outgoing_Post::get_content_attachments( $content );

		$this->assertCount( 1, $attachments );
		$this->assertEquals( $attachment_id, $attachments[0]->ID );
	}
}
