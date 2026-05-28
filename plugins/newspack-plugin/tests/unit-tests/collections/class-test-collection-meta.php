<?php
/**
 * Tests for the Collection_Meta class.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests\Unit\Collections;

use WP_UnitTestCase;
use Newspack\Collections\Collection_Meta;
use Newspack\Collections\Post_Type;

/**
 * Test the Collection_Meta class.
 */
class Test_Collection_Meta extends WP_UnitTestCase {

	use Traits\Trait_Collections_Test;
	use Traits\Trait_Meta_Handler_Test;

	/**
	 * Expected meta keys.
	 *
	 * @var array
	 */
	private const EXPECTED_META_KEYS = [
		'volume',
		'number',
		'period',
		'subscribe_link',
		'order_link',
		'ctas',
		'cover_story_img_visibility',
	];

	/**
	 * Test get_meta_definitions returns expected structure.
	 *
	 * @covers \Newspack\Collections\Collection_Meta::get_meta_definitions
	 */
	public function test_get_meta_definitions() {
		$required_properties = [ 'type', 'label', 'single', 'sanitize_callback', 'show_in_rest' ];
		$metas               = Collection_Meta::get_meta_definitions();

		// Test that we have all expected meta keys.
		$this->assertEquals( self::EXPECTED_META_KEYS, array_keys( $metas ) );

		// Test that all required properties are present.
		foreach ( $metas as $key => $meta ) {
			foreach ( $required_properties as $property ) {
				$this->assertArrayHasKey( $property, $meta, 'Meta "' . $property . '" definition is missing for "' . $key . '"' );
				$this->assertNotEmpty( $meta[ $property ], 'Meta "' . $property . '" definition is empty for "' . $key . '"' );
			}
		}
	}

	/**
	 * Test get_frontend_meta_definitions returns expected structure.
	 *
	 * @covers \Newspack\Collections\Collection_Meta::get_frontend_meta_definitions
	 */
	public function test_get_frontend_meta_definitions() {
		$required_properties = [ 'key', 'type', 'label' ];
		$definitions         = Collection_Meta::get_frontend_meta_definitions();

		// Test that we have all expected meta keys.
		$this->assertEquals( self::EXPECTED_META_KEYS, array_keys( $definitions ) );

		// Test that all required properties are present.
		foreach ( $definitions as $key => $definition ) {
			foreach ( $required_properties as $property ) {
				$this->assertArrayHasKey( $property, $definition, 'Meta "' . $property . '" definition is missing for "' . $key . '"' );
				$this->assertNotEmpty( $definition[ $property ], 'Meta "' . $property . '" definition is empty for "' . $key . '"' );
			}
		}
	}

	/**
	 * Test meta registration.
	 *
	 * @covers \Newspack\Collections\Collection_Meta::register_meta
	 * @covers \Newspack\Collections\Collection_Meta::register_meta_for_object
	 * @covers \Newspack\Collections\Collection_Meta::get_meta_definitions
	 */
	public function test_register_meta() {
		// Initialize the post type and register the meta.
		Post_Type::init();
		Collection_Meta::register_meta();

		$this->assertMetaFieldsRegistered( Collection_Meta::class, 'post', Post_Type::get_post_type() );
		$this->assertFrontendMetaDefinitionsValid( Collection_Meta::class );
		$post_id = $this->factory()->post->create();
		$this->assertMetaValueCanBeSetAndRetrieved( Collection_Meta::class, $post_id, 'volume', 'Test Volume' );
	}

	/**
	 * Test auth callback.
	 *
	 * @covers \Newspack\Collections\Collection_Meta::auth_callback
	 */
	public function test_auth_callback() {
		// Test with admin user.
		$this->set_current_user_role( 'administrator' );
		$this->assertTrue( Collection_Meta::auth_callback(), 'Admin user should be able to edit collection meta' );

		// Test with subscriber.
		$this->set_current_user_role( 'subscriber' );
		$this->assertFalse( Collection_Meta::auth_callback(), 'Subscriber user should not be able to edit collection meta' );

		// Test with no user.
		wp_set_current_user( 0 );
		$this->assertFalse( Collection_Meta::auth_callback(), 'Empty user should not be able to edit collection meta' );
	}

