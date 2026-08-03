<?php
/**
 * Tests for restricting gated content in RSS feeds.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Gate_Advanced_Settings;
use Newspack\Content_Gate\IP_Access_Rule;
use Newspack\Newsletters_Access;

/**
 * Tests that the "Restrict content in feeds" advanced setting keeps gated
 * content out of RSS feeds, where Content_Gate::restrict_post() never runs
 * (it bails on `! is_singular()`), so the feed filters are the only guard.
 *
 * @group content-gate
 */
class Test_Feed_Restriction extends \WP_UnitTestCase {

	use \Newspack\Tests\Content_Gate\Traits\Trait_Restriction_Cache_Test;

	/**
	 * Gated post content: five distinct paragraphs so we can assert which
	 * survive truncation (the default visible_paragraphs is 2).
	 */
	const POST_CONTENT = '<!-- wp:paragraph --><p>FREE_ONE paragraph one.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>FREE_TWO paragraph two.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>PAID_THREE paragraph three.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>PAID_FOUR paragraph four.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>PAID_FIVE paragraph five.</p><!-- /wp:paragraph -->';

	/**
	 * Gated post ID.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Gate ID.
	 *
	 * @var int
	 */
	private $gate_id;

	/**
	 * Enable the Content Gates feature flag for this class only.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Set up a published registration-mode gate restricting all posts, plus a
	 * gated post, consumed as an anonymous reader with restrict_feeds enabled.
	 */
	public function set_up() {
		parent::set_up();

		$this->gate_id = Content_Gate::create_gate( [ 'title' => 'Feed Gate' ] );
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'title'         => 'Feed Gate',
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'               => true,
					'metering'             => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'require_verification' => false,
					'gate_id'              => 0,
				],
			]
		);

		$this->post_id = $this->factory->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => self::POST_CONTENT,
			]
		);

		// Feeds are consumed anonymously.
		wp_set_current_user( 0 );
		update_option( Content_Gate_Advanced_Settings::OPTION_PREFIX . 'restrict_feeds', 1, false );
		Content_Gate_Advanced_Settings::reset_cache();
	}

	/**
	 * Set the stored feed restriction mode and clear the settings cache.
	 *
	 * @param string $mode One of 'truncate' or 'exclude'.
	 */
	private function set_feed_mode( $mode ) {
		update_option( Content_Gate_Advanced_Settings::OPTION_PREFIX . 'feed_restriction_mode', $mode, false );
		Content_Gate_Advanced_Settings::reset_cache();
	}

	/**
	 * Teardown after tests.
	 */
	public function tear_down() {
		foreach ( Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		wp_delete_post( $this->post_id, true );
		$this->reset_restriction_cache();

		// Restore the global state these tests mutate so they can't leak into
		// other (RSS/feed) suites and cause order-dependent failures.
		delete_option( Content_Gate_Advanced_Settings::OPTION_PREFIX . 'restrict_feeds' );
		delete_option( Content_Gate_Advanced_Settings::OPTION_PREFIX . 'feed_restriction_mode' );
		delete_option( 'rss_use_excerpt' );
		delete_option( 'posts_per_rss' );
		Content_Gate_Advanced_Settings::reset_cache();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Render the gated post through a real feed loop and return the value the
	 * given callback produces for it. Resets global post data afterwards via
	 * wp_reset_postdata().
	 *
	 * @param callable $render Runs inside the loop with the gated post set up,
	 *                         and returns the feed string for that post.
	 *
	 * @return string
	 */
	private function render_in_feed_loop( callable $render ) {
		$this->go_to( get_feed_link( 'rss2' ) );
		$this->assertTrue( is_feed(), 'Request should be a feed.' );

		$result = '';
		ob_start();
		while ( have_posts() ) {
			the_post();
			if ( get_the_ID() === $this->post_id ) {
				$result = $render();
			}
		}
		ob_end_clean();
		wp_reset_postdata();

		return $result;
	}

	/**
	 * Run the RSS feed query and return the IDs of the posts it yields, so the
	 * "exclude" mode can be asserted at the query level (the restricted post is
	 * dropped from the feed, not merely blanked).
	 *
	 * @return int[]
	 */
	private function feed_post_ids() {
		$this->go_to( get_feed_link( 'rss2' ) );
		$this->assertTrue( is_feed(), 'Request should be a feed.' );

		$ids = [];
		while ( have_posts() ) {
			the_post();
			$ids[] = get_the_ID();
		}
		wp_reset_postdata();

		return $ids;
	}

	/**
	 * Sanity check: the gate restricts the post for an anonymous reader, so the
	 * feed filters have something to act on.
	 */
	public function test_post_is_restricted_for_anonymous() {
		$this->assertTrue(
			(bool) apply_filters( 'newspack_is_post_restricted', false, $this->post_id ),
			'Gated post should be restricted for an anonymous reader.'
		);
	}

	/**
	 * Full-text feed (rss_use_excerpt=0): <content:encoded> is rendered via
	 * get_the_content_feed(), and must not leak the paid paragraphs.
	 */
	public function test_full_text_feed_content_is_truncated() {
		$this->set_feed_mode( 'truncate' );
		update_option( 'rss_use_excerpt', 0 );

		$feed_content = $this->render_in_feed_loop(
			function () {
				return get_the_content_feed( 'rss2' );
			}
		);

		$this->assertStringContainsString( 'FREE_ONE', $feed_content, 'Free preview should be present in feed content.' );
		$this->assertStringNotContainsString( 'PAID_THREE', $feed_content, 'Paid paragraph 3 must not leak into full-text feed content.' );
		$this->assertStringNotContainsString( 'PAID_FIVE', $feed_content, 'Paid paragraph 5 must not leak into full-text feed content.' );
	}

	/**
	 * Excerpt feed (rss_use_excerpt=1): <description> is rendered via
	 * the_excerpt_rss, and must not leak the paid paragraphs.
	 */
	public function test_excerpt_feed_is_truncated() {
		$this->set_feed_mode( 'truncate' );
		update_option( 'rss_use_excerpt', 1 );

		$feed_excerpt = $this->render_in_feed_loop(
			function () {
				return apply_filters( 'the_excerpt_rss', get_the_excerpt() );
			}
		);

		// Positive assertion guards against a false negative: if the loop failed
		// to capture the post and returned an empty string, the "not contains"
		// checks alone would still pass.
		$this->assertStringContainsString( 'FREE_ONE', $feed_excerpt, 'Free preview should be present in feed excerpt.' );
		$this->assertStringNotContainsString( 'PAID_THREE', $feed_excerpt, 'Paid paragraph 3 must not leak into feed excerpt.' );
		$this->assertStringNotContainsString( 'PAID_FIVE', $feed_excerpt, 'Paid paragraph 5 must not leak into feed excerpt.' );
	}

	/**
	 * When the setting is off, the feed is left untouched: the filters become a
	 * no-op and the full content flows through.
	 */
	public function test_full_content_flows_when_setting_disabled() {
		update_option( Content_Gate_Advanced_Settings::OPTION_PREFIX . 'restrict_feeds', 0, false );
		Content_Gate_Advanced_Settings::reset_cache();
		update_option( 'rss_use_excerpt', 0 );

		$feed_content = $this->render_in_feed_loop(
			function () {
				return get_the_content_feed( 'rss2' );
			}
		);

		$this->assertStringContainsString( 'PAID_FIVE', $feed_content, 'With restrict_feeds off, full content should flow into the feed.' );
	}

	/**
	 * Default mode (no stored value) is "exclude" for WC Memberships parity: a
	 * restricted post is dropped from the feed query entirely.
	 */
	public function test_default_mode_excludes_restricted_post_from_feed() {
		$this->assertSame(
			'exclude',
			Content_Gate_Advanced_Settings::get_feed_restriction_mode(),
			'Default feed restriction mode should be exclude.'
		);
		$this->assertNotContains(
			$this->post_id,
			$this->feed_post_ids(),
			'Restricted post should be absent from the feed in exclude mode.'
		);
	}

	/**
	 * Exclude mode drops only restricted posts: an unrestricted post published
	 * alongside the gated one survives in the same feed. Guards against the
	 * filter being a blunt "empty the feed" rather than a selective drop. The
	 * second post is made accessible via the same `newspack_is_post_restricted`
	 * contract the exclude filter consults.
	 */
	public function test_exclude_mode_drops_only_restricted_posts() {
		$free_post_id = $this->factory->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => self::POST_CONTENT,
			]
		);
		$grant_access = function ( $restricted, $post_id ) use ( $free_post_id ) {
			return $post_id === $free_post_id ? false : $restricted;
		};
		add_filter( 'newspack_is_post_restricted', $grant_access, 99, 2 );

		$feed_ids = $this->feed_post_ids();

		remove_filter( 'newspack_is_post_restricted', $grant_access, 99 );
		wp_delete_post( $free_post_id, true );

		$this->assertContains( $free_post_id, $feed_ids, 'Unrestricted post should survive exclude mode.' );
		$this->assertNotContains( $this->post_id, $feed_ids, 'Restricted post should be dropped in exclude mode.' );
	}

	/**
	 * Exclude mode back-fills the feed to `posts_per_rss` with older unrestricted
	 * posts, matching WC Memberships. The newest posts are all restricted, so
	 * without over-fetching the first page would be empty; with it, the feed
	 * reaches past them and is trimmed back to the requested length (proving both
	 * the back-fill and the trim, since more free posts exist than the target).
	 */
	public function test_exclude_mode_backfills_feed_to_requested_length() {
		update_option( 'posts_per_rss', 3 );

		// set_up()'s $this->post_id defaults to the current time, so it sorts
		// newest of all (after the dated fixtures below) and is restricted — it is
		// over-fetched then dropped, never displacing a free post into the window.

		// Five older unrestricted posts (dates ascending, all before the gated ones).
		$free_ids = [];
		foreach ( range( 1, 5 ) as $day ) {
			$free_ids[] = $this->factory->post->create(
				[
					'post_status'  => 'publish',
					'post_date'    => sprintf( '2020-01-%02d 00:00:00', $day ),
					'post_content' => self::POST_CONTENT,
				]
			);
		}
		// Three newest posts, all restricted (dated after every free post).
		$restricted_ids = [];
		foreach ( range( 1, 3 ) as $day ) {
			$restricted_ids[] = $this->factory->post->create(
				[
					'post_status'  => 'publish',
					'post_date'    => sprintf( '2021-01-%02d 00:00:00', $day ),
					'post_content' => self::POST_CONTENT,
				]
			);
		}

		$grant_access = function ( $restricted, $post_id ) use ( $free_ids ) {
			return in_array( $post_id, $free_ids, true ) ? false : $restricted;
		};
		add_filter( 'newspack_is_post_restricted', $grant_access, 99, 2 );

		$feed_ids   = $this->feed_post_ids();
		$feed_query = $GLOBALS['wp_query'];

		remove_filter( 'newspack_is_post_restricted', $grant_access, 99 );
		foreach ( array_merge( $free_ids, $restricted_ids ) as $id ) {
			wp_delete_post( $id, true );
		}

		$this->assertCount( 3, $feed_ids, 'Feed should be back-filled and trimmed to posts_per_rss.' );
		$this->assertSame( 3, (int) $feed_query->get( 'posts_per_rss' ), 'The trimmed page must leave posts_per_rss describing the feed actually served.' );
		$this->assertSame( 3, (int) $feed_query->get( 'posts_per_page' ), 'Core copies posts_per_rss into posts_per_page for feeds, so both must be restored.' );
		// The three newest free posts (days 5, 4, 3) fill it; the two oldest do not.
		$this->assertEqualsCanonicalizing(
			array_slice( array_reverse( $free_ids ), 0, 3 ),
			$feed_ids,
			'Back-fill should take the newest unrestricted posts, trimmed to length.'
		);
	}

	/**
	 * Run the feed and return the inflated posts_per_rss that
	 * overfetch_restricted_feed() set on the main query, captured by a
	 * later-priority pre_get_posts hook.
	 *
	 * @return int|null
	 */
	private function captured_overfetch() {
		$captured = null;
		$capture  = function ( $query ) use ( &$captured ) {
			if ( $query->is_feed() && $query->is_main_query() ) {
				$captured = (int) $query->get( 'posts_per_rss' );
			}
		};
		// overfetch_restricted_feed() runs at PHP_INT_MAX; registering the capture
		// at the same priority but later means it runs after the over-fetch.
		add_action( 'pre_get_posts', $capture, PHP_INT_MAX );
		$this->feed_post_ids();
		remove_action( 'pre_get_posts', $capture, PHP_INT_MAX );

		return $captured;
	}

	/**
	 * The over-fetch is capped at FEED_OVERFETCH_MAX so a large posts_per_rss (or
	 * multiplier) can't blow up the feed query.
	 */
	public function test_overfetch_is_capped() {
		update_option( 'posts_per_rss', 30 ); // 30 * default multiplier 5 = 150, over the 100 cap.

		$this->assertSame(
			Content_Gate_Advanced_Settings::FEED_OVERFETCH_MAX,
			$this->captured_overfetch(),
			'Over-fetch should be capped at FEED_OVERFETCH_MAX.'
		);
	}

	/**
	 * The over-fetch multiplier is filterable via
	 * newspack_content_gate_feed_overfetch_multiplier.
	 */
	public function test_overfetch_multiplier_is_filterable() {
		update_option( 'posts_per_rss', 4 );
		$triple = function () {
			return 3;
		};
		add_filter( 'newspack_content_gate_feed_overfetch_multiplier', $triple );

		$captured = $this->captured_overfetch();

		remove_filter( 'newspack_content_gate_feed_overfetch_multiplier', $triple );

		$this->assertSame( 12, $captured, 'Multiplier filter should scale the over-fetch (4 * 3).' );
	}

	/**
	 * Exclusion is scoped to feed queries: the `the_posts` filter must leave a
	 * normal (non-feed) query untouched so the restricted post still appears in
	 * front-end listings (where the gate is applied on click), not silently
	 * vanishing site-wide.
	 */
	public function test_exclude_mode_does_not_affect_non_feed_queries() {
		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Test fixture; the case seeds a handful of posts.
			]
		);
		$ids = wp_list_pluck( $query->posts, 'ID' );
		wp_reset_postdata();

		$this->assertFalse( $query->is_feed(), 'Sanity: this is not a feed query.' );
		$this->assertContains( $this->post_id, $ids, 'Restricted post must remain in non-feed queries under exclude mode.' );
	}

	/**
	 * The exclude filter is a no-op on comment feeds: WP queries the comments
	 * from $posts[0] before `the_posts` runs, so dropping the post there would
	 * not restrict anything and would only blank the feed's title/link.
	 */
	public function test_exclude_mode_skips_comment_feeds() {
		$this->go_to( get_feed_link( 'comments_rss2' ) );
		$this->assertTrue( $GLOBALS['wp_query']->is_comment_feed(), 'Sanity: should be a comment feed.' );

		$posts    = [ get_post( $this->post_id ) ];
		$filtered = Content_Gate_Advanced_Settings::exclude_restricted_posts_from_feed( $posts, $GLOBALS['wp_query'] );
		wp_reset_postdata();

		$this->assertSame( $posts, $filtered, 'Comment feeds must be left untouched by the exclude filter.' );
	}

	/**
	 * A filter that returns an unrecognized mode fails closed: it is ignored in
	 * favour of the resolved mode (exclude, by default) rather than disabling
	 * restriction and leaking full content to the feed.
	 */
	public function test_invalid_filter_return_falls_back_to_resolved_mode() {
		$garbage_mode = function () {
			return 'not-a-real-mode';
		};
		add_filter( 'newspack_content_gate_feed_restriction_mode', $garbage_mode );

		$feed_ids = $this->feed_post_ids();

		remove_filter( 'newspack_content_gate_feed_restriction_mode', $garbage_mode );

		$this->assertNotContains( $this->post_id, $feed_ids, 'An invalid filter return must fall back to the resolved exclude mode, not leak content.' );
	}

	/**
	 * Truncate mode keeps the restricted post in the feed (body blanked, item
	 * still listed) — the counterpart that makes the exclude-mode absence above
	 * a meaningful assertion rather than an empty feed.
	 */
	public function test_truncate_mode_keeps_restricted_post_in_feed() {
		$this->set_feed_mode( 'truncate' );
		$this->assertContains(
			$this->post_id,
			$this->feed_post_ids(),
			'Restricted post should remain listed in the feed in truncate mode.'
		);
	}

	/**
	 * The newspack_content_gate_feed_restriction_mode filter can make a feed more
	 * restrictive than the stored setting: stored truncate, filtered to exclude.
	 */
	public function test_filter_can_force_exclude_over_stored_truncate() {
		$this->set_feed_mode( 'truncate' );
		$force_exclude = function () {
			return 'exclude';
		};
		add_filter( 'newspack_content_gate_feed_restriction_mode', $force_exclude );

		$feed_ids = $this->feed_post_ids();

		remove_filter( 'newspack_content_gate_feed_restriction_mode', $force_exclude );

		$this->assertNotContains( $this->post_id, $feed_ids, 'Filter should be able to force exclude over a stored truncate mode.' );
	}

	/**
	 * The filter can also exempt a feed entirely by returning "off", leaving the
	 * full premium body in the feed even though restrict_feeds is on.
	 */
	public function test_filter_can_force_off_to_leak_full_content() {
		update_option( 'rss_use_excerpt', 0 );
		$force_off = function () {
			return 'off';
		};
		add_filter( 'newspack_content_gate_feed_restriction_mode', $force_off );

		$feed_content = $this->render_in_feed_loop(
			function () {
				return get_the_content_feed( 'rss2' );
			}
		);

		remove_filter( 'newspack_content_gate_feed_restriction_mode', $force_off );

		$this->assertStringContainsString( 'PAID_FIVE', $feed_content, 'Filtering the mode to off should leave full content in the feed.' );
	}

	/**
	 * The over-fetch honours a later pre_get_posts writer that raises
	 * posts_per_rss (e.g. the RSS-Enhancements module's configured item count):
	 * because overfetch_restricted_feed() runs at PHP_INT_MAX it reads the raised
	 * length, so the feed is trimmed to the partner length (6), not the stale
	 * default (3) — even when nothing is restricted. Regression guard for the
	 * cross-module interaction where the trim target was captured too early.
	 */
	public function test_overfetch_target_reflects_later_pre_get_posts_writer() {
		update_option( 'posts_per_rss', 3 );

		// A partner-feed modifier raising posts_per_rss on the default priority,
		// registered after content-gate's own load-time callback.
		$raise_feed_length = function ( $query ) {
			if ( $query->is_feed() && $query->is_main_query() ) {
				$query->set( 'posts_per_rss', 6 );
			}
		};
		add_action( 'pre_get_posts', $raise_feed_length, 10 );

		// Nothing is restricted, so the exclude filter drops no items and the feed
		// should come back at the full partner length.
		$grant_all = function () {
			return false;
		};
		add_filter( 'newspack_is_post_restricted', $grant_all, 99 );

		$free_ids = [];
		foreach ( range( 1, 7 ) as $day ) {
			$free_ids[] = $this->factory->post->create(
				[
					'post_status' => 'publish',
					'post_date'   => sprintf( '2019-02-%02d 00:00:00', $day ),
				]
			);
		}

		$feed_ids = $this->feed_post_ids();

		remove_action( 'pre_get_posts', $raise_feed_length, 10 );
		remove_filter( 'newspack_is_post_restricted', $grant_all, 99 );
		foreach ( $free_ids as $id ) {
			wp_delete_post( $id, true );
		}

		$this->assertCount( 6, $feed_ids, "Feed must honour a later writer's posts_per_rss (6), not the stale default (3)." );
	}

	/**
	 * The other idiom for setting feed length is a `post_limits` filter, which
	 * fires after `pre_get_posts` and so has the last word on the page size.
	 * verify_feed_overfetch_limit() drops the trim target when the final LIMIT is
	 * no longer the inflated one, so that plugin's 6-item feed is served whole
	 * instead of being trimmed back to posts_per_rss (3).
	 *
	 * Dropping the trim leaves the page alone but must not leave the query object
	 * inflated: the length vars are restored either way, so anything reading them
	 * later (pagination links, SEO plugins) sees the pre-over-fetch state.
	 */
	public function test_trim_is_dropped_when_a_post_limits_writer_sets_the_length() {
		update_option( 'posts_per_rss', 3 );

		$set_feed_limit = function ( $limits, $query ) {
			return $query->is_feed() && $query->is_main_query() ? 'LIMIT 0, 6' : $limits;
		};
		add_filter( 'post_limits', $set_feed_limit, 10, 2 );

		// Nothing restricted, so the page size is governed purely by the LIMIT.
		$grant_all = function () {
			return false;
		};
		add_filter( 'newspack_is_post_restricted', $grant_all, 99 );

		$free_ids = [];
		foreach ( range( 1, 7 ) as $day ) {
			$free_ids[] = $this->factory->post->create(
				[
					'post_status' => 'publish',
					'post_date'   => sprintf( '2017-05-%02d 00:00:00', $day ),
				]
			);
		}

		$feed_ids   = $this->feed_post_ids();
		$feed_query = $GLOBALS['wp_query'];

		remove_filter( 'post_limits', $set_feed_limit, 10 );
		remove_filter( 'newspack_is_post_restricted', $grant_all, 99 );
		foreach ( $free_ids as $id ) {
			wp_delete_post( $id, true );
		}

		$this->assertCount( 6, $feed_ids, "A post_limits writer's feed length must survive: no trim back to posts_per_rss (3)." );
		$this->assertSame( 3, (int) $feed_query->get( 'posts_per_rss' ), 'Abandoning the trim must still restore posts_per_rss, not leave it at the over-fetch (15).' );
		$this->assertSame( 3, (int) $feed_query->get( 'posts_per_page' ), 'posts_per_page — what third-party pagination code reads — must be restored too.' );
	}

	/**
	 * Paginated feed pages (paged > 1) are not over-fetched: inflating
	 * posts_per_rss there would push core's offset past unrestricted posts. The
	 * page keeps its requested length so later pages fall back to plain drop.
	 */
	public function test_overfetch_skips_paginated_feed_pages() {
		update_option( 'posts_per_rss', 3 );

		$captured = null;
		$capture  = function ( $query ) use ( &$captured ) {
			if ( $query->is_feed() && $query->is_main_query() ) {
				$captured = (int) $query->get( 'posts_per_rss' );
			}
		};
		add_action( 'pre_get_posts', $capture, PHP_INT_MAX );
		$this->go_to( add_query_arg( 'paged', 2, get_feed_link( 'rss2' ) ) );
		while ( have_posts() ) {
			the_post();
		}
		wp_reset_postdata();
		remove_action( 'pre_get_posts', $capture, PHP_INT_MAX );

		// The over-fetch bails without touching the query var, so it stays unset
		// (0) rather than the inflated 15 (3 × the default multiplier); WP then
		// derives the page length straight from the posts_per_rss option.
		$this->assertSame( 0, $captured, 'Paginated feed pages (paged > 1) must not be over-fetched.' );
	}

	/**
	 * The REST endpoint rejects an unknown feed restriction mode with a 400,
	 * rather than accepting it and letting the storage sanitizer quietly coerce
	 * it to the default. Covers the args schema (nested enum + validate_callback)
	 * that the storage-level sanitizer test cannot reach.
	 */
	public function test_rest_rejects_invalid_feed_restriction_mode() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( 'POST', '/newspack/v1/wizard/newspack-audience-access-control/settings' );
		$request->set_body_params( [ 'advanced_settings' => [ 'feed_restriction_mode' => 'not-a-real-mode' ] ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status(), 'An unknown mode must be rejected by the REST schema.' );
		$this->assertSame(
			'exclude',
			Content_Gate_Advanced_Settings::get_feed_restriction_mode(),
			'The rejected request must not have changed the stored mode.'
		);
	}

	/**
	 * The REST response for a successful save is shaped like the GET config
	 * (booleans, not the 0/1 integers used in storage) — the wizard writes it
	 * into the same store slot it read the config from and compares the two.
	 */
	public function test_rest_update_returns_get_config_shape() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( 'POST', '/newspack/v1/wizard/newspack-audience-access-control/settings' );
		$request->set_body_params(
			[
				'advanced_settings' => [
					'restrict_feeds'        => true,
					'feed_restriction_mode' => 'truncate',
				],
			]
		);
		$updated = rest_get_server()->dispatch( $request )->get_data();

		$get_request = new \WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-audience-access-control' );
		$config      = rest_get_server()->dispatch( $get_request )->get_data();

		$this->assertSame(
			$config['config']['advanced_settings'],
			$updated,
			'The update response must match the GET config shape, or the wizard stays dirty after saving.'
		);
	}

	/**
	 * Secondary (non-main) feed queries still have restricted items dropped —
	 * just without the back-fill, which is reserved for the main feed query.
	 */
	public function test_secondary_feed_query_drops_restricted_posts() {
		$free_post_id = $this->factory->post->create(
			[
				'post_status'  => 'publish',
				'post_date'    => '2017-05-01 00:00:00',
				'post_content' => self::POST_CONTENT,
			]
		);
		$grant_access = function ( $restricted, $post_id ) use ( $free_post_id ) {
			return $post_id === $free_post_id ? false : $restricted;
		};
		add_filter( 'newspack_is_post_restricted', $grant_access, 99, 2 );

		$secondary_feed_query = new \WP_Query(
			[
				'feed'           => 'rss2',
				'post_type'      => 'post',
				'posts_per_page' => 10,
			]
		);
		$ids = wp_list_pluck( $secondary_feed_query->posts, 'ID' );
		wp_reset_postdata();

		remove_filter( 'newspack_is_post_restricted', $grant_access, 99 );
		wp_delete_post( $free_post_id, true );

		$this->assertTrue( $secondary_feed_query->is_feed(), 'Sanity: this is a feed query.' );
		$this->assertFalse( $secondary_feed_query->is_main_query(), 'Sanity: this is not the main query.' );
		$this->assertContains( $free_post_id, $ids, 'Unrestricted post should survive a secondary feed query.' );
		$this->assertNotContains( $this->post_id, $ids, 'Restricted post should be dropped from a secondary feed query.' );
	}

	/**
	 * The comment feed is left intact end-to-end: driving a real comment-feed
	 * request (rather than calling the filter directly) also proves the
	 * `the_posts` callback is registered on the path it claims to guard.
	 */
	public function test_comment_feed_keeps_its_post() {
		$this->factory->comment->create( [ 'comment_post_ID' => $this->post_id ] );

		$this->go_to( get_post_comments_feed_link( $this->post_id ) );
		$this->assertTrue( $GLOBALS['wp_query']->is_comment_feed(), 'Sanity: should be a comment feed.' );

		$ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
		wp_reset_postdata();

		// The gated post is restricted, so an exclude-mode drop would blank the
		// feed's title and link without withholding a single comment.
		$this->assertContains( $this->post_id, $ids, 'A comment feed must keep the post its comments were queried from.' );
	}

	/**
	 * With no published gate and no Memberships, nothing on the site can
	 * restrict a post, so the feed hooks do no work at all: no over-fetch, and
	 * no per-item restriction evaluation. Guards against every Newspack site
	 * paying for a feature it cannot even see.
	 */
	public function test_no_overfetch_without_a_restriction_source() {
		foreach ( Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		$this->reset_restriction_cache();
		Content_Gate_Advanced_Settings::reset_cache();
		update_option( 'posts_per_rss', 3 );

		$this->assertSame( 0, $this->captured_overfetch(), 'A site with no restriction source must not over-fetch its feed.' );
	}

	/**
	 * The same guard covers the other half of the cost: with nothing that can
	 * restrict a post, `the_posts` must not evaluate is_post_restricted() once
	 * per feed item either. Asserted by counting the `newspack_is_post_restricted`
	 * filter fires across a whole feed request rather than by inspecting the
	 * output, since the unguarded version returns the identical feed — the cost
	 * is the regression, not the result.
	 */
	public function test_no_per_item_restriction_check_without_a_restriction_source() {
		foreach ( Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		$this->reset_restriction_cache();
		Content_Gate_Advanced_Settings::reset_cache();

		$checks       = 0;
		$count_checks = function ( $is_restricted ) use ( &$checks ) {
			++$checks;
			return $is_restricted;
		};
		add_filter( 'newspack_is_post_restricted', $count_checks );

		$feed_ids = $this->feed_post_ids();

		remove_filter( 'newspack_is_post_restricted', $count_checks );

		$this->assertContains( $this->post_id, $feed_ids, 'Sanity: with no gate published the post stays in the feed.' );
		$this->assertSame( 0, $checks, 'A site with no restriction source must not evaluate restriction per feed item.' );
	}

	/**
	 * The has_restriction_source guard is escapable. It hard-codes the two
	 * first-party answerers of the public `newspack_is_post_restricted` filter, so
	 * a publisher plugin that answers that filter on its own — restricting posts
	 * with no published gate and no Memberships — would have its articles gated on
	 * the site but shipped full-text in the feed. The
	 * `newspack_content_gate_has_restriction_source` filter opts such a site back
	 * in without patching the plugin.
	 */
	public function test_restriction_source_filter_opts_a_gateless_site_back_in() {
		foreach ( Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		$this->reset_restriction_cache();
		Content_Gate_Advanced_Settings::reset_cache();

		// Stands in for publisher custom code answering the public contract.
		$restrict_everything = function () {
			return true;
		};
		add_filter( 'newspack_is_post_restricted', $restrict_everything, 99 );

		$unguarded_feed_ids = $this->feed_post_ids();

		add_filter( 'newspack_content_gate_has_restriction_source', '__return_true' );
		$opted_in_feed_ids = $this->feed_post_ids();
		remove_filter( 'newspack_content_gate_has_restriction_source', '__return_true' );

		remove_filter( 'newspack_is_post_restricted', $restrict_everything, 99 );

		$this->assertContains( $this->post_id, $unguarded_feed_ids, 'Sanity: with no first-party restriction source the feed hooks stand down.' );
		$this->assertNotContains( $this->post_id, $opted_in_feed_ids, 'The filter must restore feed restriction for a site that restricts without a gate.' );
	}

	/**
	 * Cache-defeat covers every reader-varying access grant in this subsystem, not
	 * just the newsletter bypass: in exclude mode an institutional (IP) grant
	 * changes which items are in the feed at all, so a cached copy of one reader's
	 * feed would hand the premium headline set to everyone.
	 *
	 * Asserted on the decision rather than on the emitted headers: nocache_headers()
	 * bails on headers_sent(), which is always true under the CLI SAPI.
	 */
	public function test_every_bypass_grant_makes_the_feed_uncacheable() {
		$this->go_to( get_feed_link( 'rss2' ) );

		$this->assertFalse(
			Content_Gate_Advanced_Settings::feed_response_varies_by_reader(),
			'Sanity: an anonymous feed request carries no grant and stays cacheable.'
		);

		$grant_cookies = [
			'newsletter bypass'  => Newsletters_Access::COOKIE_NAME,
			'single-post bypass' => Newsletters_Access::SINGLE_POST_COOKIE_NAME,
			'IP/institution'     => IP_Access_Rule::COOKIE_NAME,
		];
		foreach ( $grant_cookies as $grant => $cookie_name ) {
			$_COOKIE[ $cookie_name ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
			$varies_by_reader        = Content_Gate_Advanced_Settings::feed_response_varies_by_reader();
			unset( $_COOKIE[ $cookie_name ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

			$this->assertTrue( $varies_by_reader, "A feed request carrying the $grant grant must not be cached." );
		}

		wp_reset_postdata();
	}

	/**
	 * The trim back to the requested length does not depend on the mode
	 * resolving the same way twice: if a filter flips the mode between
	 * `pre_get_posts` and `the_posts`, the already over-fetched page is still
	 * trimmed rather than shipping up to FEED_OVERFETCH_MAX items.
	 */
	public function test_overfetched_page_is_trimmed_even_if_the_mode_changes() {
		update_option( 'posts_per_rss', 2 );

		$free_ids = [];
		foreach ( range( 1, 6 ) as $day ) {
			$free_ids[] = $this->factory->post->create(
				[
					'post_status' => 'publish',
					'post_date'   => sprintf( '2016-04-%02d 00:00:00', $day ),
				]
			);
		}

		// Resolves to exclude while the over-fetch runs, then to truncate by the
		// time the page comes back.
		$flip_after_query = function ( $mode ) {
			return did_action( 'pre_get_posts' ) && doing_filter( 'the_posts' ) ? 'truncate' : $mode;
		};
		add_filter( 'newspack_content_gate_feed_restriction_mode', $flip_after_query );

		$feed_ids = $this->feed_post_ids();

		remove_filter( 'newspack_content_gate_feed_restriction_mode', $flip_after_query );
		foreach ( $free_ids as $id ) {
			wp_delete_post( $id, true );
		}

		$this->assertCount( 2, $feed_ids, 'An over-fetched page must be trimmed to the requested length regardless of the mode.' );
	}

	/**
	 * A paginated feed page whose whole window is restricted returns an empty
	 * feed page, not a 404: back-fill is deliberately limited to page 1, so
	 * without this the pairing would turn a previously short page into an error.
	 */
	public function test_emptied_paginated_feed_page_is_not_a_404() {
		update_option( 'posts_per_rss', 1 );

		// Two more restricted posts so page 2 exists and is entirely restricted.
		$restricted_ids = [];
		foreach ( range( 1, 2 ) as $day ) {
			$restricted_ids[] = $this->factory->post->create(
				[
					'post_status' => 'publish',
					'post_date'   => sprintf( '2015-06-%02d 00:00:00', $day ),
				]
			);
		}

		// go_to() runs WP::main(), which queries the posts and then calls
		// handle_404() — no need to invoke it again.
		$this->go_to( add_query_arg( 'paged', 2, get_feed_link( 'rss2' ) ) );
		$is_404 = is_404();
		wp_reset_postdata();

		$emptied_paged_feed = new \ReflectionProperty( Content_Gate_Advanced_Settings::class, 'emptied_paged_feed' );
		$emptied_paged_feed->setAccessible( true );
		$flag_after_request = $emptied_paged_feed->getValue();

		foreach ( $restricted_ids as $id ) {
			wp_delete_post( $id, true );
		}

		$this->assertEmpty( $GLOBALS['wp_query']->posts, 'Sanity: the page should have been emptied by exclusion.' );
		$this->assertFalse( $is_404, 'A feed page emptied by exclusion must stay a valid, empty feed page.' );
		$this->assertFalse( $flag_after_request, 'The flag must be one-shot, or every later empty feed query in the process inherits this page\'s 404 suppression.' );
	}

	/**
	 * A non-positive over-fetch multiplier is clamped to 1 (max( 1, … )), so the
	 * over-fetch collapses to the requested length and no inflation happens: a
	 * rogue filter return can never shorten or empty the feed.
	 */
	public function test_overfetch_multiplier_clamps_to_minimum() {
		update_option( 'posts_per_rss', 4 );

		// Everything unrestricted, so the feed length is governed purely by the
		// (clamped) over-fetch rather than by dropped items.
		$grant_all = function () {
			return false;
		};
		add_filter( 'newspack_is_post_restricted', $grant_all, 99 );

		$free_ids = [];
		foreach ( range( 1, 6 ) as $day ) {
			$free_ids[] = $this->factory->post->create(
				[
					'post_status' => 'publish',
					'post_date'   => sprintf( '2018-03-%02d 00:00:00', $day ),
				]
			);
		}

		$zero = function () {
			return 0;
		};
		add_filter( 'newspack_content_gate_feed_overfetch_multiplier', $zero );

		$feed_ids = $this->feed_post_ids();

		remove_filter( 'newspack_content_gate_feed_overfetch_multiplier', $zero );
		remove_filter( 'newspack_is_post_restricted', $grant_all, 99 );
		foreach ( $free_ids as $id ) {
			wp_delete_post( $id, true );
		}

		$this->assertCount( 4, $feed_ids, 'A non-positive multiplier must clamp to 1 and leave the feed at its requested length.' );
	}
}
