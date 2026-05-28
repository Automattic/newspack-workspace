<?php
/**
 * Unit tests for the Collection Taxonomy handler.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Collections\Taxonomy
 */

namespace Newspack\Tests\Unit\Collections;

use WP_UnitTestCase;
use Newspack\Collections\Collection_Taxonomy;

/**
 * Test the Collections Taxonomy functionality.
 */
class Test_Collection_Taxonomy extends WP_UnitTestCase {
	use Traits\Trait_Collections_Test;

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();

		// Register taxonomy directly as the WP environment is already initialized.
		Collection_Taxonomy::register_taxonomy();
	}

	/**
	 * Test that the taxonomy is registered.
	 *
	 * @covers \Newspack\Collections\Collection_Taxonomy::register_taxonomy
	 */
	public function test_taxonomy_registration() {
		$taxonomy = get_taxonomy( Collection_Taxonomy::get_taxonomy() );
		$this->assertNotNull( $taxonomy, 'Taxonomy should be registered.' );
		$this->assertEquals( 'Collections', $taxonomy->labels->name, 'Taxonomy label should be "Collections".' );
		$this->assertFalse( $taxonomy->public, 'Taxonomy should not be public.' );
		$this->assertFalse( $taxonomy->show_in_menu, 'Taxonomy should not show in menu.' );
		$this->assertContains( 'post', $taxonomy->object_type, 'Taxonomy should be associated with collection post type.' );
	}

	/**
	 * Test that hooks are registered and unregistered correctly.
	 *
	 * @covers \Newspack\Collections\Collection_Taxonomy::register_hooks
	 * @covers \Newspack\Collections\Collection_Taxonomy::unregister_hooks
	 * @covers \Newspack\Collections\Collection_Taxonomy::manage_hooks
	 */
	public function test_hooks_management() {
		$reflection = new \ReflectionMethod( Collection_Taxonomy::class, 'get_hooks' );
		$reflection->setAccessible( true );
		$hooks = $reflection->invoke( null );

		// Test hook registration.
		Collection_Taxonomy::register_hooks();
		foreach ( $hooks as $hook ) {
			$this->assertEquals(
				$hook[2] ?? 10, // If not priority is set, WP defaults to 10.
				has_action( $hook[0], $hook[1] ),
				sprintf( 'Hook "%s" should be registered.', $hook[0] )
			);
		}

		// Test hook unregistration.
		Collection_Taxonomy::unregister_hooks();
		foreach ( $hooks as $hook ) {
			$this->assertFalse(
				has_action( $hook[0], $hook[1] ),
				sprintf( 'Hook "%s" should be unregistered.', $hook[0] )
			);
		}
	}

	/**
	 * Test that a collection term can be created.
	 *
	 * @covers \Newspack\Collections\Collection_Taxonomy::register_taxonomy
	 */
	public function test_create_collection_term() {
		$args = [
			'name' => 'My Collection Term',
			'slug' => 'my-collection-term',
		];

		$term = $this->create_test_collection_term( $args );

		$this->assertEquals( $args['name'], $term->name, 'Term name should be set correctly.' );
		$this->assertEquals( $args['slug'], $term->slug, 'Term slug should be set correctly.' );
	}

	/**
	 * Test that a collection term can be found by name.
	 *
	 * @covers \Newspack\Collections\Collection_Taxonomy::find_term_by_name
	 */
	public function test_find_term_by_name() {
		$term = $this->create_test_collection_term(
			[
				'name' => 'Find Me Term',
				'slug' => 'find-me-term',
			]
		);

		$this->create_test_collection_term(
			[
				'name' => 'Find Me Term',
				'slug' => 'find-me-term-2',
			]
		);

		// Test basic term lookup.
		$found_terms = Collection_Taxonomy::find_term_by_name( 'Find Me Term' );
		$this->assertNotWPError( $found_terms, 'Term lookup should not return an error.' );
		$this->assertCount( 1, $found_terms, 'Should find exactly one term.' );
		$this->assertEquals( $term->term_id, $found_terms[0], 'Should find the first term.' );

		// Test term lookup with meta conditions.
		$meta_conditions = [
			'relation' => 'AND',
			[
				'key'     => 'non_existent_meta_key',
				'compare' => 'NOT EXISTS',
			],
		];
		$found_terms     = Collection_Taxonomy::find_term_by_name( 'Find Me Term', $meta_conditions );
		$this->assertNotWPError( $found_terms, 'Term lookup with meta should not return an error.' );
		$this->assertCount( 1, $found_terms, 'Should find exactly one term with meta conditions.' );
		$this->assertEquals( $term->term_id, $found_terms[0], 'Should find the correct term with meta conditions.' );

		// Test term lookup with non-matching meta conditions.
		$meta_conditions = [
			'relation' => 'AND',
			[
				'key'     => 'non_existent_meta_key',
				'value'   => 999,
				'compare' => '=',
			],
		];
		$found_terms     = Collection_Taxonomy::find_term_by_name( 'Find Me Term', $meta_conditions );
		$this->assertNotWPError( $found_terms, 'Term lookup with non-matching meta should not return an error.' );
		$this->assertEmpty( $found_terms, 'Should not find any terms with non-matching meta conditions.' );
	}

	/**
	 * Test term inactive status management.
	 *
	 * @covers \Newspack\Collections\Collection_Taxonomy::is_term_inactive
	 * @covers \Newspack\Collections\Collection_Taxonomy::deactivate_term
	 * @covers \Newspack\Collections\Collection_Taxonomy::reactivate_term
	 */
	public function test_term_inactive_status() {
		Collection_Taxonomy::register_hooks();

		// Create a test term.
		$term = $this->create_test_collection_term();

		// Initially term should not be inactive.
		$this->assertFalse( Collection_Taxonomy::is_term_inactive( $term->term_id ), 'Term should not be inactive by default.' );

		// Deactivate the term.
		$deactivate_result = Collection_Taxonomy::deactivate_term( $term->term_id );
		$this->assertNotWPError( $deactivate_result, 'Deactivating term should not return an error.' );
		$this->assertTrue( Collection_Taxonomy::is_term_inactive( $term->term_id ), 'Term should be marked as inactive after deactivation.' );

		// Verify term is filtered out of queries when inactive.
		$terms = get_terms(
			[
				'taxonomy'   => Collection_Taxonomy::get_taxonomy(),
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		$this->assertNotContains( (int) $term->term_id, $terms, 'Inactive term should be filtered out of queries.' );

		// Reactivate the term.
		$reactivate_result = Collection_Taxonomy::reactivate_term( $term->term_id );
		$this->assertTrue( $reactivate_result, 'Reactivating term should return true.' );
		$this->assertFalse( Collection_Taxonomy::is_term_inactive( $term->term_id ), 'Term should not be inactive after reactivation.' );

		// Verify term is included in queries after reactivation.
		$terms = get_terms(
			[
				'taxonomy'   => Collection_Taxonomy::get_taxonomy(),
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		$this->assertContains( (int) $term->term_id, $terms, 'Reactivated term should be included in queries.' );
	}
}
