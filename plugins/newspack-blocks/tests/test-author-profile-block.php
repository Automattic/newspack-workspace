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
}
