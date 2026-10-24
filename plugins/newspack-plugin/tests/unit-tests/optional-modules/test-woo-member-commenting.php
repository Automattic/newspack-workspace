<?php
/**
 * Tests for the Woo Member Commenting optional module.
 *
 * @package Newspack\Tests
 */

use Newspack\Optional_Modules;
use Newspack\Woo_Member_Commenting;

/**
 * With the module on, readers who hold one of the configured plans comment as
 * usual and everyone else is turned away, whether they use the form or submit
 * a comment some other way.
 *
 * Every test runs isolated: the module reads its plans from a constant and
 * WooCommerce Memberships from a global function, and neither can be undone
 * once declared.
 *
 * @group Woo_Member_Commenting
 */
class Newspack_Test_Woo_Member_Commenting extends WP_UnitTestCase {

	/**
	 * Plan that allows commenting.
	 */
	const PLAN = 'commenting-plan';

	/**
	 * A post open for comments.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-memberships-user-memberships-mock.php';
		if ( ! defined( 'NP_WC_MEMBER_COMMENT_SETTINGS' ) ) {
			define( 'NP_WC_MEMBER_COMMENT_SETTINGS', [ 'membership_plan_slug' => self::PLAN ] );
		}
		Optional_Modules::activate_optional_module( 'woo-member-commenting' );
		// Each test submits several comments within a second.
		remove_filter( 'check_comment_flood', 'check_comment_flood_db' );
		// Answers the display-name prompt readers get, as the form would.
		$_POST['comment_display_name'] = 'Test Reader';
		$this->post_id                 = self::factory()->post->create( [ 'comment_status' => 'open' ] );
		// The module hooks in on init, which has already run.
		Woo_Member_Commenting::on_init();
	}

	/**
	 * Create a reader, optionally holding the commenting plan.
	 *
	 * @param bool $with_plan Whether the reader holds the plan.
	 *
	 * @return int User ID.
	 */
	private function create_reader( bool $with_plan ): int {
		global $newspack_test_user_plan_slugs;
		$user_id = self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'display_name' => 'Test Reader',
			]
		);
		$newspack_test_user_plan_slugs[ $user_id ] = $with_plan ? [ self::PLAN ] : [ 'other-plan' ];
		return $user_id;
	}

	/**
	 * Submit a comment through wp-comments-post.php's handler.
	 *
	 * @param int $user_id User to submit as; 0 for a logged-out visitor.
	 *
	 * @return WP_Comment|WP_Error
	 */
	private function submit_comment_as( int $user_id ) {
		wp_set_current_user( $user_id );
		return wp_handle_comment_submission(
			[
				'comment_post_ID' => $this->post_id,
				'comment'         => 'A comment ' . wp_generate_password( 8, false ),
				'author'          => 'Visitor',
				'email'           => 'visitor@example.test',
			]
		);
	}

	/**
	 * Assert a submission was refused by this module, with the status the
	 * comment handler sends.
	 *
	 * @param WP_Comment|WP_Error $result  Submission result.
	 * @param string              $message Assertion message.
	 */
	private function assert_refused( $result, string $message ): void {
		$this->assertWPError( $result, $message );
		$this->assertSame( Woo_Member_Commenting::ERROR_CODE, $result->get_error_code(), $message );
		$this->assertSame( 403, $result->get_error_data(), $message );
	}

	/**
	 * Create a comment through the REST API.
	 *
	 * @param int        $user_id User to submit as.
	 * @param mixed|null $type    Comment type to send; null to leave it out.
	 *
	 * @return int Response status.
	 */
	private function rest_create_comment_as( int $user_id, $type = null ): int {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'post', $this->post_id );
		if ( null !== $type ) {
			$request->set_param( 'type', $type );
		}
		$request->set_param( 'content', 'A comment ' . wp_generate_password( 8, false ) );
		return rest_get_server()->dispatch( $request )->get_status();
	}

	/**
	 * A reader without the plan, and a logged-out visitor, are turned away when
	 * they submit a comment directly; a reader with the plan is not.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_comment_submission_requires_the_plan() {
		$without_plan = $this->create_reader( false );
		$with_plan    = $this->create_reader( true );

		$this->assert_refused( $this->submit_comment_as( $without_plan ), 'Reader without the plan.' );
		$this->assert_refused( $this->submit_comment_as( 0 ), 'Logged-out visitor.' );
		$this->assertInstanceOf( WP_Comment::class, $this->submit_comment_as( $with_plan ) );
	}

	/**
	 * The requirement holds when whoever was current at init could have
	 * commented freely: the hooks depend on the module's settings, not on that user.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_requirement_applies_whoever_was_current_at_init() {
		// set_up() registered these as a logged-out user; drop them so only this on_init() can refuse.
		remove_filter( 'pre_comment_approved', [ Woo_Member_Commenting::class, 'refuse_comment_submission' ] );
		remove_filter( 'rest_preprocess_comment', [ Woo_Member_Commenting::class, 'refuse_rest_comment' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		Woo_Member_Commenting::on_init();

		$this->assert_refused( $this->submit_comment_as( $this->create_reader( false ) ), 'Reader without the plan.' );
	}

	/**
	 * Pingbacks, trackbacks and product reviews are not reader comments, so a
	 * reader without the plan can still leave them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_non_reader_comment_types_are_not_refused() {
		wp_set_current_user( $this->create_reader( false ) );
		foreach ( [ 'pingback', 'trackback', 'review' ] as $comment_type ) {
			$comment_id = wp_new_comment(
				[
					'comment_post_ID'      => $this->post_id,
					'comment_content'      => 'A ' . $comment_type . ' ' . wp_generate_password( 8, false ),
					'comment_type'         => $comment_type,
					'comment_author'       => 'Visitor',
					'comment_author_email' => 'visitor@example.test',
					'comment_author_url'   => 'https://example.test/',
				],
				true
			);
			$this->assertIsInt( $comment_id, $comment_type );
		}
	}

	/**
	 * The REST API applies the same requirement.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_comment_creation_requires_the_plan() {
		$without_plan = $this->create_reader( false );

		$this->assertSame( 403, $this->rest_create_comment_as( $without_plan ) );
		$this->assertSame( 403, $this->rest_create_comment_as( $without_plan, 'comment' ) );
		// Types WordPress stores as a plain comment.
		$this->assertSame( 403, $this->rest_create_comment_as( $without_plan, '0' ) );
		$this->assertSame( 403, $this->rest_create_comment_as( $without_plan, 0 ) );
		$this->assertSame( 403, $this->rest_create_comment_as( $without_plan, [] ) );
		$this->assertSame( 201, $this->rest_create_comment_as( $this->create_reader( true ) ) );
	}

	/**
	 * A note is left to core's rule that its author can edit the post, which a
	 * role can meet without edit_posts.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notes_are_not_refused() {
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role
			'test_page_editor',
			'Test page editor',
			[
				'read'                 => true,
				'edit_pages'           => true,
				'edit_others_pages'    => true,
				'edit_published_pages' => true,
			]
		);
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'test_page_editor' ] ) );
		$this->assertTrue( Woo_Member_Commenting::require_membership_to_comment_for_user(), 'The role is subject to the requirement.' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'post', $page_id );
		$request->set_param( 'type', 'note' );
		$request->set_param( 'content', 'A note ' . wp_generate_password( 8, false ) );

		$this->assertSame( 201, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Editors are outside the module's scope and comment without a plan.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_editors_comment_without_the_plan() {
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		$this->assertInstanceOf( WP_Comment::class, $this->submit_comment_as( $editor_id ) );
		$this->assertSame( 201, $this->rest_create_comment_as( $editor_id ) );
	}

	/**
	 * Each user gets their own answer within one request, whoever was asked first.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_membership_answer_is_per_user() {
		$without_plan = $this->create_reader( false );
		$with_plan    = $this->create_reader( true );

		wp_set_current_user( $without_plan );
		$this->assertTrue( Woo_Member_Commenting::require_membership_to_comment_for_user() );
		wp_set_current_user( $with_plan );
		$this->assertFalse( Woo_Member_Commenting::require_membership_to_comment_for_user() );
		wp_set_current_user( $without_plan );
		$this->assertTrue( Woo_Member_Commenting::require_membership_to_comment_for_user() );
	}

	/**
	 * Skipping the cache recomputes the answer, including on the first call.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_skip_cache_recomputes_the_answer() {
		global $newspack_test_user_plan_slugs;
		$reader_id = $this->create_reader( false );
		wp_set_current_user( $reader_id );

		$this->assertTrue( Woo_Member_Commenting::require_membership_to_comment_for_user( true ) );
		$newspack_test_user_plan_slugs[ $reader_id ] = [ self::PLAN ];
		$this->assertTrue( Woo_Member_Commenting::require_membership_to_comment_for_user(), 'Cached.' );
		$this->assertFalse( Woo_Member_Commenting::require_membership_to_comment_for_user( true ) );
	}
}
