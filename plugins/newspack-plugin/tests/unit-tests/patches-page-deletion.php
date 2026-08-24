<?php
/**
 * Tests for the protected-page deletion guard in Newspack\Patches.
 *
 * @package Newspack\Tests
 */

use Newspack\Patches;

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
	 * Restore the front-page settings the tests change.
	 */
	public function tear_down() {
		delete_option( 'page_on_front' );
		update_option( 'show_on_front', 'posts' );
		parent::tear_down();
	}

	/**
	 * The static front page can't be deleted, even by an administrator.
	 *
	 * Both spellings are asserted. Core's map_meta_cap() handles `delete_post` and
	 * `delete_page` in one branch, and the `page` post type's own delete_post
	 * capability is literally `delete_page` — the spelling wp_ajax_delete_page()
	 * and the XML-RPC wp_deletePage() ask for. Guarding only `delete_post` leaves
	 * those two routes able to delete the homepage.
	 */
	public function test_a_protected_page_cannot_be_deleted() {
		$front_page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_page_id );

		$this->assertFalse( current_user_can( 'delete_post', $front_page_id ) );
		$this->assertFalse( current_user_can( 'delete_page', $front_page_id ) );
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
	 * The guard only inspects deletion capabilities.
	 *
	 * It runs on every meta capability check in the request, and `$args[0]` is a
	 * post ID only for post capabilities — for `edit_user` it is a user ID. Looking
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

	/**
	 * The guard scopes itself to deletion and leaves every other capability alone.
	 *
	 * The query-count test above measures that the early bail is cheap; this states
	 * what it must not break. An early return inside a `map_meta_cap` filter is the
	 * kind of change whose blast radius is the capabilities it now skips, so those
	 * are asserted directly rather than left to a reasoning step in review — and
	 * unlike the query count, this cannot go green for the wrong reason if core
	 * changes how it caches post lookups.
	 */
	public function test_the_guard_only_scopes_itself_to_deletion() {
		$front_page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_page_id );

		$this->assertTrue( current_user_can( 'edit_post', $front_page_id ), 'A protected page can still be edited; only deleting it is refused.' );
		$this->assertTrue( current_user_can( 'delete_pages' ) );
		$this->assertTrue( current_user_can( 'delete_others_pages' ) );
		$this->assertTrue( current_user_can( 'delete_published_pages' ) );
	}

	/**
	 * Every ID the guard protects is a page.
	 *
	 * That is the premise the `delete_page` widening rests on: `delete_page` is the
	 * capability core generates for the `page` post type, so if get_protected_page_ids()
	 * ever returned a post of another type, the widening would not cover it and the
	 * comment explaining the widening would be wrong.
	 *
	 * WooCommerce is not loaded in this harness, so on a bare run this exercises the
	 * front and posts pages only — the seven WooCommerce IDs the list also returns are
	 * covered wherever the suite runs with WooCommerce active. Asserting the invariant
	 * rather than one hard-coded page is what makes that possible at all.
	 */
	public function test_every_protected_id_is_a_page() {
		$front_page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$posts_page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_page_id );
		update_option( 'page_for_posts', $posts_page_id );

		$protected_page_ids = Patches::get_protected_page_ids();

		$this->assertNotEmpty( $protected_page_ids, 'Fixture precondition: the guard has something to protect.' );
		foreach ( $protected_page_ids as $protected_page_id ) {
			$this->assertSame(
				'page',
				get_post_type( $protected_page_id ),
				sprintf( 'Protected ID %d is a page, so the delete_page capability covers it.', $protected_page_id )
			);
		}
	}
}
