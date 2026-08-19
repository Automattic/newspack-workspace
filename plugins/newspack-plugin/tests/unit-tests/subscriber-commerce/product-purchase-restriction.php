<?php
/**
 * Tests subscriber-only product purchase restriction.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce;

use Newspack\Product_Purchase_Restriction;
use Newspack\Product_Targeting;
use Newspack\Subscriber_Eligibility;
use Newspack\Subscriber_Only_Products;

/**
 * Tests the WooCommerce Memberships purchase-restriction parity: a reader who
 * doesn't subscribe can still *see* a restricted product, but cannot *buy* it.
 *
 * Enforcement rides `woocommerce_is_purchasable`, so these exercise the filter
 * callback the same way WooCommerce does.
 *
 * The two process-wide guards in `is_enforcement_active()` — the
 * NEWSPACK_CONTENT_GATES flag and Memberships being inactive — are verified at
 * runtime rather than here: both are a `define()` or a `class_exists()`, so
 * faking either would leak into every test that runs afterwards in the process.
 *
 * @group subscriber-commerce
 * @group Product_Purchase_Restriction
 */
class Test_Product_Purchase_Restriction extends \WP_UnitTestCase {

	/**
	 * The restricted product.
	 *
	 * @var \WC_Product
	 */
	private $restricted_product;

	/**
	 * A product no restriction covers.
	 *
	 * @var \WC_Product
	 */
	private $open_product;

	/**
	 * The subscription that unlocks the restricted product.
	 *
	 * @var \WC_Product
	 */
	private $subscription;

	/**
	 * A reader who subscribes.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * A reader who doesn't.
	 *
	 * @var int
	 */
	private $non_subscriber_id;

	/**
	 * Enable the content gates flag and load the WooCommerce mocks.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Register the product post type, build the products, and seed a
	 * restriction covering one of them.
	 */
	public function set_up() {
		parent::set_up();

		register_post_type( 'product', [ 'public' => true ] );
		register_post_type( 'product_variation', [ 'public' => false ] );
		register_taxonomy( 'product_cat', 'product', [ 'hierarchical' => true ] );

		$this->restricted_product = $this->create_product();
		$this->open_product       = $this->create_product();
		$this->subscription       = $this->create_product();

		$this->subscriber_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->non_subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		add_filter( 'newspack_access_rules_has_active_subscription', [ $this, 'mock_oracle' ], 10, 2 );

		$this->set_rules(
			[
				[
					'id'                       => 'rule',
					'subscription_product_ids' => [ $this->subscription->get_id() ],
					'targeting'                => 'products',
					'product_ids'              => [ $this->restricted_product->get_id() ],
					'active'                   => true,
				],
			]
		);
	}

