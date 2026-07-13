<?php
/**
 * Tests for restricting product purchases to readers who pass a gate's access rules.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Gate_API;
use Newspack\Product_Purchase_Restriction;
use Newspack\Reader_Activation;

/**
 * Tests the WooCommerce Memberships purchase-restriction parity: a reader who
 * fails a gate's access rules can still *see* a restricted product, but cannot
 * *buy* it. Enforcement rides on `woocommerce_is_purchasable`, so these tests
 * exercise the filter callback the same way WooCommerce does.
 *
 * The other two guards in `is_enforced()` — the NEWSPACK_CONTENT_GATES flag and
 * WooCommerce Memberships being inactive — are verified at runtime rather than
 * here: both are process-wide (a `define()` and a `class_exists()`), so faking
 * either would leak into every test that runs afterwards in the same process.
 *
 * @group content-gate
 * @group Product_Purchase_Restriction
 */
class Test_Product_Purchase_Restriction extends \WP_UnitTestCase {

	/**
	 * Reader whose email domain fails the gate's access rules.
	 *
	 * @var int
	 */
	private $blocked_reader_id;

	/**
	 * Reader whose (verified) email domain passes the gate's access rules.
	 *
	 * @var int
	 */
	private $member_reader_id;

	/**
	 * Enable the Content Gates feature flag and load the WooCommerce mocks.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Register the WooCommerce product post type and category taxonomy (the real
	 * ones come from WooCommerce, which isn't loaded in the test suite), and
	 * create the readers used across the assertions.
	 */
	public function set_up() {
		parent::set_up();

		register_post_type(
			'product',
			[
				'public' => true,
				'label'  => 'Product',
			]
		);
		register_taxonomy(
			'product_cat',
			'product',
			[
				'public'       => true,
				'label'        => 'Product category',
				'hierarchical' => true, // As WooCommerce registers it: restricting a category restricts its children.
			]
		);

		$this->blocked_reader_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@public.test',
			]
		);
		$this->member_reader_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@members.test',
			]
		);
		Reader_Activation::set_reader_verified( $this->member_reader_id );
	}

	/**
	 * Reset the per-request caches so each assertion re-evaluates the gates.
	 */
	public function tear_down() {
		$this->reset_restriction_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Reset the memoized gate list and purchase decisions between assertions.
	 */
	private function reset_restriction_cache() {
		foreach ( [
			'blocking_gates'        => [],
			'restricted_categories' => [],
			'gate_access'           => [],
			'rendered_notices'      => [],
			'restricting_gates'     => null,
		] as $cache_property => $empty_value ) {
			$cache_property_reflection = new \ReflectionProperty( Product_Purchase_Restriction::class, $cache_property );
			$cache_property_reflection->setAccessible( true );
			$cache_property_reflection->setValue( null, $empty_value );
		}

		// Content_Restriction_Control memoizes the term-descendant lookups this class reuses.
		$term_descendants_reflection = new \ReflectionProperty( \Newspack\Content_Restriction_Control::class, 'term_descendants_map' );
		$term_descendants_reflection->setAccessible( true );
		$term_descendants_reflection->setValue( null, [] );
	}

	/**
	 * Create a published gate that restricts purchasing of the given products
	 * and/or product categories to readers with a `members.test` email address.
	 *
	 * @param array $custom_access Custom access settings to override the defaults.
	 *
	 * @return array The gate, as returned by Content_Gate::get_gate().
	 */
	private function create_purchase_gate( $custom_access = [] ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_status' => $custom_access['status'] ?? 'publish',
				'post_title'  => $custom_access['title'] ?? 'Members-only purchases',
			]
		);
		update_post_meta(
			$gate_id,
			'custom_access',
			[
				'active'                        => $custom_access['active'] ?? true,
				'access_rules'                  => $custom_access['access_rules'] ?? [
					[
						[
							'slug'  => 'email_domain',
							'value' => 'members.test',
						],
					],
				],
				'restricted_products'           => $custom_access['restricted_products'] ?? [],
				'restricted_product_categories' => $custom_access['restricted_product_categories'] ?? [],
			]
		);
		$this->reset_restriction_cache();
		return Content_Gate::get_gate( $gate_id );
	}

	/**
	 * Create a product as both a WP post (so it can carry product_cat terms) and
	 * a mock WC_Product with the same ID (so it can be passed to the filter).
	 *
	 * @param array $data Mock product data, e.g. 'type' or 'parent_id'.
	 *
	 * @return \WC_Product The mock product.
	 */
	private function create_product( $data = [] ) {
		$product_id = $this->factory->post->create(
			[
				'post_type'  => 'product',
				'post_title' => $data['name'] ?? 'Tote bag',
			]
		);
		return wc_create_mock_product( array_merge( [ 'id' => $product_id ], $data ) );
	}

	/**
	 * Run the `woocommerce_is_purchasable` filter callback as the given user.
	 *
	 * @param \WC_Product $product The product.
	 * @param int         $user_id The reader viewing the product.
	 *
	 * @return bool Whether the product is purchasable.
	 */
	private function is_purchasable( $product, $user_id = 0 ) {
		wp_set_current_user( $user_id );
		$this->reset_restriction_cache();
		return Product_Purchase_Restriction::filter_is_purchasable( true, $product );
	}

	/**
	 * A reader who fails the gate's access rules cannot purchase a restricted product.
	 */
	public function test_restricted_product_is_not_purchasable_for_blocked_reader() {
		$product = $this->create_product();
		$this->create_purchase_gate( [ 'restricted_products' => [ $product->get_id() ] ] );

		$this->assertFalse(
			$this->is_purchasable( $product, $this->blocked_reader_id ),
			'A reader failing the gate access rules should not be able to purchase a restricted product.'
		);
		$this->assertFalse(
			$this->is_purchasable( $product, 0 ),
			'An anonymous reader should not be able to purchase a restricted product.'
		);
	}

	/**
	 * A reader who passes the gate's access rules can purchase the product.
	 */
	public function test_restricted_product_is_purchasable_for_passing_reader() {
		$product = $this->create_product();
		$this->create_purchase_gate( [ 'restricted_products' => [ $product->get_id() ] ] );

		$this->assertTrue(
			$this->is_purchasable( $product, $this->member_reader_id ),
			'A reader passing the gate access rules should be able to purchase a restricted product.'
		);
	}

	/**
	 * Products the gate doesn't list are left alone.
	 */
	public function test_unlisted_product_is_purchasable() {
		$restricted_product = $this->create_product();
		$other_product      = $this->create_product();
		$this->create_purchase_gate( [ 'restricted_products' => [ $restricted_product->get_id() ] ] );

		$this->assertTrue(
			$this->is_purchasable( $other_product, $this->blocked_reader_id ),
			'A product not listed on any gate should stay purchasable.'
		);
	}

	/**
	 * A product in a restricted product category is blocked; one outside it is not.
	 */
	public function test_product_category_restriction() {
		$category = $this->factory->term->create(
			[
				'taxonomy' => 'product_cat',
				'name'     => 'Premium merch',
			]
		);
		$categorized_product = $this->create_product();
		wp_set_object_terms( $categorized_product->get_id(), [ $category ], 'product_cat' );
		$uncategorized_product = $this->create_product();

		$this->create_purchase_gate( [ 'restricted_product_categories' => [ $category ] ] );

		$this->assertFalse(
			$this->is_purchasable( $categorized_product, $this->blocked_reader_id ),
			'A product in a restricted category should not be purchasable for a blocked reader.'
		);
		$this->assertTrue(
			$this->is_purchasable( $uncategorized_product, $this->blocked_reader_id ),
			'A product outside the restricted category should stay purchasable.'
		);
		$this->assertTrue(
			$this->is_purchasable( $categorized_product, $this->member_reader_id ),
			'A product in a restricted category should be purchasable for a passing reader.'
		);
	}

	/**
	 * `product_cat` is hierarchical, so restricting a parent category restricts
	 * everything filed under it. Without this, a gate restricting "Premium" would
	 * leave every product in "Premium > Merch" purchasable by anyone — the failure
	 * mode is silent revenue leakage, not a visible break.
	 */
	public function test_restricted_category_cascades_to_child_categories() {
		$parent_category = $this->factory->term->create(
			[
				'taxonomy' => 'product_cat',
				'name'     => 'Premium',
			]
		);
		$child_category = $this->factory->term->create(
			[
				'taxonomy' => 'product_cat',
				'name'     => 'Premium merch',
				'parent'   => $parent_category,
			]
		);

		// The product sits only in the child category; the gate restricts the parent.
		$product = $this->create_product();
		wp_set_object_terms( $product->get_id(), [ $child_category ], 'product_cat' );
		$this->create_purchase_gate( [ 'restricted_product_categories' => [ $parent_category ] ] );

		$this->assertFalse(
			$this->is_purchasable( $product, $this->blocked_reader_id ),
			'A product in a child of a restricted category should not be purchasable for a blocked reader.'
		);
		$this->assertTrue(
			$this->is_purchasable( $product, $this->member_reader_id ),
			'A product in a child of a restricted category should be purchasable for a passing reader.'
		);
	}

	/**
	 * A purchase-only gate (products, no content rules) restricts purchasing without
	 * gating any content. Product_Purchase_Restriction leans on this: it is what lets
	 * a publisher restrict merch without also paywalling their articles.
	 */
	public function test_purchase_only_gate_does_not_gate_content() {
		$product = $this->create_product();
		$gate    = $this->create_purchase_gate( [ 'restricted_products' => [ $product->get_id() ] ] );
		update_post_meta( $gate['id'], 'content_rules', [] );

		$post_id = $this->factory->post->create( [ 'post_type' => 'post' ] );

		$this->assertFalse(
			\Newspack\Content_Restriction_Control::is_post_restricted( false, $post_id, $this->blocked_reader_id ),
			'A gate with no content rules should not restrict any content, only purchasing.'
		);
		$this->assertFalse(
			$this->is_purchasable( $product, $this->blocked_reader_id ),
			'The same gate should still block purchasing of its restricted product.'
		);
	}

	/**
	 * A variation inherits its parent product's restriction (WCM parity: rules
	 * target the variable product, and Woo checks purchasability per variation).
	 */
	public function test_variation_inherits_parent_restriction() {
		$parent    = $this->create_product( [ 'type' => 'variable' ] );
		$variation = $this->create_product(
			[
				'type'      => 'variation',
				'parent_id' => $parent->get_id(),
			]
		);
		$this->create_purchase_gate( [ 'restricted_products' => [ $parent->get_id() ] ] );

		$this->assertFalse(
			$this->is_purchasable( $variation, $this->blocked_reader_id ),
			'A variation of a restricted variable product should not be purchasable.'
		);
		$this->assertTrue(
			$this->is_purchasable( $variation, $this->member_reader_id ),
			'A variation of a restricted variable product should be purchasable for a passing reader.'
		);
	}

	/**
	 * A gate with no access rules grants access to everyone, so it can't block a
	 * purchase — otherwise it would lock the product for every reader, forever.
	 */
	public function test_gate_without_access_rules_does_not_restrict() {
		$product = $this->create_product();
		$this->create_purchase_gate(
			[
				'access_rules'        => [],
				'restricted_products' => [ $product->get_id() ],
			]
		);

		$this->assertTrue(
			$this->is_purchasable( $product, $this->blocked_reader_id ),
			'A gate with no access rules should not block purchasing.'
		);
	}

	/**
	 * Only published gates with custom access active enforce.
	 */
	public function test_draft_or_inactive_gate_does_not_restrict() {
		$draft_product = $this->create_product();
		$this->create_purchase_gate(
			[
				'status'              => 'draft',
				'restricted_products' => [ $draft_product->get_id() ],
			]
		);
		$this->assertTrue(
			$this->is_purchasable( $draft_product, $this->blocked_reader_id ),
			'A draft gate should not block purchasing.'
		);

		$inactive_product = $this->create_product();
		$this->create_purchase_gate(
			[
				'active'              => false,
				'restricted_products' => [ $inactive_product->get_id() ],
			]
		);
		$this->assertTrue(
			$this->is_purchasable( $inactive_product, $this->blocked_reader_id ),
			'A gate with custom access disabled should not block purchasing.'
		);
	}

	/**
	 * Shop managers (and admins) can always purchase, matching WCM, so that a
	 * restriction can never lock a publisher out of their own store.
	 */
	public function test_shop_manager_can_always_purchase() {
		$product = $this->create_product();
		$this->create_purchase_gate( [ 'restricted_products' => [ $product->get_id() ] ] );

		// WooCommerce grants `manage_woocommerce` to administrators and shop managers; it isn't loaded here, so grant it as Woo would.
		$shop_manager_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		get_user_by( 'id', $shop_manager_id )->add_cap( 'manage_woocommerce' );

		$this->assertTrue(
			$this->is_purchasable( $product, $shop_manager_id ),
			'A user who can manage WooCommerce should always be able to purchase.'
		);
	}

	/**
	 * When several gates restrict the same product, the reader must pass all of
	 * them (the OR logic lives inside a single gate's rule groups).
	 */
	public function test_reader_must_pass_every_gate_restricting_the_product() {
		$product = $this->create_product();
		$this->create_purchase_gate( [ 'restricted_products' => [ $product->get_id() ] ] );
		$this->create_purchase_gate(
			[
				'title'               => 'Staff only',
				'access_rules'        => [
					[
						[
							'slug'  => 'email_domain',
							'value' => 'staff.test',
						],
					],
				],
				'restricted_products' => [ $product->get_id() ],
			]
		);

		$this->assertFalse(
			$this->is_purchasable( $product, $this->member_reader_id ),
			'A reader passing one gate but failing another should not be able to purchase.'
		);
	}

	/**
	 * A product left unpurchasable by WooCommerce (out of stock, no price) stays
	 * unpurchasable — the filter only ever removes access, never grants it.
	 */
	public function test_filter_never_makes_a_product_purchasable() {
		$product = $this->create_product();
		$this->create_purchase_gate( [ 'restricted_products' => [ $product->get_id() ] ] );

		wp_set_current_user( $this->member_reader_id );
		$this->assertFalse(
			Product_Purchase_Restriction::filter_is_purchasable( false, $product ),
			'The filter should never turn a non-purchasable product into a purchasable one.'
		);
	}

	/**
	 * The restriction notice names the subscription products that unlock the
	 * purchase, and is filterable.
	 */
	public function test_restricted_message() {
		$subscription_product = $this->create_product( [ 'name' => 'Premium membership' ] );
		$product              = $this->create_product();
		$gate                 = $this->create_purchase_gate(
			[
				'access_rules'        => [
					[
						[
							'slug'  => 'subscription',
							'value' => [ $subscription_product->get_id() ],
						],
					],
				],
				'restricted_products' => [ $product->get_id() ],
			]
		);

		$message = Product_Purchase_Restriction::get_restricted_message( $product, $gate );
		$this->assertStringContainsString( 'This product can only be purchased by members.', $message );
		$this->assertStringContainsString( 'Premium membership', $message, 'The message should name the subscription product that unlocks the purchase.' );
		$this->assertStringContainsString( get_permalink( $subscription_product->get_id() ), $message, 'The subscription product should be linked.' );

		$generic_gate    = $this->create_purchase_gate( [ 'restricted_products' => [ $product->get_id() ] ] );
		$generic_message = Product_Purchase_Restriction::get_restricted_message( $product, $generic_gate );
		$this->assertSame(
			'This product can only be purchased by members.',
			$generic_message,
			'A gate with no subscription rule should fall back to the generic message.'
		);

		add_filter( 'newspack_product_purchase_restricted_message', '__return_empty_string' );
		$this->assertSame( '', Product_Purchase_Restriction::get_restricted_message( $product, $generic_gate ), 'The message should be filterable.' );
		remove_filter( 'newspack_product_purchase_restricted_message', '__return_empty_string' );
	}

	/**
	 * Block themes never fire `woocommerce_single_product_summary`, so the notice
	 * rides the add-to-cart block instead — otherwise the button just disappears
	 * with no explanation.
	 */
	public function test_notice_renders_on_block_theme_product_template() {
		$product = $this->create_product();
		$this->create_purchase_gate( [ 'restricted_products' => [ $product->get_id() ] ] );

		$add_to_cart_block = [
			'blockName' => 'woocommerce/add-to-cart-form',
			'attrs'     => [ 'productId' => $product->get_id() ],
		];

		wp_set_current_user( $this->blocked_reader_id );
		$this->reset_restriction_cache();
		$blocked_output = Product_Purchase_Restriction::filter_add_to_cart_block( '<form class="cart"></form>', $add_to_cart_block );
		$this->assertStringContainsString(
			'newspack-product-purchase-restricted',
			$blocked_output,
			'A blocked reader should get the notice appended to the add-to-cart block.'
		);

		// Rendering the same product again must not repeat the notice.
		$this->assertStringNotContainsString(
			'newspack-product-purchase-restricted',
			Product_Purchase_Restriction::filter_add_to_cart_block( '<form class="cart"></form>', $add_to_cart_block ),
			'The notice should render at most once per product per request.'
		);

		wp_set_current_user( $this->member_reader_id );
		$this->reset_restriction_cache();
		$this->assertStringNotContainsString(
			'newspack-product-purchase-restricted',
			Product_Purchase_Restriction::filter_add_to_cart_block( '<form class="cart"></form>', $add_to_cart_block ),
			'A passing reader should see the add-to-cart block untouched.'
		);

		// Blocks other than add-to-cart are left alone — the catalog stays as WooCommerce renders it.
		$this->reset_restriction_cache();
		wp_set_current_user( $this->blocked_reader_id );
		$this->assertSame(
			'<div>Grid</div>',
			Product_Purchase_Restriction::filter_add_to_cart_block(
				'<div>Grid</div>',
				[
					'blockName' => 'woocommerce/product-button',
					'attrs'     => [ 'productId' => $product->get_id() ],
				]
			),
			'Only the single-product add-to-cart block carries the notice.'
		);
	}

	/**
	 * Newsletter gates never restrict product purchasing.
	 */
	public function test_newsletter_gates_do_not_restrict() {
		$product = $this->create_product();
		$gate    = $this->create_purchase_gate( [ 'restricted_products' => [ $product->get_id() ] ] );
		update_post_meta( $gate['id'], 'is_newsletter', true );

		$this->assertTrue(
			$this->is_purchasable( $product, $this->blocked_reader_id ),
			'A newsletter gate should never restrict product purchasing.'
		);
	}

	/**
	 * The restricted product/category IDs round-trip through the REST sanitizer
	 * and the gate meta whitelist.
	 */
	public function test_settings_round_trip() {
		$sanitized = Content_Gate_API::sanitize_custom_access(
			[
				'active'                        => true,
				'restricted_products'           => [ '12', 12, 0, '', 'not-an-id', 34 ],
				'restricted_product_categories' => [ '7' ],
			]
		);
		$this->assertSame( [ 12, 34 ], $sanitized['restricted_products'], 'Product IDs should be cast to unique, non-empty integers.' );
		$this->assertSame( [ 7 ], $sanitized['restricted_product_categories'] );

		$gate_id = $this->factory->post->create( [ 'post_type' => Content_Gate::GATE_CPT ] );
		Content_Gate::update_custom_access_settings( $gate_id, $sanitized );
		$settings = Content_Gate::get_custom_access_settings( $gate_id );
		$this->assertSame( [ 12, 34 ], $settings['restricted_products'] );
		$this->assertSame( [ 7 ], $settings['restricted_product_categories'] );

		// An omitted key must not clobber the stored value.
		Content_Gate::update_custom_access_settings( $gate_id, Content_Gate_API::sanitize_custom_access( [ 'active' => false ] ) );
		$settings = Content_Gate::get_custom_access_settings( $gate_id );
		$this->assertSame( [ 12, 34 ], $settings['restricted_products'], 'Saving without the products key should preserve the stored products.' );

		// Gates saved before this feature existed default to empty arrays.
		$legacy_gate_id = $this->factory->post->create( [ 'post_type' => Content_Gate::GATE_CPT ] );
		update_post_meta( $legacy_gate_id, 'custom_access', [ 'active' => true ] );
		$legacy_settings = Content_Gate::get_custom_access_settings( $legacy_gate_id );
		$this->assertSame( [], $legacy_settings['restricted_products'] );
		$this->assertSame( [], $legacy_settings['restricted_product_categories'] );
	}

	/**
	 * Meta written outside the REST API — a migration script, WP-CLI — doesn't pass
	 * through the sanitizer, so the read path normalizes the IDs too. A stray 0 would
	 * otherwise be compared against a real product ID.
	 */
	public function test_malformed_meta_is_normalized_on_read() {
		$gate_id = $this->factory->post->create( [ 'post_type' => Content_Gate::GATE_CPT ] );
		update_post_meta(
			$gate_id,
			'custom_access',
			[
				'active'                        => true,
				'restricted_products'           => [ '12', 12, 0, -5, '', 'not-an-id', 34 ],
				'restricted_product_categories' => [ '7', 7 ],
			]
		);

		$settings = Content_Gate::get_custom_access_settings( $gate_id );
		$this->assertSame( [ 12, 34 ], $settings['restricted_products'], 'Zeros, negatives, empties and duplicates should be dropped on read.' );
		$this->assertSame( [ 7 ], $settings['restricted_product_categories'] );
	}
}
