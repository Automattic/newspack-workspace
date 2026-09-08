<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * The contextual prompt source triple travels from cart item data to order meta,
 * validated on the way in. Uses a duck-typed order stub: the hook only calls
 * add_meta_data(), so no WooCommerce is needed.
 *
 * @package Newspack_Popups
 */

/**
 * Contextual prompt attribution test.
 */
class ContextualPromptAttributionTest extends WP_UnitTestCase {
	/**
	 * Minimal stand-in for WC_Order.
	 *
	 * @return object
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
	 * Run the order-line-item hook with cart values and return the meta written.
	 *
	 * @param array $values Cart item values.
	 * @return array
	 */
	private function meta_for( $values ) {
		$order = $this->order_stub();
		Newspack_Popups_Data_Api::checkout_create_order_line_item( null, null, $values, $order );
		return $order->meta;
	}

	/**
	 * A valid triple is written under the _newspack_ prefix.
	 */
	public function test_valid_source_reaches_order_meta() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$meta    = $this->meta_for(
			[
				'contextual_prompt_post_id'   => (string) $post_id,
				'contextual_prompt_placement' => 'mid',
				'contextual_prompt_condition' => 'generic_control',
			]
		);
		$this->assertSame( $post_id, $meta['_newspack_contextual_prompt_post_id'] );
		$this->assertSame( 'mid', $meta['_newspack_contextual_prompt_placement'] );
		$this->assertSame( 'generic_control', $meta['_newspack_contextual_prompt_condition'] );
	}

	/**
	 * A post id that isn't a published post writes nothing at all.
	 */
	public function test_junk_post_id_writes_nothing() {
		$meta = $this->meta_for(
			[
				'contextual_prompt_post_id'   => '999999',
				'contextual_prompt_placement' => 'mid',
			] 
		);
		$this->assertArrayNotHasKey( '_newspack_contextual_prompt_post_id', $meta );
		$this->assertArrayNotHasKey( '_newspack_contextual_prompt_placement', $meta );
	}

	/**
	 * Placement and condition outside their allowlists are dropped; the id still lands.
	 */
	public function test_unknown_placement_and_condition_are_dropped() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$meta    = $this->meta_for(
			[
				'contextual_prompt_post_id'   => $post_id,
				'contextual_prompt_placement' => 'sideways',
				'contextual_prompt_condition' => 'winner',
			]
		);
		$this->assertSame( $post_id, $meta['_newspack_contextual_prompt_post_id'] );
		$this->assertArrayNotHasKey( '_newspack_contextual_prompt_placement', $meta );
		$this->assertArrayNotHasKey( '_newspack_contextual_prompt_condition', $meta );
	}

	/**
	 * Existing prompt attribution is untouched.
	 */
	public function test_popup_meta_still_written() {
		$meta = $this->meta_for(
			[
				'newspack_popup_id' => 7,
				'prompt_title'      => 'T',
			] 
		);
		$this->assertSame( 7, $meta['_newspack_popup_id'] );
	}
}
