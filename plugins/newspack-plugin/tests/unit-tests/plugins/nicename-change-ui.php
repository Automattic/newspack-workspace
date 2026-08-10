<?php
/**
 * Tests the authorization of the nicename-change AJAX handlers.
 *
 * @package Newspack\Tests
 */

use Newspack\Nicename_Change_UI;

/**
 * The handlers take a caller-supplied `user_id` and pass it to wp_update_user(), so a
 * nonce alone is not enough: it proves the request came from a page, not that the caller
 * may edit that particular user. These tests pin the object-scoped capability check.
 *
 * Note on reachability: the nonce is only printed on user-edit.php (via edit_user_profile),
 * which requires `edit_users` -- administrators only. So these tests hand the caller a nonce
 * it could not obtain in production. That is deliberate: the capability check is the layer
 * under test, and it must hold on its own rather than leaning on the nonce's scarcity.
 *
 * @group nicename-change
 */
class Test_Nicename_Change_UI extends WP_Ajax_UnitTestCase {

	/**
	 * A user the attacker is not allowed to edit.
	 *
	 * @var int
	 */
	private $victim_user_id;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->victim_user_id = self::factory()->user->create(
			[
				'role'          => 'administrator',
				'user_nicename' => 'original-slug',
			]
		);
	}

	/**
	 * Tear down. The parent resets $_POST/$_GET but not $_REQUEST, which is where
	 * check_ajax_referer() reads the nonce from -- clear it so it cannot leak into
	 * another test.
	 */
	public function tear_down() {
		unset( $_REQUEST['nonce'] );
		parent::tear_down();
	}

	/**
	 * Invoke the handler with a valid nonce, swallowing the wp_send_json() exit.
	 *
	 * @param int    $actor_user_id  The user making the request.
	 * @param int    $target_user_id The user_id sent in the payload.
	 * @param string $new_nicename   The requested nicename.
	 */
	private function post_nicename_change( $actor_user_id, $target_user_id, $new_nicename ) {
		wp_set_current_user( $actor_user_id );

		$_POST['user_id']      = $target_user_id;
		$_POST['new_nicename'] = $new_nicename;
		// check_ajax_referer() reads the nonce from $_REQUEST, which PHPUnit does not
		// populate from $_POST -- set it there or the handler dies before doing any work.
		$_REQUEST['nonce'] = wp_create_nonce( 'newspack_change_nicename_nonce' );

		// The handler echoes JSON and terminates; buffer it so the response body does not
		// leak into the test output, and swallow the die exception. The assertions below
		// care about the side effect on the target user, not the response body.
		// WP_Ajax_UnitTestCase's die handler closes a buffer on its way out, so unwind to
		// the level we started at rather than assuming a matching ob_end_clean().
		$initial_ob_level = ob_get_level();
		ob_start();
		try {
			Nicename_Change_UI::change_nicename_ajax();
		} catch ( WPAjaxDieContinueException | WPAjaxDieStopException ) {
			return;
		} finally {
			while ( ob_get_level() > $initial_ob_level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Get a user's nicename straight from the DB, bypassing any cached user object.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return string The nicename.
	 */
	private function get_nicename( $user_id ) {
		clean_user_cache( $user_id );
		return get_userdata( $user_id )->user_nicename;
	}

	/**
	 * A subscriber must not be able to rewrite an administrator's archive slug.
	 */
	public function test_subscriber_cannot_change_another_users_nicename() {
		$subscriber_user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->post_nicename_change( $subscriber_user_id, $this->victim_user_id, 'hijacked-slug' );

		$this->assertSame(
			'original-slug',
			$this->get_nicename( $this->victim_user_id ),
			'A subscriber changed an administrator nicename -- the handler is missing its capability check.'
		);
	}

	/**
	 * An editor has `edit_posts` but not `edit_users`, so it must also be rejected.
	 */
	public function test_editor_cannot_change_another_users_nicename() {
		$editor_user_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		$this->post_nicename_change( $editor_user_id, $this->victim_user_id, 'hijacked-by-editor' );

		$this->assertSame(
			'original-slug',
			$this->get_nicename( $this->victim_user_id ),
			'An editor changed another user nicename -- the handler is missing its capability check.'
		);
	}

	/**
	 * The legitimate path must keep working: an administrator may still change the slug.
	 */
	public function test_administrator_can_still_change_nicename() {
		$admin_user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->post_nicename_change( $admin_user_id, $this->victim_user_id, 'admin-set-slug' );

		$this->assertSame(
			'admin-set-slug',
			$this->get_nicename( $this->victim_user_id ),
			'An administrator should be able to change a user nicename.'
		);
	}
}
