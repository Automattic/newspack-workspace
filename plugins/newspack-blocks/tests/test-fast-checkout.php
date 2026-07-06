<?php
/**
 * Tests for the Fast Checkout class.
 *
 * @package Newspack_Blocks
 * @group fast-checkout
 */

use Newspack_Blocks\Fast_Checkout;

/**
 * Fast Checkout test case.
 */
class Test_Fast_Checkout extends WP_UnitTestCase_Blocks {

	/**
	 * Reset cache before each test.
	 */
	public function set_up() {
		parent::set_up();
		Fast_Checkout::reset_cache();
	}

	/**
	 * Test that a simple product attribute resolves to the product ID.
	 */
	public function test_resolve_simple_product() {
		$result = Fast_Checkout::resolve_product_id_from_attrs( [ 'product' => '42' ] );
		$this->assertSame( 42, $result );
	}

	/**
	 * Test that a variable product with variation resolves to the variation ID.
	 */
	public function test_resolve_variable_prefers_variation() {
		$result = Fast_Checkout::resolve_product_id_from_attrs(
			[
				'product'     => '42',
				'variation'   => '99',
				'is_variable' => true,
			]
		);
		$this->assertSame( 99, $result );
	}

	/**
	 * Test that a variable product without variation falls back to product ID.
	 */
	public function test_resolve_variable_without_variation_falls_back() {
		$result = Fast_Checkout::resolve_product_id_from_attrs(
			[
				'product'     => '42',
				'is_variable' => true,
			]
		);
		$this->assertSame( 42, $result );
	}

	/**
	 * Test that missing attributes return null.
	 */
	public function test_resolve_missing_returns_null() {
		$result = Fast_Checkout::resolve_product_id_from_attrs( [] );
		$this->assertNull( $result );
	}

	/**
	 * Test extracting product ID from a top-level Fast Checkout block.
	 */
	public function test_get_block_product_id_top_level() {
		$post_id = self::factory()->post->create(
			[
				'post_content' => '<!-- wp:newspack-blocks/fast-checkout {"product":"55"} /-->',
			]
		);
		$post    = get_post( $post_id );
		$result  = Fast_Checkout::get_block_product_id( $post );
		$this->assertSame( 55, $result );
	}