	/**
	 * Test CTA sanitization with valid inputs.
	 *
	 * @covers \Newspack\Collections\Collection_Meta::sanitize_ctas
	 */
	public function test_sanitize_ctas_valid_data() {
		$attachment_id = self::factory()->attachment->create();

		$input = [
			[
				'type'  => 'link',
				'label' => 'Test Link',
				'url'   => 'https://example.com',
			],
			[
				'type'  => 'attachment',
				'label' => 'Test File',
				'id'    => $attachment_id,
			],
		];

		$result = Collection_Meta::sanitize_ctas( $input );

		$this->assertCount( 2, $result, 'Should return 2 valid CTAs' );
		$this->assertEquals( $input[0]['type'], $result[0]['type'], 'CTA type should match' );
		$this->assertEquals( $input[0]['label'], $result[0]['label'], 'CTA label should match' );
		$this->assertEquals( $input[0]['url'], $result[0]['url'], 'CTA URL should match' );
		$this->assertEquals( $input[1]['type'], $result[1]['type'], 'CTA type should match' );
		$this->assertEquals( $input[1]['label'], $result[1]['label'], 'CTA label should match' );
		$this->assertEquals( $input[1]['id'], $result[1]['id'], 'CTA attachment ID should match' );
	}

	/**
	 * Test CTA sanitization with invalid inputs.
	 *
	 * @covers \Newspack\Collections\Collection_Meta::sanitize_ctas
	 */
	public function test_sanitize_ctas_invalid_data() {
		// Create a regular post (not attachment) for testing.
		$post_id = self::factory()->post->create();

		$input = [
			'not_an_array',
			[
				'type'  => 'invalid_type',
				'label' => 'Test',
			],
			[
				'type' => 'link',
				// Missing label.
				'url'  => 'https://example.com',
			],
			[
				'type'  => 'link',
				'label' => 'Test',
				'url'   => 'javascript:alert("invalid url")',
			],
			[
				'type'  => 'attachment',
				'label' => 'Test',
				// Missing id.
			],
			[
				'type'  => 'attachment',
				'label' => 'Test',
				'id'    => 99999, // Non-existent attachment.
			],
			[
				'type'  => 'attachment',
				'label' => 'Test',
				'id'    => $post_id, // Valid post ID but not an attachment.
			],
		];

		$result = Collection_Meta::sanitize_ctas( $input );

		$this->assertEmpty( $result, 'Should return empty array for all invalid CTAs' );

		// Test with non-array input.
		$this->assertEquals( [], Collection_Meta::sanitize_ctas( 'string' ), 'Should return empty array for string input' );
		$this->assertEquals( [], Collection_Meta::sanitize_ctas( 123 ), 'Should return empty array for integer input' );
		$this->assertEquals( [], Collection_Meta::sanitize_ctas( null ), 'Should return empty array for null input' );
	}

	/**
	 * Test register_rest_fields method.
	 *
	 * @covers \Newspack\Collections\Collection_Meta::register_rest_fields
	 */
	public function test_register_rest_fields() {
		// Initialize REST API.
		Collection_Meta::register_rest_fields();

		$post_type   = Post_Type::get_post_type();
		$rest_fields = $GLOBALS['wp_rest_additional_fields'][ $post_type ] ?? [];

		$this->assertArrayHasKey( 'ctas', $rest_fields, 'CTA field should be registered.' );
		$this->assertEquals( [ Collection_Meta::class, 'get_collection_ctas_for_rest' ], $rest_fields['ctas']['get_callback'], 'Should have correct get callback.' );
	}

