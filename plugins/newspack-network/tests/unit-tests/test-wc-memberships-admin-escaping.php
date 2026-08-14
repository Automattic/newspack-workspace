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
}
