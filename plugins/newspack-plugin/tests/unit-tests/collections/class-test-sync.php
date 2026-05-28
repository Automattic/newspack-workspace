<?php
/**
 * Tests for the Sync class.
 *
 * @package Newspack\Tests\Unit\Collections
 */

namespace Newspack\Tests\Unit\Collections;

use Newspack\Collections\Post_Type;
use Newspack\Collections\Collection_Taxonomy;
use Newspack\Collections\Sync;

/**
 * Tests for the Sync class.
 */
class Test_Sync extends \WP_UnitTestCase {
	use Traits\Trait_Collections_Test;

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();

		// Call init to register post type and taxonomy and enable sync functionality.
		Post_Type::init();
		Collection_Taxonomy::init();
	}

	/**
	 * Test creating a collection term when a post is created.
	 *
	 * @covers \Newspack\Collections\Sync::handle_post_save
	 * @covers \Newspack\Collections\Sync::create_linked_term
	 * @covers \Newspack\Collections\Collection_Taxonomy::find_term_by_name
	 */
	public function test_create_collection_term_from_post() {
		$post_id = $this->create_test_collection();
		$term_id = Sync::get_term_linked_to_collection( $post_id );

		$term = get_term( $term_id, Collection_Taxonomy::get_taxonomy() );
		$this->assertValidCollectionTerm( $term, 'Collection term should be created from post.' );
		$this->assertCollectionAndTermLinked( $post_id, $term_id );
	}

	/**
	 * Test that a term is marked as inactive when its post is trashed.
	 *
	 * @covers \Newspack\Collections\Sync::handle_post_trashed
	 * @covers \Newspack\Collections\Collection_Taxonomy::is_term_inactive
	 */
	public function test_term_marked_inactive_when_post_trashed() {
		$post_id = $this->create_test_collection();
		$term_id = Sync::get_term_linked_to_collection( $post_id );

		// Trash the post.
		wp_trash_post( $post_id );

		// Check that the term is marked as inactive.
		$this->assertTrue( Collection_Taxonomy::is_term_inactive( $term_id ), 'Term should be marked as inactive when post is trashed.' );

		// Check that the term is not returned in queries.
		$terms = get_terms(
			[
				'taxonomy'   => Collection_Taxonomy::get_taxonomy(),
				'hide_empty' => false,
			]
		);
		$this->assertNotContains( $term_id, wp_list_pluck( $terms, 'term_id' ), 'Inactive term should not be returned in queries.' );

		// Verify the term still exists.
		$term = get_term( $term_id, Collection_Taxonomy::get_taxonomy() );
		$this->assertNotNull( $term, 'Term should still exist when post is trashed.' );
	}

	/**
	 * Test that a term is reactivated when its post is untrashed.
	 *
	 * @covers \Newspack\Collections\Sync::handle_post_untrashed
	 * @covers \Newspack\Collections\Collection_Taxonomy::is_term_inactive
	 */
	public function test_term_reactivated_when_post_untrashed() {
		$post_id = $this->create_test_collection();
		$term_id = Sync::get_term_linked_to_collection( $post_id );

		// Trash the post.
		wp_trash_post( $post_id );

		// Untrash the post.
		wp_untrash_post( $post_id );

		// Check that the term is no longer inactive.
		$this->assertFalse( Collection_Taxonomy::is_term_inactive( $term_id ), 'Term should not be inactive when post is untrashed.' );

		// Check that the term is returned in queries.
		$terms = get_terms(
			[
				'taxonomy'   => Collection_Taxonomy::get_taxonomy(),
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		$this->assertContains( (int) $term_id, $terms, 'Reactivated term should be returned in queries.' );
	}

	/**
	 * Test that a term is deleted when its post is permanently deleted.
	 *
	 * @covers \Newspack\Collections\Sync::handle_post_deleted
	 */
	public function test_term_deleted_when_post_deleted() {
		$post_id = $this->create_test_collection();
		$term_id = Sync::get_term_linked_to_collection( $post_id );

		// Permanently delete the post.
		wp_delete_post( $post_id, true );

		// Check that the term is deleted.
		$term = get_term( $term_id, Collection_Taxonomy::get_taxonomy() );
		$this->assertNull( $term, 'Term should be deleted when post is permanently deleted.' );
	}

	/**
	 * Test updating a collection term when a post is updated.
	 *
	 * @covers \Newspack\Collections\Sync::handle_post_save
	 * @covers \Newspack\Collections\Sync::sync_collection_changes_to_term
	 */
	public function test_update_collection_term_from_post() {
		$post_id = $this->create_test_collection();
		$term_id = Sync::get_term_linked_to_collection( $post_id );

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Updated Post Title',
				'post_name'  => 'updated-post-slug',
			]
		);

		$updated_term = get_term( $term_id, Collection_Taxonomy::get_taxonomy() );
		$this->assertEquals( 'Updated Post Title', $updated_term->name, 'Term name should be updated.' );
		$this->assertEquals( 'updated-post-slug', $updated_term->slug, 'Term slug should be updated.' );
	}

	/**
	 * Test deleting a collection term when a post is deleted.
	 *
	 * @covers \Newspack\Collections\Sync::handle_post_deleted
	 */
	public function test_delete_collection_term_from_post() {
		$post_id = $this->create_test_collection();
		$term_id = Sync::get_term_linked_to_collection( $post_id );

		wp_delete_post( $post_id, true );

		$term = get_term( $term_id, Collection_Taxonomy::get_taxonomy() );
		$this->assertNull( $term, 'Term should be deleted when post is deleted.' );
	}

	/**
	 * Test restoring a collection term when a post is untrashed.
	 *
	 * @covers \Newspack\Collections\Sync::handle_post_untrashed
	 * @covers \Newspack\Collections\Sync::create_linked_term
	 */
	public function test_restore_collection_term_from_post() {
		$post_id = $this->create_test_collection();
		$term_id = Sync::get_term_linked_to_collection( $post_id );

		wp_trash_post( $post_id );
		wp_untrash_post( $post_id );

		$term_id = Sync::get_term_linked_to_collection( $post_id );
		$term    = get_term( $term_id, Collection_Taxonomy::get_taxonomy() );

		$this->assertValidCollectionTerm( $term, 'Term should be restored when post is untrashed.' );
		$this->assertCollectionAndTermLinked( $post_id, $term_id );
	}

	/**
	 * Test creating a collection post when a term is created.
	 *
	 * @covers \Newspack\Collections\Sync::handle_term_created
	 * @covers \Newspack\Collections\Sync::link_collection_and_term
	 */
	public function test_create_collection_post_from_term() {
		$term    = $this->create_test_collection_term();
		$post_id = Sync::get_collection_linked_to_term( $term->term_id );

		$this->assertValidCollection( $post_id, 'Collection post should be created from term.' );
		$this->assertCollectionAndTermLinked( $post_id, $term->term_id );
	}

	/**
	 * Test updating a collection post when a term is edited.
	 *
	 * @covers \Newspack\Collections\Sync::handle_term_edited
	 * @covers \Newspack\Collections\Sync::sync_term_changes_to_collection
	 */
	public function test_update_collection_post_from_term() {
		$term    = $this->create_test_collection_term();
		$post_id = Sync::get_collection_linked_to_term( $term->term_id );

		wp_update_term(
			$term->term_id,
			Collection_Taxonomy::get_taxonomy(),
			[
				'name' => 'Updated Term Name',
				'slug' => 'updated-term-slug',
			]
		);

		$updated_post = get_post( $post_id );
		$this->assertEquals( 'Updated Term Name', $updated_post->post_title, 'Post title should be updated.' );
		$this->assertEquals( 'updated-term-slug', $updated_post->post_name, 'Post slug should be updated.' );
	}

	/**
	 * Test trashing a collection post when a term is deleted.
	 *
	 * @covers \Newspack\Collections\Sync::handle_term_deleted
	 */
	public function test_trash_collection_post_from_term() {
		$term    = $this->create_test_collection_term();
		$post_id = Sync::get_collection_linked_to_term( $term->term_id );

		wp_delete_term( $term->term_id, Collection_Taxonomy::get_taxonomy() );

		$post = get_post( $post_id );
		$this->assertEquals( 'trash', $post->post_status, 'Post should be trashed when term is deleted.' );
	}
}
