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
		delete_option( 'rss_use_excerpt' );
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
}
