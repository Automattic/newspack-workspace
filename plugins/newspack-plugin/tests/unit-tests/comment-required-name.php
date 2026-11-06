<?php
/**
 * Tests the "Require a first and last name to comment" setting.
 *
 * @package Newspack\Tests
 */

use Newspack\Content_Gate_Advanced_Settings;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Comment_Display_Name;

/**
 * Every site asks for a name that is not the reader's email handle, and any name
 * will do. The publisher's setting raises that to a first and last name: readers
 * must have both, and their comments are published under them.
 *
 * @group comment-display-name
 */
class Newspack_Test_Comment_Required_Name extends WP_UnitTestCase {

	/**
	 * Clean up the request and the settings memo.
	 */
	public function tear_down() {
		unset( $_POST['comment_first_name'], $_POST['comment_last_name'], $_POST['comment_display_name'] );
		Content_Gate_Advanced_Settings::reset_cache();
		parent::tear_down();
	}

	/**
	 * Turn the setting on.
	 */
	private function require_name() {
		Content_Gate_Advanced_Settings::update_settings( [ 'comment_require_full_name' => true ] );
	}

	/**
	 * Log in a reader with a chosen display name.
	 *
	 * @param array $profile Profile fields to set.
	 *
	 * @return int User ID.
	 */
	private function log_in_reader( $profile = [] ) {
		$user_id = Reader_Activation::register_reader( 'reader' . wp_rand() . '@example.test' );
		wp_update_user( array_merge( [ 'ID' => $user_id ], [ 'display_name' => 'Chosen Name' ], $profile ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Assert the form and the submission both pass through untouched.
	 *
	 * @param array $commentdata Comment data.
	 */
	private function assert_comment_form_unchanged( $commentdata ) {
		$this->assertSame( '<submit />', Comment_Display_Name::render_display_name_field( '<submit />' ) );
		$this->assertSame( $commentdata, Comment_Display_Name::validate_display_name( $commentdata ) );
	}

	/**
	 * Comment data as wp-comments-post.php builds it for a logged-in reader.
	 *
	 * @return array
	 */
	private function get_commentdata() {
		return [
			'comment_post_ID' => $this->factory->post->create(),
			'comment_author'  => wp_get_current_user()->display_name,
		];
	}

	/**
	 * Off by default, a reader without names sees nothing new and comments freely.
	 */
	public function test_setting_off_changes_nothing() {
		$this->log_in_reader();
		$commentdata = $this->get_commentdata();

		$this->assertSame( 0, Content_Gate_Advanced_Settings::get_settings()['comment_require_full_name'] );
		$this->assert_comment_form_unchanged( $commentdata );
	}

	/**
	 * A reader missing one name sees both fields, with the name they have filled in.
	 */
	public function test_renders_both_fields_for_reader_missing_a_name() {
		$this->require_name();
		$this->log_in_reader( [ 'first_name' => 'Ada' ] );

		$output = Comment_Display_Name::render_display_name_field( '<submit />' );

		$this->assertMatchesRegularExpression( '/name="comment_first_name"[^>]*value="Ada"[^>]*required/', $output );
		$this->assertMatchesRegularExpression( '/name="comment_last_name"[^>]*value=""[^>]*required/', $output );
		$this->assertStringContainsString( 'New comments show the first and last name below.', $output );
		$this->assertStringEndsWith( '<submit />', $output );
	}

	/**
	 * Display names that need no confirmation from a reader with both names.
	 *
	 * @return array
	 */
	public function data_display_names() {
		return [
			'same as names' => [ 'Ada King' ],
			'email-derived' => [ 'handle-reader' ],
		];
	}

	/**
	 * A reader with both names and no other chosen public name is asked for nothing,
	 * not even by the email-derived prompt, because the handle cannot be published. Form
	 * and REST comments go out under the names, and the display name stays on the profile.
	 *
	 * @dataProvider data_display_names
	 *
	 * @param string $display_name Display name on the profile.
	 */
	public function test_reader_with_both_names_comments_under_them( $display_name ) {
		$this->require_name();
		$user_id = Reader_Activation::register_reader( 'handle.reader@example.test' );
		wp_update_user(
			[
				'ID'           => $user_id,
				'display_name' => $display_name,
				'first_name'   => 'Ada',
				'last_name'    => 'King',
			]
		);
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );

		$output = Comment_Display_Name::render_display_name_field( '<submit />' );
		$this->assertStringContainsString( 'Your comment will show as Ada King.', $output );
		$this->assertStringNotContainsString( '<input', $output );
		$result = Comment_Display_Name::validate_display_name( $this->get_commentdata() );

		$this->assertSame( 'Ada King', $result['comment_author'] );

		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_body_params(
			[
				'post'    => $this->factory->post->create(),
				'content' => 'A reader comment',
			]
		);
		$created = rest_do_request( $request );
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'Ada King', get_comment( $created->get_data()['id'] )->comment_author );

		$this->assertSame( $display_name, get_userdata( $user_id )->display_name );
	}

	/**
	 * Names on file may come from billing details, so a reader with another public name
	 * confirms them once, in the form, before comments change to them.
	 */
	public function test_reader_with_other_display_name_confirms_once() {
		$this->require_name();
		$user_id = $this->log_in_reader(
			[
				'first_name' => 'Ada',
				'last_name'  => 'King',
			]
		);

		$output = Comment_Display_Name::render_display_name_field( '<submit />' );
		$this->assertMatchesRegularExpression( '/name="comment_first_name"[^>]*value="Ada"/', $output );
		$this->assertStringContainsString( 'Check both before posting.', $output );

		try {
			Comment_Display_Name::validate_display_name( $this->get_commentdata() );
			$this->fail( 'The comment should have been refused.' );
		} catch ( WPDieException $refusal ) {
			$this->assertEmpty( get_user_meta( $user_id, Comment_Display_Name::NAME_CONFIRMED_META, true ) );
		}

		$_POST['comment_first_name'] = 'Ada';
		$_POST['comment_last_name']  = 'Queen';
		$result = Comment_Display_Name::validate_display_name( $this->get_commentdata() );
		$this->assertSame( 'Ada Queen', $result['comment_author'] );
		$this->assertSame( 'Chosen Name', get_userdata( $user_id )->display_name );

		unset( $_POST['comment_first_name'], $_POST['comment_last_name'] );
		$this->assertStringContainsString( 'Your comment will show as Ada Queen.', Comment_Display_Name::render_display_name_field( '<submit />' ) );
		$this->assertSame( 'Ada Queen', Comment_Display_Name::validate_display_name( $this->get_commentdata() )['comment_author'] );
	}

	/**
	 * Staff are not readers, so the requirement does not reach them.
	 */
	public function test_non_reader_is_exempt() {
		$this->require_name();
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );
		$this->assert_comment_form_unchanged( $this->get_commentdata() );
	}

