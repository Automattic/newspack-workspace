<?php
/**
 * Unit tests for the Collections module.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Optional_Modules\Collections
 */

namespace Newspack\Tests\Unit\Collections;

use WP_UnitTestCase;
use Newspack\Optional_Modules;
use Newspack\Optional_Modules\Collections;
use Newspack\Collections\Post_Type;
use Newspack\Collections\Collection_Taxonomy;

/**
 * Tests for the Collections module.
 */
class Test_Collections extends WP_UnitTestCase {
	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();

		// Reset the collections feature.
		Optional_Modules::deactivate_optional_module( 'collections' );
		remove_all_filters( 'newspack_collections_enabled' );
	}

	/**
	 * Test that the feature is enabled by default.
	 *
	 * @covers \Newspack\Optional_Modules\Collections::is_feature_enabled
	 */
	public function test_is_module_enabled_default_true() {
		$this->assertTrue( Collections::is_feature_enabled(), 'Module should be enabled by default.' );
	}

	/**
	 * Test that the feature can be disabled with a filter.
	 *
	 * @covers \Newspack\Optional_Modules\Collections::is_feature_enabled
	 */
	public function test_is_feature_enabled_filter_false() {
		add_filter( 'newspack_collections_enabled', '__return_false' );
		$this->assertFalse( Collections::is_feature_enabled(), 'Feature should be disabled by filter.' );
		remove_all_filters( 'newspack_collections_enabled' );
	}

	/**
	 * Test that the module is not initialized when disabled.
	 *
	 * @covers \Newspack\Optional_Modules\Collections::init
	 */
	public function test_module_disabled() {
		Collections::init();

		$this->assertFalse( has_action( 'init', [ Post_Type::class, 'register_post_type' ] ), 'Post type registration should not be hooked.' );
		$this->assertFalse( has_action( 'init', [ Collection_Taxonomy::class, 'register_taxonomy' ] ), 'Taxonomy registration should not be hooked.' );
	}

	/**
	 * Test that the module is initialized when enabled.
	 *
	 * @covers \Newspack\Optional_Modules\Collections::init
	 */
	public function test_module_initialization() {
		Optional_Modules::activate_optional_module( 'collections' );
		add_filter( 'newspack_collections_enabled', '__return_true' );

		Collections::init();

		$this->assertGreaterThan( 0, has_action( 'init', [ Post_Type::class, 'register_post_type' ] ), 'Post type registration should be hooked.' );
		$this->assertGreaterThan( 0, has_action( 'init', [ Collection_Taxonomy::class, 'register_taxonomy' ] ), 'Taxonomy registration should be hooked.' );
	}
}
