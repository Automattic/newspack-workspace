<?php
/**
 * Tests that a restricted post's body stays withheld on every surface, not only
 * on its own URL.
 *
 * NPPD-2172: the gate render is staged on `the_post` for the queried singular
 * post alone, so a Query Loop or an auto-generated excerpt reached
 * `post_content` directly and published the paid body to anonymous readers.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Gifting;
use Newspack\Tests\Content_Gate\Traits\Trait_Restriction_Cache_Test;

/**
 * Restriction outside the singular gate render.
 *
 * @group content-gate
 */
class Test_Restricted_Post_Outside_Gate_Render extends \WP_UnitTestCase {

	use Trait_Restriction_Cache_Test;

	/**
	 * Marker in the free part of the body, which a teaser may show.
	 */
	const FREE_MARKER = 'FREEOPENING';

	/**
	 * Marker behind the gate, which no anonymous surface may show.
	 */
	const PAID_MARKER = 'PAIDBODY';

	/**
	 * Gate restricting every post.
	 *
	 * @var int
	 */
	private $gate_id;

	/**
	 * Layout the gate renders, with the default two visible paragraphs.
	 *
	 * @var int
	 */
	private $gate_layout_id;

	/**
	 * The feature constant is process-wide once defined.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * A published registration gate restricting all posts, and an anonymous reader.
	 */
	public function set_up() {
		parent::set_up();
		$this->gate_layout_id = Content_Gate::create_gate_layout( 'NPPD-2172 Layout' );
		$this->gate_id        = Content_Gate::create_gate( [ 'title' => 'NPPD-2172 Gate' ] );
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'         => true,
					'gate_layout_id' => $this->gate_layout_id,
				],
			]
		);
		wp_set_current_user( 0 );
		$this->reset_restriction_cache();
		$this->reset_gate_render_state();
	}

	/**
	 * Teardown after tests.
	 */
	public function tear_down() {
		$this->reset_restriction_cache();
		$this->reset_gate_render_state();
		parent::tear_down();
	}

	/**
	 * Discard the request-scoped render state Content_Gate accumulates, which in
	 * production dies with the request but here would carry between cases.
	 */
	private function reset_gate_render_state() {
		foreach ( [ 'gate_rendered', 'is_gated', 'is_content_locked' ] as $flag ) {
			$flag_reflection = new \ReflectionProperty( Content_Gate::class, $flag );
			$flag_reflection->setAccessible( true );
			$flag_reflection->setValue( null, false );
		}
		// Deliberately unguarded: renaming one of these stores must fail the suite
		// loudly, not leave that state bleeding between cases while the tests stay
		// green. The withholding caches are reset by reset_restriction_cache().
		foreach ( [ 'restricted_content', 'pending_gates' ] as $store ) {
			$store_reflection = new \ReflectionProperty( Content_Gate::class, $store );
			$store_reflection->setAccessible( true );
			$store_reflection->setValue( null, [] );
		}
	}

	/**
	 * A post the gate restricts: two free paragraphs and one behind the gate.
	 *
	 * The free part is deliberately short, so an excerpt rebuilt from the body
	 * reaches the paid paragraph inside core's 55-word budget.
	 *
	 * @param array $args Post arguments to override.
	 *
	 * @return int
	 */
	private function create_restricted_post( $args = [] ) {
		return $this->factory->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_excerpt' => '',
					'post_content' => '<!-- wp:paragraph --><p>' . self::FREE_MARKER . ' opening line.</p><!-- /wp:paragraph -->'
						. '<!-- wp:paragraph --><p>Second free line.</p><!-- /wp:paragraph -->'
						. '<!-- wp:paragraph --><p>' . self::PAID_MARKER . ' is behind the gate.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
	}

	/**
	 * Render a post's body the way a listing block does: a secondary query, its
	 * own `the_post`, then the content filters.
	 *
	 * @param int    $post_id   Post to render.
	 * @param string $post_type Post type to query.
	 *
	 * @return string
	 */
	private function render_in_secondary_loop( $post_id, $post_type = 'post' ) {
		$loop = new \WP_Query(
			[
				'post_type' => $post_type,
				'post__in'  => [ $post_id ],
			]
		);
		$rendered = '';
		while ( $loop->have_posts() ) {
			$loop->the_post();
			$rendered .= apply_filters( 'the_content', get_the_content() );
		}
		wp_reset_postdata();
		return $rendered;
	}

	/**
	 * A Query Loop showing post content must show no more than the article page
	 * shows an anonymous reader.
	 */
	public function test_body_is_withheld_in_a_secondary_loop() {
		$post_id = $this->create_restricted_post();
		$this->go_to( home_url( '/' ) );

		$rendered = $this->render_in_secondary_loop( $post_id );

		$this->assertStringNotContainsString( self::PAID_MARKER, $rendered, 'A secondary loop must not publish the gated body.' );
		$this->assertStringContainsString( self::FREE_MARKER, $rendered, 'The free opening is still shown.' );
	}

	/**
	 * The gate itself belongs to the article page. Repeating its layout once per
	 * card would duplicate the registration form, and its element IDs, across the
	 * listing.
	 */
	public function test_a_secondary_loop_does_not_render_the_gate() {
		$post_id = $this->create_restricted_post();
		$this->go_to( home_url( '/' ) );

		$rendered = $this->render_in_secondary_loop( $post_id );

		$this->assertStringNotContainsString( 'newspack-content-gate__inline-gate', $rendered );
	}

	/**
	 * An unrestricted post is untouched, so the withholding cannot be read as
	 * "listings show teasers".
	 */
	public function test_an_unrestricted_post_renders_in_full_in_a_secondary_loop() {
		$page_id = $this->factory->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>' . self::FREE_MARKER . '</p><!-- /wp:paragraph -->'
					. '<!-- wp:paragraph --><p>Second free line.</p><!-- /wp:paragraph -->'
					. '<!-- wp:paragraph --><p>' . self::PAID_MARKER . '</p><!-- /wp:paragraph -->',
			]
		);
		$this->go_to( home_url( '/' ) );

		$rendered = $this->render_in_secondary_loop( $page_id, 'page' );

		$this->assertStringContainsString( self::PAID_MARKER, $rendered, 'The gate rules do not cover pages, so nothing is withheld.' );
	}

	/**
	 * An auto-generated excerpt is built from the teaser, not from the body. On an
	 * article with a short lede, core's 55-word budget otherwise reaches paid copy.
	 */
	public function test_auto_excerpt_stops_at_the_teaser() {
		$post_id = $this->create_restricted_post();

		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringNotContainsString( self::PAID_MARKER, $excerpt, 'An auto-generated excerpt must not reach the gated body.' );
		$this->assertStringContainsString( self::FREE_MARKER, $excerpt, 'The free opening is still shown.' );
	}

	/**
	 * A hand-written excerpt is the author's own teaser and stays as written.
	 */
	public function test_manual_excerpt_survives_on_a_restricted_post() {
		$post_id = $this->create_restricted_post( [ 'post_excerpt' => 'Hand written.' ] );

		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringContainsString( 'Hand written.', $excerpt );
		$this->assertStringNotContainsString( self::PAID_MARKER, $excerpt );
	}

	/**
	 * The article page is unchanged: the body is replaced by the teaser and the
	 * gate renders once, not once per pass through the content filters.
	 */
	public function test_the_article_page_still_renders_its_gate() {
		$post_id = $this->create_restricted_post();
		$this->go_to( get_permalink( $post_id ) );
		while ( have_posts() ) {
			the_post();
		}

		$rendered = apply_filters( 'the_content', get_post( $post_id )->post_content );

		$this->assertStringNotContainsString( self::PAID_MARKER, $rendered );
		$this->assertStringContainsString( self::FREE_MARKER, $rendered );
		$this->assertSame( 1, substr_count( $rendered, 'newspack-content-gate__inline-gate' ), 'The article page renders exactly one gate.' );
	}

	/**
	 * Metering grants a free read of the article being viewed. It must not unlock
	 * every other restricted post that happens to appear on the same page.
	 */
	public function test_metering_does_not_unlock_other_posts_on_the_page() {
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'registration' => [
					'active'         => true,
					'gate_layout_id' => $this->gate_layout_id,
					'metering'       => [
						'enabled' => true,
						'count'   => 1,
						'period'  => 'month',
					],
				],
			]
		);
		$metered_post_id = $this->create_restricted_post();
		$listed_post_id  = $this->create_restricted_post();
		$this->reset_restriction_cache();

		$this->go_to( get_permalink( $metered_post_id ) );
		while ( have_posts() ) {
			the_post();
		}
		$this->assertTrue( \Newspack\Metering::is_frontend_metering(), 'The metered article is readable, which is the premise of this test.' );

		$rendered = $this->render_in_secondary_loop( $listed_post_id );

		$this->assertStringNotContainsString( self::PAID_MARKER, $rendered, 'A metered view of one article must not unlock another.' );
	}

	/**
	 * This path stands down in a feed, whatever the feed settings say.
	 *
	 * Feeds are restricted by Content_Gate_Advanced_Settings, on their own hooks
	 * and against a publisher-set mode that includes leaving items whole.
	 * Withholding here would override that choice with no way to switch it off.
	 */
	public function test_feed_rendering_is_left_to_the_feed_subsystem() {
		$post_id = $this->create_restricted_post();
		$this->go_to( home_url( '/?feed=rss2' ) );

		$rendered = $this->render_in_secondary_loop( $post_id );

		$this->assertStringContainsString( self::PAID_MARKER, $rendered, 'The feed subsystem decides what a feed item shows, not this path.' );
	}

	/**
	 * A `<!--more-->` tag at the very top of a post leaves no free preview.
	 *
	 * The tag is the author's own mark for where the free part ends, and at the
	 * top of the body that means "none of it" -- not the paragraph count that
	 * applies to a post carrying no tag at all.
	 */
	public function test_a_more_tag_at_the_top_leaves_no_free_preview() {
		// The threshold is a layout setting, so the layout has to carry it rather
		// than the assertion resting on what an unwritten meta key reads as.
		update_post_meta( $this->gate_layout_id, 'use_more_tag', true );
		$post_id = $this->factory->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!--more-->'
					. '<!-- wp:paragraph --><p>' . self::PAID_MARKER . '</p><!-- /wp:paragraph -->',
			]
		);

		$teaser = Content_Gate::get_withheld_teaser( $post_id );

		$this->assertStringNotContainsString( self::PAID_MARKER, $teaser );
		$this->assertSame( '', trim( wp_strip_all_tags( $teaser ) ) );
	}

	/**
	 * Work that renders a post for something other than a reader gets the whole
	 * post, and the memo does not carry either verdict out of that window.
	 *
	 * The memo is what makes this need a helper: a verdict reached before the
	 * window would otherwise still answer inside it, and one reached inside it
	 * would outlive it.
	 */
	public function test_a_non_reader_render_gets_the_whole_post() {
		$post_id = $this->create_restricted_post();
		$this->go_to( home_url( '/' ) );

		$this->assertTrue( Content_Gate::should_withhold_content( $post_id ), 'Withheld before the window, and memoized.' );

		$inside = Content_Gate::without_reader_restrictions(
			function () use ( $post_id ) {
				return [
					'withheld' => Content_Gate::should_withhold_content( $post_id ),
					'nested'   => Content_Gate::without_reader_restrictions(
						function () use ( $post_id ) {
							return Content_Gate::should_withhold_content( $post_id );
						}
					),
				];
			}
		);

		$this->assertFalse( $inside['withheld'], 'The window reaches the memoized verdict.' );
		$this->assertFalse( $inside['nested'], 'Nesting does not end the window early.' );
		$this->assertTrue( Content_Gate::should_withhold_content( $post_id ), 'The window does not outlive itself.' );
	}

	/**
	 * An excerpt answers for the post it was asked about, not for whichever post
	 * the loop is on.
	 *
	 * Core builds an excerpt by running the body through `the_content`, and the
	 * gate substitutes there by the global post. Without the suspension a card
	 * beside a gated article shows that article's teaser under its own headline.
	 */
	public function test_an_excerpt_answers_for_its_own_post() {
		$restricted_id = $this->create_restricted_post();
		$other_id      = $this->factory->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>OTHERBODY</p><!-- /wp:paragraph -->',
			]
		);

		// A loop sitting on the gated article, as a sidebar or related-posts list
		// would find it.
		$this->go_to( get_permalink( $restricted_id ) );
		$GLOBALS['post'] = get_post( $restricted_id );
		setup_postdata( $GLOBALS['post'] );

		$excerpt = get_the_excerpt( $other_id );

		unset( $GLOBALS['post'] );
		wp_reset_postdata();

		$this->assertStringContainsString( 'OTHERBODY', $excerpt, 'The excerpt is built from the post it was asked about.' );
		$this->assertStringNotContainsString( self::FREE_MARKER, $excerpt, "A neighbouring article's teaser must not stand in for it." );
	}

	/**
	 * The body stays withheld even if the substitution filter never runs.
	 *
	 * A plugin that removes the filter would otherwise publish the full body: the
	 * chain is handed the unrestricted post, and nothing else in it withholds.
	 */
	public function test_a_removed_substitution_filter_still_withholds() {
		$post_id = $this->create_restricted_post();
		$this->go_to( home_url( '/' ) );

		remove_filter( 'the_content', [ Content_Gate::class, 'replace_restricted_content' ], Content_Gate::RESTRICTION_PRIORITY );
		try {
			$rendered = $this->render_in_secondary_loop( $post_id );
		} finally {
			add_filter( 'the_content', [ Content_Gate::class, 'replace_restricted_content' ], Content_Gate::RESTRICTION_PRIORITY );
		}

		$this->assertStringNotContainsString( self::PAID_MARKER, $rendered );
	}

	/**
	 * A reader arriving on a gift link varies from every other anonymous reader
	 * before any cookie exists, so shared caches must treat them as their own.
	 */
	public function test_a_url_bypass_marks_the_response_as_reader_varying() {
		wp_set_current_user( 0 );
		$this->assertFalse( Content_Gate::response_varies_by_reader(), 'A plain anonymous request is shareable.' );

		$_GET[ Content_Gifting::QUERY_ARG ] = 'a-gift-key';
		$varies_on_gift_link                = Content_Gate::response_varies_by_reader();
		unset( $_GET[ Content_Gifting::QUERY_ARG ] );

		$_GET['utm_medium']       = 'email';
		$varies_on_newsletter     = Content_Gate::response_varies_by_reader();
		unset( $_GET['utm_medium'] );

		$this->assertTrue( $varies_on_gift_link, 'A gift key grants access before its cookie exists.' );
		$this->assertTrue( $varies_on_newsletter, 'The newsletter fallback writes its cookie on `wp`, after caching is wired up.' );
	}

	/**
	 * The pages a reader needs in order to resolve a gate are never withheld,
	 * wherever they are rendered. Gating My Account hides the sign-in form the
	 * gate is asking the reader to use.
	 */
	public function test_pages_a_reader_needs_for_access_are_never_withheld() {
		$account_page_id = $this->factory->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>' . self::PAID_MARKER . '</p><!-- /wp:paragraph -->',
			]
		);
		update_option( 'woocommerce_myaccount_page_id', $account_page_id );
		// The gate covers pages too, so nothing but the exemption keeps this page open.
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post', 'page' ],
					],
				],
			]
		);
		$this->reset_restriction_cache();
		$this->reset_gate_render_state();

		$this->assertNotFalse( Content_Gate::is_post_restricted( $account_page_id ), 'The gate does restrict this page — the exemption is what keeps it readable.' );
		$this->assertFalse( Content_Gate::should_withhold_content( $account_page_id ) );
	}
}
