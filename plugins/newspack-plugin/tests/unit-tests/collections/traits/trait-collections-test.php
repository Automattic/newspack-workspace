<?php
/**
 * Trait with common test helper methods for collections.
 *
 * @package Newspack\Tests\Unit\Collections
 *
 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */

namespace Newspack\Tests\Unit\Collections\Traits;

use Newspack\Collections\Post_Type;
use Newspack\Collections\Collection_Taxonomy;
use Newspack\Collections\Sync;

/**
 * Trait providing common test helper methods for collections.
 *
 * This trait provides reusable methods for creating and validating
 * collection posts and terms in unit tests. It helps reduce code
 * duplication and makes tests more maintainable.
 */
trait Trait_Collections_Test {
	/**
	 * Create a test collection post.
	 *
	 * @param array $args Optional. Additional post arguments.
	 * @return int The ID of the created collection post.
	 */
	protected function create_test_collection( $args = [] ) {
		$defaults = [
			'post_type'   => Post_Type::get_post_type(),
			'post_title'  => 'Test Collection',
			'post_name'   => 'test-collection',
			'post_status' => 'publish',
		];

		$post_args = wp_parse_args( $args, $defaults );
		$post_id   = self::factory()->post->create( $post_args );

		$this->assertNotWPError( $post_id, 'Post should be created successfully.' );
		return $post_id;
	}

	/**
	 * Create a test collection term.
	 *
	 * @param array $args Optional. Additional term arguments.
	 * @return array|\WP_Error The term data on success, WP_Error on failure.
	 */
	protected function create_test_collection_term( $args = [] ) {
		$defaults = [
			'name'     => 'Test Collection Term',
			'slug'     => 'test-collection-term',
			'taxonomy' => Collection_Taxonomy::get_taxonomy(),
		];

		$term_args = wp_parse_args( $args, $defaults );
		$term      = self::factory()->term->create_and_get( $term_args );
		$this->assertValidCollectionTerm( $term );
		return $term;
	}

	/**
	 * Set the current user to a test user with the given role.
	 *
	 * @param string $role The role of the user to set.
	 */
	protected function set_current_user_role( $role ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	/**
	 * Assert that a collection post is valid and properly linked to a term.
	 *
	 * @param int    $post_id The ID of the collection post.
	 * @param string $message Optional. Message to display on failure.
	 */
	protected function assertValidCollection( $post_id, $message = '' ) {
		$post = get_post( $post_id );
		$this->assertNotNull( $post, $message ? $message : 'Post should exist.' );
		$this->assertNotWPError( $post, 'Post should be valid.' );
		$this->assertEquals( Post_Type::get_post_type(), $post->post_type, 'Post should be a collection.' );

		$term_id = Sync::get_term_linked_to_collection( $post_id );
		$this->assertNotEmpty( $term_id, 'Linked term ID should be stored in post meta.' );

		$term = get_term( $term_id, Collection_Taxonomy::get_taxonomy() );
		$this->assertNotWPError( $term, 'Linked term should exist.' );
		$this->assertEquals( $post->post_title, $term->name, 'Term name should match post title.' );
		$this->assertEquals( $post->post_name, $term->slug, 'Term slug should match post slug.' );
	}

	/**
	 * Assert that a term is a valid collection term.
	 *
	 * @param array|\WP_Error $term    The term to check.
	 * @param string          $message Optional. Message to display on failure.
	 */
	protected function assertValidCollectionTerm( $term, $message = '' ) {
		$this->assertNotWPError( $term, $message ? $message : 'Term should be created successfully.' );
		$this->assertNotNull( $term, 'Term should exist.' );
		$this->assertEquals( Collection_Taxonomy::get_taxonomy(), $term->taxonomy, 'Term should be a collection term.' );
	}

	/**
	 * Assert that a collection post and term are properly linked.
	 *
	 * @param int    $post_id The ID of the collection post.
	 * @param int    $term_id The ID of the collection term.
	 * @param string $message Optional. Message to display on failure.
	 */
	protected function assertCollectionAndTermLinked( $post_id, $term_id, $message = '' ) {
		$this->assertEquals(
			$term_id,
			Sync::get_term_linked_to_collection( $post_id ),
			$message ? $message : 'Post should be linked to term.'
		);
		$this->assertEquals(
			$post_id,
			Sync::get_collection_linked_to_term( $term_id ),
			$message ? $message : 'Term should be linked to post.'
		);
	}
}
