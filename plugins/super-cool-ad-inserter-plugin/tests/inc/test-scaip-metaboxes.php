<?php
/**
 * Tests for the SCAIP metaboxes / sidebar panel module.
 *
 * @package super-cool-ad-inserter-plugin
 */

/**
 * Test the auth callback used by register_post_meta for
 * scaip_prevent_shortcode_addition.
 *
 * The function under test, scaip_prevent_shortcode_addition_auth_callback(),
 * is defined in inc/scaip-metaboxes.php.
 */
class ScaipMetaboxesTestFunctions extends WP_UnitTestCase {

	/**
	 * Authors do not have edit_others_posts, so the meta auth callback
	 * must reject their REST writes.
	 */
	public function test_auth_callback_denies_users_without_edit_others_posts() {
		$author_id = $this->factory->user->create( array( 'role' => 'author' ) );

		$result = scaip_prevent_shortcode_addition_auth_callback(
			false,
			'scaip_prevent_shortcode_addition',
			0,
			$author_id
		);

		$this->assertFalse( $result );
	}

	/**
	 * Editors have edit_others_posts, so the meta auth callback must
	 * allow their REST writes.
	 */
	public function test_auth_callback_allows_users_with_edit_others_posts() {
		$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$result = scaip_prevent_shortcode_addition_auth_callback(
			false,
			'scaip_prevent_shortcode_addition',
			0,
			$editor_id
		);

		$this->assertTrue( $result );
	}
}
