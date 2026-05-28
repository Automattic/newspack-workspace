<?php
/**
 * Tests for Statuses class.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\Fields;

use WP_UnitTestCase;

/**
 * Test Statuses class.
 */
class Test_Statuses extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();

		// Make sure the taxonomy is registered.
		delete_option( 'np_story_budget_default_statuses_initialized' );
		Statuses::register_taxonomy();
	}

	/**
	 * Test taxonomy registration.
	 */
	public function test_register_taxonomy() {
		$this->assertTrue( taxonomy_exists( Statuses::TAXONOMY ) );

		// Check term meta registration.
		$registered_meta = get_registered_meta_keys( 'term', Statuses::TAXONOMY );
		$this->assertArrayHasKey( Statuses::CAPABILITY_META_KEY, $registered_meta );
	}

	/**
	 * Test default statuses registration.
	 */
	public function test_register_default_statuses() {
		// Force re-registration of default statuses.
		delete_option( 'np_story_budget_default_statuses_initialized' );
		Statuses::register_default_statuses();

		// Check that default statuses exist.
		$this->assertTrue( term_exists( 'writing', Statuses::TAXONOMY ) !== 0 && term_exists( 'writing', Statuses::TAXONOMY ) !== null );
		$this->assertTrue( term_exists( 'editing', Statuses::TAXONOMY ) !== 0 && term_exists( 'editing', Statuses::TAXONOMY ) !== null );
		$this->assertTrue( term_exists( 'factcheck', Statuses::TAXONOMY ) !== 0 && term_exists( 'factcheck', Statuses::TAXONOMY ) !== null );
		$this->assertTrue( term_exists( 'approved', Statuses::TAXONOMY ) !== 0 && term_exists( 'approved', Statuses::TAXONOMY ) !== null );
		$this->assertTrue( term_exists( 'published', Statuses::TAXONOMY ) !== 0 && term_exists( 'published', Statuses::TAXONOMY ) !== null );

		// Check that capabilities are set correctly.
		$factcheck_term = get_term_by( 'slug', 'factcheck', Statuses::TAXONOMY );
		$this->assertEquals( 'edit_others_posts', get_term_meta( $factcheck_term->term_id, Statuses::CAPABILITY_META_KEY, true ) );
	}

	/**
	 * Test getting all statuses.
	 */
	public function test_get_statuses() {
		$statuses = Statuses::get_statuses();

		// Check that we get an array of Status objects.
		$this->assertIsArray( $statuses );
		$this->assertContainsOnlyInstancesOf( Status::class, $statuses );

		// Check that we have all expected statuses.
		$status_slugs = array_map(
			function( $status ) {
				return $status->get_slug();
			},
			$statuses
		);

		$this->assertContains( 'writing', $status_slugs );
		$this->assertContains( 'editing', $status_slugs );
		$this->assertContains( 'factcheck', $status_slugs );
		$this->assertContains( 'approved', $status_slugs );
		$this->assertContains( 'published', $status_slugs );
	}

	/**
	 * Test getting statuses as arrays.
	 */
	public function test_get_statuses_arrays() {
		// Set up a test user.
		$editor = $this->factory->user->create_and_get(
			[
				'role' => 'editor',
			]
		);
		wp_set_current_user( $editor->ID );

		$arrays = Statuses::get_statuses_arrays();

		// Check array structure.
		$this->assertIsArray( $arrays );
		foreach ( $arrays as $status_array ) {
			$this->assertArrayHasKey( 'value', $status_array );
			$this->assertArrayHasKey( 'label', $status_array );
			$this->assertArrayHasKey( 'required_capability', $status_array );
			$this->assertArrayHasKey( 'user_can_apply', $status_array );
		}

		// Check that editor can use restricted statuses.
		$factcheck_statuses = array_filter(
			$arrays,
			function( $status ) {
				return $status['value'] === 'factcheck';
			}
		);

		// Make sure we found the factcheck status.
		$this->assertNotEmpty( $factcheck_statuses, 'Factcheck status not found in status arrays' );

		$factcheck_status = reset( $factcheck_statuses );
		$this->assertTrue( $factcheck_status['user_can_apply'] );

		// Switch to author and verify they can't use restricted statuses.
		$author = $this->factory->user->create_and_get(
			[
				'role' => 'author',
			]
		);
		wp_set_current_user( $author->ID );

		$arrays = Statuses::get_statuses_arrays();
		$factcheck_statuses = array_filter(
			$arrays,
			function( $status ) {
				return $status['value'] === 'factcheck';
			}
		);

		// Make sure we found the factcheck status.
		$this->assertNotEmpty( $factcheck_statuses, 'Factcheck status not found in status arrays' );

		$factcheck_status = reset( $factcheck_statuses );
		$this->assertFalse( $factcheck_status['user_can_apply'] );
	}

	/**
	 * Test get_status method.
	 */
	public function test_get_status() {
		// Test getting an existing status.
		$status = Statuses::get_status( 'writing' );
		$this->assertInstanceOf( Status::class, $status );
		$this->assertEquals( 'writing', $status->get_slug() );

		// Test getting a non-existent status.
		$status = Statuses::get_status( 'nonexistent' );
		$this->assertNull( $status );
	}

	/**
	 * Test post status methods.
	 */
	public function test_post_status_methods() {
		// Create a test post.
		$post_id = $this->factory->post->create();

		// Test setting a status.
		$result = Statuses::set_post_status( $post_id, 'writing' );
		$this->assertTrue( $result );

		// Test getting the status.
		$status = Statuses::get_post_status( $post_id );
		$this->assertInstanceOf( Status::class, $status );
		$this->assertEquals( 'writing', $status->get_slug() );

		// Test setting an invalid status.
		$result = Statuses::set_post_status( $post_id, 'nonexistent' );
		$this->assertInstanceOf( \WP_Error::class, $result );

		// Test permission checking.
		$author_id = $this->factory->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author_id );

		$result = Statuses::set_post_status( $post_id, 'factcheck' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'permission_denied', $result->get_error_code() );
	}
}
