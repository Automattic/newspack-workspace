<?php
/**
 * Tests for restricting gated content in RSS feeds.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Gate_Advanced_Settings;
use Newspack\Content_Restriction_Control;

/**
 * Tests that the "Restrict content in feeds" advanced setting keeps gated
 * content out of RSS feeds, where Content_Gate::restrict_post() never runs
 * (it bails on `! is_singular()`), so the feed filters are the only guard.
 *
 * @group content-gate
 */
class Test_Feed_Restriction extends \WP_UnitTestCase {

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
	 * Reset the per-request restriction caches between assertions.
	 */
	private function reset_restriction_cache() {
		foreach ( [ 'post_gate_id_map', 'post_gate_layout_id_map', 'post_gates_map', 'term_descendants_map' ] as $cache_property ) {
			$cache_property_reflection = new \ReflectionProperty( Content_Restriction_Control::class, $cache_property );
			$cache_property_reflection->setAccessible( true );
			$cache_property_reflection->setValue( null, [] );
		}
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

		$feed_ids = $this->feed_post_ids();

		remove_filter( 'newspack_is_post_restricted', $grant_access, 99 );
		foreach ( array_merge( $free_ids, $restricted_ids ) as $id ) {
			wp_delete_post( $id, true );
		}

		$this->assertCount( 3, $feed_ids, 'Feed should be back-filled and trimmed to posts_per_rss.' );
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
		// Priority 11 runs after overfetch_restricted_feed() (default priority 10).
		add_action( 'pre_get_posts', $capture, 11 );
		$this->feed_post_ids();
		remove_action( 'pre_get_posts', $capture, 11 );

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
				'posts_per_page' => -1,
			]
		);
		$ids = wp_list_pluck( $query->posts, 'ID' );
		wp_reset_postdata();

		$this->assertFalse( $query->is_feed(), 'Sanity: this is not a feed query.' );
		$this->assertContains( $this->post_id, $ids, 'Restricted post must remain in non-feed queries under exclude mode.' );
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
}
