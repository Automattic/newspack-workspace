<?php
/**
 * Tests for the Co_Authors_Plus_Count_User_Posts_Fix integration.
 *
 * The integration filters get_usernumposts so that a WP user with a linked
 * Co-Authors Plus guest author is credited with the count of distinct posts
 * across (post_author = user) UNION (attached to the GA's author term), rather
 * than CAP's default behavior of summing the two without deduplication.
 *
 * @package Newspack\Tests
 */

/**
 * Tests the CAP count_user_posts dedup filter.
 */
class Newspack_Test_CAP_Count_User_Posts_Fix extends WP_UnitTestCase {

	/**
	 * Register the CAP-owned post type and taxonomy before each test.
	 *
	 * CAP isn't loaded in the test environment, so we register just enough
	 * of its data model for the filter to exercise its lookups.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type(
			'guest-author',
			[
				'public'   => false,
				'supports' => [ 'title' ],
			]
		);
		register_taxonomy( 'author', 'post', [ 'public' => false ] );
	}

	/**
	 * Tear down the CAP-owned post type and taxonomy after each test.
	 */
	public function tear_down() {
		// Several tests set the current user to exercise the read_private_posts
		// gate; reset to 0 so viewer state can't leak into later tests.
		wp_set_current_user( 0 );
		unregister_taxonomy( 'author' );
		unregister_post_type( 'guest-author' );
		parent::tear_down();
	}

	/**
	 * Create a guest author CPT linked to a WP user and return its IDs.
	 *
	 * @param string $user_login The WP user's user_login value to link.
	 * @return array { 'ga_id' => int, 'term_id' => int }
	 */
	private function create_linked_ga( $user_login ) {
		$ga_id = self::factory()->post->create(
			[
				'post_type'   => 'guest-author',
				'post_status' => 'publish',
				'post_title'  => 'Guest ' . $user_login,
			]
		);
		update_post_meta( $ga_id, 'cap-linked_account', $user_login );
		update_post_meta( $ga_id, 'cap-user_login', $user_login );

		$term = wp_insert_term( 'cap-' . $user_login, 'author', [ 'slug' => 'cap-' . $user_login ] );
		wp_set_object_terms( $ga_id, [ $term['term_id'] ], 'author' );

		return [
			'ga_id'   => $ga_id,
			'term_id' => (int) $term['term_id'],
		];
	}

	/**
	 * Create a published post authored by a given user, optionally attached
	 * to an author term. Returns the post ID.
	 *
	 * @param int      $author_id The post_author user ID.
	 * @param int|null $term_id   Optional author term ID to attach.
	 * @return int
	 */
	private function create_authored_post( $author_id, $term_id = null ) {
		$post_id = self::factory()->post->create(
			[
				'post_author' => $author_id,
				'post_status' => 'publish',
				'post_type'   => 'post',
			]
		);
		if ( $term_id ) {
			wp_set_object_terms( $post_id, [ $term_id ], 'author' );
		}
		return $post_id;
	}

	/**
	 * Mirrors the MinnPost scenario: user's own post_author posts are 100%
	 * overlapped with posts attributed via the linked GA's author term.
	 * Without the fix, CAP returns (post_author count + GA term count) with
	 * no deduplication.
	 */
	public function test_dedups_count_with_full_overlap() {
		$user_id       = self::factory()->user->create( [ 'user_login' => 'testauthor1' ] );
		$other_user_id = self::factory()->user->create();
		$ga            = $this->create_linked_ga( 'testauthor1' );

		// 3 posts authored by the user, all tagged with the GA term.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_authored_post( $user_id, $ga['term_id'] );
		}
		// 2 posts authored by other staff, attached to the same GA term
		// (typical CAP editorial workflow where staff publishes under the columnist's byline).
		for ( $i = 0; $i < 2; $i++ ) {
			$this->create_authored_post( $other_user_id, $ga['term_id'] );
		}

