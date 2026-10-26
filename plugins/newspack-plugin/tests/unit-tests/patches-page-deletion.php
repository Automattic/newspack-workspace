<?php
/**
 * Tests for the protected-page deletion guard in Newspack\Patches.
 *
 * @package Newspack\Tests
 */

/**
 * Patches::prevent_accidental_page_deletion(), a `map_meta_cap` filter.
 */
class Test_Patches_Page_Deletion extends WP_UnitTestCase {

	/**
	 * An administrator, set as the current user.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Log in as an administrator; the guard is about what even a full admin may
	 * not do.
	 */
	public function set_up() {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Make a page and set it as the static front page.
	 *
	 * @return int The page ID.
	 */
	private function create_front_page(): int {
		$front_page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_page_id );

		return $front_page_id;
	}

	/**
	 * The static front page can't be deleted, even by an administrator.
	 *
	 * Both spellings are asserted: wp_ajax_delete_page() and the XML-RPC
	 * wp_deletePage() ask for `delete_page`, so guarding only `delete_post` leaves
	 * those two routes able to delete the homepage. Editing is asserted alongside,
	 * because deletion is the only thing the guard may refuse.
	 */
	public function test_a_protected_page_cannot_be_deleted() {
		$front_page_id = $this->create_front_page();

		$this->assertFalse( current_user_can( 'delete_post', $front_page_id ) );
		$this->assertFalse( current_user_can( 'delete_page', $front_page_id ) );
		$this->assertTrue( current_user_can( 'edit_post', $front_page_id ) );
	}

	/**
	 * An ordinary page is untouched by the guard, under either spelling.
	 */
	public function test_an_unprotected_page_can_be_deleted() {
		$ordinary_page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->assertTrue( current_user_can( 'delete_post', $ordinary_page_id ) );
		$this->assertTrue( current_user_can( 'delete_page', $ordinary_page_id ) );
	}

	/**
	 * A protected page is refused whichever shape its ID arrives in.
	 *
	 * The comparison is strict against a list of ints, so a numeric string —
	 * which is what a request-borne ID is — would otherwise slip past the guard
	 * entirely. `current_user_can( 'delete_post', $post )` is also a legal call,
	 * and casting an object yields 1, so the object is unwrapped before the cast.
	 */
	public function test_a_protected_page_is_matched_however_the_id_is_passed() {
		$front_page_id = $this->create_front_page();

		$this->assertFalse( current_user_can( 'delete_post', get_post( $front_page_id ) ) );
		$this->assertFalse( current_user_can( 'delete_post', (string) $front_page_id ) );
	}

	/**
	 * A page protected by an option core does not special-case is still refused.
	 *
	 * Core maps deletion of `page_on_front` and `page_for_posts` to
	 * `manage_options` on its own, so a front-page assertion alone cannot show
	 * that this guard is the thing doing the refusing. The privacy policy page is
	 * a plain option with no such treatment.
	 */
	public function test_a_page_core_does_not_protect_is_refused_by_the_guard() {
		$privacy_policy_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( 'wp_page_for_privacy_policy', $privacy_policy_id );

		$this->assertFalse( current_user_can( 'delete_post', $privacy_policy_id ) );
		$this->assertFalse( current_user_can( 'delete_page', $privacy_policy_id ) );
		$this->assertTrue( current_user_can( 'edit_post', $privacy_policy_id ) );
	}

	/**
	 * The guard only inspects deletion capabilities.
	 *
	 * It runs on every meta capability check in the request, and `$args[0]` is a
	 * post ID only for post capabilities: for `edit_user` it is a user ID. Looking
	 * that up as a post costs a database query per check, which turns any admin
	 * screen that resolves edit links for a page of users into an N+1.
	 */
	public function test_a_non_deletion_capability_costs_no_post_lookup() {
		global $wpdb;
		$other_user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		// The measurement only means anything while the user ID is not also a post
		// ID: WP_Post::get_instance() caches hits but not misses, so if a post with
		// this ID existed the warm-up would cache it and the measured call would be
		// a cache hit whether the guard is there or not.
		$this->assertNull( get_post( $other_user_id ), 'Fixture precondition: the user ID must not also be a post ID.' );

		// Warm everything the capability check itself needs (roles, the user's
		// capabilities) so the measurement below sees only the guard's own cost.
		current_user_can( 'edit_user', $other_user_id );

		$queries_before = $wpdb->num_queries;
		current_user_can( 'edit_user', $other_user_id );

		$this->assertSame( $queries_before, $wpdb->num_queries );
	}
}