	/**
	 * Test extracting product ID from a Fast Checkout block nested in columns.
	 */
	public function test_get_block_product_id_nested_in_columns() {
		$content = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:newspack-blocks/fast-checkout {"product":"77"} /--></div><!-- /wp:column --></div><!-- /wp:columns -->';
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );
		$post    = get_post( $post_id );
		$result  = Fast_Checkout::get_block_product_id( $post );
		$this->assertSame( 77, $result );
	}

	/**
	 * Test that a post without a Fast Checkout block returns null.
	 */
	public function test_get_block_product_id_absent() {
		$post_id = self::factory()->post->create(
			[
				'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			]
		);
		$post    = get_post( $post_id );
		$result  = Fast_Checkout::get_block_product_id( $post );
		$this->assertNull( $result );
	}

	/**
	 * Test that null post returns null.
	 */
	public function test_get_block_product_id_handles_null_post() {
		$result = Fast_Checkout::get_block_product_id( null );
		$this->assertNull( $result );
	}

	// ---- Core block context opt-in tests ----

	/**
	 * Test that core/heading gets both context keys, preserving existing ones.
	 */
	public function test_add_context_to_core_blocks_extends_heading() {
		$metadata = [
			'name'        => 'core/heading',
			'usesContext' => [ 'postId' ],
		];
		$result   = Fast_Checkout::add_context_to_core_blocks( $metadata );
		$this->assertContains( 'postId', $result['usesContext'] );
		$this->assertContains( Fast_Checkout::CONTEXT_PRODUCT_KEY, $result['usesContext'] );
		$this->assertContains( Fast_Checkout::CONTEXT_VARIATION_KEY, $result['usesContext'] );
	}

	/**
	 * Test that unrelated blocks are not modified.
	 */
	public function test_add_context_to_core_blocks_skips_unrelated() {
		$metadata = [
			'name'        => 'core/button',
			'usesContext' => [ 'postId' ],
		];
		$result   = Fast_Checkout::add_context_to_core_blocks( $metadata );
		$this->assertSame( [ 'postId' ], $result['usesContext'] );
	}

	/**
	 * Test that blocks without usesContext get context keys added.
	 */
	public function test_add_context_to_core_blocks_handles_missing_uses_context() {
		$metadata = [ 'name' => 'core/image' ];
		$result   = Fast_Checkout::add_context_to_core_blocks( $metadata );
		$this->assertContains( Fast_Checkout::CONTEXT_PRODUCT_KEY, $result['usesContext'] );
		$this->assertContains( Fast_Checkout::CONTEXT_VARIATION_KEY, $result['usesContext'] );
		$this->assertCount( 2, $result['usesContext'] );
	}

	// ---- Render filter tests ----

	/**
	 * Test that filter_render passes through when product is valid.
	 */
	public function test_filter_render_passes_through_when_product_valid() {
		$this->skip_without_wc();

		$product  = $this->create_simple_product();
		$content  = '<div class="wp-block-newspack-blocks-fast-checkout">Buy now</div>';
		$block    = [ 'attrs' => [ 'product' => (string) $product->get_id() ] ];
		$filtered = Fast_Checkout::filter_render( $content, $block );
		$this->assertSame( $content, $filtered );
	}

	/**
	 * Test that filter_render shows unavailable notice for missing product.
	 *
	 * Requires real WooCommerce: relies on wc_get_product() returning false for a
	 * non-existent product ID. The blocks PHPUnit stub instead returns a bare
	 * product object without is_purchasable(), so this must skip without WC.
	 */
	public function test_filter_render_replaces_when_product_missing() {
		$this->skip_without_wc();

		$content  = '<div>Buy now</div>';
		$block    = [ 'attrs' => [ 'product' => '999999' ] ];
		$filtered = Fast_Checkout::filter_render( $content, $block );
		$this->assertStringContainsString( 'unavailable', $filtered );
		$this->assertStringNotContainsString( 'Buy now', $filtered );
	}

	/**
	 * Test that filter_render shows unavailable notice when no product ID.
	 */
	public function test_filter_render_replaces_when_no_product_id() {
		$content  = '<div>Buy now</div>';
		$block    = [ 'attrs' => [] ];
		$filtered = Fast_Checkout::filter_render( $content, $block );
		$this->assertStringContainsString( 'unavailable', $filtered );
	}

	// ---- Cart replacement tests (WC-dependent) ----

	/**
	 * Skip the current test if WooCommerce is not available.
	 */
	private function skip_without_wc() {
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available.' );
		}
	}

	/**
	 * Create a post containing a Fast Checkout block for the given product ID.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $extra_attrs Additional block attributes.
	 * @return int Post ID.
	 */
	private function make_fast_checkout_post( $product_id, $extra_attrs = [] ) {
		$attrs   = array_merge( [ 'product' => (string) $product_id ], $extra_attrs );
		$json    = wp_json_encode( $attrs );
		$content = sprintf( '<!-- wp:newspack-blocks/fast-checkout %s /-->', $json );
		return self::factory()->post->create( [ 'post_content' => $content ] );
	}

	/**
	 * Create a simple WC product.
	 *
	 * @return \WC_Product_Simple
	 */
	private function create_simple_product() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_regular_price( '10.00' );
		$product->set_status( 'publish' );
		$product->save();
		return $product;
	}

	/**
	 * Test that maybe_replace_cart does nothing when no block is present.
	 */
	public function test_maybe_replace_cart_noop_without_block() {
		$this->skip_without_wc();

		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:paragraph --><p>No block here</p><!-- /wp:paragraph -->' ]
		);
		$this->go_to( get_permalink( $post_id ) );
		Fast_Checkout::maybe_replace_cart();
		$this->assertCount( 0, WC()->cart->get_cart() );
	}

	/**
	 * Test that maybe_replace_cart adds the product to the cart.
	 */
	public function test_maybe_replace_cart_adds_product() {
		$this->skip_without_wc();

		$product = $this->create_simple_product();
		$post_id = $this->make_fast_checkout_post( $product->get_id() );
		$this->go_to( get_permalink( $post_id ) );

		Fast_Checkout::maybe_replace_cart();

		$cart_contents = WC()->cart->get_cart();
		$this->assertCount( 1, $cart_contents );

		$item = reset( $cart_contents );
		$this->assertSame( $product->get_id(), (int) $item['product_id'] );
		$this->assertSame( $post_id, $item[ Fast_Checkout::CART_ITEM_SOURCE_KEY ] );
	}

	/**
	 * Test that calling maybe_replace_cart twice is idempotent.
	 */
	public function test_maybe_replace_cart_is_idempotent() {
		$this->skip_without_wc();

		$product = $this->create_simple_product();
		$post_id = $this->make_fast_checkout_post( $product->get_id() );
		$this->go_to( get_permalink( $post_id ) );

		Fast_Checkout::maybe_replace_cart();
		$keys_first = array_keys( WC()->cart->get_cart() );

		Fast_Checkout::reset_cache();
		Fast_Checkout::maybe_replace_cart();
		$keys_second = array_keys( WC()->cart->get_cart() );

		$this->assertSame( $keys_first, $keys_second );
	}

	// ---- Bindings source tests ----

	/**
	 * Test that the bindings source is registered.
	 */
	public function test_bindings_source_is_registered() {
		if ( ! class_exists( 'WP_Block_Bindings_Registry' ) ) {
			$this->markTestSkipped( 'WP_Block_Bindings_Registry not available.' );
		}
		$registry = WP_Block_Bindings_Registry::get_instance();
		$source   = $registry->get_registered( Fast_Checkout::BINDINGS_SOURCE );
		$this->assertNotNull( $source, 'Bindings source should be registered.' );
	}

	/**
	 * Test that the title field resolves to the product name.
	 */
	public function test_bindings_title_resolves() {
		$this->skip_without_wc();

		$product = $this->create_simple_product();
		$block   = (object) [
			'context' => [
				Fast_Checkout::CONTEXT_PRODUCT_KEY   => $product->get_id(),
				Fast_Checkout::CONTEXT_VARIATION_KEY => 0,
			],
		];
		$result  = Fast_Checkout::bindings_get_value( [ 'field' => 'title' ], $block, 'content' );
		$this->assertSame( $product->get_name(), $result );
	}

	/**
	 * Test that a missing product returns an empty string.
	 */
	public function test_bindings_missing_product_returns_empty_string() {
		$block  = (object) [
			'context' => [
				Fast_Checkout::CONTEXT_PRODUCT_KEY   => 0,
				Fast_Checkout::CONTEXT_VARIATION_KEY => 0,
			],
		];
		$result = Fast_Checkout::bindings_get_value( [ 'field' => 'title' ], $block, 'content' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test that an unknown field returns an empty string.
	 */
	public function test_bindings_unknown_field_returns_empty_string() {
		$this->skip_without_wc();

		$product = $this->create_simple_product();
		$block   = (object) [
			'context' => [
				Fast_Checkout::CONTEXT_PRODUCT_KEY   => $product->get_id(),
				Fast_Checkout::CONTEXT_VARIATION_KEY => 0,
			],
		];
		$result  = Fast_Checkout::bindings_get_value( [ 'field' => 'nonexistent' ], $block, 'content' );
		$this->assertSame( '', $result );
	}

	// ---- Post-purchase redirect tests (WC-dependent) ----

	/**
	 * Test that attach_line_item_meta copies the source post ID.
	 */
	public function test_attach_line_item_meta_copies_source_post() {
		$this->skip_without_wc();

		$item = new \WC_Order_Item_Product();
		$values = [ Fast_Checkout::CART_ITEM_SOURCE_KEY => 123 ];
		Fast_Checkout::attach_line_item_meta( $item, 'key', $values, null );
		$this->assertSame( 123, $item->get_meta( Fast_Checkout::CART_ITEM_SOURCE_KEY ) );
	}

	/**
	 * Test that attach_line_item_meta is a no-op without source key.
	 */
	public function test_attach_line_item_meta_noop_without_source() {
		$this->skip_without_wc();

		$item = new \WC_Order_Item_Product();
		Fast_Checkout::attach_line_item_meta( $item, 'key', [], null );
		$this->assertSame( '', $item->get_meta( Fast_Checkout::CART_ITEM_SOURCE_KEY ) );
	}

	/**
	 * Test that return URL is overridden when order has source post with custom URL.
	 */
	public function test_return_url_override_uses_custom_url() {
		$this->skip_without_wc();

		$product = $this->create_simple_product();
		$post_id = $this->make_fast_checkout_post(
			$product->get_id(),
			[ 'afterSuccessURL' => 'https://example.com/thank-you' ]
		);

		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->add_meta_data( Fast_Checkout::CART_ITEM_SOURCE_KEY, $post_id, true );
		$order->add_item( $item );
		$order->save();

		$result = Fast_Checkout::maybe_override_return_url( 'https://default.com', $order );
		$this->assertSame( 'https://example.com/thank-you', $result );
	}

	/**
	 * Test that return URL passes through when block has no afterSuccessURL.
	 */
	public function test_return_url_override_passes_through_when_no_custom_url() {
		$this->skip_without_wc();

		$product = $this->create_simple_product();
		$post_id = $this->make_fast_checkout_post( $product->get_id() );

		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->add_meta_data( Fast_Checkout::CART_ITEM_SOURCE_KEY, $post_id, true );
		$order->add_item( $item );
		$order->save();

		$result = Fast_Checkout::maybe_override_return_url( 'https://default.com', $order );
		$this->assertSame( 'https://default.com', $result );
	}

	/**
	 * Test that return URL passes through for unrelated orders.
	 */
	public function test_return_url_override_ignores_unrelated_orders() {
		$this->skip_without_wc();

		$product = $this->create_simple_product();
		$order   = wc_create_order();
		$item    = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$order->add_item( $item );
		$order->save();

		$result = Fast_Checkout::maybe_override_return_url( 'https://default.com', $order );
		$this->assertSame( 'https://default.com', $result );
	}

	/**
	 * Test that a mismatched product in the cart is replaced.
	 */
	public function test_maybe_replace_cart_replaces_mismatched() {
		$this->skip_without_wc();

		$product_a = $this->create_simple_product();
		$product_b = $this->create_simple_product();

		// Add product A to cart first.
		WC()->cart->add_to_cart( $product_a->get_id() );
		$this->assertCount( 1, WC()->cart->get_cart() );

		// Post references product B.
		$post_id = $this->make_fast_checkout_post( $product_b->get_id() );
		$this->go_to( get_permalink( $post_id ) );

		Fast_Checkout::maybe_replace_cart();

		$cart_contents = WC()->cart->get_cart();
		$this->assertCount( 1, $cart_contents );

		$item = reset( $cart_contents );
		$this->assertSame( $product_b->get_id(), (int) $item['product_id'] );
	}
}
