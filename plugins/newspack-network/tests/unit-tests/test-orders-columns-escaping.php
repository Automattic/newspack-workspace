<?php
/**
 * Tests that the hub Orders list table escapes node-supplied output.
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Hub\Admin\Orders;
use Newspack_Network\Hub\Database\Orders as Orders_DB;

/**
 * Verify the order column escapes node-supplied values.
 *
 * Orders and Subscriptions render equivalent anchors from the same Woo_Item
 * getters, so this file is the Orders half of the sibling Subscriptions suite.
 */
class Test_Orders_Columns_Escaping extends WP_UnitTestCase {

	/**
	 * The XSS marker. Breaks out of an unescaped attribute without needing `<`,
	 * so it survives a filter that only strips tags.
	 */
	const PAYLOAD = '" onmouseover=NPPM3042 x="';

	/**
	 * Create an order CPT post whose node-supplied fields carry the payload.
	 *
	 * The title is set with wp_update_post rather than at creation: the create
	 * path runs wp_filter_post_kses, which would neutralise the payload and
	 * leave the assertion passing on core's filtering instead of on the
	 * escaping under test.
	 *
	 * remote_id carries the payload too, because get_edit_link() concatenates
	 * it into the href. Without that, every URL in the fixture descends from
	 * get_node_url(), which falls back to get_bloginfo( 'url' ) when no node
	 * post exists -- a well-formed local URL that esc_url() returns unchanged,
	 * leaving the href escaping unpinned.
	 *
	 * @return int Post ID.
	 */
	private function make_order() {
		$post_id = self::factory()->post->create( [ 'post_type' => Orders_DB::POST_TYPE_SLUG ] );

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => self::PAYLOAD,
			]
		);

		update_post_meta( $post_id, 'user_name', self::PAYLOAD );
		update_post_meta( $post_id, 'remote_id', '42' . self::PAYLOAD );

		return $post_id;
	}

	/**
	 * Render a column and return the echoed HTML.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	private function render( $column, $post_id ) {
		ob_start();
		Orders::posts_columns_values( $column, $post_id );
		return ob_get_clean();
	}

	/**
	 * The order column must not emit a breakable attribute or a live handler.
	 */
	public function test_order_column_is_escaped() {
		$out = $this->render( 'order', $this->make_order() );

		$this->assertNotSame( '', $out, 'Precondition: the column rendered something.' );

		// The marker text survives inside the href as inert characters, which is
		// what escaping does -- it neutralises the quote that would leave the
		// attribute, it does not delete the word. So assert on the quote rather
		// than on the word: the payload must never appear as written.
		$this->assertStringNotContainsString( self::PAYLOAD, $out, 'The raw payload survived: ' . $out );

		// Two anchors, each carrying its two href quotes and two target quotes.
		// A reflected value that escaped an attribute would add a ninth.
		$this->assertSame( 8, substr_count( $out, '"' ), 'An attribute was broken out of: ' . $out );
	}

	/**
	 * The href is escaped, not merely absent: the payload reaches it through
	 * remote_id and must come back neutralised rather than dropped.
	 */
	public function test_edit_link_href_is_escaped() {
		$out = $this->render( 'order', $this->make_order() );

		preg_match( '/href="([^"]*)"/', $out, $matches );
		$href = isset( $matches[1] ) ? $matches[1] : '';

		$this->assertNotSame( '', $href, 'Precondition: the edit link has an href.' );
		$this->assertStringContainsString( 'post=42', $href, 'The remote id is still in the href.' );

		// esc_url() percent-encodes the whitespace the payload injects. Asserting
		// that encoding is what pins esc_url() to this call site: without it the
		// href would be a well-formed local URL that esc_url() returns unchanged,
		// and removing the escaper would leave this test green.
		$this->assertStringContainsString( '%20', $href, 'esc_url() did not encode the injected whitespace.' );
	}

	/**
	 * The user name is plain text and must be escaped where it is interpolated
	 * between the two anchors.
	 */
	public function test_user_name_is_escaped() {
		$out = $this->render( 'order', $this->make_order() );

		$this->assertStringContainsString( '&quot; onmouseover=NPPM3042 x=&quot;', $out, 'The user name was not escaped.' );
	}
}
