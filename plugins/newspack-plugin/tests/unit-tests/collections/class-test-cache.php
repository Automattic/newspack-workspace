<?php
/**
 * Tests for the Cache class.
 *
 * @package Newspack\Tests\Unit\Collections
 */

namespace Newspack\Tests\Unit\Collections;

use Newspack\Collections\Post_Type;
use Newspack\Collections\Post_Meta;
use Newspack\Collections\Collection_Meta;
use Newspack\Collections\Collection_Taxonomy;
use Newspack\Collections\Cache;
use Newspack\Collections\Sync;

/**
 * Tests for the Cache class.
 */
class Test_Cache extends \WP_UnitTestCase {
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

		// Flush cache before each test.
		wp_cache_flush();
	}

	/**
	 * Test get_posts_cache_key generates correct key.
	 *
	 * @covers \Newspack\Collections\Cache::get_posts_cache_key
	 */
	public function test_get_posts_cache_key() {
		$collection_id = 123;
		$cache_key     = Cache::get_posts_cache_key( $collection_id );

		$this->assertIsString( $cache_key, 'Cache key should be a string.' );
		$this->assertStringStartsWith( Cache::POSTS_CACHE_KEY . $collection_id . ':', $cache_key, 'Cache key should match the expected format.' );
	}

	/**
	 * Test cache clearing for years.
	 *
	 * @covers \Newspack\Collections\Cache::clear_cache
	 */
	public function test_clear_cache_years() {
		wp_cache_set( Cache::YEARS_CACHE_KEY, [ 'test' => 'data' ], Cache::CACHE_GROUP );

		// Clear years cache.
		Cache::clear_cache( 'years' );

		$this->assertFalse( wp_cache_get( Cache::YEARS_CACHE_KEY, Cache::CACHE_GROUP ), 'Cache should be cleared.' );
	}

	/**
	 * Test cache clearing for specific collection posts.
	 *
	 * @covers \Newspack\Collections\Cache::clear_cache
	 */
	public function test_clear_cache_posts() {
		$collection_id = 123;
		$cache_key     = Cache::get_posts_cache_key( $collection_id );

		// Set some cache data.
		wp_cache_set( $cache_key, [ 'test' => 'data' ], Cache::CACHE_GROUP );

		// Clear specific collection cache.
		Cache::clear_cache( 'posts', $collection_id );

		$this->assertFalse( wp_cache_get( $cache_key, Cache::CACHE_GROUP ), "Cache for collection $collection_id should be cleared." );
	}

	/**
	 * Test cache clearing for all posts.
	 *
	 * @covers \Newspack\Collections\Cache::clear_cache
	 */
	public function test_clear_cache_all_posts() {
		$collection_id_1 = 123;
		$collection_id_2 = 456;
		$cache_key_1     = Cache::get_posts_cache_key( $collection_id_1 );
		$cache_key_2     = Cache::get_posts_cache_key( $collection_id_2 );

		// Set some cache data.
		wp_cache_set( $cache_key_1, [ 'test' => 'data' ], Cache::CACHE_GROUP );
		wp_cache_set( $cache_key_2, [ 'test' => 'data' ], Cache::CACHE_GROUP );

		// Get initial last_changed.
		$initial_last_changed = wp_cache_get_last_changed( Cache::CACHE_GROUP );

		// Clear all posts cache.
		Cache::clear_cache( 'posts' );

		// Verify last_changed was bumped.
		$new_last_changed = wp_cache_get_last_changed( Cache::CACHE_GROUP );
		$this->assertNotEquals( $initial_last_changed, $new_last_changed, 'Last changed timestamp should be bumped.' );

		// Verify cache keys are different and that new cache keys don't exist.
		$new_cache_key_1 = Cache::get_posts_cache_key( $collection_id_1 );
		$new_cache_key_2 = Cache::get_posts_cache_key( $collection_id_2 );
		$this->assertNotEquals( $cache_key_1, $new_cache_key_1, "Cache key for collection $collection_id_1 should be different." );
		$this->assertNotEquals( $cache_key_2, $new_cache_key_2, "Cache key for collection $collection_id_2 should be different." );
		$this->assertFalse( wp_cache_get( $new_cache_key_1, Cache::CACHE_GROUP ), "Cache for collection $new_cache_key_1 should not exist." );
		$this->assertFalse( wp_cache_get( $new_cache_key_2, Cache::CACHE_GROUP ), "Cache for collection $new_cache_key_2 should not exist." );
	}

	/**
	 * Test clear_cache_on_post_change with collection post.
	 *
	 * @covers \Newspack\Collections\Cache::clear_cache_on_post_change
	 */
	public function test_clear_cache_on_post_change_with_collection() {
		$collection_id = $this->create_test_collection();

		// Set some cache data.
		wp_cache_set( Cache::YEARS_CACHE_KEY, [ 'test' => 'data' ], Cache::CACHE_GROUP );
		$cache_key = Cache::get_posts_cache_key( $collection_id );
		wp_cache_set( $cache_key, [ 'test' => 'data' ], Cache::CACHE_GROUP );

		// Clear cache for collection post.
		Cache::clear_cache_on_post_change( $collection_id );

		// Verify years cache is cleared.
		$this->assertFalse( wp_cache_get( Cache::YEARS_CACHE_KEY, Cache::CACHE_GROUP ), 'Years cache should be cleared.' );

		// Verify posts cache key changed.
		$new_cache_key = Cache::get_posts_cache_key( $collection_id );
		$this->assertEquals( $cache_key, $new_cache_key, "Cache key for collection $collection_id should be the same." );
		$this->assertFalse( wp_cache_get( $new_cache_key, Cache::CACHE_GROUP ), "Cache for collection $collection_id should be cleared." );
	}

	/**
	 * Test clear_cache_on_post_change ignores non-collection posts.
	 *
	 * @covers \Newspack\Collections\Cache::clear_cache_on_post_change
	 */
	public function test_clear_cache_on_post_change_ignores_unrelated_posts() {
		$regular_post_id = self::factory()->post->create();

		$data = [ 'test' => 'data' ];
		wp_cache_set( Cache::YEARS_CACHE_KEY, $data, Cache::CACHE_GROUP );

		// Try to clear cache for regular post that's not in any collection.
		Cache::clear_cache_on_post_change( $regular_post_id );

		$this->assertEquals( $data, wp_cache_get( Cache::YEARS_CACHE_KEY, Cache::CACHE_GROUP ), 'Years cache should not be cleared for regular posts.' );
	}

	/**
	 * Test clear_cache_on_meta_change clears cache for relevant meta keys.
	 *
	 * @covers \Newspack\Collections\Cache::clear_cache_on_meta_change
	 */
	public function test_clear_cache_on_meta_change() {
		$collection_id = $this->create_test_collection();
		$data          = [ 'test' => 'data' ];

		// Set cache data.
		wp_cache_set( Cache::YEARS_CACHE_KEY, $data, Cache::CACHE_GROUP );

		// Test with relevant meta key - should clear cache.
		Cache::clear_cache_on_meta_change( 1, $collection_id, Collection_Meta::$prefix . 'test' );
		$this->assertFalse( wp_cache_get( Cache::YEARS_CACHE_KEY, Cache::CACHE_GROUP ), 'Years cache should be cleared for relevant meta.' );

		// Reset cache and test with irrelevant meta key - should not clear cache.
		wp_cache_set( Cache::YEARS_CACHE_KEY, $data, Cache::CACHE_GROUP );
		Cache::clear_cache_on_meta_change( 2, $collection_id, 'irrelevant_meta_without_prefix' );
		$this->assertEquals( $data, wp_cache_get( Cache::YEARS_CACHE_KEY, Cache::CACHE_GROUP ), 'Cache should not be cleared for irrelevant meta.' );

		// Test with regular post in collection.
		$post_id = self::factory()->post->create();
		wp_set_object_terms( $post_id, Sync::get_term_linked_to_collection( $collection_id ), Collection_Taxonomy::get_taxonomy() );
		wp_cache_set( Cache::get_posts_cache_key( $collection_id ), $data, Cache::CACHE_GROUP );
		Cache::clear_cache_on_meta_change( 3, $post_id, Post_Meta::$prefix . 'is_cover_story' );
		$this->assertEquals( $data, wp_cache_get( Cache::YEARS_CACHE_KEY, Cache::CACHE_GROUP ), 'Years cache should not be cleared.' );
		$this->assertFalse( wp_cache_get( Cache::get_posts_cache_key( $collection_id ), Cache::CACHE_GROUP ), 'Post cache should be cleared.' );
	}
}
