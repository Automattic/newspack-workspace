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
		// green. reset_restriction_cache() covers the Content_Restriction_Control
		// maps, which are a separate set; the loop below is what clears these.
		foreach ( [ 'restricted_content', 'pending_gates', 'withheld_teasers' ] as $store ) {
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
	 * A metered view spends the reader's allowance on the article they opened, so
	 * it answers for that article alone. Asked about any other post -- which is
	 * what a listing, or a REST collection, does -- the allowance is not theirs to
	 * spend and the restriction stands.
	 */
	public function test_metering_answers_only_for_the_article_being_read() {
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

		$this->assertFalse( \Newspack\Metering::restrict_post( true, $metered_post_id ), 'The article the allowance was spent on is unlocked.' );
		$this->assertTrue( \Newspack\Metering::restrict_post( true, $listed_post_id ), 'Every other post stays restricted.' );
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

		$teaser = Content_Gate::get_teaser_outside_article( get_post( $post_id ) );

		$this->assertStringNotContainsString( self::PAID_MARKER, $teaser );
		$this->assertSame( '', trim( wp_strip_all_tags( $teaser ) ) );
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
	 * The teaser is in hand for the filters that run between the substitution and
	 * the gate append, not only in the final output.
	 *
	 * Third-party integrations gate their own embeds on `the_content` at a high
	 * priority. Handing them the body and correcting it afterwards would leave
	 * those embeds ungated on a restricted post.
	 */
	public function test_filters_after_the_substitution_are_handed_the_teaser() {
		$post_id = $this->create_restricted_post();
		$this->go_to( home_url( '/' ) );

		$seen = null;
		$spy  = function ( $content ) use ( &$seen ) {
			$seen = $content;
			return $content;
		};
		add_filter( 'the_content', $spy, 99999 );
		try {
			$this->render_in_secondary_loop( $post_id );
		} finally {
			remove_filter( 'the_content', $spy, 99999 );
		}

		$this->assertNotNull( $seen, 'The spy ran.' );
		$this->assertStringNotContainsString( self::PAID_MARKER, $seen, 'A later filter is handed the teaser, not the body.' );
		$this->assertStringContainsString( self::FREE_MARKER, $seen );
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
		$this->assertNull( Content_Gate::get_teaser_outside_article( get_post( $account_page_id ) ), 'An exempt page is never withheld, wherever it is rendered.' );
	}

	/**
	 * A listing rendered before the article must not consume the once-per-request
	 * gate lock. Withholding a listed post is not a gate render, and treating it
	 * as one would leave the article below it ungated.
	 */
	public function test_a_listing_above_the_article_does_not_disarm_its_gate() {
		$article_id = $this->create_restricted_post();
		$listed_id  = $this->create_restricted_post();
		$this->go_to( get_permalink( $article_id ) );

		// A Query Loop in the header, rendered before the main loop runs.
		$this->render_in_secondary_loop( $listed_id );

		while ( have_posts() ) {
			the_post();
		}
		$rendered = apply_filters( 'the_content', get_post( $article_id )->post_content );

		$this->assertStringNotContainsString( self::PAID_MARKER, $rendered );
		$this->assertSame( 1, substr_count( $rendered, 'newspack-content-gate__inline-gate' ), 'The article still renders its own gate.' );
	}

	/**
	 * A listing shows the teaser to every reader, including one the gate would let
	 * through. Newspack's block cache keys rendered listing markup by block
	 * attributes and position with no reader dimension, so a listing that varied
	 * by entitlement would be handed to the next reader along.
	 */
	public function test_a_listing_shows_the_teaser_to_an_entitled_reader() {
		$post_id   = $this->create_restricted_post();
		$reader_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $reader_id );
		$this->reset_restriction_cache();
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( Content_Gate::is_post_restricted( $post_id ), 'A logged-in reader passes this registration gate, which is the premise of this test.' );

		$listed = $this->render_in_secondary_loop( $post_id );
		wp_set_current_user( 0 );

		$this->assertStringNotContainsString( self::PAID_MARKER, $listed, 'The listing is reader-independent, so it shows the teaser.' );
	}

	/**
	 * A listing on the article's own page must not stage anything on the article's
	 * behalf, in either order and with no reset between the two — which is what a
	 * real request looks like.
	 *
	 * The listing entry carries no gate, so writing it over the article's own would
	 * serve an anonymous reader the free opening with no call to action.
	 */
	public function test_a_listing_below_the_article_leaves_its_gate_intact() {
		$post_id = $this->create_restricted_post();
		$this->go_to( get_permalink( $post_id ) );
		while ( have_posts() ) {
			the_post();
		}
		$article = apply_filters( 'the_content', get_post( $post_id )->post_content );

		// A Query Loop under the body, listing the article it sits below.
		$this->render_in_secondary_loop( $post_id );

		// In a block theme core sets the post up once and renders the whole
		// template, so a second pass over the article's body follows the listing.
		$second_pass = apply_filters( 'the_content', get_post( $post_id )->post_content );

		$this->assertSame( 1, substr_count( $article, 'newspack-content-gate__inline-gate' ), 'The article renders its gate.' );
		$this->assertStringNotContainsString( self::PAID_MARKER, $second_pass );
		$this->assertSame( 1, substr_count( $second_pass, 'newspack-content-gate__inline-gate' ), 'A listing below the article does not disarm the gate for a later pass.' );
	}

	/**
	 * The same ordering in reverse, for a reader the gate lets through: the article
	 * render stages nothing because there is nothing to withhold from them, so a
	 * listing above it staging a teaser on the post's behalf would serve a paying
	 * subscriber a stub of the article they paid for.
	 */
	public function test_a_listing_above_the_article_leaves_an_entitled_reader_the_whole_post() {
		$post_id   = $this->create_restricted_post();
		$reader_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $reader_id );
		$this->reset_restriction_cache();
		$this->go_to( get_permalink( $post_id ) );

		$this->assertFalse( Content_Gate::is_post_restricted( $post_id ), 'A logged-in reader passes this registration gate, which is the premise of this test.' );

		// A Query Loop in the header, listing the article it sits above.
		$this->render_in_secondary_loop( $post_id );

		while ( have_posts() ) {
			the_post();
		}
		$article = apply_filters( 'the_content', get_post( $post_id )->post_content );
		wp_set_current_user( 0 );

		$this->assertStringContainsString( self::PAID_MARKER, $article, 'The article page gives an entitled reader the whole post.' );
		$this->assertStringNotContainsString( 'newspack-content-gate__inline-gate', $article );
	}

	/**
	 * A password-protected post is core's to withhold, and this path leaves it
	 * alone. Substituting a teaser for the password form would publish the free
	 * opening of a post core meant to show nothing of, and drop the form with it.
	 */
	public function test_a_password_protected_post_is_left_to_core_in_a_loop() {
		$post_id = $this->create_restricted_post( [ 'post_password' => 'letmein' ] );
		$this->go_to( home_url( '/' ) );

		$rendered = $this->render_in_secondary_loop( $post_id );

		$this->assertStringContainsString( 'post-password-form', $rendered, 'Core\'s password form stands.' );
		$this->assertStringNotContainsString( self::FREE_MARKER, $rendered, 'Not even the free opening is published for a protected post.' );
		$this->assertStringNotContainsString( self::PAID_MARKER, $rendered );
	}

	/**
	 * WP_Query fires `the_post` outside loops too: setup_postdata() ends with it,
	 * and WP_REST_Posts_Controller::prepare_item_for_response() calls that for
	 * every item it serves. Those reads belong to filter_rest_response(), which
	 * evaluates entitlement per requester and leaves an editor's context=edit
	 * payload whole, so a bare setup_postdata() must stage nothing.
	 */
	public function test_a_bare_setup_postdata_is_not_a_render() {
		$post_id = $this->create_restricted_post();
		$this->go_to( home_url( '/' ) );

		setup_postdata( get_post( $post_id ) );

		$staged = new \ReflectionProperty( Content_Gate::class, 'restricted_content' );
		$staged->setAccessible( true );

		$this->assertSame( [], $staged->getValue(), 'Setting a post up outside a loop is not a render.' );
	}

	/**
	 * An admin-context loop rendered for someone who cannot edit the post is a
	 * read like any other.
	 *
	 * Jetpack infinite scroll, which newspack-theme registers, fetches archive
	 * pages 2 and up over admin-ajax — a real loop rendering the_content() for
	 * whoever asked, with is_admin() true throughout.
	 */
	public function test_an_admin_context_loop_still_withholds_from_a_reader() {
		$post_id = $this->create_restricted_post();
		$this->go_to( home_url( '/' ) );
		set_current_screen( 'dashboard' );

		try {
			$this->assertTrue( is_admin(), 'The request reads as admin context, which is the premise of this test.' );
			$rendered = $this->render_in_secondary_loop( $post_id );
		} finally {
			unset( $GLOBALS['current_screen'] );
		}

		$this->assertStringNotContainsString( self::PAID_MARKER, $rendered, 'Admin context is not an entitlement.' );
	}

	/**
	 * A REST route that runs its own loop and renders bodies is a render like any
	 * other, and withholding stays on for it.
	 *
	 * The load-more endpoint in newspack-blocks (`newspack-blocks/v1/articles`,
	 * permission_callback `__return_true`) is the case: it runs a real loop and
	 * renders the_content() for each item, so a blanket stand-down inside the API
	 * would serve page 2 of any Homepage Posts block in full to anonymous readers
	 * while page 1 withheld. What separates that from the posts controller is
	 * `in_the_loop`, not the transport.
	 */
	public function test_a_rest_route_running_a_loop_still_withholds() {
		$post_id  = $this->create_restricted_post();
		$rendered = null;
		add_action(
			'rest_api_init',
			function () use ( &$post_id, &$rendered ) {
				register_rest_route(
					'newspack-test/v1',
					'/loop',
					[
						'methods'             => 'GET',
						'permission_callback' => '__return_true',
						'callback'            => function () use ( &$post_id, &$rendered ) {
							$rendered = $this->render_in_secondary_loop( $post_id );
							return [ 'ok' => true ];
						},
					]
				);
			}
		);

		// A server may already be standing from an earlier case, in which case
		// `rest_api_init` has fired and the route above would never register.
		global $wp_rest_server;
		$wp_rest_server = null;
		$this->go_to( home_url( '/' ) );
		rest_do_request( new \WP_REST_Request( 'GET', '/newspack-test/v1/loop' ) );
		$wp_rest_server = null;

		$this->assertNotNull( $rendered, 'The route ran, which is the premise of this test.' );
		$this->assertStringNotContainsString( self::PAID_MARKER, $rendered, 'A loop inside a REST dispatch withholds the gated body.' );
		$this->assertStringContainsString( self::FREE_MARKER, $rendered );
	}

	/**
	 * A block in the withheld post's own body that runs a loop over that same post
	 * re-fires `the_post` for it in the middle of the teaser build. The slot is
	 * claimed before the build for exactly this reason (#821); without it the
	 * build re-enters itself until the stack gives out.
	 */
	public function test_a_block_looping_over_the_post_does_not_re_enter_the_teaser_build() {
		$post_id = null;
		$loops   = 0;
		register_block_type(
			'newspack-test/looping-block',
			[
				'render_callback' => function () use ( &$post_id, &$loops ) {
					// A cap, not a guard: without the claimed slot this recurses
					// until the stack gives out, and a blown stack asserts nothing.
					if ( ++$loops > 3 ) {
						return '';
					}
					$loop = new \WP_Query( [ 'post__in' => [ $post_id ] ] );
					while ( $loop->have_posts() ) {
						$loop->the_post();
					}
					wp_reset_postdata();
					return '';
				},
			]
		);

		// Every teaser build runs the body through this filter, so counting it
		// counts the builds. No gate is rendered in a listing, which is the only
		// other producer.
		$builds      = 0;
		$count_build = function ( $content ) use ( &$builds ) {
			++$builds;
			return $content;
		};
		add_filter( 'newspack_gate_content', $count_build, 1 );

		try {
			$post_id = $this->create_restricted_post(
				[
					'post_content' => '<!-- wp:paragraph --><p>' . self::FREE_MARKER . ' opening line.</p><!-- /wp:paragraph -->'
						. '<!-- wp:paragraph --><p>Second free line.</p><!-- /wp:paragraph -->'
						. '<!-- wp:newspack-test/looping-block /-->'
						. '<!-- wp:paragraph --><p>' . self::PAID_MARKER . ' is behind the gate.</p><!-- /wp:paragraph -->',
				]
			);
			$this->go_to( home_url( '/' ) );

			$rendered = $this->render_in_secondary_loop( $post_id );
		} finally {
			remove_filter( 'newspack_gate_content', $count_build, 1 );
			unregister_block_type( 'newspack-test/looping-block' );
		}

		$this->assertSame( 1, $builds, 'The re-entrant `the_post` is answered from the claimed slot, so the body is built into a teaser once.' );
		$this->assertStringNotContainsString( self::PAID_MARKER, $rendered );
		$this->assertStringContainsString( self::FREE_MARKER, $rendered );
	}
}
