<?php
/**
 * Test Newspack Network Incoming Post Inserted functionality.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests;

use WP_UnitTestCase;
use Newspack\Bylines;

/**
 * Test class for Newspack Network Incoming Post Inserted functionality.
 */
class Test_Newspack_Network_Incoming_Post_Inserted extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		Bylines::register_post_meta();
	}

	/**
	 * Test that method does nothing when post is not linked.
	 */
	public function test_does_nothing_when_post_not_linked() {
		$post_id = $this->factory->post->create();
		$byline = '[Author id=1]John Doe[/Author] and [Author id=2]Jane Smith[/Author]';

		update_post_meta( $post_id, Bylines::META_KEY_BYLINE, $byline );
		update_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );

		$original_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );

		Bylines::newspack_network_incoming_post_inserted( $post_id, false, [] );

		$updated_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );
		$this->assertEquals( $original_byline, $updated_byline );
	}

	/**
	 * Test that method does nothing when byline is empty.
	 */
	public function test_does_nothing_when_byline_empty() {
		$post_id = $this->factory->post->create();

		update_post_meta( $post_id, Bylines::META_KEY_BYLINE, '' );
		update_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );

		Bylines::newspack_network_incoming_post_inserted( $post_id, true, [] );

		$updated_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );
		$this->assertEquals( '', $updated_byline );
	}

		/**
		 * Test that method removes all shortcodes when mapping is empty.
		 */
	public function test_removes_all_shortcodes_when_mapping_empty() {
		$post_id = $this->factory->post->create();
		$byline = '[Author id=1]John Doe[/Author] and [Author id=2]Jane Smith[/Author]';

		update_post_meta( $post_id, Bylines::META_KEY_BYLINE, $byline );
		update_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );

		Bylines::newspack_network_incoming_post_inserted( $post_id, true, [] );

		$expected_byline = 'John Doe and Jane Smith'; // All shortcodes should be removed.
		$updated_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );
		$this->assertEquals( $expected_byline, $updated_byline );
	}

	/**
	 * Test successful ID replacement when author exists locally.
	 */
	public function test_successful_id_replacement_when_author_exists() {
		$post_id = $this->factory->post->create();
		$local_user = $this->factory->user->create(
			[
				'user_email' => 'john@example.com',
			]
		);

		$byline = '[Author id=1]John Doe[/Author]';
		$mapping = [ '1' => 'john@example.com' ];

		update_post_meta( $post_id, Bylines::META_KEY_BYLINE, $byline );
		update_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( $post_id, '_newspack_byline_network_authors', wp_json_encode( $mapping ) );

		Bylines::newspack_network_incoming_post_inserted( $post_id, true, [] );

		$expected_byline = sprintf( '[Author id=%d]John Doe[/Author]', $local_user );
		$updated_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );
		$this->assertEquals( $expected_byline, $updated_byline );
	}

	/**
	 * Test shortcode removal when author ID not in mapping.
	 */
	public function test_shortcode_removal_when_author_id_not_in_mapping() {
		$post_id = $this->factory->post->create();
		$local_user = $this->factory->user->create(
			[
				'user_email' => 'john@example.com',
			]
		);

		$byline = '[Author id=1]John Doe[/Author] and [Author id=2]Jane Smith[/Author]';
		$mapping = [ '1' => 'john@example.com' ]; // Only author 1 is in mapping.

		update_post_meta( $post_id, Bylines::META_KEY_BYLINE, $byline );
		update_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( $post_id, '_newspack_byline_network_authors', wp_json_encode( $mapping ) );

		Bylines::newspack_network_incoming_post_inserted( $post_id, true, [] );

		// Author 1 should be replaced with local user ID, author 2 should be stripped to just name.
		$expected_byline = sprintf( '[Author id=%d]John Doe[/Author] and Jane Smith', $local_user );
		$updated_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );
		$this->assertEquals( $expected_byline, $updated_byline );
	}

	/**
	 * Test shortcode removal when author in mapping but no local user found.
	 */
	public function test_shortcode_removal_when_author_in_mapping_but_no_local_user() {
		$post_id = $this->factory->post->create();

		$byline = '[Author id=1]John Doe[/Author]';
		$mapping = [ '1' => 'nonexistent@example.com' ]; // Email doesn't exist locally.

		update_post_meta( $post_id, Bylines::META_KEY_BYLINE, $byline );
		update_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( $post_id, '_newspack_byline_network_authors', wp_json_encode( $mapping ) );

		Bylines::newspack_network_incoming_post_inserted( $post_id, true, [] );

		$expected_byline = 'John Doe'; // Shortcode should be removed, only name remains.
		$updated_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );
		$this->assertEquals( $expected_byline, $updated_byline );
	}

	/**
	 * Test mixed scenario with multiple authors.
	 */
	public function test_mixed_scenario_with_multiple_authors() {
		$post_id = $this->factory->post->create();
		$local_user1 = $this->factory->user->create(
			[
				'user_email' => 'john@example.com',
			]
		);
		$local_user2 = $this->factory->user->create(
			[
				'user_email' => 'jane@example.com',
			]
		);

		$byline = '[Author id=1]John Doe[/Author], [Author id=2]Jane Smith[/Author], and [Author id=3]Bob Wilson[/Author]';
		$mapping = [
			'1' => 'john@example.com',     // Exists locally.
			'2' => 'jane@example.com',     // Exists locally.
			// Author 3 not in mapping.
		];

		update_post_meta( $post_id, Bylines::META_KEY_BYLINE, $byline );
		update_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( $post_id, '_newspack_byline_network_authors', wp_json_encode( $mapping ) );

		Bylines::newspack_network_incoming_post_inserted( $post_id, true, [] );

		$expected_byline = sprintf(
			'[Author id=%d]John Doe[/Author], [Author id=%d]Jane Smith[/Author], and Bob Wilson',
			$local_user1,
			$local_user2
		);
		$updated_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );
		$this->assertEquals( $expected_byline, $updated_byline );
	}

	/**
	 * Test complex scenario with authors in mapping but no local users.
	 */
	public function test_complex_scenario_authors_in_mapping_but_no_local_users() {
		$post_id = $this->factory->post->create();

		$byline = '[Author id=1]John Doe[/Author] and [Author id=2]Jane Smith[/Author]';
		$mapping = [
			'1' => 'nonexistent1@example.com', // Doesn't exist locally.
			'2' => 'nonexistent2@example.com', // Doesn't exist locally.
		];

		update_post_meta( $post_id, Bylines::META_KEY_BYLINE, $byline );
		update_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( $post_id, '_newspack_byline_network_authors', wp_json_encode( $mapping ) );

		Bylines::newspack_network_incoming_post_inserted( $post_id, true, [] );

		$expected_byline = 'John Doe and Jane Smith'; // Both shortcodes should be removed.
		$updated_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );
		$this->assertEquals( $expected_byline, $updated_byline );
	}

	/**
	 * Test byline with no author shortcodes.
	 */
	public function test_byline_with_no_author_shortcodes() {
		$post_id = $this->factory->post->create();

		$byline = 'By Staff Reporter';
		$mapping = [ '1' => 'john@example.com' ];

		update_post_meta( $post_id, Bylines::META_KEY_BYLINE, $byline );
		update_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( $post_id, '_newspack_byline_network_authors', wp_json_encode( $mapping ) );

		Bylines::newspack_network_incoming_post_inserted( $post_id, true, [] );

		$updated_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );
		$this->assertEquals( $byline, $updated_byline ); // Should remain unchanged.
	}

		/**
		 * Test byline with malformed shortcodes.
		 */
	public function test_byline_with_malformed_shortcodes() {
		$post_id = $this->factory->post->create();

		$byline = '[Author id=1]John Doe and [Author id=2]Jane Smith[/Author]'; // Malformed - missing closing tag for author 1.
		$mapping = [ '1' => 'john@example.com' ];

		update_post_meta( $post_id, Bylines::META_KEY_BYLINE, $byline );
		update_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( $post_id, '_newspack_byline_network_authors', wp_json_encode( $mapping ) );

		Bylines::newspack_network_incoming_post_inserted( $post_id, true, [] );

		// The regex treats the entire string as one malformed shortcode since there's only one closing tag.
		// It matches: [Author id=1]John Doe and [Author id=2]Jane Smith[/Author]
		// Since author ID 1 is in mapping but no local user found, it returns just the content.
		$expected_byline = 'John Doe and [Author id=2]Jane Smith'; // The entire content becomes the author name.
		$updated_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );
		$this->assertEquals( $expected_byline, $updated_byline );
	}

	/**
	 * Test byline with non-numeric author IDs.
	 */
	public function test_byline_with_non_numeric_author_ids() {
		$post_id = $this->factory->post->create();

		$byline = '[Author id=abc]John Doe[/Author]'; // Non-numeric ID.
		$mapping = [ 'abc' => 'john@example.com' ];

		update_post_meta( $post_id, Bylines::META_KEY_BYLINE, $byline );
		update_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( $post_id, '_newspack_byline_network_authors', wp_json_encode( $mapping ) );

		Bylines::newspack_network_incoming_post_inserted( $post_id, true, [] );

		// Should remain unchanged since regex only matches numeric IDs.
		$updated_byline = get_post_meta( $post_id, Bylines::META_KEY_BYLINE, true );
		$this->assertEquals( $byline, $updated_byline );
	}
}
