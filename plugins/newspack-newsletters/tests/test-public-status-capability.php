<?php
/**
 * Class Newsletters Test Public_Status_Capability
 *
 * @package Newspack_Newsletters
 */

/**
 * Tests the one rule that governs who may set a newsletter's public-page flag, and
 * the guard that enforces it on the write itself rather than at each entry point.
 *
 * The flag is not a label: the service provider moves the post between `private` and
 * `publish` to match it, so setting it true publishes a page.
 */
class Public_Status_Capability_Test extends WP_UnitTestCase {

	/**
	 * Register the newsletter CPT. Not redundant: the WP test suite does not register
	 * it, and without it `map_meta_cap()` cannot resolve `edit_post` for this post type
	 * -- every capability assertion here then trips an incorrect-usage notice. A full
	 * suite run hides that, because another test file registers the CPT first, so this
	 * only shows up when the class runs on its own.
	 */
	public function set_up() {
		parent::set_up();
		\Newspack_Newsletters::register_cpt();
	}

	/**
	 * Create a newsletter owned by the given user.
	 *
	 * @param int    $author Author user ID.
	 * @param string $status Post status.
	 *
	 * @return int Newsletter post ID.
	 */
	private function make_newsletter( $author, $status = 'private' ) {
		return self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => $status,
				'post_author' => $author,
			]
		);
	}

	/**
	 * The guard covers writers that never consult the rule themselves -- the REST meta
	 * route among them. A direct write stands in for that route: both reach
	 * update_metadata(), which is where the filter runs.
	 */
	public function test_guard_blocks_an_escalating_write_without_publish_capability() {
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$newsletter  = $this->make_newsletter( $contributor );
		wp_set_current_user( $contributor );

		$this->assertTrue( current_user_can( 'edit_post', $newsletter ), 'Precondition: the author can edit their own newsletter.' );
		$this->assertFalse( current_user_can( 'publish_post', $newsletter ), 'Precondition: the author cannot publish.' );

		update_post_meta( $newsletter, 'is_public', true );

		$this->assertFalse( (bool) get_post_meta( $newsletter, 'is_public', true ), 'The write must be blocked, not merely uncounted.' );
	}

	/**
	 * The de-escalating direction needs only edit_post, on every writer.
	 */
	public function test_guard_allows_the_de_escalating_write() {
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$newsletter  = $this->make_newsletter( $contributor );
		update_post_meta( $newsletter, 'is_public', true );
		wp_set_current_user( $contributor );

		update_post_meta( $newsletter, 'is_public', false );

		$this->assertFalse( (bool) get_post_meta( $newsletter, 'is_public', true ), 'Turning a page non-public must stay available to anyone who can edit it.' );
	}

	/**
	 * The publish capability maps to the primitive publish_posts and never consults the post's
	 * author, so it can only ever be an addition to edit_post. An Author holds
	 * publish_posts; without edit_others_posts they must not reach someone else's
	 * newsletter.
	 */
	public function test_publish_capability_alone_does_not_reach_another_authors_newsletter() {
		$owner  = self::factory()->user->create( [ 'role' => 'author' ] );
		$actor  = self::factory()->user->create( [ 'role' => 'author' ] );
		$letter = $this->make_newsletter( $owner );
		wp_set_current_user( $actor );

		$this->assertTrue( current_user_can( 'publish_posts' ), 'Precondition: an Author holds publish_posts.' );
		$this->assertFalse( current_user_can( 'edit_post', $letter ), 'Precondition: they cannot edit another author\'s newsletter.' );

		$this->assertFalse(
			\Newspack_Newsletters::current_user_can_set_public_status( $letter, true ),
			'Ownership must still gate the escalating direction.'
		);

		update_post_meta( $letter, 'is_public', true );
		$this->assertFalse( (bool) get_post_meta( $letter, 'is_public', true ), 'The guard must block it too.' );
	}

	/**
	 * A capability belongs to a user, and there is none on a WP-CLI run, a cron event
	 * or a migration. Those writes must pass through rather than fail closed.
	 */
	public function test_guard_leaves_writes_with_no_current_user_alone() {
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$newsletter  = $this->make_newsletter( $contributor );
		wp_set_current_user( 0 );

		update_post_meta( $newsletter, 'is_public', true );

		$this->assertTrue( (bool) get_post_meta( $newsletter, 'is_public', true ), 'A programmatic write must not be blocked.' );
	}

	/**
	 * The guard is scoped to this meta key on this post type.
	 */
	public function test_guard_ignores_other_keys_and_post_types() {
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$newsletter  = $this->make_newsletter( $contributor );
		$plain_post  = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_author' => $contributor,
			]
		);
		wp_set_current_user( $contributor );

		update_post_meta( $newsletter, 'some_other_key', 'value' );
		$this->assertSame( 'value', get_post_meta( $newsletter, 'some_other_key', true ), 'Another key on a newsletter is not the guard\'s business.' );

		update_post_meta( $plain_post, 'is_public', true );
		$this->assertTrue( (bool) get_post_meta( $plain_post, 'is_public', true ), 'The same key on another post type is not the guard\'s business.' );
	}

	/**
	 * The classic quick-edit save consults the same rule. Without this the guard would
	 * still block the write, but the handler would not know it had been skipped.
	 */
	public function test_quick_edit_save_requires_publish_capability_to_make_public() {
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$newsletter  = $this->make_newsletter( $contributor );
		wp_set_current_user( $contributor );

		$_POST['newspack_nl_quick_edit_nonce'] = wp_create_nonce( 'newspack_nl_quick_edit' );
		$_POST['switch_public_page']           = '1';

		\Newspack_Newsletters_Quick_Edit::save( $newsletter );

		$this->assertFalse( (bool) get_post_meta( $newsletter, 'is_public', true ), 'Quick edit must not make a page public without publish capability.' );

		unset( $_POST['newspack_nl_quick_edit_nonce'], $_POST['switch_public_page'] );
	}

	/**
	 * The quick-edit de-escalating direction stays available on edit_post alone.
	 */
	public function test_quick_edit_save_allows_the_de_escalating_direction() {
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$newsletter  = $this->make_newsletter( $contributor );
		update_post_meta( $newsletter, 'is_public', true );
		wp_set_current_user( $contributor );

		$_POST['newspack_nl_quick_edit_nonce'] = wp_create_nonce( 'newspack_nl_quick_edit' );
		// The box is unchecked, so switch_public_page is absent.

		\Newspack_Newsletters_Quick_Edit::save( $newsletter );

		$this->assertFalse( (bool) get_post_meta( $newsletter, 'is_public', true ), 'Turning the page off must still work.' );

		unset( $_POST['newspack_nl_quick_edit_nonce'] );
	}
}