	/**
	 * Reset everything the restriction memoizes.
	 */
	public function tear_down() {
		remove_filter( 'newspack_access_rules_has_active_subscription', [ $this, 'mock_oracle' ], 10 );
		delete_option( Subscriber_Only_Products::OPTION_NAME );
		delete_option( Subscriber_Only_Products::SETTINGS_OPTION_NAME );
		$this->flush_caches();
		wp_set_current_user( 0 );
		global $products_database;
		$products_database = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * Stand in for the subscription oracle: only the subscriber subscribes.
	 *
	 * @param bool $has_subscription Whether the user has an active subscription.
	 * @param int  $user_id          User ID.
	 *
	 * @return bool
	 */
	public function mock_oracle( $has_subscription, $user_id ) {
		return $user_id === $this->subscriber_id;
	}

	/**
	 * Drop every per-request cache the restriction relies on.
	 */
	private function flush_caches() {
		Product_Purchase_Restriction::flush_cache();
		Product_Targeting::flush_cache();
		Subscriber_Eligibility::flush_cache();
	}

	/**
	 * Store the restrictions and drop the caches keyed on them.
	 *
	 * @param array[] $rules The rules.
	 */
	private function set_rules( $rules ) {
		$sanitized = array_map( [ 'Newspack\Subscriber_Commerce', 'sanitize_base_rule' ], $rules );
		update_option( Subscriber_Only_Products::OPTION_NAME, $sanitized );
		$this->flush_caches();
	}

	/**
	 * Create a product post plus its mock, registered so wc_get_product() finds it.
	 *
	 * @param int $parent_id Parent product ID, for a variation.
	 *
	 * @return \WC_Product
	 */
	private function create_product( $parent_id = 0 ) {
		$post_id = $this->factory->post->create(
			[
				'post_type'   => $parent_id ? 'product_variation' : 'product',
				'post_parent' => $parent_id,
				'post_title'  => 'Product ' . wp_rand(),
			]
		);
		$product = new \WC_Product(
			[
				'id'        => $post_id,
				'parent_id' => $parent_id,
			]
		);

		global $products_database;
		$products_database[ $post_id ] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return $product;
	}

	/**
	 * A reader who doesn't subscribe cannot buy a restricted product.
	 */
	public function test_non_subscriber_cannot_purchase() {
		wp_set_current_user( $this->non_subscriber_id );

		$this->assertFalse( Product_Purchase_Restriction::filter_is_purchasable( true, $this->restricted_product ) );
	}

	/**
	 * A subscriber can.
	 */
	public function test_subscriber_can_purchase() {
		wp_set_current_user( $this->subscriber_id );

		$this->assertTrue( Product_Purchase_Restriction::filter_is_purchasable( true, $this->restricted_product ) );
	}

	/**
	 * An anonymous reader cannot.
	 */
	public function test_anonymous_reader_cannot_purchase() {
		$this->assertFalse( Product_Purchase_Restriction::filter_is_purchasable( true, $this->restricted_product ) );
	}

	/**
	 * A product no restriction covers is untouched.
	 */
	public function test_unrestricted_product_is_left_alone() {
		$this->assertTrue( Product_Purchase_Restriction::filter_is_purchasable( true, $this->open_product ) );
	}

	/**
	 * A product WooCommerce already ruled unpurchasable stays that way: the
	 * filter may only take purchasability away, never grant it.
	 */
	public function test_never_grants_purchasability_woocommerce_denied() {
		wp_set_current_user( $this->subscriber_id );

		$this->assertFalse( Product_Purchase_Restriction::filter_is_purchasable( false, $this->restricted_product ) );
	}

	/**
	 * Shop managers keep purchasing rights, so a restriction can't lock a
	 * publisher out of their own products.
	 */
	public function test_shop_manager_can_always_purchase() {
		$manager_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		// WooCommerce registers manage_woocommerce; it isn't loaded here, so the
		// capability the check actually reads has to be granted explicitly.
		get_user_by( 'id', $manager_id )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $manager_id );

		$this->assertTrue( Product_Purchase_Restriction::filter_is_purchasable( true, $this->restricted_product ) );
	}

	/**
	 * Pausing a restriction hands its products back.
	 */
	public function test_inactive_restriction_does_not_block() {
		$this->set_rules(
			[
				[
					'id'                       => 'rule',
					'subscription_product_ids' => [ $this->subscription->get_id() ],
					'targeting'                => 'products',
					'product_ids'              => [ $this->restricted_product->get_id() ],
					'active'                   => false,
				],
			]
		);

		$this->assertTrue( Product_Purchase_Restriction::filter_is_purchasable( true, $this->restricted_product ) );
	}

	/**
	 * A restriction naming no subscription names no way in. Blocking everyone
	 * is far more likely to be a half-finished rule than an intent to withdraw
	 * the product from sale, so it fails open.
	 */
	public function test_restriction_without_subscriptions_fails_open() {
		$this->set_rules(
			[
				[
					'id'                       => 'rule',
					'subscription_product_ids' => [],
					'targeting'                => 'products',
					'product_ids'              => [ $this->restricted_product->get_id() ],
					'active'                   => true,
				],
			]
		);

		$this->assertTrue( Product_Purchase_Restriction::filter_is_purchasable( true, $this->restricted_product ) );
	}

