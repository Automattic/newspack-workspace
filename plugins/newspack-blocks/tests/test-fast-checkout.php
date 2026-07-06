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
	 * Invoke the private static get_query_params method via Reflection.
	 *
	 * @return array
	 */
	private function invoke_get_query_params(): array {
		$method = ( new ReflectionClass( Fast_Checkout::class ) )->getMethod( 'get_query_params' );
		$method->setAccessible( true );
		return $method->invoke( null );
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
	 */
	public function test_filter_render_replaces_when_product_missing() {
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

	/**
	 * Test that filter_render passes through for a grouped product without
	 * an editor-set grouped_child, by resolving to the first purchasable child.
	 *
	 * Regression: previously called resolve_product_id_from_attrs (attrs-only),
	 * which returned the parent grouped product ID — and grouped parents are
	 * not purchasable, so the unavailable notice rendered instead of content.
	 */
	public function test_filter_render_passes_through_for_grouped_without_child() {
		$this->skip_without_wc();

		$child   = $this->create_simple_product();
		$grouped = $this->create_grouped_product( [ $child->get_id() ] );
		$content = '<div class="wp-block-newspack-blocks-fast-checkout">Buy now</div>';
		$block   = [
			'attrs' => [
				'product'    => (string) $grouped->get_id(),
				'is_grouped' => true,
			],
		];
		$filtered = Fast_Checkout::filter_render( $content, $block );
		$this->assertSame( $content, $filtered );
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
	 * Create a grouped WC product with the given children.
	 *
	 * @param int[] $child_ids Existing child product IDs.
	 * @return \WC_Product_Grouped
	 */
	private function create_grouped_product( $child_ids ) {
		$product = new \WC_Product_Grouped();
		$product->set_name( 'Test Grouped Product' );
		$product->set_status( 'publish' );
		$product->set_children( $child_ids );
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

	// ---- Grouped product resolution tests ----

	/**
	 * Test that a grouped product with grouped_child resolves to the child ID.
	 */
	public function test_resolve_grouped_prefers_child() {
		$result = Fast_Checkout::resolve_product_id_from_attrs(
			[
				'product'       => '42',
				'grouped_child' => '88',
				'is_grouped'    => true,
			]
		);
		$this->assertSame( 88, $result );
	}

	/**
	 * Test that a grouped product without grouped_child returns the parent ID.
	 * Server-side runtime resolution to the first child happens in maybe_replace_cart.
	 */
	public function test_resolve_grouped_without_child_returns_parent() {
		$result = Fast_Checkout::resolve_product_id_from_attrs(
			[
				'product'    => '42',
				'is_grouped' => true,
			]
		);
		// Without runtime WC lookup, the helper returns the parent ID.
		// Runtime resolution to the first child happens in maybe_replace_cart.
		$this->assertSame( 42, $result );
	}

	// ---- Query param tests ----

	/**
	 * Test that fc_grouped_child query param is read into params.
	 */
	public function test_get_query_params_reads_grouped_child() {
		$_GET[ Fast_Checkout::QP_GROUPED_CHILD ] = '123';

		$params = $this->invoke_get_query_params();

		$this->assertSame( 123, $params['grouped_child'] ?? null );

		unset( $_GET[ Fast_Checkout::QP_GROUPED_CHILD ] );
	}

	/**
	 * Test that an invalid (non-numeric) fc_grouped_child is dropped.
	 */
	public function test_get_query_params_rejects_invalid_grouped_child() {
		$_GET[ Fast_Checkout::QP_GROUPED_CHILD ] = 'not-a-number';

		$params = $this->invoke_get_query_params();

		$this->assertArrayNotHasKey( 'grouped_child', $params );

		unset( $_GET[ Fast_Checkout::QP_GROUPED_CHILD ] );
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

	// ---- Cart replacement: grouped product tests ----

	/**
	 * Test that maybe_replace_cart adds grouped_child product to cart when set.
	 */
	public function test_maybe_replace_cart_adds_grouped_child() {
		$this->skip_without_wc();

		$child   = $this->create_simple_product();
		$grouped = $this->create_grouped_product( [ $child->get_id() ] );
		$post_id = $this->make_fast_checkout_post(
			$grouped->get_id(),
			[
				'is_grouped'    => true,
				'grouped_child' => (string) $child->get_id(),
			]
		);
		$this->go_to( get_permalink( $post_id ) );

		Fast_Checkout::maybe_replace_cart();

		$cart_contents = WC()->cart->get_cart();
		$this->assertCount( 1, $cart_contents );
		$item = reset( $cart_contents );
		$this->assertSame( $child->get_id(), (int) $item['product_id'] );
	}

	/**
	 * Test that maybe_replace_cart resolves to the first child when grouped_child is empty.
	 */
	public function test_maybe_replace_cart_grouped_falls_back_to_first_child() {
		$this->skip_without_wc();

		$first   = $this->create_simple_product();
		$second  = $this->create_simple_product();
		$grouped = $this->create_grouped_product( [ $first->get_id(), $second->get_id() ] );
		$post_id = $this->make_fast_checkout_post(
			$grouped->get_id(),
			[ 'is_grouped' => true ]
		);
		$this->go_to( get_permalink( $post_id ) );

		Fast_Checkout::maybe_replace_cart();

		$cart_contents = WC()->cart->get_cart();
		$this->assertCount( 1, $cart_contents );
		$item = reset( $cart_contents );
		$this->assertSame( $first->get_id(), (int) $item['product_id'] );
	}

	/**
	 * Test that fc_grouped_child query param overrides grouped_child attribute.
	 */
	public function test_maybe_replace_cart_grouped_query_param_override() {
		$this->skip_without_wc();

		$first   = $this->create_simple_product();
		$second  = $this->create_simple_product();
		$grouped = $this->create_grouped_product( [ $first->get_id(), $second->get_id() ] );
		$post_id = $this->make_fast_checkout_post(
			$grouped->get_id(),
			[
				'is_grouped'    => true,
				'grouped_child' => (string) $first->get_id(),
			]
		);

		$_GET[ Fast_Checkout::QP_GROUPED_CHILD ] = (string) $second->get_id();
		$this->go_to( get_permalink( $post_id ) );

		Fast_Checkout::maybe_replace_cart();

		$cart_contents = WC()->cart->get_cart();
		$this->assertCount( 1, $cart_contents );
		$item = reset( $cart_contents );
		$this->assertSame( $second->get_id(), (int) $item['product_id'] );

		unset( $_GET[ Fast_Checkout::QP_GROUPED_CHILD ] );
	}

	/**
	 * Test that fc_grouped_child referencing a non-child is rejected (falls back).
	 */
	public function test_maybe_replace_cart_grouped_query_param_rejects_foreign_id() {
		$this->skip_without_wc();

		$first    = $this->create_simple_product();
		$foreign  = $this->create_simple_product();
		$grouped  = $this->create_grouped_product( [ $first->get_id() ] );
		$post_id  = $this->make_fast_checkout_post(
			$grouped->get_id(),
			[ 'is_grouped' => true ]
		);

		$_GET[ Fast_Checkout::QP_GROUPED_CHILD ] = (string) $foreign->get_id();
		$this->go_to( get_permalink( $post_id ) );

		Fast_Checkout::maybe_replace_cart();

		$cart_contents = WC()->cart->get_cart();
		$this->assertCount( 1, $cart_contents );
		$item = reset( $cart_contents );
		$this->assertSame( $first->get_id(), (int) $item['product_id'] );

		unset( $_GET[ Fast_Checkout::QP_GROUPED_CHILD ] );
	}

	/**
	 * Test that maybe_replace_cart applies nyp_price attribute when fc_price is absent.
	 *
	 * Skips when WC Name Your Price plugin isn't active.
	 */
	public function test_maybe_replace_cart_applies_nyp_attribute() {
		$this->skip_without_wc();
		if ( ! class_exists( '\WC_Name_Your_Price_Helpers' ) ) {
			$this->markTestSkipped( 'WC Name Your Price not available.' );
		}

		$product = $this->create_simple_product();
		update_post_meta( $product->get_id(), '_nyp', 'yes' );
		update_post_meta( $product->get_id(), '_min_price', '5' );
		update_post_meta( $product->get_id(), '_max_price', '50' );
		update_post_meta( $product->get_id(), '_suggested_price', '15' );

		$post_id = $this->make_fast_checkout_post(
			$product->get_id(),
			[
				'is_nyp'    => true,
				'nyp_price' => '20.00',
			]
		);
		$this->go_to( get_permalink( $post_id ) );

		Fast_Checkout::maybe_replace_cart();

		$cart_contents = WC()->cart->get_cart();
		$this->assertCount( 1, $cart_contents );
		$item = reset( $cart_contents );
		$this->assertSame( 20.0, (float) $item['nyp'] );
	}

	/**
	 * Test that the Store API NYP bridge filter applies nyp from request body.
	 */
	public function test_store_api_nyp_bridge_applies_request_value() {
		$this->skip_without_wc();
		if ( ! class_exists( '\WC_Name_Your_Price_Helpers' ) ) {
			$this->markTestSkipped( 'WC Name Your Price not available.' );
		}

		$product = $this->create_simple_product();
		update_post_meta( $product->get_id(), '_nyp', 'yes' );
		update_post_meta( $product->get_id(), '_min_price', '5' );
		update_post_meta( $product->get_id(), '_max_price', '50' );
		update_post_meta( $product->get_id(), '_suggested_price', '15' );

		// Simulate Store API add_to_cart payload with cart_item_data.nyp.
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
		$request->set_body_params(
			[
				'id'       => $product->get_id(),
				'quantity' => 1,
				'nyp'      => 22.5,
			]
		);

		$cart_item_data = [];
		$cart_item_data = Fast_Checkout::store_api_nyp_bridge(
			$cart_item_data,
			$product->get_id(),
			$request
		);

		$this->assertSame( 22.5, $cart_item_data['nyp'] ?? null );
	}

	/**
	 * Test that the bridge falls back to the suggested price when the request
	 * has no nyp value — covers the case of grouped-selector swaps for NYP
	 * children, where addItemToCart is called without an explicit price.
	 */
	public function test_store_api_nyp_bridge_falls_back_to_suggested() {
		$this->skip_without_wc();
		if ( ! class_exists( '\WC_Name_Your_Price_Helpers' ) ) {
			$this->markTestSkipped( 'WC Name Your Price not available.' );
		}

		$product = $this->create_simple_product();
		update_post_meta( $product->get_id(), '_nyp', 'yes' );
		update_post_meta( $product->get_id(), '_min_price', '5' );
		update_post_meta( $product->get_id(), '_max_price', '50' );
		update_post_meta( $product->get_id(), '_suggested_price', '15' );

		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
		$request->set_body_params(
			[
				'id'       => $product->get_id(),
				'quantity' => 1,
			]
		);

		// Selector swaps carry the source-post marker (set by propagate_source_post),
		// which is what enables the suggested-price fallback.
		$cart_item_data = Fast_Checkout::store_api_nyp_bridge(
			[ Fast_Checkout::CART_ITEM_SOURCE_KEY => 123 ],
			$product->get_id(),
			$request
		);

		$this->assertSame( 15.0, (float) ( $cart_item_data['nyp'] ?? 0 ) );
	}

	/**
	 * Test that the bridge does NOT inject a fallback price for non-Fast-Checkout
	 * Store API add-to-cart calls (no source-post marker present).
	 */
	public function test_store_api_nyp_bridge_skips_fallback_without_source_marker() {
		$this->skip_without_wc();
		if ( ! class_exists( '\WC_Name_Your_Price_Helpers' ) ) {
			$this->markTestSkipped( 'WC Name Your Price not available.' );
		}

		$product = $this->create_simple_product();
		update_post_meta( $product->get_id(), '_nyp', 'yes' );
		update_post_meta( $product->get_id(), '_min_price', '5' );
		update_post_meta( $product->get_id(), '_suggested_price', '15' );

		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
		$request->set_body_params(
			[
				'id'       => $product->get_id(),
				'quantity' => 1,
			]
		);

		$cart_item_data = Fast_Checkout::store_api_nyp_bridge(
			[],
			$product->get_id(),
			$request
		);

		$this->assertArrayNotHasKey( 'nyp', $cart_item_data );
	}

	/**
	 * Test that the bridge respects an nyp value already present in
	 * cart_item_data rather than overwriting it with the fallback price.
	 */
	public function test_store_api_nyp_bridge_respects_existing_cart_item_data_nyp() {
		$this->skip_without_wc();
		if ( ! class_exists( '\WC_Name_Your_Price_Helpers' ) ) {
			$this->markTestSkipped( 'WC Name Your Price not available.' );
		}

		$product = $this->create_simple_product();
		update_post_meta( $product->get_id(), '_nyp', 'yes' );
		update_post_meta( $product->get_id(), '_min_price', '5' );
		update_post_meta( $product->get_id(), '_max_price', '50' );
		update_post_meta( $product->get_id(), '_suggested_price', '15' );

		// No top-level nyp in the request; the price rides along in cart_item_data.
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
		$request->set_body_params(
			[
				'id'       => $product->get_id(),
				'quantity' => 1,
			]
		);

		$cart_item_data = Fast_Checkout::store_api_nyp_bridge(
			[ 'nyp' => 22.5 ],
			$product->get_id(),
			$request
		);

		$this->assertSame( 22.5, (float) ( $cart_item_data['nyp'] ?? 0 ) );
	}

	/**
	 * Test that fc_price query param overrides nyp_price attribute when both set.
	 *
	 * Skips when WC Name Your Price plugin isn't active.
	 */
	public function test_maybe_replace_cart_nyp_query_param_overrides_attribute() {
		$this->skip_without_wc();
		if ( ! class_exists( '\WC_Name_Your_Price_Helpers' ) ) {
			$this->markTestSkipped( 'WC Name Your Price not available.' );
		}

		$product = $this->create_simple_product();
		update_post_meta( $product->get_id(), '_nyp', 'yes' );
		update_post_meta( $product->get_id(), '_min_price', '5' );
		update_post_meta( $product->get_id(), '_max_price', '50' );
		update_post_meta( $product->get_id(), '_suggested_price', '15' );

		$post_id = $this->make_fast_checkout_post(
			$product->get_id(),
			[
				'is_nyp'    => true,
				'nyp_price' => '20.00',
			]
		);

		$_GET[ Fast_Checkout::QP_PRICE ] = '35.00';
		$this->go_to( get_permalink( $post_id ) );

		Fast_Checkout::maybe_replace_cart();

		$cart_contents = WC()->cart->get_cart();
		$this->assertCount( 1, $cart_contents );
		$item = reset( $cart_contents );
		$this->assertSame( 35.0, (float) $item['nyp'] );

		unset( $_GET[ Fast_Checkout::QP_PRICE ] );
	}

	/**
	 * Test that maybe_replace_cart falls back to the product's suggested price
	 * when neither fc_price nor nyp_price attribute is set.
	 *
	 * Skips when WC Name Your Price plugin isn't active.
	 */
	public function test_maybe_replace_cart_falls_back_to_suggested_nyp() {
		$this->skip_without_wc();
		if ( ! class_exists( '\WC_Name_Your_Price_Helpers' ) ) {
			$this->markTestSkipped( 'WC Name Your Price not available.' );
		}

		$product = $this->create_simple_product();
		update_post_meta( $product->get_id(), '_nyp', 'yes' );
		update_post_meta( $product->get_id(), '_min_price', '5' );
		update_post_meta( $product->get_id(), '_max_price', '50' );
		update_post_meta( $product->get_id(), '_suggested_price', '15' );

		$post_id = $this->make_fast_checkout_post(
			$product->get_id(),
			[ 'is_nyp' => true ]
		);
		$this->go_to( get_permalink( $post_id ) );

		Fast_Checkout::maybe_replace_cart();

		$cart_contents = WC()->cart->get_cart();
		$this->assertCount( 1, $cart_contents );
		$item = reset( $cart_contents );
		$this->assertSame( 15.0, (float) $item['nyp'] );
	}
}
