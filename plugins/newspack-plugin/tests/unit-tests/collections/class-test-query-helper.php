<?php
/**
 * Tests for the Query_Helper class.
 *
 * @package Newspack\Tests\Unit\Collections
 */

namespace Newspack\Tests\Unit\Collections;

use Newspack\Collections\Post_Type;
use Newspack\Collections\Collection_Meta;
use Newspack\Collections\Collection_Taxonomy;
use Newspack\Collections\Collection_Category_Taxonomy;
use Newspack\Collections\Collection_Section_Taxonomy;
use Newspack\Collections\Post_Meta;
use Newspack\Collections\Query_Helper;
use Newspack\Collections\Sync;

/**
 * Tests for the Query_Helper class.
 */
class Test_Query_Helper extends \WP_UnitTestCase {
	use Traits\Trait_Collections_Test;

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();

		Post_Type::init();
		Collection_Meta::register_meta();
		Post_Meta::register_meta();
		Collection_Taxonomy::register_taxonomy();
		Collection_Category_Taxonomy::register_taxonomy();
		Collection_Category_Taxonomy::register_meta();
		Collection_Section_Taxonomy::register_taxonomy();
		Collection_Section_Taxonomy::register_meta();
	}

	/**
	 * Test get_available_years with no collections.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_available_years
	 */
	public function test_get_available_years_empty() {
		$years = Query_Helper::get_available_years();

		$this->assertIsArray( $years, 'Years should be an array.' );
		$this->assertEmpty( $years, 'Years should be empty.' );
	}

	/**
	 * Test get_available_years with collections but no categories.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_available_years
	 */
	public function test_get_available_years_no_categories() {
		// Create collections from different years.
		$this->create_test_collection(
			[
				'post_title' => 'Collection 2023',
				'post_date'  => '2023-06-15 12:00:00',
			]
		);
		$this->create_test_collection(
			[
				'post_title' => 'Collection 2024',
				'post_date'  => '2024-03-20 10:00:00',
			]
		);

		$years = Query_Helper::get_available_years();

		$this->assertIsArray( $years, 'Years should be an array.' );
		$this->assertContains( 2023, $years, '2023 should be in years array.' );
		$this->assertContains( 2024, $years, '2024 should be in years array.' );

		// Years should be sorted in descending order.
		$this->assertEquals( [ 2024, 2023 ], array_values( $years ), 'Years should be sorted in descending order.' );
	}

	/**
	 * Test get_available_years with multiple categories.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_available_years
	 */
	public function test_get_available_years_with_categories() {
		// Create test categories.
		$this->set_current_user_role( 'administrator' );
		$sports_category = wp_insert_term( 'Sports', Collection_Category_Taxonomy::get_taxonomy() );
		$news_category   = wp_insert_term( 'News', Collection_Category_Taxonomy::get_taxonomy() );
		$this->assertNotWPError( $sports_category, 'Sports category should be created.' );
		$this->assertNotWPError( $news_category, 'News category should be created.' );

		// Create collections in different years for different categories.
		$sports_collection_2022 = $this->create_test_collection(
			[
				'post_title' => 'Sports Collection 2022',
				'post_date'  => '2022-05-15 10:00:00',
			]
		);
		$sports_collection_2023 = $this->create_test_collection(
			[
				'post_title' => 'Sports Collection 2023',
				'post_date'  => '2023-08-20 14:00:00',
			]
		);
		$news_collection_2023   = $this->create_test_collection(
			[
				'post_title' => 'News Collection 2023',
				'post_date'  => '2023-12-01 12:00:00',
			]
		);
		$news_collection_2024   = $this->create_test_collection(
			[
				'post_title' => 'News Collection 2024',
				'post_date'  => '2024-01-15 09:00:00',
			]
		);

		// Assign collections to categories.
		wp_set_object_terms( $sports_collection_2022, $sports_category['term_id'], Collection_Category_Taxonomy::get_taxonomy() );
		wp_set_object_terms( $sports_collection_2023, $sports_category['term_id'], Collection_Category_Taxonomy::get_taxonomy() );
		wp_set_object_terms( $news_collection_2023, $news_category['term_id'], Collection_Category_Taxonomy::get_taxonomy() );
		wp_set_object_terms( $news_collection_2024, $news_category['term_id'], Collection_Category_Taxonomy::get_taxonomy() );

		// Test sports category years.
		$sports_term  = get_term( $sports_category['term_id'] );
		$sports_years = Query_Helper::get_available_years( $sports_term->slug );

		$this->assertIsArray( $sports_years, 'Sports years should be an array.' );
		$this->assertContains( 2022, $sports_years, '2022 should be in sports years.' );
		$this->assertContains( 2023, $sports_years, '2023 should be in sports years.' );
		$this->assertNotContains( 2024, $sports_years, '2024 should not be in sports years.' );

		// Test news category years.
		$news_term  = get_term( $news_category['term_id'] );
		$news_years = Query_Helper::get_available_years( $news_term->slug );

		$this->assertIsArray( $news_years, 'News years should be an array.' );
		$this->assertContains( 2023, $news_years, '2023 should be in news years.' );
		$this->assertContains( 2024, $news_years, '2024 should be in news years.' );
		$this->assertNotContains( 2022, $news_years, '2022 should not be in news years.' );

		// Test all years (no category filter).
		$all_years = Query_Helper::get_available_years();

		$this->assertIsArray( $all_years, 'All years should be an array.' );
		$this->assertContains( 2022, $all_years, '2022 should be in all years.' );
		$this->assertContains( 2023, $all_years, '2023 should be in all years.' );
		$this->assertContains( 2024, $all_years, '2024 should be in all years.' );

		// Should be sorted in descending order.
		$this->assertEquals( [ 2024, 2023, 2022 ], array_values( $all_years ), 'All years should be sorted in descending order.' );
	}

	/**
	 * Test get_collection_categories with no collections or categories.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collection_categories
	 */
	public function test_get_collection_categories_empty() {
		$categories = Query_Helper::get_collection_categories();

		$this->assertIsArray( $categories, 'Categories should be an array.' );
		$this->assertEmpty( $categories, 'Categories should be empty.' );
	}

	/**
	 * Test get_collection_categories with a single category.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collection_categories
	 */
	public function test_get_collection_categories() {
		$this->set_current_user_role( 'administrator' );
		$term_data = wp_insert_term( 'Test Category', Collection_Category_Taxonomy::get_taxonomy() );
		$this->assertNotWPError( $term_data, 'Test category should be created.' );

		// Create a test collection and assign it to the category.
		// Otherwise, the method will not return the category as it's hiding empty terms ('hide_empty' => true).
		$collection_id = $this->create_test_collection();
		wp_set_object_terms( $collection_id, $term_data['term_id'], Collection_Category_Taxonomy::get_taxonomy() );

		$categories = Query_Helper::get_collection_categories();

		$this->assertIsArray( $categories, 'Categories should be an array.' );
		$this->assertCount( 1, $categories, 'There should be one category.' );
		$this->assertInstanceOf( \WP_Term::class, $categories[0], 'The first category should be an instance of WP_Term.' );
		$this->assertEquals( 'Test Category', $categories[0]->name, 'The first category should be named "Test Category".' );
	}

	/**
	 * Test get_ctas with no CTAs.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_ctas
	 */
	public function test_get_ctas_empty() {
		$collection_id = $this->create_test_collection();

		$ctas = Query_Helper::get_ctas( $collection_id );

		$this->assertIsArray( $ctas, 'CTAs should be an array.' );
		$this->assertEmpty( $ctas, 'CTAs should be empty.' );
	}

	/**
	 * Test get_ctas with limit.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_ctas
	 */
	public function test_get_ctas_with_limit() {
		$collection_id = $this->create_test_collection();

		// Mock CTAs data.
		$ctas_data = [
			[
				'type'  => 'link',
				'label' => 'CTA 1',
				'url'   => 'https://example.com/1',
			],
			[
				'type'  => 'link',
				'label' => 'CTA 2',
				'url'   => 'https://example.com/2',
			],
			[
				'type'  => 'link',
				'label' => 'CTA 3',
				'url'   => 'https://example.com/3',
			],
		];

		Collection_Meta::set( $collection_id, 'ctas', $ctas_data );

		$ctas = Query_Helper::get_ctas( $collection_id, 2 );

		$this->assertIsArray( $ctas, 'CTAs should be an array.' );
		$this->assertCount( 2, $ctas, 'There should be two CTAs.' );
		$this->assertEquals( $ctas_data[0]['label'], $ctas[0]['label'], 'The first CTA should be labeled ' . $ctas_data[0]['label'] );
		$this->assertEquals( $ctas_data[1]['label'], $ctas[1]['label'], 'The second CTA should be labeled ' . $ctas_data[1]['label'] );
	}

	/**
	 * Test get_collection_posts with no posts.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collection_posts
	 */
	public function test_get_collection_posts_empty() {
		$collection_id = $this->create_test_collection();

		$collection_posts = Query_Helper::get_collection_posts( $collection_id );

		$this->assertIsArray( $collection_posts, 'Collection posts should be an array.' );
		$this->assertEmpty( $collection_posts, 'Collection posts should be empty.' );
	}

	/**
	 * Test get_collection_posts basic functionality.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collection_posts
	 */
	public function test_get_collection_posts() {
		$collection_id = $this->create_test_collection();
		$term_id       = Sync::get_term_linked_to_collection( $collection_id );

		// Create posts and assign them to the collection.
		$post_id_1 = self::factory()->post->create( [ 'post_title' => 'Post 1' ] );
		$post_id_2 = self::factory()->post->create( [ 'post_title' => 'Post 2' ] );
		wp_set_object_terms( $post_id_1, $term_id, Collection_Taxonomy::get_taxonomy() );
		wp_set_object_terms( $post_id_2, $term_id, Collection_Taxonomy::get_taxonomy() );

		$collection_posts = Query_Helper::get_collection_posts( $collection_id );

		$this->assertIsArray( $collection_posts, 'Collection posts should be an array.' );
		$this->assertArrayHasKey( '', $collection_posts, 'Posts without sections should be in the array.' );
		$this->assertContains( $post_id_1, $collection_posts[''], 'Post 1 should be in the array.' );
		$this->assertContains( $post_id_2, $collection_posts[''], 'Post 2 should be in the array.' );
	}

	/**
	 * Test get_collection_posts with cover stories and sections.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collection_posts
	 */
	public function test_get_collection_posts_with_sections_and_cover() {
		$collection_id = $this->create_test_collection();
		$term_id       = Sync::get_term_linked_to_collection( $collection_id );

		// Create section terms.
		$this->set_current_user_role( 'administrator' );
		$sports_section = wp_insert_term(
			'Sports',
			Collection_Section_Taxonomy::get_taxonomy(),
			[
				'slug' => 'sports',
			] 
		);
		$news_section   = wp_insert_term(
			'News',
			Collection_Section_Taxonomy::get_taxonomy(),
			[
				'slug' => 'news',
			] 
		);
		$this->assertNotWPError( $sports_section, 'Sports section should be created.' );
		$this->assertNotWPError( $news_section, 'News section should be created.' );

		// Create posts with different classifications.
		$cover_post      = self::factory()->post->create( [ 'post_title' => 'Cover Story' ] );
		$sports_post     = self::factory()->post->create( [ 'post_title' => 'Sports Post' ] );
		$news_post       = self::factory()->post->create( [ 'post_title' => 'News Post' ] );
		$no_section_post = self::factory()->post->create( [ 'post_title' => 'No Section Post' ] );

		// Assign posts to collection.
		wp_set_object_terms( $cover_post, $term_id, Collection_Taxonomy::get_taxonomy() );
		wp_set_object_terms( $sports_post, $term_id, Collection_Taxonomy::get_taxonomy() );
		wp_set_object_terms( $news_post, $term_id, Collection_Taxonomy::get_taxonomy() );
		wp_set_object_terms( $no_section_post, $term_id, Collection_Taxonomy::get_taxonomy() );

		// Assign sections to posts.
		wp_set_object_terms( $sports_post, $sports_section['term_id'], Collection_Section_Taxonomy::get_taxonomy() );
		wp_set_object_terms( $news_post, $news_section['term_id'], Collection_Section_Taxonomy::get_taxonomy() );

		// Mark cover post as cover story.
		Post_Meta::set( $cover_post, 'is_cover_story', true );

		$collection_posts = Query_Helper::get_collection_posts( $collection_id );

		$this->assertIsArray( $collection_posts, 'Collection posts should be an array.' );

		// Test cover section exists and contains cover post.
		$this->assertArrayHasKey( 'cover', $collection_posts, 'Cover section should be in the array.' );
		$this->assertContains( $cover_post, $collection_posts['cover'], 'Cover post should be in the cover section.' );

		// Test sports section exists and contains sports post.
		$this->assertArrayHasKey( 'sports', $collection_posts, 'Sports section should be in the array.' );
		$this->assertContains( $sports_post, $collection_posts['sports'], 'Sports post should be in the sports section.' );

		// Test news section exists and contains news post.
		$this->assertArrayHasKey( 'news', $collection_posts, 'News section should be in the array.' );
		$this->assertContains( $news_post, $collection_posts['news'], 'News post should be in the news section.' );

		// Test no-section posts are in empty key.
		$this->assertArrayHasKey( '', $collection_posts, 'Posts without sections should be in the array.' );
		$this->assertContains( $no_section_post, $collection_posts[''], 'No section post should be in the array.' );

		// Verify cover post is not in any other section.
		$this->assertNotContains( $cover_post, $collection_posts[''], 'Cover post should not be in the array.' );
		$this->assertNotContains( $cover_post, $collection_posts['sports'], 'Cover post should not be in the sports section.' );
		$this->assertNotContains( $cover_post, $collection_posts['news'], 'Cover post should not be in the news section.' );
	}

	/**
	 * Test get_post_collections with no collections.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_post_collections
	 */
	public function test_get_post_collections_empty() {
		$post_id = self::factory()->post->create();

		$result = Query_Helper::get_post_collections( $post_id );

		$this->assertIsArray( $result, 'Result should be an array.' );
		$this->assertEmpty( $result, 'Result should be empty.' );
	}

	/**
	 * Test get_post_collections with IDs.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_post_collections
	 */
	public function test_get_post_collections_with_ids() {
		$collection_id = $this->create_test_collection();
		$post_id       = self::factory()->post->create();

		// Get the linked term.
		$term_id = Sync::get_term_linked_to_collection( $collection_id );

		// Assign post to collection.
		wp_set_object_terms( $post_id, $term_id, Collection_Taxonomy::get_taxonomy() );

		$collection_ids = Query_Helper::get_post_collections( $post_id );

		$this->assertIsArray( $collection_ids, 'Collection IDs should be an array.' );
		$this->assertCount( 1, $collection_ids, 'There should be one collection ID.' );
		$this->assertIsInt( $collection_ids[0], 'The first collection ID should be an integer.' );
		$this->assertEquals( $collection_id, $collection_ids[0], 'The first collection ID should be ' . $collection_id );
	}

	/**
	 * Test get_post_collections with posts.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_post_collections
	 */
	public function test_get_post_collections_with_posts() {
		$collection_id = $this->create_test_collection();
		$post_id       = self::factory()->post->create();

		// Get the linked term.
		$term_id = Sync::get_term_linked_to_collection( $collection_id );

		// Assign post to collection.
		wp_set_object_terms( $post_id, $term_id, Collection_Taxonomy::get_taxonomy() );

		$collection_posts = Query_Helper::get_post_collections( $post_id, true );

		$this->assertIsArray( $collection_posts, 'Collection posts should be an array.' );
		$this->assertCount( 1, $collection_posts, 'There should be one collection post.' );
		$this->assertInstanceOf( \WP_Post::class, $collection_posts[0], 'The first collection post should be an instance of WP_Post.' );
		$this->assertEquals( $collection_id, $collection_posts[0]->ID, 'The first collection post should have the collection ID ' . $collection_id );
	}

	/**
	 * Test get_post_collections with single parameter.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_post_collections
	 */
	public function test_get_post_collections_single_result() {
		$collection_id_1 = $this->create_test_collection( [ 'post_title' => 'Collection 1' ] );
		$collection_id_2 = $this->create_test_collection( [ 'post_title' => 'Collection 2' ] );
		$post_id         = self::factory()->post->create();

		// Get the linked terms.
		$term_id_1 = Sync::get_term_linked_to_collection( $collection_id_1 );
		$term_id_2 = Sync::get_term_linked_to_collection( $collection_id_2 );

		// Assign post to both collections.
		wp_set_object_terms( $post_id, [ $term_id_1, $term_id_2 ], Collection_Taxonomy::get_taxonomy() );

		// Test single = true returns only one result.
		$collection_terms = Query_Helper::get_post_collections( $post_id, true, true );

		$this->assertIsArray( $collection_terms, 'Collection terms should be an array.' );
		$this->assertCount( 1, $collection_terms, 'There should be one collection term.' );
		$this->assertInstanceOf( \WP_Post::class, $collection_terms[0], 'The first collection term should be an instance of WP_Post.' );
	}

	/**
	 * Test get_post_collections filter.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_post_collections
	 */
	public function test_get_post_collections_filter() {
		$collection_id = $this->create_test_collection();
		$post_id       = self::factory()->post->create();

		// Get the linked term.
		$term_id = Sync::get_term_linked_to_collection( $collection_id );

		// Assign post to collection.
		wp_set_object_terms( $post_id, $term_id, Collection_Taxonomy::get_taxonomy() );

		// Add filter to modify result.
		$filter_called = false;
		add_filter(
			'newspack_collections_post_collections',
			function ( $result, $test_post_id, $return_posts, $single ) use ( $post_id, &$filter_called ) {
				$filter_called = true;
				$this->assertEquals( $post_id, $test_post_id, 'Test post ID should be ' . $post_id );
				$this->assertFalse( $return_posts, 'Return posts should be false.' );
				$this->assertFalse( $single, 'Single should be false.' );
				return [];
			},
			10,
			4
		);

		$collection_posts = Query_Helper::get_post_collections( $post_id );

		$this->assertTrue( $filter_called, 'Filter should be called.' );
		$this->assertEmpty( $collection_posts, 'Collection posts should be empty.' );

		// Clean up.
		remove_all_filters( 'newspack_collections_post_collections' );
	}

	/**
	 * Test get_section_name with empty slug.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_section_name
	 */
	public function test_get_section_name_empty() {
		$section_name = Query_Helper::get_section_name( '' );

		$this->assertIsString( $section_name, 'Section name should be a string.' );
		$this->assertEquals( '', $section_name, 'Section name should be empty.' );
	}

	/**
	 * Test get_section_name with non-existent section (fallback to slug).
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_section_name
	 */
	public function test_get_section_name_nonexistent() {
		$section_name = Query_Helper::get_section_name( 'nonexistent-section' );

		$this->assertIsString( $section_name, 'Section name should be a string.' );
		$this->assertEquals( 'nonexistent-section', $section_name, 'Section name should be "nonexistent-section".' );
	}

	/**
	 * Test get_section_name with valid section term.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_section_name
	 */
	public function test_get_section_name_with_term() {
		$this->set_current_user_role( 'administrator' );
		$section_term = wp_insert_term(
			'Test Section',
			Collection_Section_Taxonomy::get_taxonomy(),
			[
				'slug' => 'test-section',
			]
		);
		$this->assertNotWPError( $section_term, 'Section term should be created.' );

		// Test that the method returns the term name, not the slug.
		$section_name = Query_Helper::get_section_name( 'test-section' );

		$this->assertIsString( $section_name, 'Section name should be a string.' );
		$this->assertEquals( 'Test Section', $section_name, 'Section name should be "Test Section".' );
	}

	/**
	 * Test get_recent with no collections.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_recent
	 */
	public function test_get_recent_empty() {
		$recent = Query_Helper::get_recent();

		$this->assertIsArray( $recent, 'Recent should be an array.' );
		$this->assertEmpty( $recent, 'Recent should be empty.' );
	}

	/**
	 * Test get_recent collections.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_recent
	 */
	public function test_get_recent() {
		// Create multiple collections.
		$this->create_test_collection( [ 'post_title' => 'Collection 1' ] );
		$this->create_test_collection( [ 'post_title' => 'Collection 2' ] );
		$this->create_test_collection( [ 'post_title' => 'Collection 3' ] );

		$recent = Query_Helper::get_recent( [], 2 );

		$this->assertIsArray( $recent, 'Recent should be an array.' );
		$this->assertCount( 2, $recent, 'There should be two recent posts.' );
		$this->assertInstanceOf( \WP_Post::class, $recent[0], 'The first recent post should be an instance of WP_Post.' );
		$this->assertEquals( Post_Type::get_post_type(), $recent[0]->post_type, 'The first recent post should be a ' . Post_Type::get_post_type() . ' post.' );
	}

	/**
	 * Test get_recent with exclusions.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_recent
	 */
	public function test_get_recent_with_exclusions() {
		// Create multiple collections.
		$collection_1 = $this->create_test_collection( [ 'post_title' => 'Collection 1' ] );
		$this->create_test_collection( [ 'post_title' => 'Collection 2' ] );
		$this->create_test_collection( [ 'post_title' => 'Collection 3' ] );

		$recent = Query_Helper::get_recent( [ $collection_1 ], 2 );

		$this->assertIsArray( $recent, 'Recent should be an array.' );
		$this->assertCount( 2, $recent, 'There should be two recent posts.' );

		// Should not contain the excluded collection.
		$recent_ids = wp_list_pluck( $recent, 'ID' );
		$this->assertNotContains( $collection_1, $recent_ids, 'The excluded collection should not be in the recent posts.' );
	}

	/**
	 * Test get_collections_by_attributes with default attributes (recent).
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collections_by_attributes
	 */
	public function test_get_collections_by_attributes_recent() {
		// Create test collections.
		$collection_1 = $this->create_test_collection( [ 'post_title' => 'Collection 1' ] );
		$collection_2 = $this->create_test_collection( [ 'post_title' => 'Collection 2' ] );

		$attributes = [
			'queryType'     => 'recent',
			'numberOfItems' => 2,
			'offset'        => 0,
		];

		$collections = Query_Helper::get_collections_by_attributes( $attributes );

		$this->assertIsArray( $collections, 'Collections should be an array.' );
		$this->assertCount( 2, $collections, 'Should return 2 collections.' );
		$this->assertInstanceOf( \WP_Post::class, $collections[0], 'First item should be WP_Post.' );
		$this->assertEquals( Post_Type::get_post_type(), $collections[0]->post_type, 'Should be collection post type.' );
	}

	/**
	 * Test get_collections_by_attributes with specific collections.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collections_by_attributes
	 */
	public function test_get_collections_by_attributes_specific() {
		// Create test collections.
		$collection_1 = $this->create_test_collection( [ 'post_title' => 'Collection 1' ] );
		$collection_2 = $this->create_test_collection( [ 'post_title' => 'Collection 2' ] );
		$collection_3 = $this->create_test_collection( [ 'post_title' => 'Collection 3' ] );

		$attributes = [
			'queryType'           => 'specific',
			'numberOfItems'       => 3,
			'selectedCollections' => [ $collection_2, $collection_1 ], // Test ordering.
			'offset'              => 0,
		];

		$collections = Query_Helper::get_collections_by_attributes( $attributes );

		$this->assertIsArray( $collections, 'Collections should be an array.' );
		$this->assertCount( 2, $collections, 'Should return 2 collections.' );
		
		// Should maintain the order from selectedCollections.
		$this->assertEquals( $collection_2, $collections[0]->ID, 'First should be collection 2.' );
		$this->assertEquals( $collection_1, $collections[1]->ID, 'Second should be collection 1.' );
	}

	/**
	 * Test get_collections_by_attributes with offset.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collections_by_attributes
	 */
	public function test_get_collections_by_attributes_with_offset() {
		// Create test collections with specific dates to ensure order.
		$collection_1 = $this->create_test_collection(
			[
				'post_title' => 'Collection 1',
				'post_date'  => '2024-01-03 12:00:00',
			] 
		);
		$collection_2 = $this->create_test_collection(
			[
				'post_title' => 'Collection 2',
				'post_date'  => '2024-01-02 12:00:00',
			] 
		);
		$collection_3 = $this->create_test_collection(
			[
				'post_title' => 'Collection 3',
				'post_date'  => '2024-01-01 12:00:00',
			] 
		);

		$attributes = [
			'queryType'     => 'recent',
			'numberOfItems' => 1,
			'offset'        => 1, // Skip first collection.
		];

		$collections = Query_Helper::get_collections_by_attributes( $attributes );

		$this->assertIsArray( $collections, 'Collections should be an array.' );
		$this->assertCount( 1, $collections, 'Should return 1 collection.' );
		$this->assertEquals( $collection_2, $collections[0]->ID, 'Should return second collection due to offset.' );
	}

	/**
	 * Test get_collections_by_attributes with category filtering (include).
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collections_by_attributes
	 */
	public function test_get_collections_by_attributes_include_categories() {
		$this->set_current_user_role( 'administrator' );
		
		// Create categories.
		$sports_category = wp_insert_term( 'Sports', Collection_Category_Taxonomy::get_taxonomy() );
		$news_category   = wp_insert_term( 'News', Collection_Category_Taxonomy::get_taxonomy() );
		$this->assertNotWPError( $sports_category );
		$this->assertNotWPError( $news_category );

		// Create collections.
		$sports_collection  = $this->create_test_collection( [ 'post_title' => 'Sports Collection' ] );
		$news_collection    = $this->create_test_collection( [ 'post_title' => 'News Collection' ] );
		$general_collection = $this->create_test_collection( [ 'post_title' => 'General Collection' ] );

		// Assign categories.
		wp_set_object_terms( $sports_collection, $sports_category['term_id'], Collection_Category_Taxonomy::get_taxonomy() );
		wp_set_object_terms( $news_collection, $news_category['term_id'], Collection_Category_Taxonomy::get_taxonomy() );

		$attributes = [
			'queryType'         => 'recent',
			'numberOfItems'     => 10,
			'includeCategories' => [ $sports_category['term_id'] ],
			'offset'            => 0,
		];

		$collections = Query_Helper::get_collections_by_attributes( $attributes );

		$this->assertIsArray( $collections, 'Collections should be an array.' );
		$this->assertCount( 1, $collections, 'Should return 1 sports collection.' );
		$this->assertEquals( $sports_collection, $collections[0]->ID, 'Should return sports collection.' );
	}

	/**
	 * Test get_collections_by_attributes with category filtering (exclude).
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collections_by_attributes
	 */
	public function test_get_collections_by_attributes_exclude_categories() {
		$this->set_current_user_role( 'administrator' );
		
		// Create category.
		$sports_category = wp_insert_term( 'Sports', Collection_Category_Taxonomy::get_taxonomy() );
		$this->assertNotWPError( $sports_category );

		// Create collections.
		$sports_collection  = $this->create_test_collection( [ 'post_title' => 'Sports Collection' ] );
		$general_collection = $this->create_test_collection( [ 'post_title' => 'General Collection' ] );

		// Assign category to sports collection.
		wp_set_object_terms( $sports_collection, $sports_category['term_id'], Collection_Category_Taxonomy::get_taxonomy() );

		$attributes = [
			'queryType'         => 'recent',
			'numberOfItems'     => 10,
			'excludeCategories' => [ $sports_category['term_id'] ],
			'offset'            => 0,
		];

		$collections = Query_Helper::get_collections_by_attributes( $attributes );

		$this->assertIsArray( $collections, 'Collections should be an array.' );
		$this->assertCount( 1, $collections, 'Should return 1 general collection.' );
		$this->assertEquals( $general_collection, $collections[0]->ID, 'Should return general collection.' );
	}

	/**
	 * Test get_collections_by_attributes with both include and exclude categories.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collections_by_attributes
	 */
	public function test_get_collections_by_attributes_include_and_exclude_categories() {
		$this->set_current_user_role( 'administrator' );
		
		// Create categories.
		$sports_category   = wp_insert_term( 'Sports', Collection_Category_Taxonomy::get_taxonomy() );
		$news_category     = wp_insert_term( 'News', Collection_Category_Taxonomy::get_taxonomy() );
		$featured_category = wp_insert_term( 'Featured', Collection_Category_Taxonomy::get_taxonomy() );
		$this->assertNotWPError( $sports_category );
		$this->assertNotWPError( $news_category );
		$this->assertNotWPError( $featured_category );

		// Create collections.
		$sports_collection          = $this->create_test_collection( [ 'post_title' => 'Sports Collection' ] );
		$news_collection            = $this->create_test_collection( [ 'post_title' => 'News Collection' ] );
		$featured_sports_collection = $this->create_test_collection( [ 'post_title' => 'Featured Sports Collection' ] );

		// Assign categories.
		wp_set_object_terms( $sports_collection, $sports_category['term_id'], Collection_Category_Taxonomy::get_taxonomy() );
		wp_set_object_terms( $news_collection, $news_category['term_id'], Collection_Category_Taxonomy::get_taxonomy() );
		wp_set_object_terms( $featured_sports_collection, [ $sports_category['term_id'], $featured_category['term_id'] ], Collection_Category_Taxonomy::get_taxonomy() );

		$attributes = [
			'queryType'         => 'recent',
			'numberOfItems'     => 10,
			'includeCategories' => [ $sports_category['term_id'] ], // Include sports.
			'excludeCategories' => [ $featured_category['term_id'] ], // Exclude featured.
			'offset'            => 0,
		];

		$collections = Query_Helper::get_collections_by_attributes( $attributes );

		$this->assertIsArray( $collections, 'Collections should be an array.' );
		$this->assertCount( 1, $collections, 'Should return 1 sports (non-featured) collection.' );
		$this->assertEquals( $sports_collection, $collections[0]->ID, 'Should return sports collection only.' );
	}

	/**
	 * Test get_collections_by_attributes filters.
	 *
	 * @covers \Newspack\Collections\Query_Helper::get_collections_by_attributes
	 */
	public function test_get_collections_by_attributes_filters() {
		$this->create_test_collection( [ 'post_title' => 'Collection 1' ] );

		$attributes = [
			'queryType'     => 'recent',
			'numberOfItems' => 1,
			'offset'        => 0,
		];

		// Test query args filter.
		$query_args_filter_called = false;
		add_filter(
			'newspack_collections_query_args',
			function ( $query_args ) use ( $attributes, &$query_args_filter_called ) {
				$query_args_filter_called = true;
				$this->assertEquals( $attributes['numberOfItems'], $query_args['posts_per_page'], 'Filter should receive correct args.' );
				return $query_args;
			},
			10,
			2
		);

		// Test posts filter.
		$posts_filter_called = false;
		add_filter(
			'newspack_collections_query_posts',
			function ( $posts, $query_args ) use ( $attributes, &$posts_filter_called ) {
				$posts_filter_called = true;
				$this->assertEquals( $attributes['numberOfItems'], $query_args['posts_per_page'], 'Posts filter should receive correct args.' );
				return $posts;
			},
			10,
			3
		);

		$collections = Query_Helper::get_collections_by_attributes( $attributes );

		$this->assertTrue( $query_args_filter_called, 'Query args filter should be called.' );
		$this->assertTrue( $posts_filter_called, 'Posts filter should be called.' );

		// Clean up.
		remove_all_filters( 'newspack_collections_query_args' );
		remove_all_filters( 'newspack_collections_query_posts' );
	}
}
