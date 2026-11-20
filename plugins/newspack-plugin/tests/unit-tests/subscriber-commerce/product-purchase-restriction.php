<?php
/**
 * Tests subscriber-only product purchase restriction.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce;

use Newspack\Product_Purchase_Restriction;
use Newspack\Product_Targeting;
use Newspack\Subscriber_Commerce;
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
		// The REST route marker one test sets; nothing else resets $wp's query vars.
		unset( $GLOBALS['wp']->query_vars['rest_route'] );
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
		$sanitized = array_map( [ 'Newspack\Subscriber_Only_Products', 'sanitize_rule' ], $rules );
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
	 * Create a subscription product plus its mock.
	 *
	 * @return \WC_Product
	 */
	private function create_subscription_product() {
		$post_id = $this->factory->post->create(
			[
				'post_type'  => 'product',
				'post_title' => 'Subscription ' . wp_rand(),
			]
		);
		$product = new \WC_Product(
			[
				'id'   => $post_id,
				'type' => 'subscription',
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
	 * With hiding on, a reader who can't see the product doesn't find it listed.
	 */
	public function test_hiding_removes_hidden_products_from_lists() {
		$this->hide_view_restricted_products();

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
		$this->hide_view_restricted_products();

		$query = $this->run_product_query( [ $this->restricted_product->get_id(), $this->open_product->get_id() ] );

		$this->assertSame( [ $this->open_product->get_id() ], (array) $query->get( 'post__in' ) );
	}

	/**
	 * When every pick is hidden the listing comes back empty. An emptied
	 * `post__in` would read as "no constraint" and list the whole catalog.
	 */
	public function test_hiding_every_curated_pick_empties_the_listing() {
		$this->hide_view_restricted_products();

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
		$this->hide_view_restricted_products();
		add_filter( 'newspack_subscriber_only_hide_from_query', '__return_false' );

		$query = $this->run_product_query();

		$this->assertEmpty( $query->get( 'post__not_in' ) );
		$this->assertFalse( Product_Purchase_Restriction::can_purchase( $this->restricted_product ) );

		remove_filter( 'newspack_subscriber_only_hide_from_query', '__return_false' );
	}

	/**
	 * A subscriber sees everything: hiding follows visibility, so it must not
	 * hide a product from the very readers it is sold to.
	 */
	public function test_hiding_leaves_subscribers_lists_intact() {
		$this->hide_view_restricted_products();
		wp_set_current_user( $this->subscriber_id );

		$query = $this->run_product_query();

		$this->assertNotContains( $this->restricted_product->get_id(), (array) $query->get( 'post__not_in' ) );
	}

	/**
	 * A direct link still resolves: hiding covers listings only, so a reader
	 * holding the URL isn't left wondering where the product went.
	 */
	public function test_hiding_leaves_the_product_page_reachable() {
		$this->hide_view_restricted_products();

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
					'restriction_type'         => Subscriber_Only_Products::RESTRICTION_TYPE_VIEW,
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

	/**
	 * A restriction open to every subscriber leaves the subscriptions themselves on
	 * sale. Without that, "all subscribers" plus "all products" bricks the store:
	 * the only way to satisfy the rule is to buy a subscription, and the rule
	 * covers those too, so a reader is told to subscribe and then refused the
	 * subscription. The discount side already answers this question the same way.
	 */
	public function test_an_all_subscriptions_restriction_leaves_subscriptions_on_sale() {
		$subscription = $this->create_subscription_product();
		$this->set_rules(
			[
				[
					'id'                     => 'lockdown',
					'subscription_targeting' => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
					'targeting'              => 'all',
					'active'                 => true,
				],
			]
		);

		$this->assertFalse(
			Product_Purchase_Restriction::can_purchase( $this->restricted_product, $this->non_subscriber_id ),
			'An ordinary product is still subscriber-only.'
		);
		$this->assertTrue(
			Product_Purchase_Restriction::can_purchase( $subscription, $this->non_subscriber_id ),
			'The subscription that would satisfy the rule stays purchasable.'
		);
	}

	/**
	 * A named restriction is unchanged: naming a subscription and restricting it is
	 * a deliberate pair of choices, not the incidental sweep the exemption exists for.
	 */
	public function test_a_named_restriction_can_still_cover_a_subscription() {
		$subscription = $this->create_subscription_product();
		$this->set_rules(
			[
				[
					'id'                       => 'named',
					'subscription_product_ids' => [ $this->subscription->get_id() ],
					'targeting'                => 'all',
					'active'                   => true,
				],
			]
		);

		$this->assertFalse(
			Product_Purchase_Restriction::can_purchase( $subscription, $this->non_subscriber_id ),
			'A rule naming its subscriptions still covers whatever its targeting reaches.'
		);
	}

	/**
	 * The reader-facing half of the same rule. A restriction open to everyone names
	 * no subscription, and naming only what a narrower rule beside it lists would
	 * send the reader to buy one particular subscription when any would do — so one
	 * such rule suppresses the whole list, whichever order the rules are in.
	 */
	public function test_an_all_subscriptions_restriction_suppresses_the_subscription_links() {
		$this->set_rules(
			[
				[
					'id'                       => 'named',
					'subscription_product_ids' => [ $this->subscription->get_id() ],
					'targeting'                => 'products',
					'product_ids'              => [ $this->restricted_product->get_id() ],
					'active'                   => true,
				],
				[
					'id'                     => 'open',
					'subscription_targeting' => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
					'targeting'              => 'products',
					'product_ids'            => [ $this->restricted_product->get_id() ],
					'active'                 => true,
				],
			]
		);

		$this->assertSame(
			'This product is available to subscribers.',
			Product_Purchase_Restriction::get_restricted_message( $this->restricted_product ),
			'The notice does not point at one subscription when any of them unlocks the product.'
		);
	}

	/**
	 * The fail-open direction, on the one path where a rule reaches the runtime
	 * exactly as stored: get_rules() does not fill defaults, so a restriction
	 * written before the audience mode existed arrives with no mode at all. It
	 * has to read as naming its subscriptions — under the other reading its empty
	 * list would become "every subscriber", and a half-finished rule nobody has
	 * touched in months would start refusing purchases.
	 */
	public function test_a_stored_rule_without_a_mode_names_no_audience() {
		update_option(
			Subscriber_Only_Products::OPTION_NAME,
			[
				[
					'id'                       => 'legacy',
					'subscription_product_ids' => [],
					'targeting'                => 'products',
					'product_ids'              => [ $this->restricted_product->get_id() ],
					'active'                   => true,
				],
			]
		);
		$this->flush_caches();

		$this->assertSame( [], Subscriber_Only_Products::get_active_rules(), 'The rule names no way in, so it is not enforced.' );
		$this->assertTrue(
			Product_Purchase_Restriction::can_purchase( $this->restricted_product, $this->non_subscriber_id ),
			'A reader with no subscription can still buy the product.'
		);
	}

	/**
	 * A restriction can unlock its products for every subscriber rather than for
	 * a named list. The rule names no subscription and is still enforced, which
	 * is the one case where an empty list does not mean a half-finished rule.
	 */
	public function test_all_subscriptions_restriction_unlocks_for_any_subscriber() {
		$this->set_rules(
			[
				[
					'id'                     => 'rule',
					'subscription_targeting' => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
					'targeting'              => 'products',
					'product_ids'            => [ $this->restricted_product->get_id() ],
					'active'                 => true,
				],
			]
		);

		$this->assertTrue(
			Product_Purchase_Restriction::can_purchase( $this->restricted_product, $this->subscriber_id ),
			'A reader with any active subscription can buy it.'
		);
		$this->assertFalse(
			Product_Purchase_Restriction::can_purchase( $this->restricted_product, $this->non_subscriber_id ),
			'A reader with no subscription still cannot.'
		);
	}

	/**
	 * Make the seeded rule a "Purchase and visibility" one.
	 */
	private function make_rule_view_restricted() {
		$rules                        = Subscriber_Only_Products::get_rules();
		$rules[0]['restriction_type'] = Subscriber_Only_Products::RESTRICTION_TYPE_VIEW;
		update_option( Subscriber_Only_Products::OPTION_NAME, $rules );
		$this->flush_caches();
	}

	/**
	 * Hide "Purchase and visibility" products from product lists, and make the
	 * seeded rule one of them.
	 */
	private function hide_view_restricted_products() {
		update_option( Subscriber_Only_Products::SETTINGS_OPTION_NAME, [ 'hide_from_product_lists' => true ] );
		$this->make_rule_view_restricted();
	}

	/**
	 * The one subscription the subscriber holds, for mock_per_subscription_oracle().
	 *
	 * @var int
	 */
	private $held_subscription_id = 0;

	/**
	 * Stand in for the subscription oracle, answering per subscription: the
	 * subscriber holds only `$this->held_subscription_id`.
	 *
	 * @param bool  $has_subscription Whether the user has an active subscription.
	 * @param int   $user_id          User ID.
	 * @param int[] $product_ids      Subscription products asked about; empty for any.
	 *
	 * @return bool
	 */
	public function mock_per_subscription_oracle( $has_subscription, $user_id, $product_ids = [] ) {
		if ( $user_id !== $this->subscriber_id ) {
			return false;
		}
		return empty( $product_ids ) || in_array( $this->held_subscription_id, array_map( 'intval', (array) $product_ids ), true );
	}

	/**
	 * A "Purchase" rule leaves the product visible to everyone; only the
	 * purchase is blocked. That is the default for a rule stored without a type.
	 */
	public function test_a_purchase_rule_leaves_the_product_visible() {
		$this->assertTrue( Product_Purchase_Restriction::can_view( $this->restricted_product, $this->non_subscriber_id ) );
		$this->assertFalse( Product_Purchase_Restriction::can_purchase( $this->restricted_product, $this->non_subscriber_id ) );
	}

	/**
	 * A "Purchase and visibility" rule hides the product from non-subscribers
	 * and lets its subscribers both see and buy it.
	 */
	public function test_a_view_rule_hides_the_product_from_non_subscribers() {
		$this->make_rule_view_restricted();

		$this->assertFalse( Product_Purchase_Restriction::can_view( $this->restricted_product, $this->non_subscriber_id ) );
		$this->assertFalse( Product_Purchase_Restriction::can_purchase( $this->restricted_product, $this->non_subscriber_id ) );
		$this->assertTrue( Product_Purchase_Restriction::can_view( $this->restricted_product, $this->subscriber_id ) );
		$this->assertTrue( Product_Purchase_Restriction::can_purchase( $this->restricted_product, $this->subscriber_id ) );
		$this->assertTrue( Product_Purchase_Restriction::can_view( $this->open_product, $this->non_subscriber_id ), 'A product no rule covers stays visible.' );
	}

	/**
	 * Memberships parity on a product with both rule types: the "Purchase and
	 * visibility" audience may look but not buy while a "Purchase" rule names
	 * another audience, and the "Purchase" audience may both look and buy.
	 */
	public function test_a_view_audience_cannot_buy_what_a_purchase_rule_covers() {
		$purchase_subscription = $this->create_product();
		$this->set_rules(
			[
				[
					'id'                       => 'view',
					'restriction_type'         => Subscriber_Only_Products::RESTRICTION_TYPE_VIEW,
					'subscription_product_ids' => [ $this->subscription->get_id() ],
					'targeting'                => 'products',
					'product_ids'              => [ $this->restricted_product->get_id() ],
					'active'                   => true,
				],
				[
					'id'                       => 'purchase',
					'subscription_product_ids' => [ $purchase_subscription->get_id() ],
					'targeting'                => 'products',
					'product_ids'              => [ $this->restricted_product->get_id() ],
					'active'                   => true,
				],
			]
		);
		remove_filter( 'newspack_access_rules_has_active_subscription', [ $this, 'mock_oracle' ], 10 );
		add_filter( 'newspack_access_rules_has_active_subscription', [ $this, 'mock_per_subscription_oracle' ], 10, 3 );

		$this->held_subscription_id = $this->subscription->get_id();
		$this->assertTrue( Product_Purchase_Restriction::can_view( $this->restricted_product, $this->subscriber_id ) );
		$this->assertFalse( Product_Purchase_Restriction::can_purchase( $this->restricted_product, $this->subscriber_id ) );

		$this->held_subscription_id = $purchase_subscription->get_id();
		$this->flush_caches();
		$this->assertTrue( Product_Purchase_Restriction::can_view( $this->restricted_product, $this->subscriber_id ), 'A purchase audience sees what it can buy.' );
		$this->assertTrue( Product_Purchase_Restriction::can_purchase( $this->restricted_product, $this->subscriber_id ) );
	}

	/**
	 * Visibility never grants the purchase on its own: a filter that shows a
	 * hidden product as a teaser leaves it unbuyable.
	 */
	public function test_a_visibility_filter_does_not_open_the_sale() {
		$this->make_rule_view_restricted();
		add_filter( 'newspack_subscriber_only_product_can_view', '__return_true' );

		$this->assertTrue( Product_Purchase_Restriction::can_view( $this->restricted_product, $this->non_subscriber_id ) );
		$this->assertFalse( Product_Purchase_Restriction::can_purchase( $this->restricted_product, $this->non_subscriber_id ) );
	}

	/**
	 * Shop managers always see every product, so a publisher can't hide a
	 * product from themselves.
	 */
	public function test_a_store_manager_can_always_view() {
		$this->make_rule_view_restricted();
		$manager_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		get_user_by( 'id', $manager_id )->add_cap( 'manage_woocommerce' );

		$this->assertTrue( Product_Purchase_Restriction::can_view( $this->restricted_product, $manager_id ) );
	}

	/**
	 * "Hide from product lists" hides only "Purchase and visibility" products,
	 * as Memberships does. A "Purchase" product stays listed.
	 */
	public function test_hiding_leaves_purchase_only_products_listed() {
		update_option( Subscriber_Only_Products::SETTINGS_OPTION_NAME, [ 'hide_from_product_lists' => true ] );
		$this->flush_caches();

		$query = $this->run_product_query();

		$this->assertNotContains( $this->restricted_product->get_id(), (array) $query->get( 'post__not_in' ) );
	}

	/**
	 * Visit the restricted product's page as the given reader.
	 *
	 * @param int $user_id The reader.
	 */
	private function visit_restricted_product_page( $user_id ) {
		wp_set_current_user( $user_id );
		$this->go_to( get_permalink( $this->restricted_product->get_id() ) );
	}

	/**
	 * The default product-page setting keeps the page but swaps its details for
	 * the notice, through the password-form path both WooCommerce single-product
	 * templates check.
	 */
	public function test_hidden_product_page_shows_only_the_notice_by_default() {
		$this->make_rule_view_restricted();
		$this->visit_restricted_product_page( $this->non_subscriber_id );

		$this->assertTrue( post_password_required( $this->restricted_product->get_id() ) );
		$form = get_the_password_form( $this->restricted_product->get_id() );
		$this->assertStringContainsString( 'newspack-subscriber-only-notice', $form );
		$this->assertStringNotContainsString( 'post_password', $form, 'No password field is offered.' );

		$this->visit_restricted_product_page( $this->subscriber_id );
		$this->assertFalse( post_password_required( $this->restricted_product->get_id() ) );
	}

	/**
	 * "Hide completely" turns the page into a 404 for a reader who can't see it.
	 */
	public function test_hidden_product_page_can_404() {
		$this->make_rule_view_restricted();
		Subscriber_Only_Products::update_settings( [ 'product_page' => Subscriber_Only_Products::PRODUCT_PAGE_HIDE ] );
		$this->visit_restricted_product_page( $this->non_subscriber_id );

		Product_Purchase_Restriction::restrict_product_page();

		$this->assertTrue( is_404() );
	}

	/**
	 * "Hide completely" keeps hidden products out of lists too, so a list never
	 * links to a page that answers 404.
	 */
	public function test_hiding_completely_also_hides_from_lists() {
		$this->make_rule_view_restricted();
		Subscriber_Only_Products::update_settings( [ 'product_page' => Subscriber_Only_Products::PRODUCT_PAGE_HIDE ] );

		$query = $this->run_product_query();

		$this->assertContains( $this->restricted_product->get_id(), (array) $query->get( 'post__not_in' ) );
	}

	/**
	 * Related products, upsells and cross-sells skip WP_Query, so list hiding
	 * reaches them through product visibility, and only for readers who can't
	 * see the product.
	 */
	public function test_hiding_reaches_lists_built_without_a_query() {
		$this->make_rule_view_restricted();
		wp_set_current_user( $this->non_subscriber_id );
		$this->assertTrue( Product_Purchase_Restriction::filter_product_is_visible( true, $this->restricted_product->get_id() ), 'List hiding is off.' );

		$this->hide_view_restricted_products();
		$this->assertFalse( Product_Purchase_Restriction::filter_product_is_visible( true, $this->restricted_product->get_id() ) );
		$this->assertTrue( Product_Purchase_Restriction::filter_product_is_visible( true, $this->open_product->get_id() ) );

		wp_set_current_user( $this->subscriber_id );
		$this->assertTrue( Product_Purchase_Restriction::filter_product_is_visible( true, $this->restricted_product->get_id() ) );
	}

	/**
	 * Where a hidden product is still listed, its price and image are withheld
	 * whatever the product page does, and a hidden variation drops out of its
	 * product's options.
	 */
	public function test_a_listed_hidden_product_shows_no_price_image_or_variation() {
		$this->make_rule_view_restricted();
		$variation = $this->create_product( $this->restricted_product->get_id() );
		wp_set_current_user( $this->non_subscriber_id );

		$this->assertSame( '', Product_Purchase_Restriction::filter_listed_price( '$8.00', $this->restricted_product ) );
		$this->assertSame( '$8.00', Product_Purchase_Restriction::filter_listed_price( '$8.00', $this->open_product ) );
		$this->assertStringNotContainsString( 'ebook.jpg', Product_Purchase_Restriction::filter_listed_image( '<img src="ebook.jpg">', $this->restricted_product ) );
		$this->assertFalse( Product_Purchase_Restriction::filter_variation_is_visible( true, $variation->get_id(), $this->restricted_product->get_id(), $variation ) );

		Subscriber_Only_Products::update_settings( [ 'product_page' => Subscriber_Only_Products::PRODUCT_PAGE_REDIRECT ] );
		$this->assertSame( '', Product_Purchase_Restriction::filter_listed_price( '$8.00', $this->restricted_product ), 'Whatever the product page does.' );

		wp_set_current_user( $this->subscriber_id );
		$this->assertSame( '$8.00', Product_Purchase_Restriction::filter_listed_price( '$8.00', $this->restricted_product ) );
		$this->assertTrue( Product_Purchase_Restriction::filter_variation_is_visible( true, $variation->get_id(), $this->restricted_product->get_id(), $variation ) );
	}

	/**
	 * The Store API serves a hidden product without its prices, descriptions,
	 * images or variations, to the readers who can't see it. Name and link stay,
	 * as on the product page.
	 */
	public function test_the_store_api_withholds_a_hidden_products_details() {
		$this->make_rule_view_restricted();
		$item = function ( $product ) {
			return [
				'id'                => $product->get_id(),
				'name'              => 'Ebook',
				'description'       => '<p>The whole book.</p>',
				'short_description' => '<p>Teaser.</p>',
				'prices'            => (object) [
					'price'         => '800',
					'regular_price' => '800',
					'sale_price'    => '800',
					'currency_code' => 'USD',
				],
				'price_html'        => '$8.00',
				'images'            => [ [ 'src' => 'ebook.jpg' ] ],
				'variations'        => [],
			];
		};
		$dispatch = function ( $route, $data ) {
			$response = new \WP_REST_Response( $data );
			return Product_Purchase_Restriction::filter_store_api_response( $response, null, new \WP_REST_Request( 'GET', $route ) )->get_data();
		};

		wp_set_current_user( $this->non_subscriber_id );
		$single = $dispatch( '/wc/store/v1/products/' . $this->restricted_product->get_id(), $item( $this->restricted_product ) );
		$this->assertSame( 'Ebook', $single['name'] );
		$this->assertSame( '', $single['description'] );
		$this->assertSame( '', $single['prices']->price );
		$this->assertSame( 'USD', $single['prices']->currency_code );
		$this->assertSame( [], $single['images'] );

		$collection = $dispatch( '/wc/store/products', [ $item( $this->restricted_product ), $item( $this->open_product ) ] );
		$this->assertSame( '', $collection[0]['price_html'] );
		$this->assertSame( '$8.00', $collection[1]['price_html'], 'A product nobody hides is untouched.' );

		// Through the hook, where core trims the response to `_fields`: a
		// request naming fields without `prices` is still redacted.
		$trimmed_request = new \WP_REST_Request( 'GET', '/wc/store/v1/products/' . $this->restricted_product->get_id() );
		$trimmed_request->set_param( '_fields', 'id,description,images' );
		$trimmed = apply_filters( 'rest_post_dispatch', new \WP_REST_Response( $item( $this->restricted_product ) ), rest_get_server(), $trimmed_request )->get_data();
		$this->assertSame( '', $trimmed['description'] );
		$this->assertSame( [], $trimmed['images'] );

		wp_set_current_user( $this->subscriber_id );
		$this->flush_caches();
		$this->assertSame( '<p>The whole book.</p>', $dispatch( '/wc/store/v1/products/' . $this->restricted_product->get_id(), $item( $this->restricted_product ) )['description'] );
	}

	/**
	 * Off its own page too, outside any product-page request, a hidden product
	 * counts as password-protected; that is what makes WordPress withhold its
	 * content and excerpt in the REST API and feeds. Not for a reader who can
	 * see it.
	 */
	public function test_a_hidden_product_is_protected_everywhere_on_the_front_end() {
		$this->make_rule_view_restricted();
		wp_set_current_user( $this->non_subscriber_id );
		$this->assertTrue( post_password_required( $this->restricted_product->get_id() ) );
		$this->assertStringContainsString( 'newspack-subscriber-only-notice', get_the_password_form( $this->restricted_product->get_id() ) );
		$this->assertFalse( post_password_required( $this->open_product->get_id() ) );

		wp_set_current_user( $this->subscriber_id );
		$this->assertFalse( post_password_required( $this->restricted_product->get_id() ) );
	}

	/**
	 * The core REST API leaves out a hidden product's featured image too.
	 */
	public function test_the_rest_api_leaves_out_a_hidden_products_image() {
		$this->make_rule_view_restricted();
		$response = function () {
			$response = new \WP_REST_Response( [ 'featured_media' => 42 ] );
			$response->add_link( 'https://api.w.org/featuredmedia', 'https://example.com/wp-json/wp/v2/media/42' );
			return $response;
		};

		wp_set_current_user( $this->non_subscriber_id );
		$hidden = Product_Purchase_Restriction::filter_rest_product( $response(), get_post( $this->restricted_product->get_id() ) );
		$this->assertSame( 0, $hidden->get_data()['featured_media'] );
		$this->assertArrayNotHasKey( 'https://api.w.org/featuredmedia', $hidden->get_links() );

		wp_set_current_user( $this->subscriber_id );
		$this->assertSame( 42, Product_Purchase_Restriction::filter_rest_product( $response(), get_post( $this->restricted_product->get_id() ) )->get_data()['featured_media'] );
	}

	/**
	 * A hidden product's oEmbed carries no image, and admin-ajax requests, which
	 * themes use for front-end listings, get the reader's view rather than
	 * wp-admin's.
	 */
	public function test_oembed_and_admin_ajax_respect_visibility() {
		$this->make_rule_view_restricted();
		wp_set_current_user( $this->non_subscriber_id );
		$image_id = $this->factory->attachment->create_object( 'ebook.jpg', $this->restricted_product->get_id(), [ 'post_mime_type' => 'image/jpeg' ] );
		set_post_thumbnail( $this->restricted_product->get_id(), $image_id );
		$this->assertArrayNotHasKey( 'thumbnail_url', (array) get_oembed_response_data( $this->restricted_product->get_id(), 600 ) );
		wp_set_current_user( $this->subscriber_id );
		$this->assertArrayHasKey( 'thumbnail_url', (array) get_oembed_response_data( $this->restricted_product->get_id(), 600 ), 'Core adds the image for a reader who can see it.' );
		wp_set_current_user( $this->non_subscriber_id );
		$GLOBALS['post'] = get_post( $this->restricted_product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertFalse( apply_filters( 'embed_thumbnail_id', 42 ), 'The embed iframe shows no image either.' );

		set_current_screen( 'edit-product' );
		$this->assertFalse( post_password_required( $this->restricted_product->get_id() ), 'wp-admin edits products.' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertTrue( post_password_required( $this->restricted_product->get_id() ), 'admin-ajax serves readers.' );
		$this->assertSame( '', Product_Purchase_Restriction::filter_listed_price( '$8.00', $this->restricted_product ) );
		$_REQUEST['action'] = 'woocommerce_json_search_products';
		$this->assertSame( '$8.00', Product_Purchase_Restriction::filter_listed_price( '$8.00', $this->restricted_product ), "WooCommerce's admin AJAX manages products." );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		// The core product route is public, so a REST read gets the reader's view.
		set_current_screen( 'front' );
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/product/' . $this->restricted_product->get_id();
		add_filter( 'wp_is_rest_endpoint', '__return_true' );
		$this->assertTrue( post_password_required( $this->restricted_product->get_id() ), 'A REST read of a hidden product withholds its content.' );
	}

	/**
	 * Outside WooCommerce's templates: a site search can list products, so list
	 * hiding reaches it, and where a hidden product is still listed its excerpt
	 * is the notice and its featured image is left out.
	 */
	public function test_site_search_hides_or_masks_hidden_products() {
		$this->hide_view_restricted_products();
		$search             = new \WP_Query();
		$search->query_vars = [ 's' => 'ebook' ];
		$search->is_search  = true;
		Product_Purchase_Restriction::filter_product_query( $search );
		$this->assertContains( $this->restricted_product->get_id(), (array) $search->get( 'post__not_in' ) );

		update_option( Subscriber_Only_Products::SETTINGS_OPTION_NAME, [ 'hide_from_product_lists' => false ] );
		$this->flush_caches();
		wp_set_current_user( $this->non_subscriber_id );
		$GLOBALS['post'] = get_post( $this->restricted_product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertStringContainsString( 'available to subscribers', Product_Purchase_Restriction::filter_listed_excerpt( '<p>The whole book.</p>' ) );
		$this->assertSame( '', Product_Purchase_Restriction::filter_listed_thumbnail( '<img src="ebook.jpg">', $this->restricted_product->get_id() ) );
		$GLOBALS['post'] = get_post( $this->open_product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertSame( '<p>Mug.</p>', Product_Purchase_Restriction::filter_listed_excerpt( '<p>Mug.</p>' ) );
	}

	/**
	 * "Redirect to a page" sends the reader to the chosen page. A page that is
	 * gone falls back to hiding the content, so the product never shows.
	 */
	public function test_hidden_product_page_can_redirect() {
		$this->make_rule_view_restricted();
		$page_id = $this->factory->post->create( [ 'post_type' => 'page' ] );
		Subscriber_Only_Products::update_settings(
			[
				'product_page'     => Subscriber_Only_Products::PRODUCT_PAGE_REDIRECT,
				'redirect_page_id' => $page_id,
			]
		);
		$this->visit_restricted_product_page( $this->non_subscriber_id );

		// Throwing from the redirect stops the request before its exit.
		$capture = function ( $location ) {
			throw new \RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
		add_filter( 'wp_redirect', $capture );
		try {
			Product_Purchase_Restriction::restrict_product_page();
			$this->fail( 'The reader was not redirected.' );
		} catch ( \RuntimeException $redirect ) {
			$this->assertSame( get_permalink( $page_id ), $redirect->getMessage() );
		}

		wp_delete_post( $page_id, true );
		Product_Purchase_Restriction::restrict_product_page();
		remove_filter( 'wp_redirect', $capture );
		$this->assertFalse( is_404() );
		$this->assertTrue( post_password_required( $this->restricted_product->get_id() ) );
	}

	/**
	 * A "Purchase" product's page is never touched.
	 */
	public function test_purchase_only_product_page_is_left_alone() {
		Subscriber_Only_Products::update_settings( [ 'product_page' => Subscriber_Only_Products::PRODUCT_PAGE_HIDE ] );
		$this->visit_restricted_product_page( $this->non_subscriber_id );

		Product_Purchase_Restriction::restrict_product_page();

		$this->assertFalse( is_404() );
		$this->assertFalse( post_password_required( $this->restricted_product->get_id() ) );
	}

	/**
	 * The rule type and the product-page setting read as the narrow defaults
	 * when absent or unrecognized.
	 */
	public function test_unknown_rule_type_and_page_setting_fall_back_to_defaults() {
		$saved = Subscriber_Only_Products::save_rule(
			[
				'restriction_type'         => 'bogus',
				'subscription_product_ids' => [ $this->subscription->get_id() ],
				'product_ids'              => [ $this->open_product->get_id() ],
				'active'                   => true,
			]
		);
		$this->assertSame( Subscriber_Only_Products::RESTRICTION_TYPE_PURCHASE, $saved['restriction_type'] );

		$typeless_rule = Subscriber_Only_Products::get_rule( 'rule' );
		unset( $typeless_rule['restriction_type'] );
		update_option( Subscriber_Only_Products::OPTION_NAME, [ $typeless_rule ] );
		$this->flush_caches();
		$this->assertSame( Subscriber_Only_Products::RESTRICTION_TYPE_PURCHASE, Subscriber_Only_Products::get_rule( 'rule' )['restriction_type'], 'A rule stored without a type reads as "Purchase".' );
		$this->assertFalse( Product_Purchase_Restriction::can_purchase( $this->restricted_product, $this->non_subscriber_id ), 'It still blocks the purchase.' );

		$settings = Subscriber_Only_Products::update_settings( [ 'product_page' => 'bogus' ] );
		$this->assertSame( Subscriber_Only_Products::PRODUCT_PAGE_HIDE_CONTENT, $settings['product_page'] );
	}
}
