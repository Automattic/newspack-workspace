<?php
/**
 * Tests for the Collections_Block class.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Blocks\Collections\Collections_Block
 */

namespace Newspack\Tests\Unit\Collections;

use Newspack\Blocks\Collections\Collections_Block;
use Newspack\Collections\Post_Type;
use Newspack\Collections\Collection_Meta;
use Newspack\Collections\Collection_Category_Taxonomy;

/**
 * Tests for the Collections_Block class.
 */
class Test_Collections_Block extends \WP_UnitTestCase {
	use Traits\Trait_Collections_Test;

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();

		Post_Type::register_post_type();
		Collection_Meta::register_meta();
		Collection_Category_Taxonomy::register_taxonomy();

		// Ensure the block is registered.
		require_once NEWSPACK_ABSPATH . 'src/blocks/collections/index.php';

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( Collections_Block::BLOCK_NAME ) ) {
			Collections_Block::register_block();
		}
	}

	/**
	 * Tear down the test environment.
	 */
	public function tear_down() {
		// Clean up registered blocks.
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( Collections_Block::BLOCK_NAME ) ) {
			unregister_block_type( Collections_Block::BLOCK_NAME );
		}

		parent::tear_down();
	}

	/**
	 * Helper method to render block with proper WordPress context.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	private function render_collections_block( $attributes = [] ) {
		// Set block context for get_block_wrapper_attributes to work.
		\WP_Block_Supports::$block_to_render = [
			'blockName' => Collections_Block::BLOCK_NAME,
			'attrs'     => $attributes,
		];

		$output = Collections_Block::render_block( $attributes );

		// Clean up block context.
		\WP_Block_Supports::$block_to_render = null;

		return $output;
	}

	/**
	 * Test block registration.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::register_block
	 */
	public function test_register_block() {
		$registered_blocks = \WP_Block_Type_Registry::get_instance()->get_all_registered();

		$this->assertArrayHasKey( Collections_Block::BLOCK_NAME, $registered_blocks, 'Collections block should be registered' );
		$this->assertInstanceOf( \WP_Block_Type::class, $registered_blocks[ Collections_Block::BLOCK_NAME ], 'Should be a WP_Block_Type instance' );
		$this->assertEquals( [ Collections_Block::class, 'render_block' ], $registered_blocks[ Collections_Block::BLOCK_NAME ]->render_callback, 'Should have correct render callback' );
	}

	/**
	 * Test render_block with no collections.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_block
	 */
	public function test_render_block_no_collections() {
		$output = $this->render_collections_block( [] );

		$this->assertStringContainsString( 'wp-block-newspack-collections', $output, 'Should contain block wrapper class' );
		$this->assertStringContainsString( 'No collections found.', $output, 'Should show no collections message' );
	}

	/**
	 * Test render_block with collections.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_block
	 */
	public function test_render_block_with_collections() {
		$post_title = 'Test Collection';
		$this->create_test_collection( [ 'post_title' => $post_title ] );

		$attributes = [
			'numberOfItems' => 1,
		];

		$output = $this->render_collections_block( $attributes );

		$this->assertStringContainsString( 'wp-block-newspack-collections', $output, 'Should contain block wrapper class' );
		$this->assertStringContainsString( $post_title, $output, 'Should contain collection title' );
		$this->assertStringNotContainsString( 'No collections found.', $output, 'Should not show no collections message' );
	}

	/**
	 * Test numberOfCTAs attribute handles -1 correctly for showing all CTAs.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_block
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_collection_ctas
	 * @covers \Newspack\Collections\Template_Helper::render_cta
	 */
	public function test_render_block_with_all_ctas() {
		$collection_id = $this->create_test_collection();

		// Create multiple CTAs using a loop.
		$ctas_data  = [];
		$total_ctas = 5;
		for ( $i = 1; $i <= $total_ctas; $i++ ) {
			$ctas_data[] = [
				'type'  => 'link',
				'label' => "CTA $i",
				'url'   => "https://example.com/$i",
			];
		}
		Collection_Meta::set( $collection_id, 'ctas', $ctas_data );

		$attributes = [
			'selectedCollections' => [ $collection_id ],
			'numberOfCTAs'        => -1,
			'showCTAs'            => true,
		];

		$output = $this->render_collections_block( $attributes );

		// When numberOfCTAs is -1, all CTAs should be displayed.
		for ( $i = 1; $i <= $total_ctas; $i++ ) {
			$this->assertStringContainsString( "CTA $i", $output );
		}

		// Count CTA elements to verify total count.
		$cta_count = substr_count( $output, 'wp-block-button__link' );
		$this->assertEquals( $total_ctas, $cta_count, "Should render all $total_ctas CTAs when numberOfCTAs is -1" );
	}

	/**
	 * Test get_block_classes method.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::get_block_classes
	 */
	public function test_get_block_classes() {
		// Test grid layout classes.
		$attributes = [
			'layout'         => 'grid',
			'columns'        => 3,
			'imageAlignment' => 'top',
		];
		$classes    = Collections_Block::get_block_classes( $attributes );

		$this->assertStringContainsString( 'wp-block-newspack-collections', $classes, 'Should contain base class' );
		$this->assertStringContainsString( 'layout-grid', $classes, 'Should contain layout class' );
		$this->assertStringContainsString( 'columns-3', $classes, 'Should contain columns class' );
		$this->assertStringContainsString( 'image-top', $classes, 'Should contain image alignment class' );

		// Test list layout classes.
		$attributes = [
			'layout'         => 'list',
			'imageAlignment' => 'left',
			'imageSize'      => 'medium',
		];
		$classes    = Collections_Block::get_block_classes( $attributes );

		$this->assertStringContainsString( 'layout-list', $classes, 'Should contain list layout class' );
		$this->assertStringContainsString( 'image-left', $classes, 'Should contain image alignment class' );
		$this->assertStringContainsString( 'image-size-medium', $classes, 'Should contain image size class for list' );
	}

	/**
	 * Test get_image_size_from_attributes method.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::get_image_size_from_attributes
	 */
	public function test_get_image_size_from_attributes() {
		// Test small size.
		$attributes = [
			'layout'    => 'list',
			'imageSize' => 'small',
		];
		$size       = Collections_Block::get_image_size_from_attributes( $attributes );
		$this->assertEquals( 'newspack_collection_small', $size, 'Small should map to newspack_collection_small' );

		// Test medium size.
		$attributes = [
			'layout'    => 'list',
			'imageSize' => 'medium',
		];
		$size       = Collections_Block::get_image_size_from_attributes( $attributes );
		$this->assertEquals( 'newspack_collection_medium', $size, 'Medium should map to newspack_collection_medium' );

		// Test large size.
		$attributes = [
			'layout'    => 'list',
			'imageSize' => 'large',
		];
		$size       = Collections_Block::get_image_size_from_attributes( $attributes );
		$this->assertEquals( 'newspack_collection_large', $size, 'Large should map to newspack_collection_large' );

		// Test default.
		$attributes = [ 'layout' => 'list' ];
		$size       = Collections_Block::get_image_size_from_attributes( $attributes );
		$this->assertEquals( 'newspack_collection_small', $size, 'Default should be newspack_collection_small' );

		// Test grid layout with columns < 3 (should return newspack_collection_large).
		foreach ( [ 1, 2 ] as $columns ) {
			$attributes = [
				'layout'  => 'grid',
				'columns' => $columns,
			];
			$size       = Collections_Block::get_image_size_from_attributes( $attributes );
			$this->assertEquals( 'newspack_collection_large', $size, "Grid layout with {$columns} columns should map to newspack_collection_large" );
		}

		// Test grid layout with columns = 3 (should return newspack_collection_medium).
		$attributes = [
			'layout'  => 'grid',
			'columns' => 3,
		];
		$size       = Collections_Block::get_image_size_from_attributes( $attributes );
		$this->assertEquals( 'newspack_collection_medium', $size, 'Grid layout with 3 columns should map to newspack_collection_medium' );

		// Test grid layout with columns >= 4 (should return newspack_collection_small).
		foreach ( [ 4, 5, 6 ] as $columns ) {
			$attributes = [
				'layout'  => 'grid',
				'columns' => $columns,
			];
			$size       = Collections_Block::get_image_size_from_attributes( $attributes );
			$this->assertEquals( 'newspack_collection_small', $size, "Grid layout with {$columns} columns should map to newspack_collection_small" );
		}
	}

	/**
	 * Test render_collection_categories with no categories.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_collection_categories
	 */
	public function test_render_collection_categories_empty() {
		$collection_id = $this->create_test_collection();
		$collection    = get_post( $collection_id );

		ob_start();
		Collections_Block::render_collection_categories( $collection );
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Should produce no output when no categories' );
	}

	/**
	 * Test render_collection_categories with categories.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_collection_categories
	 */
	public function test_render_collection_categories_with_categories() {
		$category_name = 'Test Category';
		$this->set_current_user_role( 'administrator' );
		$category_term = wp_insert_term( $category_name, Collection_Category_Taxonomy::get_taxonomy() );

		$collection_id = $this->create_test_collection();
		wp_set_object_terms( $collection_id, $category_term['term_id'], Collection_Category_Taxonomy::get_taxonomy() );

		$collection = get_post( $collection_id );

		ob_start();
		Collections_Block::render_collection_categories( $collection );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wp-block-newspack-collections__categories', $output, 'Should contain categories wrapper' );
		$this->assertStringContainsString( 'wp-block-newspack-collections__category', $output, 'Should contain category link' );
		$this->assertStringContainsString( $category_name, $output, 'Should contain category name' );
	}

	/**
	 * Test render_collection_meta with all fields.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_collection_meta
	 */
	public function test_render_collection_meta_all_fields() {
		$period        = 'Spring 2024';
		$volume        = '5';
		$number        = '2';
		$collection_id = $this->create_test_collection();

		// Set meta fields.
		Collection_Meta::set( $collection_id, 'period', $period );
		Collection_Meta::set( $collection_id, 'volume', $volume );
		Collection_Meta::set( $collection_id, 'number', $number );

		$collection = get_post( $collection_id );
		$attributes = [
			'showPeriod' => true,
			'showVolume' => true,
			'showNumber' => true,
			'layout'     => 'grid',
		];

		ob_start();
		Collections_Block::render_collection_meta( $collection, $attributes );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wp-block-newspack-collections__meta', $output, 'Should contain meta wrapper' );
		$this->assertStringContainsString( $period, $output, 'Should contain period' );
		$this->assertStringContainsString( 'Vol. ' . $volume, $output, 'Should contain volume' );
		$this->assertStringContainsString( 'No. ' . $number, $output, 'Should contain number' );
	}

	/**
	 * Test render_collection_meta with list layout (different separator).
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_collection_meta
	 */
	public function test_render_collection_meta_list_layout() {
		$period        = 'Spring 2024';
		$volume        = '5';
		$collection_id = $this->create_test_collection();

		Collection_Meta::set( $collection_id, 'period', $period );
		Collection_Meta::set( $collection_id, 'volume', $volume );

		$collection = get_post( $collection_id );
		$attributes = [
			'showPeriod' => true,
			'showVolume' => true,
			'showNumber' => false,
			'layout'     => 'list',
		];

		ob_start();
		Collections_Block::render_collection_meta( $collection, $attributes );
		$output = ob_get_clean();

		$this->assertStringContainsString( $period, $output, 'Should contain period' );
		$this->assertStringContainsString( 'Vol. ' . $volume, $output, 'Should contain volume' );
		$this->assertStringContainsString( '>/</', $output, 'Should use slash separator for list layout' );
	}

	/**
	 * Test render_collection_ctas with no CTAs.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_collection_ctas
	 */
	public function test_render_collection_ctas_empty() {
		$collection_id = $this->create_test_collection();
		$collection    = get_post( $collection_id );

		$attributes = [
			'showCTAs'            => true,
			'numberOfCTAs'        => 1,
			'showSubscriptionUrl' => true,
			'showOrderUrl'        => true,
		];

		ob_start();
		Collections_Block::render_collection_ctas( $collection, $attributes );
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Should produce no output when no CTAs' );
	}

	/**
	 * Test render_collection_ctas with subscription and order CTAs toggles.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_collection_ctas
	 */
	public function test_subscription_and_order_cta_toggles() {
		$collection_id = $this->create_test_collection();
		$collection    = get_post( $collection_id );

		// Add hierarchical CTAs.
		Collection_Meta::set( $collection_id, 'subscribe_link', 'https://example.com/subscribe' );
		Collection_Meta::set( $collection_id, 'order_link', 'https://example.com/order' );

		// Additional CTA data.
		$ctas_data = [
			[
				'type'  => 'link',
				'label' => 'Download',
				'url'   => 'https://example.com/download',
			],
		];
		Collection_Meta::set( $collection_id, 'ctas', $ctas_data );

		// Test with both subscription and order URLs enabled.
		$attributes = [
			'showCTAs'            => true,
			'numberOfCTAs'        => 4,
			'showSubscriptionUrl' => true,
			'showOrderUrl'        => true,
		];

		ob_start();
		Collections_Block::render_collection_ctas( $collection, $attributes );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wp-block-newspack-collections__ctas', $output, 'Should contain CTAs wrapper' );
		$this->assertStringContainsString( 'Subscribe', $output, 'Should contain Subscribe CTA' );
		$this->assertStringContainsString( 'Order', $output, 'Should contain Order CTA' );
		$this->assertStringContainsString( 'Download', $output, 'Should contain Download CTA' );

		// Test with subscription URL disabled but order URL enabled.
		$attributes['showSubscriptionUrl'] = false;

		ob_start();
		Collections_Block::render_collection_ctas( $collection, $attributes );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Subscribe', $output, 'Should not contain Subscribe CTA' );
		$this->assertStringContainsString( 'Order', $output, 'Should contain Order CTA' );
		$this->assertStringContainsString( 'Download', $output, 'Should contain Download CTA' );

		// Test with both subscription and order URLs disabled.
		$attributes['showOrderUrl'] = false;

		ob_start();
		Collections_Block::render_collection_ctas( $collection, $attributes );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Subscribe', $output, 'Should not contain Subscribe CTA' );
		$this->assertStringNotContainsString( 'Order', $output, 'Should not contain Order CTA' );
		$this->assertStringContainsString( 'Download', $output, 'Should contain Download CTA' );
	}

	/**
	 * Test render_collection_ctas with specific CTAs filter.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_collection_ctas
	 */
	public function test_render_collection_ctas_specific_filter() {
		$collection_id = $this->create_test_collection();
		$collection    = get_post( $collection_id );

		// Mock CTAs data.
		$ctas_data = [
			[
				'type'  => 'link',
				'label' => 'Link 1',
				'url'   => 'https://example.com/link',
			],
			[
				'type'  => 'link',
				'label' => 'Another Link',
				'url'   => 'https://example.com/another-link',
			],
			[
				'type'  => 'link',
				'label' => 'Download',
				'url'   => 'https://example.com/download',
			],
		];

		Collection_Meta::set( $collection_id, 'ctas', $ctas_data );

		// Test specific CTAs filter.
		$attributes = [
			'showCTAs'            => true,
			'numberOfCTAs'        => 3,
			'showSubscriptionUrl' => true,
			'showOrderUrl'        => true,
			'specificCTAs'        => 'Download,Another Link',
		];

		ob_start();
		Collections_Block::render_collection_ctas( $collection, $attributes );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Download', $output, 'Should contain Download CTA' );
		$this->assertStringContainsString( 'Another Link', $output, 'Should contain Another Link CTA' );
		$this->assertStringNotContainsString( 'Link 1', $output, 'Should not contain non-specified Link 1 CTA' );
	}

	/**
	 * Test render_collection_ctas with CTA limit.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_collection_ctas
	 */
	public function test_render_collection_ctas_with_limit() {
		$collection_id = $this->create_test_collection();
		$collection    = get_post( $collection_id );

		// Mock CTAs data.
		$ctas_data = [
			[
				'type'  => 'link',
				'label' => 'Link 1',
				'url'   => 'https://example.com/link-1',
			],
			[
				'type'  => 'link',
				'label' => 'Link 2',
				'url'   => 'https://example.com/link-2',
			],
		];

		Collection_Meta::set( $collection_id, 'ctas', $ctas_data );

		// Test limit to 1 CTA.
		$attributes = [
			'showCTAs'            => true,
			'numberOfCTAs'        => 1,
			'showSubscriptionUrl' => true,
			'showOrderUrl'        => true,
		];

		ob_start();
		Collections_Block::render_collection_ctas( $collection, $attributes );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Link 1', $output, 'Should contain first CTA' );
		$this->assertStringNotContainsString( 'Link 2', $output, 'Should not contain second CTA due to limit' );
	}

	/**
	 * Test attribute sanitization.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::sanitize_attributes
	 */
	public function test_attribute_sanitization() {
		$post_title    = 'Test Collection';
		$collection_id = $this->create_test_collection( [ 'post_title' => $post_title ] );

		// Test with negative and invalid values.
		$invalid_attributes = [
			'numberOfItems'       => 'one',
			'columns'             => 0,
			'numberOfCTAs'        => -1,
			'offset'              => -0.5,
			'selectedCollections' => [ 'invalid', '123', $collection_id ],
		];

		$sanitized = Collections_Block::sanitize_attributes( $invalid_attributes );

		$this->assertEquals( Collections_Block::DEFAULT_ATTRIBUTES['numberOfItems'], $sanitized['numberOfItems'], 'Invalid numberOfItems should use default' );
		$this->assertEquals( Collections_Block::DEFAULT_ATTRIBUTES['columns'], $sanitized['columns'], 'Invalid columns should use default' );
		$this->assertEquals( Collections_Block::DEFAULT_ATTRIBUTES['offset'], $sanitized['offset'], 'Invalid offset should use default' );
		$this->assertEquals( -1, $sanitized['numberOfCTAs'], 'Special value -1 for numberOfCTAs should be preserved' );

		// Test with valid values that should be preserved (even if less than defaults).
		$valid_attributes = [
			'numberOfItems' => 2,
			'columns'       => 1,
			'numberOfCTAs'  => 3,
		];

		$sanitized_valid = Collections_Block::sanitize_attributes( $valid_attributes );

		$this->assertEquals( 2, $sanitized_valid['numberOfItems'], 'Valid numberOfItems should be preserved' );
		$this->assertEquals( 1, $sanitized_valid['columns'], 'Valid columns should be preserved' );
		$this->assertEquals( 3, $sanitized_valid['numberOfCTAs'], 'Valid numberOfCTAs should be preserved' );

		// Test rendering still works with invalid attributes.
		$output = $this->render_collections_block( $invalid_attributes );

		$this->assertStringContainsString( 'wp-block-newspack-collections', $output, 'Should contain block wrapper despite invalid attributes' );
		$this->assertStringContainsString( $post_title, $output, 'Should contain collection title' );
	}

	/**
	 * Test newspack_collections_block_wrapper_classes filter.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_block
	 */
	public function test_wrapper_classes_filter() {
		$this->create_test_collection();

		// Add filter to modify wrapper classes.
		add_filter(
			'newspack_collections_block_wrapper_classes',
			function ( $classes ) {
				return $classes . ' custom-wrapper-class';
			},
			10,
			2
		);
		$output = $this->render_collections_block();

		$this->assertStringContainsString( 'custom-wrapper-class', $output, 'Should contain custom wrapper class from filter' );

		// Clean up.
		remove_all_filters( 'newspack_collections_block_wrapper_classes' );
	}

	/**
	 * Test newspack_collections_block_ctas filter.
	 *
	 * @covers \Newspack\Blocks\Collections\Collections_Block::render_collection_ctas
	 */
	public function test_ctas_filter() {
		$collection_id = $this->create_test_collection();
		$collection    = get_post( $collection_id );

		// Mock CTAs data.
		$ctas_data = [
			[
				'type'  => 'link',
				'label' => 'Subscribe',
				'url'   => 'https://example.com/subscribe',
			],
		];

		Collection_Meta::set( $collection_id, 'ctas', $ctas_data );

		// Add filter to modify CTAs.
		add_filter(
			'newspack_collections_block_ctas',
			function ( $filtered_ctas ) {
				// Add a custom CTA.
				$filtered_ctas[] = [
					'label' => 'Custom CTA',
					'url'   => 'https://example.com/custom',
				];
				return $filtered_ctas;
			},
			10,
			4
		);

		$attributes = [
			'showCTAs'            => true,
			'numberOfCTAs'        => 2,
			'showSubscriptionUrl' => true,
			'showOrderUrl'        => true,
		];

		ob_start();
		Collections_Block::render_collection_ctas( $collection, $attributes );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Subscribe', $output, 'Should contain original CTA' );
		$this->assertStringContainsString( 'Custom CTA', $output, 'Should contain custom CTA from filter' );

		// Clean up.
		remove_all_filters( 'newspack_collections_block_ctas' );
	}
}
