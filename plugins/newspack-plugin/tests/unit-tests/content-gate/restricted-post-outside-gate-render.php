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
