<?php
/**
 * Tests for Status class.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\Fields;

use WP_UnitTestCase;
use WP_Term;

/**
 * Test Status class.
 */
class Test_Status extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();

		// Make sure the taxonomy is registered.
		Statuses::register_taxonomy();

		// Create test statuses.
		wp_insert_term(
			'Test Status',
			Statuses::TAXONOMY,
			[
				'slug' => 'test-status',
			]
		);

		$term = get_term_by( 'slug', 'test-status', Statuses::TAXONOMY );
		update_term_meta( $term->term_id, Statuses::CAPABILITY_META_KEY, '' );

		wp_insert_term(
			'Editor Status',
			Statuses::TAXONOMY,
			[
				'slug' => 'editor-status',
			]
		);

		$term = get_term_by( 'slug', 'editor-status', Statuses::TAXONOMY );
		update_term_meta( $term->term_id, Statuses::CAPABILITY_META_KEY, 'edit_others_posts' );
	}

	/**
	 * Test status creation from term.
	 */
	public function test_status_creation_from_term() {
		$term = get_term_by( 'slug', 'test-status', Statuses::TAXONOMY );
		$status = new Status( $term );

		$this->assertFalse( $status->has_errors() );
		$this->assertEquals( 'test-status', $status->get_slug() );
		$this->assertEquals( 'Test Status', $status->get_label() );
		$this->assertEquals( '', $status->get_required_capability() );
	}

	/**
	 * Test status creation from slug.
	 */
	public function test_status_creation_from_slug() {
		$status = new Status( 'test-status' );

		$this->assertFalse( $status->has_errors() );
		$this->assertEquals( 'test-status', $status->get_slug() );
		$this->assertEquals( 'Test Status', $status->get_label() );
	}

	/**
	 * Test status creation validation.
	 */
	public function test_status_validation() {
		// Test missing slug.
		$status = new Status( '' );
		$this->assertTrue( $status->has_errors() );
		$this->assertContains( 'Status slug is required.', $status->get_errors()->get_error_messages() );

		// Test invalid slug.
		$status = new Status( 'nonexistent-status' );
		$this->assertTrue( $status->has_errors() );
		$this->assertContains( 'Invalid status slug.', $status->get_errors()->get_error_messages() );
	}

	/**
	 * Test permission checking.
	 */
	public function test_permission_checking() {
		// Create test users.
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		$author_id = $this->factory->user->create( [ 'role' => 'author' ] );

		// Get status with capability requirement.
		$status = new Status( 'editor-status' );

		// Test editor can use the status.
		$this->assertTrue( $status->user_can( $editor_id ) );

		// Test author cannot use the status.
		$this->assertFalse( $status->user_can( $author_id ) );

		// Test caching - calling user_can() again should use cached result.
		$this->assertTrue( $status->user_can( $editor_id ) );
	}

	/**
	 * Test default permissions when no capability is required.
	 */
	public function test_default_permissions() {
		$status = new Status( 'test-status' );

		// Create test users.
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		$author_id = $this->factory->user->create( [ 'role' => 'author' ] );

		// Both users should be able to use the status.
		$this->assertTrue( $status->user_can( $editor_id ) );
		$this->assertTrue( $status->user_can( $author_id ) );
	}

	/**
	 * Test current_user_can method.
	 */
	public function test_current_user_can() {
		$status = new Status( 'editor-status' );

		// Set up a test user.
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$this->assertTrue( $status->current_user_can() );

		// Switch to a different user.
		$author_id = $this->factory->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author_id );
		$this->assertFalse( $status->current_user_can() );
	}

	/**
	 * Test to_array method.
	 */
	public function test_to_array() {
		$status = new Status( 'editor-status' );

		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$array = $status->to_array();
		$this->assertArrayHasKey( 'value', $array );
		$this->assertArrayHasKey( 'label', $array );
		$this->assertArrayHasKey( 'required_capability', $array );
		$this->assertArrayHasKey( 'user_can_apply', $array );
		$this->assertEquals( 'editor-status', $array['value'] );
		$this->assertEquals( 'Editor Status', $array['label'] );
		$this->assertEquals( 'edit_others_posts', $array['required_capability'] );
		$this->assertTrue( $array['user_can_apply'] );
	}
}
