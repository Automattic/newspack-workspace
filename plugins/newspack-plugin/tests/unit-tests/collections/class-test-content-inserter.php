<?php
/**
 * Tests for the Content_Inserter class.
 *
 * @package Newspack\Tests\Unit\Collections
 */

namespace Newspack\Tests\Unit\Collections;

use Newspack\Collections\Content_Inserter;
use Newspack\Collections\Post_Type;
use Newspack\Collections\Collection_Taxonomy;
use Newspack\Collections\Sync;
use Newspack\Collections\Enqueuer;

/**
 * Tests for the Content_Inserter class.
 */
class Test_Content_Inserter extends \WP_UnitTestCase {
	use Traits\Trait_Collections_Test;
	use Traits\Trait_Enqueuer_Test;

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();

		Post_Type::init();
		Collection_Taxonomy::register_taxonomy();
	}

	/**
	 * Tear down the test environment.
	 */
	public function tear_down() {
		parent::tear_down();

		$this->reset_content_inserter_state();
		remove_all_filters( 'the_content' );
	}

	/**
	 * Reset Content_Inserter static state using reflection.
	 */
	private function reset_content_inserter_state() {
		$reflection = new \ReflectionClass( Content_Inserter::class );
		$reflection->setStaticPropertyValue( 'the_content_has_rendered', false );
		$reflection->setStaticPropertyValue( 'post_collections', [] );
	}

	/**
	 * Test check_if_post_is_in_collection behavior.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::check_if_post_is_in_collection
	 */
	public function test_check_if_post_is_in_collection() {
		$filter_name     = 'the_content';
		$filter_function = [ Content_Inserter::class, 'maybe_insert_collection_indicators' ];

		// Non-singular page should not add filter.
		$this->go_to( home_url() );
		Content_Inserter::check_if_post_is_in_collection();
		$this->assertFalse( has_filter( $filter_name, $filter_function ), 'Filter should not be added on non-singular pages.' );

		// Reset state.
		$this->reset_content_inserter_state();

		// Singular post with no collections should not add filter.
		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );
		Content_Inserter::check_if_post_is_in_collection();
		$this->assertFalse( has_filter( $filter_name, $filter_function ), 'Filter should not be added when post is not in collections.' );

		// Reset state.
		$this->reset_content_inserter_state();

		// Singular post with collections should add filter and enqueuer data.
		$collection_id = $this->create_test_collection();

		// Get the linked term and assign post to collection.
		$term_id = Sync::get_term_linked_to_collection( $collection_id );
		wp_set_object_terms( $post_id, $term_id, Collection_Taxonomy::get_taxonomy() );

		$this->go_to( get_permalink( $post_id ) );
		Content_Inserter::check_if_post_is_in_collection();

		// Should add filter since post is in a collection.
		$this->assertNotFalse( has_filter( $filter_name, $filter_function ), 'Filter should be added when post is in collections.' );

		// Should add enqueuer data.
		$enqueuer_data = Enqueuer::get_data();
		$this->assertArrayHasKey( 'post_is_in_collections', $enqueuer_data, 'Enqueuer data should indicate post is in collections.' );
		$this->assertTrue( $enqueuer_data['post_is_in_collections'], 'Post should be marked as being in collections.' );

		// Clean up.
		$this->reset_enqueuer_data();
	}

	/**
	 * Test maybe_insert_collection_indicators early returns.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::maybe_insert_collection_indicators
	 */
	public function test_maybe_insert_collection_indicators_early_returns() {
		$content = '<p>Test content</p>';

		// Test when already rendered.
		$reflection = new \ReflectionClass( Content_Inserter::class );
		$reflection->setStaticPropertyValue( 'the_content_has_rendered', true );

		$result = Content_Inserter::maybe_insert_collection_indicators( $content );
		$this->assertEquals( $content, $result, 'Content should be unchanged when already rendered.' );

		// Reset state.
		$this->reset_content_inserter_state();

		// Test with empty content.
		$result = Content_Inserter::maybe_insert_collection_indicators( '' );
		$this->assertEquals( '', $result, 'Empty content should remain unchanged.' );
	}

	/**
	 * Test maybe_insert_collection_indicators with default style.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::maybe_insert_collection_indicators
	 * @covers \Newspack\Collections\Content_Inserter::build_default_indicator_html
	 */
	public function test_maybe_insert_collection_indicators_default_style() {
		$collection_id_1 = $this->create_test_collection( [ 'post_title' => 'Test Collection 1' ] );
		$collection_id_2 = $this->create_test_collection( [ 'post_title' => 'Test Collection 2' ] );

		// Set up collections for the inserter.
		$reflection = new \ReflectionClass( Content_Inserter::class );
		$reflection->setStaticPropertyValue( 'post_collections', [ $collection_id_1, $collection_id_2 ] );

		// Mock in_the_loop() to return true.
		global $wp_query;
		$wp_query->in_the_loop = true;

		$content = '<p>Test content paragraph.</p>';
		$result  = Content_Inserter::maybe_insert_collection_indicators( $content );

		$this->assertStringContainsString( $content, $result, 'Original content should be preserved.' );
		$this->assertStringContainsString( 'This article appears in', $result, 'Default indicator text should be present.' );
		$this->assertStringContainsString( get_the_title( $collection_id_1 ), $result, 'Collection title should be present.' );
		$this->assertStringContainsString( get_permalink( $collection_id_2 ), $result, 'Collection link should be present.' );
	}

	/**
	 * Test maybe_insert_collection_indicators with card style.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::maybe_insert_collection_indicators
	 * @covers \Newspack\Collections\Content_Inserter::build_card_html
	 */
	public function test_maybe_insert_collection_indicators_card_style() {
		$collection_title = 'Test Collection';
		$collection_id    = $this->create_test_collection( [ 'post_title' => $collection_title ] );

		// Set up collections for the inserter.
		$reflection = new \ReflectionClass( Content_Inserter::class );
		$reflection->setStaticPropertyValue( 'post_collections', [ $collection_id ] );

		// Mock settings to use card style.
		$card_message = 'Custom card message';
		add_filter(
			'pre_option_newspack_collections_settings',
			function () use ( $card_message ) {
				return [
					'post_indicator_style' => 'card',
					'card_message'         => $card_message,
				];
			}
		);

		// Mock in_the_loop() to return true.
		global $wp_query;
		$wp_query->in_the_loop = true;

		$content = '<p>First paragraph.</p><p>Second paragraph.</p><p>Third paragraph.</p>';
		$result  = Content_Inserter::maybe_insert_collection_indicators( $content );

		$this->assertStringContainsString( 'First paragraph', $result, 'First paragraph should be preserved.' );
		$this->assertStringContainsString( 'Second paragraph', $result, 'Second paragraph should be preserved.' );
		$this->assertStringContainsString( 'Browse ' . $collection_title, $result, 'Collection title should be present in card.' );
		$this->assertStringContainsString( $card_message, $result, 'Custom card message should be present.' );
		$this->assertStringContainsString( 'See more', $result, 'See more button should be present.' );

		// Clean up.
		remove_all_filters( 'pre_option_newspack_collections_settings' );
	}

	/**
	 * Test maybe_insert_collection_indicators prevents double execution.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::maybe_insert_collection_indicators
	 */
	public function test_maybe_insert_collection_indicators_prevents_double_execution() {
		$collection_id = $this->create_test_collection( [ 'post_title' => 'Test Collection' ] );

		// Set up collections for the inserter.
		$reflection = new \ReflectionClass( Content_Inserter::class );
		$reflection->setStaticPropertyValue( 'post_collections', [ $collection_id ] );

		// Mock in_the_loop() to return true.
		global $wp_query;
		$wp_query->in_the_loop = true;

		$content = '<p>Test content.</p>';

		// First call should add indicators.
		$result1 = Content_Inserter::maybe_insert_collection_indicators( $content );
		$this->assertStringContainsString( 'This article appears in', $result1, 'First call should add indicators.' );

		// Second call should not add indicators again.
		$result2 = Content_Inserter::maybe_insert_collection_indicators( $content );
		$this->assertEquals( $content, $result2, 'Second call should not modify content.' );
	}

	/**
	 * Test build_card_html respects limit parameter.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::build_card_html
	 */
	public function test_build_card_html_respects_limit() {
		$collection_id_1 = $this->create_test_collection( [ 'post_title' => 'Collection 1' ] );
		$collection_id_2 = $this->create_test_collection( [ 'post_title' => 'Collection 2' ] );

		$result = Content_Inserter::build_card_html( [ $collection_id_1, $collection_id_2 ], 1 );

		$this->assertStringContainsString( 'Collection 1', $result, 'First collection should be present.' );
		$this->assertStringNotContainsString( 'Collection 2', $result, 'Second collection should not be present due to limit.' );
	}

	/**
	 * Test insert_after_nth_block with insufficient blocks.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::insert_after_nth_block
	 */
	public function test_insert_after_nth_block_insufficient_blocks() {
		$content     = '<p>Only one paragraph.</p>';
		$insert_html = '<div>Inserted content</div>';
		$result      = Content_Inserter::insert_after_nth_block( $content, $insert_html, 3 );

		// Should append at the end since there aren't 3 blocks.
		$this->assertStringContainsString( $content, $result, 'Original content should be preserved.' );
		$this->assertStringEndsWith( $insert_html, $result, 'Insert HTML should be appended at the end.' );
	}

	/**
	 * Test insert_after_nth_block behavior with different content types.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::insert_after_nth_block
	 */
	public function test_insert_after_nth_block() {
		$insert_html = '<div>Inserted content</div>';

		// Test HTML content.
		$html_content = '<p>First paragraph.</p><p>Second paragraph.</p>';
		$html_result  = Content_Inserter::insert_after_nth_block( $html_content, $insert_html, 2 );
		$this->assertEquals( $html_content . $insert_html, $html_result, 'HTML content should append at end when insufficient blocks detected.' );

		// Test Gutenberg blocks.
		$block_content = "<!-- wp:paragraph -->\n<p>First paragraph.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second paragraph.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Third paragraph.</p>\n<!-- /wp:paragraph -->";
		$block_result  = Content_Inserter::insert_after_nth_block( $block_content, $insert_html, 2 );

		$this->assertStringContainsString( '<p>First paragraph.</p>', $block_result, 'First paragraph should be present.' );
		$this->assertStringContainsString( '<p>Second paragraph.</p>', $block_result, 'Second paragraph should be present.' );
		$this->assertStringContainsString( $insert_html, $block_result, 'Inserted content should be present.' );
		$this->assertStringContainsString( '<p>Third paragraph.</p>', $block_result, 'Third paragraph should be present.' );

		// Verify proper insertion order for Gutenberg blocks.
		$second_pos = strpos( $block_result, '<p>Second paragraph.</p>' );
		$insert_pos = strpos( $block_result, $insert_html );
		$third_pos  = strpos( $block_result, '<p>Third paragraph.</p>' );

		$this->assertGreaterThan( $second_pos, $insert_pos, 'Inserted content should come after second paragraph.' );
		$this->assertLessThan( $third_pos, $insert_pos, 'Inserted content should come before third paragraph.' );
	}

	/**
	 * Test check_if_post_is_in_collection excludes draft collections.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::check_if_post_is_in_collection
	 */
	public function test_draft_collection_excludes_indicator() {
		$filter_name     = 'the_content';
		$filter_function = [ Content_Inserter::class, 'maybe_insert_collection_indicators' ];

		// Create a draft collection and assign a post to it.
		$collection_id = $this->create_test_collection( [ 'post_status' => 'draft' ] );
		$post_id       = self::factory()->post->create();
		$term_id       = Sync::get_term_linked_to_collection( $collection_id );
		wp_set_object_terms( $post_id, $term_id, Collection_Taxonomy::get_taxonomy() );

		$this->go_to( get_permalink( $post_id ) );
		Content_Inserter::check_if_post_is_in_collection();

		$this->assertFalse( has_filter( $filter_name, $filter_function ), 'Filter should not be added when collection is a draft.' );

		// Reset state.
		$this->reset_content_inserter_state();

		// Now publish the collection and verify the filter IS added.
		wp_update_post(
			[
				'ID'          => $collection_id,
				'post_status' => 'publish',
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		Content_Inserter::check_if_post_is_in_collection();

		$this->assertNotFalse( has_filter( $filter_name, $filter_function ), 'Filter should be added when collection is published.' );

		// Clean up.
		$this->reset_enqueuer_data();
	}

	/**
	 * Test check_if_post_is_in_collection with mix of published, draft, and scheduled collections.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::check_if_post_is_in_collection
	 */
	public function test_mixed_status_collections_only_includes_published() {
		// Create one published, one draft, and one scheduled collection.
		$published_id = $this->create_test_collection(
			[
				'post_title'  => 'Published Collection',
				'post_name'   => 'published-collection',
				'post_status' => 'publish',
			]
		);
		$draft_id     = $this->create_test_collection(
			[
				'post_title'  => 'Draft Collection',
				'post_name'   => 'draft-collection',
				'post_status' => 'draft',
			]
		);
		$scheduled_id = $this->create_test_collection(
			[
				'post_title'  => 'Scheduled Collection',
				'post_name'   => 'scheduled-collection',
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '+1 week' ) ),
			]
		);

		// Create a post and assign it to all three collections.
		$post_id        = self::factory()->post->create();
		$published_term = Sync::get_term_linked_to_collection( $published_id );
		$draft_term     = Sync::get_term_linked_to_collection( $draft_id );
		$scheduled_term = Sync::get_term_linked_to_collection( $scheduled_id );
		wp_set_object_terms( $post_id, [ $published_term, $draft_term, $scheduled_term ], Collection_Taxonomy::get_taxonomy() );

		$this->go_to( get_permalink( $post_id ) );
		Content_Inserter::check_if_post_is_in_collection();

		// The stored collections should only include the published one.
		$reflection  = new \ReflectionClass( Content_Inserter::class );
		$collections = $reflection->getStaticPropertyValue( 'post_collections' );

		$this->assertContains( $published_id, $collections, 'Published collection should be included.' );
		$this->assertNotContains( $draft_id, $collections, 'Draft collection should be excluded.' );
		$this->assertNotContains( $scheduled_id, $collections, 'Scheduled collection should be excluded.' );

		// Clean up.
		$this->reset_enqueuer_data();
	}

	/**
	 * Test build_default_indicator_html with empty collections.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::build_default_indicator_html
	 */
	public function test_build_default_indicator_html_empty() {
		$result = Content_Inserter::build_default_indicator_html( [] );
		$this->assertEmpty( $result, 'Empty collections should return empty string.' );
	}

	/**
	 * Test build_default_indicator_html with valid collections.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::build_default_indicator_html
	 */
	public function test_build_default_indicator_html_with_collections() {
		$collection_title = 'Test Collection';
		$collection_id    = $this->create_test_collection( [ 'post_title' => $collection_title ] );

		$result = Content_Inserter::build_default_indicator_html( [ $collection_id ] );

		$this->assertStringContainsString( 'This article appears in', $result, 'Default indicator text should be present.' );
		$this->assertStringContainsString( $collection_title, $result, 'Collection title should be present.' );
		$this->assertStringContainsString( get_permalink( $collection_id ), $result, 'Collection permalink should be present.' );
	}

	/**
	 * Test build_default_indicator_html respects limit parameter.
	 *
	 * @covers \Newspack\Collections\Content_Inserter::build_default_indicator_html
	 */
	public function test_build_default_indicator_html_respects_limit() {
		$collection_id_1 = $this->create_test_collection( [ 'post_title' => 'Collection 1' ] );
		$collection_id_2 = $this->create_test_collection( [ 'post_title' => 'Collection 2' ] );

		$result = Content_Inserter::build_default_indicator_html( [ $collection_id_1, $collection_id_2 ], 1 );

		$this->assertStringContainsString( 'Collection 1', $result, 'First collection should be present.' );
		$this->assertStringNotContainsString( 'Collection 2', $result, 'Second collection should not be present due to limit.' );
	}
}