	/**
	 * A product's comment form collects reviews, which the setting does not cover.
	 */
	public function test_product_reviews_are_exempt() {
		$this->require_name();
		$this->log_in_reader();
		$product_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		$GLOBALS['post'] = get_post( $product_id );
		setup_postdata( $GLOBALS['post'] );
		$this->assert_comment_form_unchanged( [ 'comment_post_ID' => $product_id ] );
		wp_reset_postdata();
	}

	/**
	 * On a product, the email-derived prompt still fills both names, as it does with
	 * the setting off.
	 */
	public function test_product_review_prompt_still_saves_names() {
		$this->require_name();
		$user_id = Reader_Activation::register_reader( 'handle' . wp_rand() . '@example.test' );
		wp_set_current_user( $user_id );
		$_POST['comment_display_name'] = 'Ada King';

		Comment_Display_Name::validate_display_name( [ 'comment_post_ID' => $this->factory->post->create( [ 'post_type' => 'product' ] ) ] );

		$user = get_userdata( $user_id );
		$this->assertSame( 'Ada', $user->first_name );
		$this->assertSame( 'King', $user->last_name );
	}

	/**
	 * Submissions that leave a name out, or fill it with nothing a profile can hold.
	 *
	 * @return array
	 */
	public function data_incomplete_names() {
		return [
			'no fields'       => [ null, null ],
			'no first name'   => [ '', 'King' ],
			'no last name'    => [ 'Ada', '' ],
			'only whitespace' => [ 'Ada', '   ' ],
			'only markup'     => [ 'Ada', '<b></b>' ],
			'unsaveable name' => [ '0', 'King' ],
			'too long'        => [ str_repeat( 'é', 70 ), str_repeat( 'ü', 70 ) ],
		];
	}

