<?php
/**
 * Test Story
 *
 * @package Newspack_Story_Budget
 */

//phpcs:disable Squiz.Commenting.VariableComment.Missing

namespace Newspack_Story_Budget;

/**
 * Test Story Class.
 */
class Test_Story extends \WP_UnitTestCase {

	protected static $budgets = [];
	protected static $stories = [];

	/**
	 * WP setup before class.
	 */
	public static function wpSetUpBeforeClass() {
		self::$budgets = self::factory()->term->create_many(
			2,
			[
				'taxonomy' => Budgets::TAXONOMY,
			]
		);
		self::$stories = self::factory()->post->create_many(
			100,
			[
				'post_type' => 'post',
			]
		);
		foreach ( self::$stories as $i => $post_id ) {
			wp_set_post_terms( $post_id, [ self::$budgets[ $i % 2 ] ], Budgets::TAXONOMY );
		}
	}

	/**
	 * Test Story.
	 */
	public function test_new_story() {
		$story_id = self::$stories[0];
		$story = new Story( $story_id );
		$this->assertTrue( $story->is_valid() );
		$this->assertEquals( $story_id, $story->id );
	}

	/**
	 * Test non story.
	 */
	public function test_new_non_story() {
		$story = new Story( 0 );
		$this->assertFalse( $story->is_valid() );
	}

	/**
	 * Test to array.
	 */
	public function test_to_array() {
		$story_id = self::$stories[0];
		$story = new Story( $story_id );
		$arr = $story->to_array();
		$this->assertIsArray( $arr );
		$this->assertArrayHasKey( 'id', $arr );
		$this->assertArrayHasKey( 'title', $arr );
	}

	/**
	 * Test get budgets.
	 */
	public function test_get_budgets() {
		$story_id = self::$stories[0];
		$story    = new Story( $story_id );
		$budgets  = $story->get_budgets();
		$this->assertIsArray( $budgets );
		$this->assertContains( self::$budgets[0], $budgets );
	}

	/**
	 * Test update budgets.
	 */
	public function test_update_budgets() {
		$story_id = self::$stories[1];
		$story    = new Story( $story_id );

		$story->update_budgets( [ self::$budgets[0] ] );

		$result = $story->update_budgets( [ self::$budgets[1] ], true );
		$this->assertNotWPError( $result );

		$budgets = $story->get_budgets();
		$this->assertContains( self::$budgets[0], $budgets );
		$this->assertContains( self::$budgets[1], $budgets );
	}

	/**
	 * Test remove budgets.
	 */
	public function test_remove_budgets() {
		$story_id = self::$stories[2];
		$story    = new Story( $story_id );

		$story->update_budgets( self::$budgets );

		$result = $story->remove_budgets( [ self::$budgets[0] ] );
		$this->assertNotWPError( $result );

		$budgets = $story->get_budgets();
		$this->assertNotContains( self::$budgets[0], $budgets );
		$this->assertContains( self::$budgets[1], $budgets );
	}

	/**
	 * Test get metadata.
	 */
	public function test_get_metadata() {
		$story_id = self::$stories[0];
		$story    = new Story( $story_id );
		$metadata = $story->get_metadata();

		$this->assertIsArray( $metadata );
		$this->assertArrayHasKey( 'slug', $metadata );
		$this->assertArrayHasKey( 'preview_url', $metadata );
		$this->assertArrayHasKey( 'edit_url', $metadata );
		$this->assertArrayHasKey( 'can_edit', $metadata );
		$this->assertArrayHasKey( 'can_preview', $metadata );
		$this->assertArrayHasKey( 'fields_props', $metadata );
	}

	/**
	 * Test can preview.
	 */
	public function test_can_preview() {
		$story_id = self::$stories[0];
		$story    = new Story( $story_id );

		// For published posts, should be able to preview.
		wp_update_post(
			[
				'ID'          => $story_id,
				'post_status' => 'publish',
			]
		);

		$this->assertTrue( $story->can_preview() );
	}

	/**
	 * Test is_valid with different post statuses.
	 */
	public function test_is_valid_post_statuses() {
		$valid_statuses = [ 'publish', 'draft', 'pending', 'future' ];

		foreach ( $valid_statuses as $status ) {
			$story_id = self::factory()->post->create(
				[
					'post_type'   => 'post',
					'post_status' => $status,
				]
			);

			$story = new Story( $story_id );
			$this->assertTrue( $story->is_valid(), "Story should be valid with status: $status" );
		}

		$story_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'trash',
			]
		);

		$story = new Story( $story_id );
		$this->assertFalse( $story->is_valid(), 'Story should not be valid with trash status' );
	}

	/**
	 * Test update method with invalid field.
	 */
	public function test_update_invalid_field() {
		$story_id = self::$stories[0];
		$story    = new Story( $story_id );

		$result = $story->update( [ 'invalid_field' => 'value' ] );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_field', $result->get_error_code() );
	}

	/**
	 * Test update method with empty fields.
	 */
	public function test_update_empty_fields() {
		$story_id = self::$stories[0];
		$story    = new Story( $story_id );

		$result = $story->update( [] );
		$this->assertWPError( $result );
		$this->assertEquals( 'missing_field', $result->get_error_code() );
	}
}
