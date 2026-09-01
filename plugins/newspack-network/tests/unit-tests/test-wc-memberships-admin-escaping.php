<?php
/**
 * Tests that the WC Memberships admin row action escapes the node site URL.
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Woocommerce_Memberships\Admin;

/**
 * Verify the "Managed in <site>" row action escapes node-supplied values.
 */
class Test_WC_Memberships_Admin_Escaping extends WP_UnitTestCase {

	/**
	 * The row action must escape the node site URL in href and text.
	 */
	public function test_row_action_escapes_site_url() {
		$payload = '<img src=x onerror=NPPM3042>';
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Admin::NETWORK_MANAGED_META_KEY, '1' );
		update_post_meta( $post_id, Admin::SITE_URL_META_KEY, $payload );
		update_post_meta( $post_id, Admin::REMOTE_ID_META_KEY, '11' );

		$actions = Admin::post_row_actions( [], get_post( $post_id ) );
		$html    = implode( ' ', $actions );

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=NPPM3042&gt;', $html );
	}

	/**
	 * The href is escaped, and this is the assertion that says so.
	 *
	 * The site URL reaches both the link text and, through get_edit_post_link(),
	 * the href. A tag-shaped payload is neutralised by the esc_html() on the text
	 * alone, so the test above passes with or without the esc_url() on the href.
	 * This one uses a payload that only escaping the attribute can stop, and
	 * pins the transformation esc_url() performs.
	 */
	public function test_row_action_escapes_the_href() {
		$payload = 'https://node.test" onmouseover=NPPM3042 x="';
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Admin::NETWORK_MANAGED_META_KEY, '1' );
		update_post_meta( $post_id, Admin::SITE_URL_META_KEY, $payload );
		update_post_meta( $post_id, Admin::REMOTE_ID_META_KEY, '11' );

		$html = implode( ' ', Admin::post_row_actions( [], get_post( $post_id ) ) );

		// One anchor: two href quotes and two target quotes. A value that escaped
		// the attribute would add a fifth.
		$this->assertSame( 4, substr_count( $html, '"' ), 'An attribute was broken out of: ' . $html );

		preg_match( '/href="([^"]*)"/', $html, $matches );
		$href = isset( $matches[1] ) ? $matches[1] : '';
		$this->assertNotSame( '', $href, 'Precondition: the row action has an href.' );
		$this->assertStringContainsString( '%20', $href, 'esc_url() did not encode the injected whitespace.' );
	}
}