	/**
	 * Two restrictions covering one product are alternatives, not hurdles:
	 * satisfying either one unlocks the purchase. Each rule is an offer, so
	 * adding one can only widen access.
	 */
	public function test_overlapping_restrictions_are_ored() {
		$other_subscription = $this->create_product();
		$this->set_rules(
			[
				[
					'id'                       => 'unsatisfied',
					// A subscription nobody in this test holds.
					'subscription_product_ids' => [ $other_subscription->get_id() ],
					'targeting'                => 'products',
					'product_ids'              => [ $this->restricted_product->get_id() ],
					'active'                   => true,
				],
				[
					'id'                       => 'satisfied',
					'subscription_product_ids' => [ $this->subscription->get_id() ],
					'targeting'                => 'products',
					'product_ids'              => [ $this->restricted_product->get_id() ],
					'active'                   => true,
				],
			]
		);
		wp_set_current_user( $this->subscriber_id );

		$this->assertTrue( Product_Purchase_Restriction::filter_is_purchasable( true, $this->restricted_product ) );
	}

	/**
	 * A variation is restricted through its parent, which is what the publisher
	 * picked.
	 */
	public function test_variation_is_restricted_through_its_parent() {
		$variation = $this->create_product( $this->restricted_product->get_id() );

		$this->assertFalse( Product_Purchase_Restriction::filter_is_purchasable( true, $variation ) );
	}

	/**
	 * The notice names the subscription that unlocks the product, so the reader
	 * knows what to buy.
	 */
	public function test_notice_links_the_unlocking_subscription() {
		$message = Product_Purchase_Restriction::get_restricted_message( $this->restricted_product );

		$this->assertStringContainsString( 'available to subscribers', $message );
		$this->assertStringContainsString( get_permalink( $this->subscription->get_id() ), $message );
	}

	/**
	 * Reader-facing copy says "subscribers", never Memberships' "members".
	 */
	public function test_notice_uses_subscriber_vocabulary() {
		$message = Product_Purchase_Restriction::get_restricted_message( $this->restricted_product );

		$this->assertStringNotContainsStringIgnoringCase( 'member', $message );
	}

	/**
	 * A subscription the reader can't buy either is left out of the notice,
	 * rather than pointing them at a product they've just been barred from.
	 */
	public function test_notice_omits_subscriptions_the_reader_cannot_buy() {
		$this->set_rules(
			[
				[
					'id'                       => 'rule',
					'subscription_product_ids' => [ $this->subscription->get_id() ],
					'targeting'                => 'products',
					'product_ids'              => [ $this->restricted_product->get_id() ],
					'active'                   => true,
				],
				[
					'id'                       => 'locks-the-subscription',
					'subscription_product_ids' => [ $this->open_product->get_id() ],
					'targeting'                => 'products',
					'product_ids'              => [ $this->subscription->get_id() ],
					'active'                   => true,
				],
			]
		);

		$message = Product_Purchase_Restriction::get_restricted_message( $this->restricted_product );

		$this->assertStringNotContainsString( get_permalink( $this->subscription->get_id() ), $message );
	}

	/**
	 * The subscription picker offers variations of a variable subscription, so a
	 * rule can name one. A variation post has no page a reader can buy from, so
	 * the notice has to link the parent instead.
	 */
	public function test_notice_links_a_subscription_variation_through_its_parent() {
		$variable_subscription = $this->create_product();
		$variation             = $this->create_product( $variable_subscription->get_id() );
		$this->set_rules(
			[
				[
					'id'                       => 'rule',
					'subscription_product_ids' => [ $variation->get_id() ],
					'targeting'                => 'products',
					'product_ids'              => [ $this->restricted_product->get_id() ],
					'active'                   => true,
				],
			]
		);

		$message = Product_Purchase_Restriction::get_restricted_message( $this->restricted_product );

		$this->assertStringContainsString( get_permalink( $variable_subscription->get_id() ), $message );
		$this->assertStringNotContainsString( get_permalink( $variation->get_id() ), $message );
	}

