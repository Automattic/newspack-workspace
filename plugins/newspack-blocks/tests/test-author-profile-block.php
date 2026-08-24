<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Author Profile block tests.
 *
 * @package Newspack_Blocks
 */

/**
 * Author Profile block avatar handling tests.
 */
class Newspack_Blocks_Author_Profile_Test extends WP_UnitTestCase {
	/**
	 * Enable avatars site-wide and boot a REST server for the editor-path test.
	 *
	 * `show_avatars` defaults to on in core's schema, but it must be explicit
	 * here: with it off, get_avatar() returns false and the "avatar is hidden"
	 * assertions below would pass for the wrong reason — against unfixed code
	 * too — silently turning these regression tests into no-ops.
	 */
	public function set_up() {
		parent::set_up();

		update_option( 'show_avatars', 1 );

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Tear down the REST server.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * When "Hide default avatar" is enabled, a user whose avatar is served by
	 * Gravatar's generated fallback (i.e. no uploaded avatar) must not get an
	 * avatar. Gravatar URLs never carry the `avatar-default` class core only
	 * emits when no avatar was found, so detection must force the `blank`
	 * fallback and match on it — mirroring the Author List block.
	 */
	public function test_hide_default_avatar_excludes_gravatar_fallback() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'nppm2626-no-gravatar@example.com' ] );

		$author = newspack_blocks_get_author_or_guest_author( $user_id, 128, true, false );

		$this->assertNotFalse( $author );
		$this->assertArrayNotHasKey( 'avatar', $author, 'Gravatar-fallback avatar must be excluded when avatarHideDefault is enabled.' );
	}

	/**
	 * With the toggle off, the avatar renders as before.
	 */
	public function test_avatar_present_when_hide_default_off() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'nppm2626-control@example.com' ] );

		$author = newspack_blocks_get_author_or_guest_author( $user_id, 128, false, false );

		$this->assertNotFalse( $author );
		$this->assertArrayHasKey( 'avatar', $author );
		$this->assertStringContainsString( '<img', $author['avatar'] );
	}

	/**
	 * An avatar supplied locally (an uploaded image, or any plugin hooking
	 * `pre_get_avatar`) carries neither `avatar-default` nor `d=blank`, so it
	 * must survive the toggle. This is the boundary that keeps the accepted
	 * trade-off limited to Gravatar-served images rather than all images —
	 * a future tightening of the detection would break here first.
	 */
	public function test_locally_supplied_avatar_survives_hide_default() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'nppm2626-local-avatar@example.com' ] );

		$local_avatar = '<img src="https://example.org/uploads/local-avatar.jpg" class="avatar avatar-128 photo" height="128" width="128" />';

		$filter = function () use ( $local_avatar ) {
			return $local_avatar;
		};
		add_filter( 'pre_get_avatar', $filter );

		$author = newspack_blocks_get_author_or_guest_author( $user_id, 128, true, false );

		remove_filter( 'pre_get_avatar', $filter );

		$this->assertArrayHasKey( 'avatar', $author, 'A locally supplied avatar must not be treated as a default.' );
		$this->assertSame( $local_avatar, $author['avatar'] );
	}

	/**
	 * The editor preview reads authors through the REST controller rather than
	 * the front-end render path, so the toggle has to be honored there too.
	 * This is the path NPPM-163 reported as broken.
	 */
	public function test_rest_authors_honors_hide_default_avatar() {
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$user_id = self::factory()->user->create(
			[
				'role'       => 'author',
				'user_email' => 'nppm2626-rest@example.com',
			]
		);

		$hidden = $this->get_rest_author( $user_id, true );
		$this->assertArrayNotHasKey( 'avatar', $hidden, 'REST response must omit the Gravatar-fallback avatar when avatar_hide_default is set.' );

		$shown = $this->get_rest_author( $user_id, false );
		$this->assertArrayHasKey( 'avatar', $shown, 'REST response must include the avatar when avatar_hide_default is unset.' );
	}

	/**
	 * The contextual branch of the endpoint resolves a post's authors instead of
	 * an explicit ID, and is a separate code path from the one above. Without
	 * Co-Authors Plus it falls through to the post_author lookup, which is the
	 * remaining changed call site in this controller.
	 */
	public function test_rest_authors_honors_hide_default_avatar_in_post_context() {
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$author_id = self::factory()->user->create(
			[
				'role'       => 'author',
				'user_email' => 'nppm2626-post-context@example.com',
			]
		);
		$post_id   = self::factory()->post->create( [ 'post_author' => $author_id ] );

		$hidden = $this->get_rest_post_author( $post_id, true );
		$this->assertArrayNotHasKey( 'avatar', $hidden, 'Post-context response must omit the Gravatar-fallback avatar when avatar_hide_default is set.' );

		$shown = $this->get_rest_post_author( $post_id, false );
		$this->assertArrayHasKey( 'avatar', $shown, 'Post-context response must include the avatar when avatar_hide_default is unset.' );
	}

	/**
	 * Dispatch the authors endpoint for a single WP user and return its entry.
	 *
	 * @param int  $author_id    WP user ID to fetch.
	 * @param bool $hide_default Value for the avatar_hide_default param.
	 *
	 * @return array Author data for the requested user.
	 */
	private function get_rest_author( $author_id, $hide_default ) {
		$request = new WP_REST_Request( 'GET', '/newspack-blocks/v1/authors' );
		$request->set_param( 'author_id', $author_id );
		$request->set_param( 'is_guest_author', 0 );
		$request->set_param( 'fields', 'avatar' );
		$request->set_param( 'avatar_hide_default', $hide_default ? 1 : 0 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( $author_id, $data[0]['id'] );

		return $data[0];
	}

	/**
	 * Dispatch the authors endpoint in post context and return the sole author.
	 *
	 * @param int  $post_id      Post whose authors to fetch.
	 * @param bool $hide_default Value for the avatar_hide_default param.
	 *
	 * @return array Author data for the post's author.
	 */
	private function get_rest_post_author( $post_id, $hide_default ) {
		$request = new WP_REST_Request( 'GET', '/newspack-blocks/v1/authors' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'fields', 'avatar' );
		$request->set_param( 'avatar_hide_default', $hide_default ? 1 : 0 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertCount( 1, $data );

		return $data[0];
	}
}
