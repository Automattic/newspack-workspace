<?php
/**
 * Tests for Newspack\Memberships::remove_unnecessary_content_restriction().
 *
 * @package Newspack\Tests
 */

/**
 * The handler drops Woo Memberships' content restriction callbacks on the front
 * page and on archives. An archive requested as a feed is still an archive, so
 * those callbacks came off there too and feed items carried whole restricted
 * post bodies.
 *
 * Every test runs in its own process: the mock declares wc_memberships(), which
 * would otherwise make Memberships::is_active() true for the rest of the suite.
 *
 * @group wc-memberships
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_Memberships_Archive_Restriction_Hooks extends WP_UnitTestCase {

	/**
	 * The memoized restrictions handler whose callbacks are under test.
	 *
	 * @var object
	 */
	private $restrictions_handler;

	/**
	 * Category the archive requests resolve to.
	 *
	 * @var int
	 */
	private $category_id;

	/**
	 * Stand in for an active Memberships install and publish one categorized post.
	 */
	public function set_up() {
		parent::set_up();

		require_once dirname( __DIR__, 3 ) . '/mocks/wc-memberships-restrictions-mock.php';

		$this->restrictions_handler = wc_memberships()->get_restrictions_instance()->get_posts_restrictions_instance();
		$this->category_id          = $this->factory->category->create( [ 'slug' => 'members-only' ] );

		// The category needs a post: an empty term archive 404s in
		// WP::handle_404(), which would leave is_archive() false and make the
		// assertions below pass without exercising the branch under test.
		$this->factory->post->create(
			[
				'post_status'   => 'publish',
				'post_category' => [ $this->category_id ],
			]
		);
	}

	/**
	 * Register the three callbacks Woo Memberships adds on `wp` at priority 9,
	 * at the priorities it uses, so the handler under test can find them.
	 */
	private function register_memberships_restriction_hooks() {
		add_action( 'the_post', [ $this->restrictions_handler, 'restrict_post' ], 0 );
		add_filter( 'the_content', [ $this->restrictions_handler, 'handle_restricted_post_content_filtering' ], 999 );
		add_action( 'loop_start', [ $this->restrictions_handler, 'display_restricted_taxonomy_term_notice' ], 1 );
	}

	/**
	 * Assert the registered priority of each content restriction callback.
	 *
	 * Asserting the priority pins the three numbers the production removals
	 * hard-code against the ones this test registers with — the two have to agree
	 * or a remove_action() is a silent no-op. They come from Memberships'
	 * handle_restriction_modes(), src/Restrictions/Posts.php:107-123.
	 *
	 * `restrict_post` sits at priority 0, so the value is compared, never treated
	 * as a boolean: has_action() returns the priority, and 0 is falsy.
	 *
	 * @param int|false $the_post    Expected priority of the_post/restrict_post.
	 * @param int|false $the_content Expected priority of the_content/handle_restricted_post_content_filtering.
	 * @param int|false $loop_start  Expected priority of loop_start/display_restricted_taxonomy_term_notice.
	 */
	private function assert_hook_priorities( $the_post, $the_content, $loop_start ) {
		$this->assertSame(
			$the_post,
			has_action( 'the_post', [ $this->restrictions_handler, 'restrict_post' ] ),
			'the_post/restrict_post'
		);
		$this->assertSame(
			$the_content,
			has_filter( 'the_content', [ $this->restrictions_handler, 'handle_restricted_post_content_filtering' ] ),
			'the_content/handle_restricted_post_content_filtering'
		);
		$this->assertSame(
			$loop_start,
			has_action( 'loop_start', [ $this->restrictions_handler, 'display_restricted_taxonomy_term_notice' ] ),
			'loop_start/display_restricted_taxonomy_term_notice'
		);
	}

	/**
	 * A category feed is an archive, and its items carry full post bodies. The two
	 * callbacks that blank a restricted body are the only thing standing between a
	 * restricted post and its whole content going out over RSS, so they survive.
	 * The term notice does not: it echoes markup the feed would emit between the
	 * channel head and the first item.
	 */
	public function test_archive_feed_keeps_the_content_restriction_hooks() {
		$this->register_memberships_restriction_hooks();

		// get_term_feed_link() escapes the URL it returns, and go_to() would read
		// the resulting `&amp;` as part of the query var name and drop the term.
		$this->go_to( html_entity_decode( get_term_feed_link( $this->category_id, 'category' ) ) );

		$this->assertTrue( is_feed(), 'Expected a feed request.' );
		$this->assertTrue( is_archive(), 'Expected the category feed to be an archive.' );
		$this->assert_hook_priorities( 0, 999, false );
	}

	/**
	 * The HTML archive keeps the optimization the handler exists for: there the
	 * callbacks only rewrite excerpts, and dropping them saves the per-post rule
	 * lookups.
	 */
	public function test_html_archive_still_drops_restriction_hooks() {
		$this->register_memberships_restriction_hooks();

		$this->go_to( get_term_link( $this->category_id, 'category' ) );

		$this->assertFalse( is_feed(), 'Expected a page request, not a feed.' );
		$this->assertTrue( is_archive(), 'Expected a category archive.' );
		$this->assert_hook_priorities( false, false, false );
	}
}