	/**
	 * A comment missing either name is refused, and neither name is saved.
	 *
	 * @dataProvider data_incomplete_names
	 *
	 * @param string|null $first_name Submitted first name.
	 * @param string|null $last_name  Submitted last name.
	 */
	public function test_refuses_comment_without_both_names( $first_name, $last_name ) {
		$this->require_name();
		$user_id = $this->log_in_reader();
		if ( null !== $first_name ) {
			$_POST['comment_first_name'] = $first_name;
			$_POST['comment_last_name']  = $last_name;
		}

		try {
			Comment_Display_Name::validate_display_name( $this->get_commentdata() );
			$this->fail( 'The comment should have been refused.' );
		} catch ( WPDieException $refusal ) {
			$this->assertSame( '', get_userdata( $user_id )->first_name );
			$this->assertSame( '', get_userdata( $user_id )->last_name );
		}
	}

	/**
	 * Saving the names leaves a display name the reader chose on the profile, publishes
	 * this comment under the names, and the next comment needs nothing further.
	 */
	public function test_saves_names_and_keeps_chosen_display_name() {
		$this->require_name();
		$user_id     = $this->log_in_reader();
		$commentdata = $this->get_commentdata();
		$_POST['comment_first_name'] = ' Ada ';
		$_POST['comment_last_name']  = 'O\\\'Brien \\\\ King';

		$result = Comment_Display_Name::validate_display_name( $commentdata );

		$this->assertSame( wp_slash( "Ada O'Brien \\ King" ), $result['comment_author'] );
		$user = get_userdata( $user_id );
		$this->assertSame( 'Ada', $user->first_name );
		$this->assertSame( "O'Brien \\ King", $user->last_name );
		$this->assertSame( 'Chosen Name', $user->display_name );

		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );
		unset( $_POST['comment_first_name'], $_POST['comment_last_name'] );
		$this->assertStringNotContainsString( '<input', Comment_Display_Name::render_display_name_field( '<submit />' ) );
		$this->assertSame( 'Ada O\\\'Brien \\\\ King', Comment_Display_Name::validate_display_name( $commentdata )['comment_author'] );
	}

	/**
	 * A reader who needs both prompts gets the two fields alone, and the names replace
	 * the email-derived display name so the handle is still never published.
	 */
	public function test_names_replace_email_derived_display_name() {
		$this->require_name();
		$user_id = Reader_Activation::register_reader( 'handle' . wp_rand() . '@example.test' );
		wp_set_current_user( $user_id );
		$commentdata = $this->get_commentdata();

		$output = Comment_Display_Name::render_display_name_field( '<submit />' );
		$this->assertStringContainsString( 'name="comment_first_name"', $output );
		$this->assertStringNotContainsString( 'name="comment_display_name"', $output );

		$_POST['comment_first_name'] = 'Ada';
		$_POST['comment_last_name']  = 'Ki\\\\ng';
		$result = Comment_Display_Name::validate_display_name( $commentdata );

		$this->assertSame( 'Ada Ki\\ng', wp_unslash( $result['comment_author'] ) );
		$this->assertSame( 'Ada Ki\\ng', get_userdata( $user_id )->display_name );
		$this->assertFalse( Reader_Activation::reader_has_generic_display_name( $user_id ) );
	}

	/**
	 * On a product the single Name field stays, and a one-word answer must not cost
	 * a reader the last name the site requires everywhere else.
	 */
	public function test_display_name_prompt_keeps_saved_names() {
		$this->require_name();
		$user_id = Reader_Activation::register_reader( 'handle' . wp_rand() . '@example.test' );
		wp_update_user(
			[
				'ID'         => $user_id,
				'first_name' => 'Ada',
				'last_name'  => 'King',
			]
		);
		wp_set_current_user( $user_id );
		$product_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		$GLOBALS['post'] = get_post( $product_id );
		setup_postdata( $GLOBALS['post'] );

		$this->assertStringContainsString( 'name="comment_display_name"', Comment_Display_Name::render_display_name_field( '<submit />' ) );
		wp_reset_postdata();

		$_POST['comment_display_name'] = 'Ada';
		$result = Comment_Display_Name::validate_display_name( [ 'comment_post_ID' => $product_id ] );

		$this->assertSame( 'Ada', $result['comment_author'] );
		$user = get_userdata( $user_id );
		$this->assertSame( 'Ada', $user->first_name );
		$this->assertSame( 'King', $user->last_name );
	}

	/**
	 * Over REST a reader missing a name is refused, as is one who has yet to confirm
	 * their names in the form. Once confirmed, comments are published under them.
	 */
	public function test_rest_requires_and_publishes_full_name() {
		$user_id = $this->log_in_reader();
		$post_id = $this->factory->post->create();
		add_filter( 'comment_flood_filter', '__return_false' );

		$create_comment = function () use ( $post_id ) {
			$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
			$request->set_body_params(
				[
					'post'    => $post_id,
					'content' => 'A reader comment ' . wp_rand(),
				]
			);
			return rest_do_request( $request );
		};

		$this->assertSame( 201, $create_comment()->get_status(), 'The setting is off.' );

		$this->require_name();
		$refused = $create_comment();
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( Comment_Display_Name::ERROR_CODE, $refused->get_data()['code'] );

		update_user_meta( $user_id, 'first_name', 'Ada' );
		update_user_meta( $user_id, 'last_name', wp_slash( 'Ki\\ng' ) );
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );
		$this->assertSame( 403, $create_comment()->get_status(), 'REST has no form to confirm the names in.' );

		update_user_meta( $user_id, Comment_Display_Name::NAME_CONFIRMED_META, true );
		$created = $create_comment();
		$this->assertSame( 201, $created->get_status() );
		$comment = get_comment( $created->get_data()['id'] );
		$this->assertSame( 'Ada Ki\\ng', $comment->comment_author );
		$this->assertSame( $user_id, (int) $comment->user_id );
	}

	/**
	 * The REST API has no form to prompt from, so it refuses a reader whose comment
	 * would publish their email handle, on every site.
	 */
	public function test_rest_refuses_email_derived_display_name() {
		$user_id = Reader_Activation::register_reader( 'handle' . wp_rand() . '@example.test' );
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_body_params(
			[
				'post'    => $this->factory->post->create(),
				'content' => 'A reader comment',
			]
		);

		$refused = rest_do_request( $request );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( Comment_Display_Name::ERROR_CODE, $refused->get_data()['code'] );

		wp_update_user(
			[
				'ID'           => $user_id,
				'display_name' => 'Chosen Name',
			]
		);
		$this->assertSame( 201, rest_do_request( $request )->get_status() );
	}

	/**
	 * A reader who can edit a post has no form to give a name from in the editor, and
	 * a note is never published.
	 */
	public function test_rest_lets_reader_who_can_edit_the_post_leave_a_note() {
		$this->require_name();
		$user_id = $this->log_in_reader();
		( new WP_User( $user_id ) )->set_role( 'author' );
		update_user_meta( $user_id, Reader_Activation::READER, true );
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );

		$post_id   = $this->factory->post->create( [ 'post_author' => $user_id ] );
		$add_note = function () use ( $post_id ) {
			$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
			$request->set_body_params(
				[
					'post'    => $post_id,
					'content' => 'An editor note ' . wp_rand(),
					'type'    => 'note',
				]
			);
			return rest_do_request( $request );
		};

		$this->assertSame( 201, $add_note()->get_status() );

		update_user_meta( $user_id, 'first_name', 'Ada' );
		update_user_meta( $user_id, 'last_name', 'King' );
		$this->assertSame( 'Chosen Name', get_comment( $add_note()->get_data()['id'] )->comment_author, 'A note is never published, so it keeps the display name.' );
	}
}
