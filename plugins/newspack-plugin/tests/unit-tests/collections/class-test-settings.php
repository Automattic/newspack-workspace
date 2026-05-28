<?php
/**
 * Unit tests for the Collections Settings class.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Collections\Settings
 */

namespace Newspack\Tests\Unit\Collections;

use WP_UnitTestCase;
use WP_REST_Request;
use Newspack\Collections\Settings;

/**
 * Tests for the Collections Settings class.
 */
class Test_Settings extends WP_UnitTestCase {

	/**
	 * Clean up settings before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION_NAME );
	}

	/**
	 * Test get_settings returns defaults when no settings exist.
	 *
	 * @covers \Newspack\Collections\Settings::get_settings
	 */
	public function test_get_settings_returns_defaults_when_empty() {
		$settings = Settings::get_settings();
		$defaults = Settings::get_rest_args( 'defaults' );
		$this->assertEquals( $defaults, $settings );
	}

	/**
	 * Test get_settings includes existing settings with defaults.
	 *
	 * @covers \Newspack\Collections\Settings::get_settings
	 */
	public function test_get_settings_includes_existing_settings() {
		$existing_settings = [
			'custom_name'    => 'Issues',
			'subscribe_link' => 'https://example.com/subscribe',
			'order_link'     => 'https://example.com/order',
		];

		update_option( Settings::OPTION_NAME, $existing_settings );

		$settings = Settings::get_settings();
		$defaults = Settings::get_rest_args( 'defaults' );
		$expected = array_merge( $defaults, $existing_settings );

		$this->assertEquals( $expected, $settings );
	}

	/**
	 * Test update_settings includes existing settings.
	 *
	 * @covers \Newspack\Collections\Settings::update_settings
	 */
	public function test_update_settings_includes_existing_settings() {
		$existing_settings = [
			'custom_name'    => 'Issues',
			'subscribe_link' => 'https://old.example.com/subscribe',
		];

		update_option( Settings::OPTION_NAME, $existing_settings );

		$new_settings = [
			'custom_name'           => 'Magazines',
			'custom_naming_enabled' => true,
		];

		$result = Settings::update_settings( $new_settings );
		$this->assertTrue( $result );

		$expected        = array_merge( $existing_settings, $new_settings );
		$stored_settings = get_option( Settings::OPTION_NAME );
		$this->assertEquals( $expected, $stored_settings );
	}

	/**
	 * Test get_setting returns correct values and handles edge cases.
	 *
	 * @covers \Newspack\Collections\Settings::get_setting
	 */
	public function test_get_setting() {
		$settings = [
			'custom_name'           => 'Issues',
			'custom_naming_enabled' => true,
		];

		update_option( Settings::OPTION_NAME, $settings );

		// Test existing settings.
		$this->assertEquals( 'Issues', Settings::get_setting( 'custom_name' ) );
		$this->assertTrue( Settings::get_setting( 'custom_naming_enabled' ) );

		// Test defaults for unset fields.
		$this->assertEquals( '', Settings::get_setting( 'custom_singular_name' ) );
		$this->assertEquals( '', Settings::get_setting( 'subscribe_link' ) );
		$this->assertEquals( '', Settings::get_setting( 'order_link' ) );

		// Test non-existent field.
		$this->assertNull( Settings::get_setting( 'non_existent_field' ) );
	}

	/**
	 * Test get_rest_args returns correct structure and sanitization callbacks.
	 *
	 * @covers \Newspack\Collections\Settings::get_rest_args
	 */
	public function test_get_rest_args() {
		$rest_args = Settings::get_rest_args();

		$this->assertIsArray( $rest_args );

		// Test all expected fields exist.
		$expected_fields = Settings::get_rest_args( 'keys' );
		foreach ( $expected_fields as $field ) {
			$this->assertArrayHasKey( $field, $rest_args );
			$this->assertArrayHasKey( 'required', $rest_args[ $field ] );
			$this->assertArrayHasKey( 'default', $rest_args[ $field ] );
			$this->assertArrayHasKey( 'sanitize_callback', $rest_args[ $field ] );
			$this->assertFalse( $rest_args[ $field ]['required'] );
			$this->assertIsCallable( $rest_args[ $field ]['sanitize_callback'] );
		}
	}