	/**
	 * On a block theme the notice rides the add-to-cart block, because
	 * `woocommerce_single_product_summary` never fires.
	 */
	public function test_add_to_cart_block_carries_the_notice_on_the_product_page() {
		$this->go_to( get_permalink( $this->restricted_product->get_id() ) );

		$this->assertStringContainsString(
			'newspack-subscriber-only-notice',
			Product_Purchase_Restriction::filter_add_to_cart_block( 'cart', $this->add_to_cart_block() )
		);
	}

	/**
	 * Anywhere else it stays out. Nothing stops a custom listing from putting a
	 * single-product add-to-cart block on every card, and the notice would then
	 * repeat down the page — so the block path covers the same surface as the
	 * classic one and no more.
	 */
	public function test_add_to_cart_block_carries_no_notice_off_the_product_page() {
		$this->go_to( home_url( '/' ) );

		$this->assertSame( 'cart', Product_Purchase_Restriction::filter_add_to_cart_block( 'cart', $this->add_to_cart_block() ) );
	}

	/**
	 * A parsed add-to-cart block naming the restricted product.
	 *
	 * @return array
	 */
	private function add_to_cart_block() {
		return [
			'blockName' => 'woocommerce/add-to-cart-form',
			'attrs'     => [ 'productId' => $this->restricted_product->get_id() ],
		];
	}

	/**
	 * Hiding is off by default: the parity feature blocks purchasing and leaves
	 * the product listed.
	 */
	public function test_products_stay_listed_by_default() {
		$query = $this->run_product_query();

		$this->assertEmpty( $query->get( 'post__not_in' ) );
	}

	/**
	 * With hiding on, a reader who can't buy the product doesn't see it listed.
	 */
	public function test_hiding_removes_unpurchasable_products_from_lists() {
		update_option( Subscriber_Only_Products::SETTINGS_OPTION_NAME, [ 'hide_from_product_lists' => true ] );
		$this->flush_caches();

		$query = $this->run_product_query();

		$this->assertContains( $this->restricted_product->get_id(), (array) $query->get( 'post__not_in' ) );
		$this->assertNotContains( $this->open_product->get_id(), (array) $query->get( 'post__not_in' ) );
	}

	/**
	 * A curated listing — a hand-picked Products block, a Product Collection
	 * with chosen products — passes `post__in`, and WordPress then ignores
	 * `post__not_in` entirely. Hiding has to narrow the picks instead, or that
	 * one listing keeps showing what every other listing hides.
	 */
	public function test_hiding_narrows_a_curated_listing() {
		update_option( Subscriber_Only_Products::SETTINGS_OPTION_NAME, [ 'hide_from_product_lists' => true ] );
		$this->flush_caches();

		$query = $this->run_product_query( [ $this->restricted_product->get_id(), $this->open_product->get_id() ] );

		$this->assertSame( [ $this->open_product->get_id() ], (array) $query->get( 'post__in' ) );
	}

	/**
	 * When every pick is hidden the listing comes back empty. An emptied
	 * `post__in` would read as "no constraint" and list the whole catalog.
	 */
	public function test_hiding_every_curated_pick_empties_the_listing() {
		update_option( Subscriber_Only_Products::SETTINGS_OPTION_NAME, [ 'hide_from_product_lists' => true ] );
		$this->flush_caches();

		$query = $this->run_product_query( [ $this->restricted_product->get_id() ] );

		$this->assertSame( [ 0 ], (array) $query->get( 'post__in' ) );
	}

	/**
	 * Hiding covers secondary listings — related products, cross-sells, cart
	 * upsells — by design, so a publisher who wants it confined to the primary
	 * catalog needs a way to say so.
	 *
	 * Purchase restriction is untouched by the filter: the point is a listing
	 * that still shows the product, not one that sells it.
	 */
	public function test_a_filter_can_exempt_a_listing_from_hiding() {
		update_option( Subscriber_Only_Products::SETTINGS_OPTION_NAME, [ 'hide_from_product_lists' => true ] );
		$this->flush_caches();
		add_filter( 'newspack_subscriber_only_hide_from_query', '__return_false' );

		$query = $this->run_product_query();

		$this->assertEmpty( $query->get( 'post__not_in' ) );
		$this->assertFalse( Product_Purchase_Restriction::can_purchase( $this->restricted_product ) );

		remove_filter( 'newspack_subscriber_only_hide_from_query', '__return_false' );
	}

