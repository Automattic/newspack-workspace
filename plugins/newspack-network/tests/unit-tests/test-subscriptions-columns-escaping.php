<?php
/**
 * Tests that the hub Subscriptions list table escapes node-supplied output.
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Hub\Admin\Subscriptions;
use Newspack_Network\Hub\Database\Subscriptions as Subscriptions_DB;

/**
 * Verify the subscription/items/total columns escape node-supplied values.
 */
class Test_Subscriptions_Columns_Escaping extends WP_UnitTestCase {

	/**
	 * Create a subscription CPT post carrying payload-bearing meta.
	 *
	 * @param string $payload The XSS marker payload.
	 * @return int Post ID.
	 */
	private function make_subscription( $payload ) {
		$post_id = self::factory()->post->create( [ 'post_type' => Subscriptions_DB::POST_TYPE_SLUG ] );

		// wp_filter_post_kses runs for any user without unfiltered_html, on create
		// and update alike, and strips this payload to an empty string. A title
		// written as the default user therefore never reaches get_title(), and the
		// subscription column's assertion passes on the adjacent escaped
		// get_user_name() instead of on the title. Writing it as an administrator
		// (who holds unfiltered_html on single site, which is how these run) puts
		// the payload where the column can render it, so the escaping under test
		// is what has to neutralise it.
		$previous = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => $payload, // Read back by get_title().
			]
		);
		wp_set_current_user( $previous );
		update_post_meta( $post_id, 'user_name', $payload );
		update_post_meta( $post_id, 'formatted_total', $payload );
		update_post_meta( $post_id, 'payment_method_title', $payload );
		update_post_meta( $post_id, 'payment_count', $payload );
		update_post_meta( $post_id, 'remote_id', '42' );
		add_post_meta(
			$post_id,
			'products',
			[
				'id'   => 5,
				'name' => $payload,
			]
		);
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
		Subscriptions::posts_columns_values( $column, $post_id );
		return ob_get_clean();
	}

	/**
	 * The subscription, items and total columns must be escaped.
	 */
	public function test_columns_are_escaped() {
		$payload = '<img src=x onerror=NPPM3042>';
		$post_id = $this->make_subscription( $payload );

		foreach ( [ 'subscription', 'items', 'orders', 'total' ] as $column ) {
			$out = $this->render( $column, $post_id );
			$this->assertStringNotContainsString( '<img src=x', $out, "Column {$column} rendered a live tag" );
			$this->assertStringContainsString( '&lt;img src=x onerror=NPPM3042&gt;', $out, "Column {$column} not escaped" );
		}
	}

	/**
	 * The formatted_total meta is plain text: a mixed legit+malicious value
	 * is fully escaped (no executable payload survives).
	 */
	public function test_total_neutralizes_mixed_payload() {
		$post_id = $this->make_subscription( 'x' );
		update_post_meta( $post_id, 'formatted_total', '<span class="amount">$1</span><script>x()</script>' );

		$out = $this->render( 'total', $post_id );
		$this->assertStringNotContainsString( '<script>', $out );
		$this->assertStringNotContainsString( '<span class="amount">', $out );
		$this->assertStringContainsString( '&lt;script&gt;', $out );
	}
}
