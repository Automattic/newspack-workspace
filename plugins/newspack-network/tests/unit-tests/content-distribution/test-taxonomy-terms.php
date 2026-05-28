<?php
/**
 * Class TestTaxonomyTerms
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Taxonomy_Terms;
use ReflectionClass;
use ReflectionMethod;

/**
 * Test the Taxonomy_Terms class.
 */
class TestTaxonomyTerms extends \WP_UnitTestCase {

	/**
	 * The Taxonomy_Terms instance.
	 *
	 * @var Taxonomy_Terms
	 */
	protected $taxonomy_terms;

	/**
	 * Test get_post_taxonomy_terms with basic terms.
	 */
	public function test_get_post_taxonomy_terms_basic() {
		// Create a post.
		$post_id = $this->factory->post->create( [ 'post_title' => 'Test Post' ] );
		$post = get_post( $post_id );

		// Create categories and tags.
		$category_1 = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Category 1',
			]
		);
		$category_2 = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Category 2',
			]
		);
		$tag_1 = $this->factory->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => 'Tag 1',
			]
		);
		$tag_2 = $this->factory->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => 'Tag 2',
			]
		);

		// Assign terms to the post.
		wp_set_post_terms( $post_id, [ $category_1, $category_2 ], 'category' );
		wp_set_post_terms( $post_id, [ $tag_1, $tag_2 ], 'post_tag' );

		// Get taxonomy terms.
		$terms = Taxonomy_Terms::get_post_taxonomy_terms( $post );

		// Assert categories.
		$this->assertArrayHasKey( 'category', $terms );
		$this->assertCount( 2, $terms['category'] );
		$this->assertSame( 'Category 1', $terms['category'][0]['name'] );
		$this->assertSame( 'category-1', $terms['category'][0]['slug'] );
		$this->assertSame( 'Category 2', $terms['category'][1]['name'] );
		$this->assertSame( 'category-2', $terms['category'][1]['slug'] );

		// Assert tags.
		$this->assertArrayHasKey( 'post_tag', $terms );
		$this->assertCount( 2, $terms['post_tag'] );
		$this->assertSame( 'Tag 1', $terms['post_tag'][0]['name'] );
		$this->assertSame( 'tag-1', $terms['post_tag'][0]['slug'] );
		$this->assertSame( 'Tag 2', $terms['post_tag'][1]['name'] );
		$this->assertSame( 'tag-2', $terms['post_tag'][1]['slug'] );
	}

	/**
	 * Test get_post_taxonomy_terms with hierarchical terms.
	 */
	public function test_get_post_taxonomy_terms_hierarchical() {
		// Create a post.
		$post_id = $this->factory->post->create( [ 'post_title' => 'Test Post' ] );
		$post = get_post( $post_id );

		// Create parent and child categories.
		$parent_category = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Parent Category',
				'slug'     => 'parent-category',
			]
		);
		$child_category = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Child Category',
				'slug'     => 'child-category',
				'parent'   => $parent_category,
			]
		);
		$grandchild_category = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Grandchild Category',
				'slug'     => 'grandchild-category',
				'parent'   => $child_category,
			]
		);

		// Assign the grandchild category to the post.
		wp_set_post_terms( $post_id, [ $grandchild_category ], 'category' );

		// Get taxonomy terms.
		$terms = Taxonomy_Terms::get_post_taxonomy_terms( $post );

		// Assert hierarchical category name.
		$this->assertArrayHasKey( 'category', $terms );
		$this->assertCount( 1, $terms['category'] );
		$expected_name = 'Parent Category' . Taxonomy_Terms::SEPARATOR . 'Child Category' . Taxonomy_Terms::SEPARATOR . 'Grandchild Category';
		$this->assertSame( $expected_name, $terms['category'][0]['name'] );
		$this->assertSame( 'grandchild-category', $terms['category'][0]['slug'] );
	}

	/**
	 * Test get_post_taxonomy_terms with ignored taxonomies.
	 */
	public function test_get_post_taxonomy_terms_ignored_taxonomies() {
		// Create a post.
		$post_id = $this->factory->post->create( [ 'post_title' => 'Test Post' ] );
		$post = get_post( $post_id );

		// Register a custom taxonomy.
		register_taxonomy( 'test_taxonomy', 'post', [ 'public' => true ] );

		// Create terms.
		$category = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Test Category',
			]
		);
		$custom_term = $this->factory->term->create(
			[
				'taxonomy' => 'test_taxonomy',
				'name'     => 'Test Custom Term',
			]
		);

		// Assign terms to the post.
		wp_set_post_terms( $post_id, [ $category ], 'category' );
		wp_set_post_terms( $post_id, [ $custom_term ], 'test_taxonomy' );

		// Mock the ignored taxonomies filter.
		add_filter(
			'newspack_network_content_distribution_ignored_taxonomies',
			function( $taxonomies ) {
				$taxonomies[] = 'test_taxonomy';
				return $taxonomies;
			}
		);

		// Get taxonomy terms.
		$terms = Taxonomy_Terms::get_post_taxonomy_terms( $post );

		// Assert that category is included but test_taxonomy is ignored.
		$this->assertArrayHasKey( 'category', $terms );
		$this->assertArrayNotHasKey( 'test_taxonomy', $terms );
	}

	/**
	 * Test get_post_taxonomy_terms with non-public taxonomies.
	 */
	public function test_get_post_taxonomy_terms_non_public_taxonomies() {
		// Create a post.
		$post_id = $this->factory->post->create( [ 'post_title' => 'Test Post' ] );
		$post = get_post( $post_id );

		// Register a non-public taxonomy.
		register_taxonomy( 'private_taxonomy', 'post', [ 'public' => false ] );

		// Create terms.
		$category = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Test Category',
			]
		);
		$private_term = $this->factory->term->create(
			[
				'taxonomy' => 'private_taxonomy',
				'name'     => 'Private Term',
			]
		);

		// Assign terms to the post.
		wp_set_post_terms( $post_id, [ $category ], 'category' );
		wp_set_post_terms( $post_id, [ $private_term ], 'private_taxonomy' );

		// Get taxonomy terms.
		$terms = Taxonomy_Terms::get_post_taxonomy_terms( $post );

		// Assert that category is included but private_taxonomy is not.
		$this->assertArrayHasKey( 'category', $terms );
		$this->assertArrayNotHasKey( 'private_taxonomy', $terms );
	}

	/**
	 * Test recursively_get_term_name with single term.
	 */
	public function test_recursively_get_term_name_single() {
		// Create a single term.
		$term_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Single Term',
				'slug'     => 'single-term',
			]
		);
		$term = get_term( $term_id );

		// Get the term name.
		$name = Taxonomy_Terms::recursively_get_term_name( $term );

		// Assert the name.
		$this->assertSame( 'Single Term', $name );
	}

	/**
	 * Test recursively_get_term_name with hierarchical terms.
	 */
	public function test_recursively_get_term_name_hierarchical() {
		// Create parent term.
		$parent_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Parent Term',
				'slug'     => 'parent-term',
			]
		);

		// Create child term.
		$child_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Child Term',
				'slug'     => 'child-term',
				'parent'   => $parent_id,
			]
		);

		// Create grandchild term.
		$grandchild_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Grandchild Term',
				'slug'     => 'grandchild-term',
				'parent'   => $child_id,
			]
		);

		$grandchild_term = get_term( $grandchild_id );

		// Get the hierarchical term name.
		$name = Taxonomy_Terms::recursively_get_term_name( $grandchild_term );

		// Assert the hierarchical name.
		$expected_name = 'Parent Term' . Taxonomy_Terms::SEPARATOR . 'Child Term' . Taxonomy_Terms::SEPARATOR . 'Grandchild Term';
		$this->assertSame( $expected_name, $name );
	}

	/**
	 * Test recursively_get_term_name with two-level hierarchy.
	 */
	public function test_recursively_get_term_name_two_levels() {
		// Create parent term.
		$parent_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Parent Term',
				'slug'     => 'parent-term',
			]
		);

		// Create child term.
		$child_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Child Term',
				'slug'     => 'child-term',
				'parent'   => $parent_id,
			]
		);

		$child_term = get_term( $child_id );

		// Get the hierarchical term name.
		$name = Taxonomy_Terms::recursively_get_term_name( $child_term );

		// Assert the hierarchical name.
		$expected_name = 'Parent Term' . Taxonomy_Terms::SEPARATOR . 'Child Term';
		$this->assertSame( $expected_name, $name );
	}

	/**
	 * Test the SEPARATOR constant.
	 */
	public function test_separator_constant() {
		$this->assertSame( '|--|', Taxonomy_Terms::SEPARATOR );
	}

	/**
	 * Test get_or_create_term_ids with existing terms.
	 */
	public function test_get_or_create_term_ids_existing_terms() {
		// Create existing terms.
		$category_1_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Existing Category 1',
				'slug'     => 'existing-category-1',
			]
		);
		$category_2_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Existing Category 2',
				'slug'     => 'existing-category-2',
			]
		);

		// Prepare terms data.
		$terms = [
			[
				'name' => 'Existing Category 1',
				'slug' => 'existing-category-1',
			],
			[
				'name' => 'Existing Category 2',
				'slug' => 'existing-category-2',
			],
		];

		// Get term IDs.
		$term_ids = Taxonomy_Terms::get_or_create_term_ids( $terms, 'category' );

		// Assert that the correct term IDs are returned.
		$this->assertIsArray( $term_ids );
		$this->assertCount( 2, $term_ids );
		$this->assertContains( $category_1_id, $term_ids );
		$this->assertContains( $category_2_id, $term_ids );
	}

	/**
	 * Test get_or_create_term_ids with new terms.
	 */
	public function test_get_or_create_term_ids_new_terms() {
		// Prepare terms data for new terms.
		$terms = [
			[
				'name' => 'New Category 1',
				'slug' => 'new-category-1',
			],
			[
				'name' => 'New Category 2',
				'slug' => 'new-category-2',
			],
		];

		// Get term IDs (should create new terms).
		$term_ids = Taxonomy_Terms::get_or_create_term_ids( $terms, 'category' );

		// Assert that term IDs are returned.
		$this->assertIsArray( $term_ids );
		$this->assertCount( 2, $term_ids );

		// Verify that the terms were actually created.
		$term_1 = get_term_by( 'name', 'New Category 1', 'category' );
		$term_2 = get_term_by( 'name', 'New Category 2', 'category' );

		$this->assertNotFalse( $term_1 );
		$this->assertNotFalse( $term_2 );
		$this->assertContains( $term_1->term_id, $term_ids );
		$this->assertContains( $term_2->term_id, $term_ids );
	}

	/**
	 * Test get_or_create_term_ids with hierarchical terms.
	 */
	public function test_get_or_create_term_ids_hierarchical() {
		// Prepare hierarchical terms data.
		$terms = [
			[
				'name' => 'Parent Category' . Taxonomy_Terms::SEPARATOR . 'Child Category',
				'slug' => 'child-category',
			],
		];

		// Get term IDs (should create hierarchical terms).
		$term_ids = Taxonomy_Terms::get_or_create_term_ids( $terms, 'category' );

		// Assert that term ID is returned.
		$this->assertIsArray( $term_ids );
		$this->assertCount( 1, $term_ids );

		// Verify that both parent and child terms were created.
		$parent_term = get_term_by( 'name', 'Parent Category', 'category' );
		$child_term = get_term_by( 'name', 'Child Category', 'category' );

		$this->assertNotFalse( $parent_term );
		$this->assertNotFalse( $child_term );
		$this->assertSame( $parent_term->term_id, $child_term->parent );
		$this->assertContains( $child_term->term_id, $term_ids );
	}

	/**
	 * Test get_or_create_term_ids with existing terms only taxonomy.
	 */
	public function test_get_or_create_term_ids_existing_terms_only() {
		// Register a custom taxonomy.
		register_taxonomy( 'existing_only_tax', 'post', [ 'public' => true ] );

		// Add the taxonomy to the existing terms only list.
		add_filter(
			'newspack_network_content_distribution_existing_terms_only_taxonomies',
			function( $taxonomies ) {
				$taxonomies[] = 'existing_only_tax';
				return $taxonomies;
			}
		);

		// Prepare terms data for non-existent terms.
		$terms = [
			[
				'name' => 'Non-existent Term',
				'slug' => 'non-existent-term',
			],
		];

		// Get term IDs (should not create new terms).
		$term_ids = Taxonomy_Terms::get_or_create_term_ids( $terms, 'existing_only_tax' );

		// Assert that no term IDs are returned.
		$this->assertIsArray( $term_ids );
		$this->assertEmpty( $term_ids );

		// Verify that the term was not created.
		$term = get_term_by( 'name', 'Non-existent Term', 'existing_only_tax' );
		$this->assertFalse( $term );
	}

	/**
	 * Test recursively_get_and_create_term_id with single term.
	 */
	public function test_recursively_get_and_create_term_id_single() {
		// Test with non-existent term.
		$term_id = Taxonomy_Terms::recursively_get_and_create_term_id( 'New Single Term', 'category' );

		// Assert that a term ID is returned.
		$this->assertIsInt( $term_id );
		$this->assertGreaterThan( 0, $term_id );

		// Verify that the term was created.
		$term = get_term( $term_id );
		$this->assertSame( 'New Single Term', $term->name );
		$this->assertSame( 'category', $term->taxonomy );
		$this->assertSame( 0, $term->parent );
	}

	/**
	 * Test recursively_get_and_create_term_id with existing term.
	 */
	public function test_recursively_get_and_create_term_id_existing() {
		// Create an existing term.
		$existing_term_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Existing Term',
				'slug'     => 'existing-term',
			]
		);

		// Get the term ID.
		$term_id = Taxonomy_Terms::recursively_get_and_create_term_id( 'Existing Term', 'category' );

		// Assert that the existing term ID is returned.
		$this->assertSame( $existing_term_id, $term_id );
	}

	/**
	 * Test recursively_get_and_create_term_id with hierarchical terms.
	 */
	public function test_recursively_get_and_create_term_id_hierarchical() {
		// Test with hierarchical term name.
		$hierarchical_name = 'Parent Term' . Taxonomy_Terms::SEPARATOR . 'Child Term' . Taxonomy_Terms::SEPARATOR . 'Grandchild Term';
		$term_id = Taxonomy_Terms::recursively_get_and_create_term_id( $hierarchical_name, 'category' );

		// Assert that a term ID is returned.
		$this->assertIsInt( $term_id );
		$this->assertGreaterThan( 0, $term_id );

		// Verify the hierarchy was created correctly.
		$grandchild_term = get_term( $term_id );
		$this->assertSame( 'Grandchild Term', $grandchild_term->name );

		$child_term = get_term( $grandchild_term->parent );
		$this->assertSame( 'Child Term', $child_term->name );

		$parent_term = get_term( $child_term->parent );
		$this->assertSame( 'Parent Term', $parent_term->name );
		$this->assertSame( 0, $parent_term->parent );
	}

	/**
	 * Test recursively_get_and_create_term_id with hierarchical terms.
	 */
	public function test_recursively_get_and_create_term_id_hierarchical_parent_exists() {
		// Create an existing term.
		$existing_term_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Parent Term',
				'slug'     => 'parent-term',
			]
		);

		// Test with hierarchical term name.
		$hierarchical_name = 'Parent Term' . Taxonomy_Terms::SEPARATOR . 'Child Term' . Taxonomy_Terms::SEPARATOR . 'Grandchild Term';
		$term_id = Taxonomy_Terms::recursively_get_and_create_term_id( $hierarchical_name, 'category' );

		// Assert that a term ID is returned.
		$this->assertIsInt( $term_id );
		$this->assertGreaterThan( 0, $term_id );

		// Verify the hierarchy was created correctly.
		$grandchild_term = get_term( $term_id );
		$this->assertSame( 'Grandchild Term', $grandchild_term->name );

		$child_term = get_term( $grandchild_term->parent );
		$this->assertSame( 'Child Term', $child_term->name );

		$parent_term = get_term( $child_term->parent );
		$this->assertSame( 'Parent Term', $parent_term->name );
		$this->assertSame( 0, $parent_term->parent );
	}

	/**
	 * Test recursively_get_and_create_term_id with partially existing hierarchy.
	 */
	public function test_recursively_get_and_create_term_id_partial_hierarchy() {
		// Create a parent term.
		$parent_term_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Existing Parent',
				'slug'     => 'existing-parent',
			]
		);

		// Test with hierarchical term name where parent exists.
		$hierarchical_name = 'Existing Parent' . Taxonomy_Terms::SEPARATOR . 'New Child';
		$term_id = Taxonomy_Terms::recursively_get_and_create_term_id( $hierarchical_name, 'category' );

		// Assert that a term ID is returned.
		$this->assertIsInt( $term_id );
		$this->assertGreaterThan( 0, $term_id );

		// Verify the child was created with correct parent.
		$child_term = get_term( $term_id );
		$this->assertSame( 'New Child', $child_term->name );
		$this->assertSame( $parent_term_id, $child_term->parent );
	}

	/**
	 * Test recursively_get_and_create_term_id with existing terms only taxonomy.
	 */
	public function test_recursively_get_and_create_term_id_existing_only() {
		// Register a custom taxonomy.
		register_taxonomy( 'existing_only_tax', 'post', [ 'public' => true ] );

		// Add the taxonomy to the existing terms only list.
		add_filter(
			'newspack_network_content_distribution_existing_terms_only_taxonomies',
			function( $taxonomies ) {
				$taxonomies[] = 'existing_only_tax';
				return $taxonomies;
			}
		);

		// Test with non-existent term.
		$term_id = Taxonomy_Terms::recursively_get_and_create_term_id( 'Non-existent Term', 'existing_only_tax' );

		// Assert that false is returned.
		$this->assertFalse( $term_id );

		// Verify that the term was not created.
		$term = get_term_by( 'name', 'Non-existent Term', 'existing_only_tax' );
		$this->assertFalse( $term );
	}

	/**
	 * Test recursively_get_and_create_term_id with existing terms only taxonomy and existing term.
	 */
	public function test_recursively_get_and_create_term_id_existing_only_with_existing_term() {
		// Register a custom taxonomy.
		register_taxonomy( 'existing_only_tax', 'post', [ 'public' => true ] );

		// Add the taxonomy to the existing terms only list.
		add_filter(
			'newspack_network_content_distribution_existing_terms_only_taxonomies',
			function( $taxonomies ) {
				$taxonomies[] = 'existing_only_tax';
				return $taxonomies;
			}
		);

		// Create an existing term.
		$existing_term_id = $this->factory->term->create(
			[
				'taxonomy' => 'existing_only_tax',
				'name'     => 'Existing Term',
				'slug'     => 'existing-term',
			]
		);

		// Test with existing term.
		$term_id = Taxonomy_Terms::recursively_get_and_create_term_id( 'Existing Term', 'existing_only_tax' );

		// Assert that the existing term ID is returned.
		$this->assertSame( $existing_term_id, $term_id );
	}

	/**
	 * Test get_or_create_term_ids with mixed existing and new terms.
	 */
	public function test_get_or_create_term_ids_mixed() {
		// Create one existing term.
		$existing_term_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Existing Category',
				'slug'     => 'existing-category',
			]
		);

		// Prepare mixed terms data.
		$terms = [
			[
				'name' => 'Existing Category',
				'slug' => 'existing-category',
			],
			[
				'name' => 'New Category',
				'slug' => 'new-category',
			],
		];

		// Get term IDs.
		$term_ids = Taxonomy_Terms::get_or_create_term_ids( $terms, 'category' );

		// Assert that both term IDs are returned.
		$this->assertIsArray( $term_ids );
		$this->assertCount( 2, $term_ids );
		$this->assertContains( $existing_term_id, $term_ids );

		// Verify that the new term was created.
		$new_term = get_term_by( 'name', 'New Category', 'category' );
		$this->assertNotFalse( $new_term );
		$this->assertContains( $new_term->term_id, $term_ids );
	}

	/**
	 * Test get_post_taxonomy_terms with no terms returns empty array.
	 */
	public function test_get_post_taxonomy_terms_no_terms_returns_empty_array() {
		// Create a post without any terms.
		$post_id = $this->factory->post->create( [ 'post_title' => 'Test Post' ] );
		$post = get_post( $post_id );

		// Get taxonomy terms.
		$terms = Taxonomy_Terms::get_post_taxonomy_terms( $post );

		// Assert that categories and tags exist in the array as empty arrays.
		$this->assertArrayHasKey( 'post_tag', $terms );
		$this->assertIsArray( $terms['post_tag'] );
		$this->assertEmpty( $terms['post_tag'] );
	}

	/**
	 * Test get_post_taxonomy_terms with mixed terms (some empty, some not).
	 */
	public function test_get_post_taxonomy_terms_mixed_empty_and_populated() {
		// Create a post.
		$post_id = $this->factory->post->create( [ 'post_title' => 'Test Post' ] );
		$post = get_post( $post_id );

		// Create and assign categories but not tags.
		$category_1 = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Category 1',
			]
		);
		wp_set_post_terms( $post_id, [ $category_1 ], 'category' );

		// Get taxonomy terms.
		$terms = Taxonomy_Terms::get_post_taxonomy_terms( $post );

		// Assert that categories are populated.
		$this->assertArrayHasKey( 'category', $terms );
		$this->assertNotEmpty( $terms['category'] );
		$this->assertCount( 1, $terms['category'] );
		$this->assertSame( 'Category 1', $terms['category'][0]['name'] );

		// Assert that tags are present but empty.
		$this->assertArrayHasKey( 'post_tag', $terms );
		$this->assertIsArray( $terms['post_tag'] );
		$this->assertEmpty( $terms['post_tag'] );
	}

	/**
	 * Test get_post_taxonomy_terms with custom taxonomy having no terms.
	 */
	public function test_get_post_taxonomy_terms_custom_taxonomy_no_terms() {
		// Register a custom taxonomy.
		register_taxonomy( 'custom_taxonomy', 'post', [ 'public' => true ] );

		// Create a post without any custom taxonomy terms.
		$post_id = $this->factory->post->create( [ 'post_title' => 'Test Post' ] );
		$post = get_post( $post_id );

		// Get taxonomy terms.
		$terms = Taxonomy_Terms::get_post_taxonomy_terms( $post );

		// Assert that custom taxonomy exists in the array as an empty array.
		$this->assertArrayHasKey( 'custom_taxonomy', $terms );
		$this->assertIsArray( $terms['custom_taxonomy'] );
		$this->assertEmpty( $terms['custom_taxonomy'] );
	}
}
