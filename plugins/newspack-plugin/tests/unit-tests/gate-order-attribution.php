<?php
/**
 * Tests gate-id validation before it reaches order meta (NPPD-1887).
 *
 * `_gate_post_id` is written from a client-supplied form field. Since NPPD-1887 that
 * field can also be REPLAYED from sessionStorage onto a landing-page checkout form,
 * so the value is validated before it is persisted. Insights GROUPs its per-gate
 * breakdown by this meta value, so a junk id doesn't just fail to attribute — it
 * invents a gate.
 *
 * Uses a duck-typed order stub: checkout_create_order_line_item() only ever calls
 * add_meta_data(), so the test needs no WooCommerce.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events\Memberships;

/**
 * Test gate-id validation.
 */
class Newspack_Test_Gate_Order_Attribution extends WP_UnitTestCase {

	/**
	 * Minimal stand-in for WC_Order. Anonymous so the file holds one class, per PHPCS.
	 *
	 * @return object An object exposing add_meta_data() and a public $meta array.
	 */
	private function order_stub() {
		return new class() {
			/**
			 * Collected meta.
			 *
			 * @var array
			 */
			public $meta = [];

			/**
			 * Record a meta write.
			 *
			 * @param string $key   Meta key.
			 * @param mixed  $value Meta value.
			 */
			public function add_meta_data( $key, $value ) {
				$this->meta[ $key ] = $value;
			}
		};
	}

	/**
	 * Run the order-line-item hook with a candidate gate id and return the meta written.
	 *
	 * @param mixed $gate_post_id Candidate id.
	 * @return array
	 */
	private function meta_for( $gate_post_id ) {
		$order = $this->order_stub();
		Memberships::checkout_create_order_line_item( null, null, [ 'gate_post_id' => $gate_post_id ], $order );
		return $order->meta;
	}

	/**
	 * The gate id must be collected on BOTH cart paths.
	 *
	 * Donations add to cart through Donations::process_donation_request(), which applies
	 * `newspack_donations_cart_item_data` and never the modal-checkout filter. Without a
	 * listener there, a gate CTA pointing at the donation page carries `gate_post_id` all
	 * the way into `INPUT_GET` and then silently drops it — the client-side replay looks
	 * wired but nothing persists. Caught by driving a real donation, not by unit tests.
	 */
	public function test_gate_id_is_collected_on_both_cart_paths() {
		foreach ( [ 'newspack_blocks_modal_checkout_cart_item_data', 'newspack_donations_cart_item_data' ] as $filter ) {
			$this->assertNotFalse(
				has_filter( $filter, [ Memberships::class, 'checkout_cart_item_data' ] ),
				"Memberships::checkout_cart_item_data must listen on $filter"
			);
		}
	}

	/**
	 * A real gate is attributed, and persisted as an integer.
	 */
	public function test_real_gate_is_attributed_as_int() {
		$gate_id = self::factory()->post->create( [ 'post_type' => \Newspack\Content_Gate::GATE_CPT ] );

		$meta = $this->meta_for( $gate_id );
		$this->assertSame( $gate_id, $meta['_gate_post_id'] );
		$this->assertIsInt( $meta['_gate_post_id'] );
	}

	/**
	 * The validator casts, so the WRITER must persist the cast value — not the raw
	 * candidate. `(int) '123 OR 1=1'` is 123: validating the cast while writing the
	 * original would land a malformed string in `_gate_post_id`, and Insights groups
	 * its per-gate breakdown by that value.
	 *
	 * Found by driving a real checkout in a local environment, not by reading the code.
	 */
	public function test_malformed_id_is_normalized_not_persisted_raw() {
		$gate_id = self::factory()->post->create( [ 'post_type' => \Newspack\Content_Gate::GATE_CPT ] );

		foreach ( [ "$gate_id OR 1=1", "{$gate_id}abc", " $gate_id " ] as $candidate ) {
			$meta = $this->meta_for( $candidate );
			$this->assertSame( $gate_id, $meta['_gate_post_id'], "Candidate: $candidate" );
			$this->assertIsInt( $meta['_gate_post_id'], "Candidate: $candidate" );
		}
	}

	/**
	 * A post that exists but is not a gate is rejected outright.
	 */
	public function test_non_gate_post_is_rejected() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$this->assertArrayNotHasKey( '_gate_post_id', $this->meta_for( $post_id ) );
	}

	/**
	 * Nonexistent, zero, negative and non-numeric ids are all rejected.
	 *
	 * @dataProvider bad_ids
	 * @param mixed $candidate The id that must not be attributed.
	 */
	public function test_bad_ids_are_rejected( $candidate ) {
		$this->assertArrayNotHasKey( '_gate_post_id', $this->meta_for( $candidate ), var_export( $candidate, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	}

	/**
	 * Ids that must never reach order meta.
	 *
	 * @return array
	 */
	public function bad_ids() {
		return [
			'nonexistent'  => [ 99999999 ],
			'zero'         => [ 0 ],
			'negative'     => [ -5 ],
			'non-numeric'  => [ 'abc' ],
			'empty string' => [ '' ],
		];
	}
}
