<?php
/**
 * Class Test_Email_Editor_Request
 *
 * @package Newspack_Newsletters
 */

/**
 * Tests for Newspack_Newsletters_Editor::is_email_editor_request().
 *
 * Regression coverage for: using URL params instead of get_the_ID() to
 * determine whether the current request is the newsletter editor. The
 * old approach produced false positives when setup_postdata() was called
 * with a newsletter post during block rendering on non-newsletter pages
 * (e.g. a Content Loop block configured to display newsletter posts).
 */
class Test_Email_Editor_Request extends WP_UnitTestCase {

	/**
	 * A newsletter post ID created for testing.
	 *
	 * @var int
	 */
	private static $newsletter_post_id;

	/**
	 * A newsletter ad post ID created for testing.
	 *
	 * @var int
	 */
	private static $newsletter_ad_post_id;

	/**
	 * A regular post ID created for testing.
	 *
	 * @var int
	 */
	private static $regular_post_id;

	/**
	 * Original value of $pagenow.
	 *
	 * @var string
	 */
	private $original_pagenow;

	/**
	 * Original $_GET values.
	 *
	 * @var array
	 */
	private $original_get;

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$newsletter_post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
			]
		);

		self::$newsletter_ad_post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters\Ads::CPT,
				'post_status' => 'draft',
			]
		);

		self::$regular_post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
			]
		);
	}

	/**
	 * Save and reset global state before each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->original_pagenow = $GLOBALS['pagenow'] ?? null;
		$this->original_get     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET                   = []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Restore global state after each test.
	 */
	public function tear_down() {
		if ( null === $this->original_pagenow ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->original_pagenow;
		}
		$_GET = $this->original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		parent::tear_down();
	}

	/**
	 * Returns true when editing an existing newsletter post (post.php + ?post=<newsletter_id>).
	 */
	public function test_returns_true_when_editing_newsletter_post() {
		global $pagenow;
		$pagenow          = 'post.php';
		$_GET['post']     = self::$newsletter_post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->assertTrue( Newspack_Newsletters_Editor::is_email_editor_request() );
	}

	/**
	 * Returns true when creating a new newsletter (post-new.php + ?post_type=<newsletter_cpt>).
	 */
	public function test_returns_true_when_creating_newsletter() {
		global $pagenow;
		$pagenow              = 'post-new.php';
		$_GET['post_type']    = \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->assertTrue( Newspack_Newsletters_Editor::is_email_editor_request() );
	}

	/**
	 * Returns false when editing a regular post — even if get_the_ID() would
	 * return a newsletter post ID (the regression case: setup_postdata() called
	 * with a newsletter post during block rendering on a non-newsletter page).
	 */
	public function test_returns_false_for_regular_post_with_newsletter_in_loop() {
		global $pagenow, $post;
		$pagenow      = 'post.php';
		$_GET['post'] = self::$regular_post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Simulate what a Content Loop block does: call setup_postdata() with a
		// newsletter post, making get_the_ID() return the newsletter's ID.
		$newsletter_post = get_post( self::$newsletter_post_id );
		setup_postdata( $newsletter_post );

		$result = Newspack_Newsletters_Editor::is_email_editor_request();

		wp_reset_postdata();

		$this->assertFalse( $result );
	}

	/**
	 * Returns false when on post.php with no ?post param.
	 */
	public function test_returns_false_when_no_post_param() {
		global $pagenow;
		$pagenow = 'post.php';

		$this->assertFalse( Newspack_Newsletters_Editor::is_email_editor_request() );
	}

	/**
	 * Returns false when on post-new.php with no ?post_type param.
	 */
	public function test_returns_false_when_no_post_type_param() {
		global $pagenow;
		$pagenow = 'post-new.php';

		$this->assertFalse( Newspack_Newsletters_Editor::is_email_editor_request() );
	}

	/**
	 * Returns false when on an unrelated admin page.
	 */
	public function test_returns_false_on_non_editor_page() {
		global $pagenow;
		$pagenow = 'edit.php';

		$this->assertFalse( Newspack_Newsletters_Editor::is_email_editor_request() );
	}

	/**
	 * Returns false when post.php is used but the post is a regular post type.
	 */
	public function test_returns_false_when_editing_non_newsletter_post() {
		global $pagenow;
		$pagenow      = 'post.php';
		$_GET['post'] = self::$regular_post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->assertFalse( Newspack_Newsletters_Editor::is_email_editor_request() );
	}

	/**
	 * Returns false when post-new.php is used with a non-newsletter post type.
	 */
	public function test_returns_false_when_creating_non_newsletter_post() {
		global $pagenow;
		$pagenow           = 'post-new.php';
		$_GET['post_type'] = 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->assertFalse( Newspack_Newsletters_Editor::is_email_editor_request() );
	}

	/**
	 * Returns true when editing an existing newsletter ad (post.php + ad post ID).
	 */
	public function test_returns_true_when_editing_newsletter_ad() {
		global $pagenow;
		$pagenow      = 'post.php';
		$_GET['post'] = self::$newsletter_ad_post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->assertTrue( Newspack_Newsletters_Editor::is_email_editor_request() );
	}

	/**
	 * Returns true when creating a new newsletter ad.
	 */
	public function test_returns_true_when_creating_newsletter_ad() {
		global $pagenow;
		$pagenow           = 'post-new.php';
		$_GET['post_type'] = \Newspack_Newsletters\Ads::CPT; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->assertTrue( Newspack_Newsletters_Editor::is_email_editor_request() );
	}
}