	/**
	 * Test get_collection_ctas_for_rest method.
	 *
	 * @covers \Newspack\Collections\Collection_Meta::get_collection_ctas_for_rest
	 */
	public function test_get_collection_ctas_for_rest() {
		$collection_id = $this->create_test_collection();

		// Mock CTAs data.
		$ctas_data = [
			[
				'type'  => 'link',
				'label' => 'Subscribe',
				'url'   => 'https://example.com/subscribe',
			],
			[
				'type'  => 'link',
				'label' => 'Order',
				'url'   => 'https://example.com/order',
			],
		];

		Collection_Meta::set( $collection_id, 'ctas', $ctas_data );

		$post_data = [ 'id' => $collection_id ];
		$result    = Collection_Meta::get_collection_ctas_for_rest( $post_data );

		$this->assertIsArray( $result, 'Result should be an array.' );
		$this->assertCount( 2, $result, 'Should return 2 CTAs.' );
		$this->assertEquals( 'Subscribe', $result[0]['label'], 'First CTA should have correct label.' );
		$this->assertEquals( 'https://example.com/subscribe', $result[0]['url'], 'First CTA should have correct URL.' );
		$this->assertEquals( 'Order', $result[1]['label'], 'Second CTA should have correct label.' );
		$this->assertEquals( 'https://example.com/order', $result[1]['url'], 'Second CTA should have correct URL.' );
	}

	/**
	 * Test get_collection_ctas_for_rest with empty CTAs.
	 *
	 * @covers \Newspack\Collections\Collection_Meta::get_collection_ctas_for_rest
	 */
	public function test_get_collection_ctas_for_rest_empty() {
		$collection_id = $this->create_test_collection();

		$post_data = [ 'id' => $collection_id ];
		$result    = Collection_Meta::get_collection_ctas_for_rest( $post_data );

		$this->assertIsArray( $result, 'Result should be an array.' );
		$this->assertEmpty( $result, 'Should return empty array when no CTAs.' );
	}

	/**
	 * Test that get_collection_ctas_for_rest escapes malicious values.
	 *
	 * @covers \Newspack\Collections\Collection_Meta::get_collection_ctas_for_rest
	 */
	public function test_get_collection_ctas_for_rest_escaping() {
		$ctas_data = [
			[
				'type'  => 'link',
				'label' => '<script>alert("xss")</script>Malicious Label',
				'url'   => 'javascript:alert("malicious")',
			],
			[
				'type'  => 'link',
				'label' => 'Safe & Good Label',
				'url'   => 'https://example.com/page?param=value&other=test',
			],
			[
				'type'  => 'link"',
				'label' => 'Test',
				'url'   => 'https://example.com',
				'class' => 'btn" onclick="alert(\'xss\')" class="fake',
			],
		];

		// Test all fields are properly escaped.
		$expected = [
			[
				'type'  => 'link',
				'label' => '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;Malicious Label',
				'url'   => '',
				'class' => '',
			],
			[
				'type'  => 'link',
				'label' => 'Safe &amp; Good Label',
				'url'   => 'https://example.com/page?param=value&#038;other=test',
				'class' => '',
			],
			[
				'type'  => 'link&quot;',
				'label' => 'Test',
				'url'   => 'https://example.com',
				'class' => 'btn&quot; onclick=&quot;alert(&#039;xss&#039;)&quot; class=&quot;fake',
			],
		];

		$collection_id = $this->create_test_collection();
		Collection_Meta::set( $collection_id, 'ctas', $ctas_data );
		$result = Collection_Meta::get_collection_ctas_for_rest( [ 'id' => $collection_id ] );

		$this->assertIsArray( $result, 'Result should be an array.' );
		$this->assertCount( 3, $result, 'Should return 3 CTAs.' );
		$this->assertEquals( $expected, $result, 'All CTA fields should be properly escaped.' );
	}
}