	/**
	 * Test sanitization callbacks work correctly.
	 *
	 * @covers \Newspack\Collections\Settings::get_rest_args
	 */
	public function test_sanitization_callbacks() {
		$rest_args = Settings::get_rest_args();

		// Test boolean sanitization.
		$boolean_callback = $rest_args['custom_naming_enabled']['sanitize_callback'];
		$this->assertTrue( $boolean_callback( 'true' ) );
		$this->assertTrue( $boolean_callback( 1 ) );
		$this->assertFalse( $boolean_callback( 'false' ) );
		$this->assertFalse( $boolean_callback( 0 ) );

		// Test text field sanitization.
		$text_callback = $rest_args['custom_name']['sanitize_callback'];
		$this->assertEquals( 'Clean Text', $text_callback( 'Clean Text' ) );
		$this->assertEquals( 'Clean Text', $text_callback( '<script>alert("xss")</script>Clean Text' ) );

		// Test URL sanitization.
		$url_callback = $rest_args['subscribe_link']['sanitize_callback'];
		$this->assertEquals( 'https://example.com/subscribe', $url_callback( 'https://example.com/subscribe' ) );
		$this->assertEquals( '', $url_callback( 'javascript:alert("xss")' ) ); // Dangerous URLs should be sanitized to empty string.

		$url_callback = $rest_args['order_link']['sanitize_callback'];
		$this->assertEquals( 'https://example.com/order', $url_callback( 'https://example.com/order' ) );
		$this->assertEquals( 'http://example.com', $url_callback( '{example}.com' ) );

		// Test slug sanitization.
		$slug_callback = $rest_args['custom_slug']['sanitize_callback'];
		$this->assertEquals( 'clean-slug', $slug_callback( 'Clean Slug' ) );
		$this->assertEquals( 'clean-slug', $slug_callback( 'Clean Slug!' ) );

		// Test post indicator style sanitization.
		$style_callback = $rest_args['post_indicator_style']['sanitize_callback'];
		$this->assertEquals( 'default', $style_callback( 'default' ) );
		$this->assertEquals( 'card', $style_callback( 'card' ) );
		$this->assertEquals( 'default', $style_callback( 'custom' ) );

		// Test card message sanitization.
		$message_callback = $rest_args['card_message']['sanitize_callback'];
		$this->assertEquals( 'Custom message', $message_callback( 'Custom message' ) );
		$this->assertEquals( 'Clean message', $message_callback( '<script>alert("xss")</script>Clean message' ) );

		// Test posts per page archive sanitization.
		$posts_per_page_callback = $rest_args['posts_per_page']['sanitize_callback'];
		foreach ( Settings::POSTS_PER_PAGE_OPTIONS as $option ) {
			$this->assertEquals( $option, $posts_per_page_callback( $option ) );
		}
		$this->assertEquals( 12, $posts_per_page_callback( 42 ) ); // Invalid values default to 12.

		// Test highlight latest sanitization.
		$highlight_latest_callback = $rest_args['highlight_latest']['sanitize_callback'];
		$this->assertTrue( $highlight_latest_callback( 'true' ) );
		$this->assertFalse( $highlight_latest_callback( 'false' ) );

		// Test category filter label sanitization.
		$category_filter_label_callback = $rest_args['category_filter_label']['sanitize_callback'];
		$this->assertEquals( 'Custom label', $category_filter_label_callback( 'Custom label' ) );
		$this->assertEquals( 'Clean label', $category_filter_label_callback( '<script>alert("xss")</script>Clean label' ) );

		// Test articles block attrs sanitization.
		$articles_callback = $rest_args['articles_block_attrs']['sanitize_callback'];
		$this->assertEquals( [], $articles_callback( 'invalid' ) );

		// Set up existing settings for proper testing.
		Settings::update_setting( 'articles_block_attrs', [] );
		$this->assertTrue( $articles_callback( [ 'showCategory' => true ] )['showCategory'] );
		$this->assertFalse( $articles_callback( [ 'showCategory' => 'false' ] )['showCategory'] );

		// Test show cover story image sanitization.
		$show_cover_story_img_callback = $rest_args['show_cover_story_img']['sanitize_callback'];
		$this->assertTrue( $show_cover_story_img_callback( 'true' ) );
		$this->assertFalse( $show_cover_story_img_callback( 'false' ) );
	}

	/**
	 * Test update_from_request handles non-field parameters and empty requests.
	 *
	 * @covers \Newspack\Collections\Settings::update_from_request
	 */
	public function test_update_from_request_handles_non_field_parameters_and_empty_requests() {
		// Test with empty request.
		$request  = new WP_REST_Request();
		$result   = Settings::update_from_request( $request );
		$defaults = Settings::get_rest_args( 'defaults' );
		$this->assertEquals( $defaults, $result );

		// Test ignores non-field parameters.
		$request = new WP_REST_Request();
		$request->set_param( 'custom_name', 'Issues' );
		$request->set_param( 'non_field_param', 'should_be_ignored' );

		$result = Settings::update_from_request( $request );
		$this->assertEquals( 'Issues', $result['custom_name'] );
		$this->assertArrayNotHasKey( 'non_field_param', $result );

		$stored_settings = get_option( Settings::OPTION_NAME );
		$this->assertArrayNotHasKey( 'non_field_param', $stored_settings );
	}

	/**
	 * Test sanitize_articles_block_attrs preserves unmanaged keys.
	 *
	 * @covers \Newspack\Collections\Settings::sanitize_articles_block_attrs
	 */
	public function test_sanitize_articles_block_attrs() {
		// Set up existing settings with unmanaged keys.
		$articles_block_attrs = [
			'showDate'      => true,
			'excerptLength' => 150,
			'showCategory'  => false,
		];
		Settings::update_setting( 'articles_block_attrs', $articles_block_attrs );

		// Test preserving unmanaged keys while updating managed ones.
		$result = Settings::sanitize_articles_block_attrs( [ 'showCategory' => true ] );
		$this->assertEquals( true, $result['showCategory'], 'showCategory should be true' );
		$this->assertEquals( $articles_block_attrs['showDate'], $result['showDate'], 'showDate should be preserved' );
		$this->assertEquals( $articles_block_attrs['excerptLength'], $result['excerptLength'], 'excerptLength should be preserved' );

		// Test with invalid input.
		$this->assertEquals( [], Settings::sanitize_articles_block_attrs( 'invalid' ) );
		$this->assertEquals( [], Settings::sanitize_articles_block_attrs( null ) );

		// Test with empty existing settings.
		delete_option( Settings::OPTION_NAME );
		$result = Settings::sanitize_articles_block_attrs( [ 'showCategory' => true ] );
		$this->assertEquals( true, $result['showCategory'], 'showCategory should be true' );
		$this->assertArrayNotHasKey( 'showDate', $result, 'showDate should be removed' );
	}
}