		// Distinct posts attributed to the user: 3 own + 2 via GA term = 5.
		$this->assertEquals( 5, count_user_posts( $user_id ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * When the user has post_author posts that are NOT all tagged with the GA
	 * term, the dedup'd count is the union (user's posts plus GA-term posts
	 * authored by others), not a simple sum.
	 */
	public function test_dedups_count_with_partial_overlap() {
		$user_id       = self::factory()->user->create( [ 'user_login' => 'partial' ] );
		$other_user_id = self::factory()->user->create();
		$ga            = $this->create_linked_ga( 'partial' );

		// 3 user-authored posts; only 1 carries the GA term.
		$user_posts = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$user_posts[] = $this->create_authored_post( $user_id );
		}
		wp_set_object_terms( $user_posts[0], [ $ga['term_id'] ], 'author' );

		// 4 other-authored posts attached to the GA term.
		for ( $i = 0; $i < 4; $i++ ) {
			$this->create_authored_post( $other_user_id, $ga['term_id'] );
		}

		// Union: 3 (user's own) + 4 (others via GA term) = 7. The 1 overlap is naturally dedup'd.
		$this->assertEquals( 7, count_user_posts( $user_id ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * No linked guest author → the filter must no-op and return WP's default count.
	 */
	public function test_returns_original_count_when_no_linked_ga() {
		$user_id = self::factory()->user->create( [ 'user_login' => 'unlinked' ] );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->create_authored_post( $user_id );
		}
		$this->assertEquals( 4, count_user_posts( $user_id ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * A GA exists with a different linked_account → not this user's GA.
	 * Filter must no-op.
	 */
	public function test_no_op_when_ga_links_to_different_user() {
		$user_id        = self::factory()->user->create( [ 'user_login' => 'notlinked' ] );
		$linked_user_id = self::factory()->user->create( [ 'user_login' => 'isLinked' ] );

		// GA links to a different user.
		$ga = $this->create_linked_ga( 'isLinked' );

		// notlinked has 2 posts of their own; not attached to the other user's GA term.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->create_authored_post( $user_id );
		}
		// linkedUser has 3 posts attached to their GA term.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_authored_post( $linked_user_id, $ga['term_id'] );
		}

		// The filter must NOT pull in the other user's GA-term posts for this user.
		$this->assertEquals( 2, count_user_posts( $user_id ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * A linked GA exists but the GA term is attached to no posts other than the
	 * GA CPT itself. Result is just the user's own post_author count.
	 */
	public function test_returns_user_post_count_when_ga_term_empty() {
		$user_id = self::factory()->user->create( [ 'user_login' => 'lonely' ] );
		$this->create_linked_ga( 'lonely' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_authored_post( $user_id );
		}

		$this->assertEquals( 5, count_user_posts( $user_id ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * Respects the $public_only param — non-published posts excluded when true.
	 */
	public function test_respects_public_only_filter() {
		$user_id       = self::factory()->user->create( [ 'user_login' => 'drafts' ] );
		$other_user_id = self::factory()->user->create();
		$ga            = $this->create_linked_ga( 'drafts' );

		// 2 published, 1 draft for the user (all tagged with GA term).
		for ( $i = 0; $i < 2; $i++ ) {
			$this->create_authored_post( $user_id, $ga['term_id'] );
		}
		$draft = self::factory()->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'draft',
				'post_type'   => 'post',
			]
		);
		wp_set_object_terms( $draft, [ $ga['term_id'] ], 'author' );

		// 1 published other-authored post on the GA term.
		$this->create_authored_post( $other_user_id, $ga['term_id'] );

		// public_only = true → 2 + 1 = 3 distinct published. Drafts excluded.
		$this->assertEquals( 3, count_user_posts( $user_id, 'post', true ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * Drafts and trashed posts must NOT be counted even when the default $public_only=false
	 * is passed. WP core's count_user_posts and CAP's filter both restrict to publish (+ private)
	 * in this branch; including every registered status would inflate the Users-list column
	 * as drafts accumulate over time.
	 */
	public function test_excludes_drafts_and_trash_when_public_only_false() {
		$user_id       = self::factory()->user->create( [ 'user_login' => 'drafty' ] );
		$other_user_id = self::factory()->user->create();
		$ga            = $this->create_linked_ga( 'drafty' );

		// 2 published posts authored by the user, all tagged with the GA term.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->create_authored_post( $user_id, $ga['term_id'] );
		}
		// 1 draft authored by the user, also tagged with the GA term.
		$draft = self::factory()->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'draft',
				'post_type'   => 'post',
			]
		);
		wp_set_object_terms( $draft, [ $ga['term_id'] ], 'author' );
		// 1 trashed post authored by the user, also tagged with the GA term.
		$trash = self::factory()->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'trash',
				'post_type'   => 'post',
			]
		);
		wp_set_object_terms( $trash, [ $ga['term_id'] ], 'author' );
		// 1 published other-authored post on the GA term.
		$this->create_authored_post( $other_user_id, $ga['term_id'] );

		// Default $public_only=false should still exclude drafts/trash.
		// Distinct published attributed to the user: 2 + 1 = 3.
		$this->assertEquals( 3, count_user_posts( $user_id ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * Private posts must be included when $public_only=false AND the viewer can
	 * read private posts — matching WP core's get_posts_by_author_sql() capability
	 * gate. Here the viewer is an administrator, so private posts count.
	 */
	public function test_includes_private_posts_when_viewer_can_read_private() {
		$user_id  = self::factory()->user->create( [ 'user_login' => 'privposts' ] );
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$ga       = $this->create_linked_ga( 'privposts' );

		// 2 published, 1 private, all by the user and all tagged with the GA term.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->create_authored_post( $user_id, $ga['term_id'] );
		}
		$private = self::factory()->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'private',
				'post_type'   => 'post',
			]
		);
		wp_set_object_terms( $private, [ $ga['term_id'] ], 'author' );

		// Viewer can read private posts → publish + private = 3.
		wp_set_current_user( $admin_id );
		$this->assertEquals( 3, count_user_posts( $user_id ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * Private posts must be EXCLUDED when the viewer cannot read private posts,
	 * even with $public_only=false. WP core's get_posts_by_author_sql() only adds
	 * `post_status = 'private'` when the viewer has read_private_posts; an
	 * anonymous viewer (e.g. the public REST `users` endpoint) would otherwise see
	 * a private-inflated count where core returns the published-only figure.
	 */
	public function test_excludes_private_posts_when_viewer_cannot_read_private() {
		$user_id = self::factory()->user->create( [ 'user_login' => 'privhidden' ] );
		$ga      = $this->create_linked_ga( 'privhidden' );

		// 2 published, 1 private, all by the user and all tagged with the GA term.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->create_authored_post( $user_id, $ga['term_id'] );
		}
		$private = self::factory()->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'private',
				'post_type'   => 'post',
			]
		);
		wp_set_object_terms( $private, [ $ga['term_id'] ], 'author' );

		// Anonymous viewer (no read_private_posts) → private excluded, publish only = 2.
		wp_set_current_user( 0 );
		$this->assertEquals( 2, count_user_posts( $user_id ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * The capability gate is a conjunction: private posts are counted only when the
	 * viewer can read private posts for EVERY resolved type. Here an editor (who CAN
	 * read private `post`) counts across `['post', <cpt>]` where the CPT's
	 * read-private cap they lack — so private must be excluded for ALL types, not just
	 * the one that fails. Guards against a regression that loosens the gate to "any type".
	 */
	public function test_private_gate_requires_capability_for_all_resolved_types() {
		$user_id   = self::factory()->user->create( [ 'user_login' => 'multitype' ] );
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$ga        = $this->create_linked_ga( 'multitype' );

		// A CPT whose read-private capability the editor does NOT hold.
		register_post_type(
			'gated_thing',
			[
				'public'          => false,
				'capability_type' => 'gated_thing',
				'map_meta_cap'    => true,
			]
		);

		// 2 published + 1 private `post`, all by the user and tagged with the GA term.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->create_authored_post( $user_id, $ga['term_id'] );
		}
		$private = self::factory()->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'private',
				'post_type'   => 'post',
			]
		);
		wp_set_object_terms( $private, [ $ga['term_id'] ], 'author' );

		// Editor can read private `post` but NOT `gated_thing`; the conjunction fails,
		// so private is excluded across the union → 2 published only.
		wp_set_current_user( $editor_id );
		try {
			$this->assertEquals( 2, count_user_posts( $user_id, [ 'post', 'gated_thing' ] ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
		} finally {
			unregister_post_type( 'gated_thing' );
		}
	}

	/**
	 * Defensive guard: if the resolved post-type set comes up empty (e.g. a
	 * publisher filters `coauthors_count_published_post_types` down to []), the
	 * dedup query would build a malformed `post_type IN ()`. The filter must
	 * instead no-op and return the upstream count rather than silently yielding 0.
	 */
	public function test_returns_upstream_count_when_post_types_resolve_empty() {
		$user_id = self::factory()->user->create( [ 'user_login' => 'emptytypes' ] );
		$ga      = $this->create_linked_ga( 'emptytypes' );

		// 3 published posts authored by the user, all tagged with the GA term.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_authored_post( $user_id, $ga['term_id'] );
		}

		// Collapse the countable post types to an empty set.
		$filter = function () {
			return [];
		};
		add_filter( 'coauthors_count_published_post_types', $filter );

		try {
			// Guard returns the upstream count (3) instead of a malformed IN () → 0.
			$this->assertEquals( 3, count_user_posts( $user_id ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
		} finally {
			remove_filter( 'coauthors_count_published_post_types', $filter );
		}
	}

	/**
	 * WP_Query's 'any' post_type sentinel must be honored — `IN ('any')` would match
	 * zero rows. Caller in the wild: newspack-blocks Author List controller calls
	 * count_user_posts($id, ['any'], true) and drops authors with count=0 from the
	 * API response when exclude_empty is set.
	 */
	public function test_honors_any_post_type_sentinel() {
		$user_id = self::factory()->user->create( [ 'user_login' => 'manytype' ] );
		$ga      = $this->create_linked_ga( 'manytype' );

		// Author taxonomy needs to apply to pages for this test's fixtures.
		register_taxonomy_for_object_type( 'author', 'page' );

		// 2 published posts + 1 published page, all authored by the user and tagged with the GA term.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->create_authored_post( $user_id, $ga['term_id'] );
		}
		$page = self::factory()->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'publish',
				'post_type'   => 'page',
			]
		);
		wp_set_object_terms( $page, [ $ga['term_id'] ], 'author' );

		// 'any' should expand to all searchable post types (post + page here) and return 3.
		$this->assertEquals( 3, count_user_posts( $user_id, 'any' ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * The newspack-blocks Author List controller calls count_user_posts with the array form
	 * `['any']` (not the bare string). The filter must honor both forms.
	 */
	public function test_honors_array_form_any_sentinel() {
		$user_id = self::factory()->user->create( [ 'user_login' => 'arrayany' ] );
		$ga      = $this->create_linked_ga( 'arrayany' );

		register_taxonomy_for_object_type( 'author', 'page' );

		// 2 published posts + 1 published page, all authored by the user and tagged with the GA term.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->create_authored_post( $user_id, $ga['term_id'] );
		}
		$page = self::factory()->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'publish',
				'post_type'   => 'page',
			]
		);
		wp_set_object_terms( $page, [ $ga['term_id'] ], 'author' );

		// ['any'] (array form, public_only=true) — the call shape used by newspack-blocks Author List.
		$this->assertEquals( 3, count_user_posts( $user_id, [ 'any' ], true ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * Data-corruption / migration edge case: more than one guest author with
	 * `cap-linked_account` pointing at the same WP user. The filter must aggregate
	 * across ALL linked GAs (and their author terms), not silently pick one and
	 * undercount.
	 */
	public function test_dedups_across_multiple_linked_guest_authors() {
		$user_id       = self::factory()->user->create( [ 'user_login' => 'multilink' ] );
		$other_user_id = self::factory()->user->create();

		// GA #1 linked to the user.
		$ga1_id = self::factory()->post->create(
			[
				'post_type'   => 'guest-author',
				'post_status' => 'publish',
				'post_title'  => 'Multilink A',
			]
		);
		update_post_meta( $ga1_id, 'cap-linked_account', 'multilink' );
		update_post_meta( $ga1_id, 'cap-user_login', 'multilink-a' );
		$term1 = wp_insert_term( 'cap-multilink-a', 'author', [ 'slug' => 'cap-multilink-a' ] );
		wp_set_object_terms( $ga1_id, [ $term1['term_id'] ], 'author' );

		// GA #2 ALSO linked to the same user.
		$ga2_id = self::factory()->post->create(
			[
				'post_type'   => 'guest-author',
				'post_status' => 'publish',
				'post_title'  => 'Multilink B',
			]
		);
		update_post_meta( $ga2_id, 'cap-linked_account', 'multilink' );
		update_post_meta( $ga2_id, 'cap-user_login', 'multilink-b' );
		$term2 = wp_insert_term( 'cap-multilink-b', 'author', [ 'slug' => 'cap-multilink-b' ] );
		wp_set_object_terms( $ga2_id, [ $term2['term_id'] ], 'author' );

		// 1 post by the user, attached to term1.
		$this->create_authored_post( $user_id, $term1['term_id'] );
		// 1 post by other user, attached to term1 only.
		$this->create_authored_post( $other_user_id, $term1['term_id'] );
		// 1 post by other user, attached to term2 only — would be silently dropped if only term1 were aggregated.
		$this->create_authored_post( $other_user_id, $term2['term_id'] );

		// Distinct: 1 (user own + in term1) + 1 (other in term1) + 1 (other in term2) = 3.
		$this->assertEquals( 3, count_user_posts( $user_id ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
	}

	/**
	 * When the default 'post' is passed, the filter must honor CAP's
	 * `coauthors_count_published_post_types` hook so third-party CPT additions
	 * (e.g. newsletters) are included.
	 */
	public function test_applies_coauthors_count_published_post_types_filter() {
		$user_id = self::factory()->user->create( [ 'user_login' => 'expanded' ] );
		$ga      = $this->create_linked_ga( 'expanded' );

		register_post_type( 'newsletter', [ 'public' => false ] );
		register_taxonomy_for_object_type( 'author', 'newsletter' );

		// 1 'post' + 1 'newsletter', both by the user, both tagged with the GA term.
		$this->create_authored_post( $user_id, $ga['term_id'] );
		$nl = self::factory()->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'publish',
				'post_type'   => 'newsletter',
			]
		);
		wp_set_object_terms( $nl, [ $ga['term_id'] ], 'author' );

		$filter = function ( $types ) {
			return array_merge( $types, [ 'newsletter' ] );
		};
		add_filter( 'coauthors_count_published_post_types', $filter );

		try {
			// Default 'post' + the filter should include 'newsletter' → 2 distinct.
			$this->assertEquals( 2, count_user_posts( $user_id ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
		} finally {
			remove_filter( 'coauthors_count_published_post_types', $filter );
			unregister_post_type( 'newsletter' );
		}
	}
}