	/**
	 * A subscriber sees everything: hiding follows purchasability, so it must
	 * not hide a product from the very readers it is sold to.
	 */
	public function test_hiding_leaves_subscribers_lists_intact() {
		update_option( Subscriber_Only_Products::SETTINGS_OPTION_NAME, [ 'hide_from_product_lists' => true ] );
		$this->flush_caches();
		wp_set_current_user( $this->subscriber_id );

		$query = $this->run_product_query();

		$this->assertNotContains( $this->restricted_product->get_id(), (array) $query->get( 'post__not_in' ) );
	}

	/**
	 * A direct link still resolves: hiding covers listings only, so a reader
	 * holding the URL isn't left wondering where the product went.
	 */
	public function test_hiding_leaves_the_product_page_reachable() {
		update_option( Subscriber_Only_Products::SETTINGS_OPTION_NAME, [ 'hide_from_product_lists' => true ] );
		$this->flush_caches();

		$query = new \WP_Query();
		$query->query_vars = [
			'post_type' => 'product',
			'p'         => $this->restricted_product->get_id(),
		];
		$query->is_singular = true;
		Product_Purchase_Restriction::filter_product_query( $query );

		$this->assertEmpty( $query->get( 'post__not_in' ) );
	}

	/**
	 * Working out what to hide has to enumerate the covered products, and that
	 * query fires `pre_get_posts` again — straight back into this filter. Run a
	 * category rule through the real hook (not the callback directly, which
	 * can't reproduce it) to prove the re-entry terminates.
	 */
	public function test_hiding_a_category_does_not_recurse_through_pre_get_posts() {
		$category_id = $this->factory->term->create( [ 'taxonomy' => 'product_cat' ] );
		wp_set_object_terms( $this->restricted_product->get_id(), [ $category_id ], 'product_cat' );
		$this->set_rules(
			[
				[
					'id'                       => 'rule',
					'subscription_product_ids' => [ $this->subscription->get_id() ],
					'targeting'                => 'category',
					'category_ids'             => [ $category_id ],
					'active'                   => true,
				],
			]
		);
		update_option( Subscriber_Only_Products::SETTINGS_OPTION_NAME, [ 'hide_from_product_lists' => true ] );
		Product_Purchase_Restriction::flush_cache();

		add_action( 'pre_get_posts', [ 'Newspack\Product_Purchase_Restriction', 'filter_product_query' ] );
		try {
			$query = new \WP_Query( [ 'post_type' => 'product' ] );
		} finally {
			remove_action( 'pre_get_posts', [ 'Newspack\Product_Purchase_Restriction', 'filter_product_query' ] );
		}

		$this->assertContains( $this->restricted_product->get_id(), (array) $query->get( 'post__not_in' ) );
		$this->assertNotContains( $this->open_product->get_id(), (array) $query->get( 'post__not_in' ) );
	}

	/**
	 * Run a product listing query through the hiding filter.
	 *
	 * @param int[] $post__in Products a curated listing picked out, if any.
	 *
	 * @return \WP_Query
	 */
	private function run_product_query( $post__in = [] ) {
		$query             = new \WP_Query();
		$query->query_vars = [ 'post_type' => 'product' ];
		if ( ! empty( $post__in ) ) {
			$query->query_vars['post__in'] = $post__in;
		}
		Product_Purchase_Restriction::filter_product_query( $query );
		return $query;
	}

	/**
	 * Saving a restriction takes effect immediately: the memoized verdicts must
	 * not outlive the rules they were computed from.
	 */
	public function test_saving_a_restriction_invalidates_the_cache() {
		$this->assertFalse( Product_Purchase_Restriction::filter_is_purchasable( true, $this->restricted_product ) );

		Subscriber_Only_Products::delete_rule( 'rule' );

		$this->assertTrue( Product_Purchase_Restriction::filter_is_purchasable( true, $this->restricted_product ) );
	}
}
