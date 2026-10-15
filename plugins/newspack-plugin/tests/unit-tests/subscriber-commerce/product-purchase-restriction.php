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
use WCS_ATT_Product_Schemes;

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
		require_once dirname( __DIR__, 2 ) . '/mocks/wcs-plans-mocks.php';
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
		// WP_UnitTestCase restores hooks per test; wcs-plans-mocks.php adds this
		// filter only once, at require_once time.
		add_filter( 'woocommerce_is_subscription', [ 'WCS_ATT_Product_Schemes', 'filter_is_subscription' ], 10, 3 );

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
		\WCS_ATT_Product_Schemes::mock_reset();
		\Newspack\Subscription_Products::flush_cache();
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
	 * A hybrid product (one-time + a monthly plan) covered by an "all subscribers" rule.
	 */
	private function hybrid_under_all_subscribers_rule() {
		$hybrid = $this->create_product();
		WCS_ATT_Product_Schemes::mock_register(
			$hybrid->get_id(),
			[
				'1_month' => [
					'period'   => 'month',
					'interval' => 1,
				],
			] 
		);
		$this->set_rules(
			[
				[
					'id'                     => 'all',
					'subscription_targeting' => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
					'targeting'              => 'products',
					'product_ids'            => [ $hybrid->get_id() ],
					'active'                 => true,
				],
			]
		);
		return $hybrid;
	}

	/**
	 * The subscription option on a hybrid product stays exempt for a reader an
	 * "all subscribers" rule would otherwise refuse, but its one-time option is
	 * withdrawn so the rule's exemption for subscriptions can't be used to buy
	 * the product once.
	 */
	public function test_non_subscriber_can_subscribe_to_hybrid_but_not_buy_it_once() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );
		$this->assertTrue( Product_Purchase_Restriction::can_purchase( $hybrid ), 'The subscription stays on sale.' );
		$this->assertSame(
			[ 'plan:1_month' ],
			wp_list_pluck( \Newspack\Subscription_Products::get_purchase_options( $hybrid ), 'key' ),
			'The one-time option is withdrawn for this reader.'
		);
	}

	/**
	 * A reader the rule doesn't refuse keeps both options.
	 */
	public function test_subscriber_keeps_both_options() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->subscriber_id );
		$this->assertSame(
			[ 'one_time', 'plan:1_month' ],
			wp_list_pluck( \Newspack\Subscription_Products::get_purchase_options( $hybrid ), 'key' )
		);
	}

	/**
	 * A rule change takes effect in the same request: a reader the deleted rule
	 * restricted gets the one-time option back without anything else flushing.
	 */
	public function test_deleting_a_rule_restores_the_one_time_option() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );
		$this->assertSame( [ 'plan:1_month' ], wp_list_pluck( \Newspack\Subscription_Products::get_purchase_options( $hybrid ), 'key' ) );

		Subscriber_Only_Products::delete_rule( 'all' );

		$this->assertSame(
			[ 'one_time', 'plan:1_month' ],
			wp_list_pluck( \Newspack\Subscription_Products::get_purchase_options( $hybrid ), 'key' )
		);
	}

	/**
	 * A catalog button for a product sold both ways posts no plan, which the
	 * plan-less refusal turns away for a reader the rule restricts. That reader's
	 * catalog button links to the product page instead, where the form posts the
	 * plan. A product sold only on plans keeps its catalog button: a plan-less add
	 * of it is never refused.
	 */
	public function test_restricted_reader_is_sent_to_the_product_page_to_subscribe() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		$forced = $this->create_product();
		WCS_ATT_Product_Schemes::mock_register(
			$forced->get_id(),
			[
				'1_month' => [
					'period'   => 'month',
					'interval' => 1,
				],
			],
			true
		);
		$this->set_rules(
			[
				[
					'id'                     => 'all',
					'subscription_targeting' => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
					'targeting'              => 'products',
					'product_ids'            => [ $hybrid->get_id(), $forced->get_id() ],
					'active'                 => true,
				],
			]
		);

		wp_set_current_user( $this->non_subscriber_id );
		$this->assertTrue( apply_filters( 'wcsatt_prompt_plan_selection_in_catalog', false, $hybrid ), 'Restricted reader, sold both ways.' );
		$this->assertFalse( apply_filters( 'wcsatt_prompt_plan_selection_in_catalog', false, $forced ), 'Sold only on plans.' );

		wp_set_current_user( $this->subscriber_id );
		$this->assertFalse( apply_filters( 'wcsatt_prompt_plan_selection_in_catalog', false, $hybrid ), 'A reader the rule allows.' );
	}

	/**
	 * A cart safety net: a one-time line of a restricted hybrid product that
	 * reached the cart anyway (a cart saved before the rule existed, or a
	 * request that posted the one-time choice) is removed with a notice.
	 */
	public function test_cart_check_removes_one_time_line_of_restricted_hybrid() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );
		$one_time_line = clone $hybrid; // No plan applied: WooCommerce would charge it once.
		WCS_ATT_Product_Schemes::set_subscription_scheme( $one_time_line, false );
		$cart = new \WC_Cart( [ 'line' => [ 'data' => $one_time_line ] ] );
		global $wc_mock_notices;
		$wc_mock_notices = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		Product_Purchase_Restriction::enforce_one_time_restriction_in_cart( $cart );

		$this->assertSame( [], $cart->get_cart() );
		$this->assertSame( 'error', $wc_mock_notices[0]['type'] ?? null );
	}

	/**
	 * A plan line of the same restricted hybrid product is left in the cart:
	 * only the one-time option is withdrawn.
	 */
	public function test_cart_check_keeps_plan_line() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );
		$plan_line = clone $hybrid;
		WCS_ATT_Product_Schemes::set_subscription_scheme( $plan_line, '1_month' );
		$cart = new \WC_Cart( [ 'line' => [ 'data' => $plan_line ] ] );
		Product_Purchase_Restriction::enforce_one_time_restriction_in_cart( $cart );
		$this->assertCount( 1, $cart->get_cart() );
	}

	/**
	 * A legacy subscription product covered by an "all subscribers" rule stays
	 * exempt exactly as before: it has no one-time option to withdraw.
	 */
	public function test_legacy_subscription_exemption_is_unchanged() {
		$legacy = $this->create_product();
		wc_create_mock_product(
			[
				'id'   => $legacy->get_id(),
				'type' => 'subscription',
			] 
		);
		$this->set_rules(
			[
				[
					'id'                     => 'all',
					'subscription_targeting' => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
					'targeting'              => 'products',
					'product_ids'            => [ $legacy->get_id() ],
					'active'                 => true,
				],
			]
		);
		wp_set_current_user( $this->non_subscriber_id );
		$this->assertTrue( Product_Purchase_Restriction::can_purchase( wc_get_product( $legacy->get_id() ) ) );
	}

	/**
	 * An add-to-cart that posts no plan for a restricted hybrid product is refused:
	 * WooCommerce would otherwise put the product's first plan in the cart, starting
	 * a subscription from a button that showed the one-time price. Posting the
	 * one-time choice is refused the same way.
	 */
	public function test_plan_less_add_to_cart_of_restricted_hybrid_is_refused() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );
		$field = 'convert_to_sub_' . $hybrid->get_id();
		global $wc_mock_notices;
		$wc_mock_notices = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		try {
			$this->assertFalse( Product_Purchase_Restriction::validate_add_to_cart( true, $hybrid->get_id(), 1 ), 'No plan posted.' );
			$this->assertSame( 'error', $wc_mock_notices[0]['type'] ?? null );
			$this->assertSame( Product_Purchase_Restriction::get_restricted_message( $hybrid ), $wc_mock_notices[0]['notice'] ?? null );

			$_REQUEST[ $field ] = '0';
			$this->assertFalse( Product_Purchase_Restriction::validate_add_to_cart( true, $hybrid->get_id(), 1 ), 'One-time choice posted.' );

			$_REQUEST[ $field ] = '1_month';
			$this->assertTrue( Product_Purchase_Restriction::validate_add_to_cart( true, $hybrid->get_id(), 1 ), 'A posted plan goes through.' );
		} finally {
			unset( $_REQUEST[ $field ] );
		}
	}

	/**
	 * The same refusal on the path that never runs the validation filter: a direct
	 * WC_Cart::add_to_cart() call, which is how the modal checkout adds a product.
	 * A cart item that already carries a plan (a renewal or resubscribe restoring
	 * one) goes through.
	 */
	public function test_plan_less_cart_item_of_restricted_hybrid_is_refused() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );

		$data = [ 'wcsatt_data' => [ 'active_subscription_scheme' => '1_month' ] ];
		$this->assertSame( $data, Product_Purchase_Restriction::refuse_plan_less_cart_item( $data, $hybrid->get_id(), 0 ), 'A restored plan goes through.' );

		$this->expectException( \Exception::class );
		Product_Purchase_Restriction::refuse_plan_less_cart_item( [], $hybrid->get_id(), 0 );
	}

	/**
	 * A reader the rule doesn't refuse may add the hybrid with no plan posted,
	 * which is an ordinary one-time purchase.
	 */
	public function test_plan_less_add_to_cart_is_allowed_for_a_subscriber() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->subscriber_id );
		$this->assertTrue( Product_Purchase_Restriction::validate_add_to_cart( true, $hybrid->get_id(), 1 ) );
		$this->assertSame( [], Product_Purchase_Restriction::refuse_plan_less_cart_item( [], $hybrid->get_id(), 0 ) );
	}

	/**
	 * A product sold only on plans, or a legacy subscription, has no one-time price
	 * a plan-less button could have shown, so a plan-less add stays allowed: forms
	 * that post only a product ID (the countdown banner, the gifting prompt) keep
	 * selling them.
	 */
	public function test_plan_less_add_to_cart_is_allowed_for_subscription_only_products() {
		$forced = $this->create_product();
		WCS_ATT_Product_Schemes::mock_register(
			$forced->get_id(),
			[
				'1_month' => [
					'period'   => 'month',
					'interval' => 1,
				],
			],
			true
		);
		$legacy = $this->create_product();
		wc_create_mock_product(
			[
				'id'   => $legacy->get_id(),
				'type' => 'subscription',
			]
		);
		$this->set_rules(
			[
				[
					'id'                     => 'all',
					'subscription_targeting' => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
					'targeting'              => 'products',
					'product_ids'            => [ $forced->get_id(), $legacy->get_id() ],
					'active'                 => true,
				],
			]
		);
		wp_set_current_user( $this->non_subscriber_id );

		$this->assertTrue( Product_Purchase_Restriction::validate_add_to_cart( true, $forced->get_id(), 1 ), 'Sold only on plans.' );
		$this->assertTrue( Product_Purchase_Restriction::validate_add_to_cart( true, $legacy->get_id(), 1 ), 'Legacy subscription.' );
		$this->assertSame( [], Product_Purchase_Restriction::refuse_plan_less_cart_item( [], $legacy->get_id(), 0 ) );
	}

	/**
	 * A refusal another validator already made is left alone: no second notice,
	 * even for an add this filter would have refused itself.
	 */
	public function test_plan_less_add_to_cart_keeps_an_earlier_refusal() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );
		wc_mocks_reset_notices();

		$this->assertFalse( Product_Purchase_Restriction::validate_add_to_cart( false, $hybrid->get_id(), 1 ) );
		global $wc_mock_notices;
		$this->assertSame( [], $wc_mock_notices );
	}

	/**
	 * A cart WooCommerce Subscriptions rebuilds from an order (a failed first
	 * payment, a manual or early renewal, a resubscribe) posts no plan: it hands
	 * the plan the item was bought on to the validation filter as cart item data.
	 * That plan is the reader's choice, so the add goes through.
	 */
	public function test_restored_plan_passes_add_to_cart_validation() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );
		wc_mocks_reset_notices();
		$restored = [ 'wcsatt_data' => [ 'active_subscription_scheme' => '1_month' ] ];

		$this->assertTrue(
			apply_filters( 'woocommerce_add_to_cart_validation', true, $hybrid->get_id(), 1, 0, [], $restored ),
			'The filter WooCommerce Subscriptions runs, with the restored item data as its sixth argument.'
		);
		global $wc_mock_notices;
		$this->assertSame( [], $wc_mock_notices );

		$one_time = [ 'wcsatt_data' => [ 'active_subscription_scheme' => false ] ];
		$this->assertFalse(
			Product_Purchase_Restriction::validate_add_to_cart( true, $hybrid->get_id(), 1, 0, [], $one_time ),
			'An order bought one-time restores no plan, and stays refused.'
		);
	}

	/**
	 * A separate process never ran the top-level require_once that initializes the
	 * WooCommerce mocks' globals in the main run, so the mocks that read them need
	 * them set here.
	 */
	private function init_mock_globals_in_isolation() {
		global $subscriptions_database, $orders_database, $order_items_database, $products_database;
		$subscriptions_database = $subscriptions_database ?? []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$orders_database        = $orders_database ?? []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$order_items_database   = $order_items_database ?? []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$products_database      = $products_database ?? []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Each plan-less refusal is logged with the product and whether the cart item
	 * came restored from an order, so a payment flow that is still refused shows
	 * up in the logs.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_plan_less_refusal_is_logged() {
		if ( ! defined( 'NEWSPACK_LOG_LEVEL' ) ) {
			define( 'NEWSPACK_LOG_LEVEL', 1 );
		}
		$this->init_mock_globals_in_isolation();
		$hybrid   = $this->hybrid_under_all_subscribers_rule();
		$log_file = get_temp_dir() . 'plan-less-refusal-' . wp_generate_password( 8, false ) . '.log';
		$previous = ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		wp_set_current_user( $this->non_subscriber_id );

		Product_Purchase_Restriction::validate_add_to_cart( true, $hybrid->get_id(), 1 );
		Product_Purchase_Restriction::validate_add_to_cart( true, $hybrid->get_id(), 1, 0, [], [ 'wcsatt_data' => [ 'active_subscription_scheme' => false ] ] );

		ini_set( 'error_log', $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$logged = file_exists( $log_file ) ? file_get_contents( $log_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local temp file.
		if ( file_exists( $log_file ) ) {
			unlink( $log_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}

		$this->assertStringContainsString( 'product ' . $hybrid->get_id() . ' (no restored cart item data)', $logged );
		$this->assertStringContainsString( 'product ' . $hybrid->get_id() . ' (restored cart item data)', $logged );
	}

	/**
	 * A refused cart rebuilt from an order is a payment flow that failed, so it is
	 * reported whatever the site's log level. A plain plan-less refusal is not.
	 */
	public function test_a_refused_restored_cart_is_reported() {
		$hybrid   = $this->hybrid_under_all_subscribers_rule();
		$reported = [];
		$capture  = function ( $code, $message, $params ) use ( &$reported ) {
			$reported[] = [ $code, $params['data']['product_id'] ?? null ];
		};
		add_action( 'newspack_log', $capture, 10, 3 );
		wp_set_current_user( $this->non_subscriber_id );

		Product_Purchase_Restriction::validate_add_to_cart( true, $hybrid->get_id(), 1 );
		$this->assertSame( [], $reported, 'A plain plan-less refusal.' );

		Product_Purchase_Restriction::validate_add_to_cart( true, $hybrid->get_id(), 1, 0, [], [ 'wcsatt_data' => [ 'active_subscription_scheme' => false ] ] );
		$this->assertSame( [ [ 'newspack_subscriber_commerce_plan_less_refusal', $hybrid->get_id() ] ], $reported );
	}

	/**
	 * Only the Store API skips the throwing cart item refusal: it applies the cart
	 * item data filter outside the cart's try/catch. Any other REST request that adds
	 * to the cart directly never runs the validation filter, so it still refuses.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_plan_less_cart_item_is_refused_over_rest_outside_the_store_api() {
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}
		$this->init_mock_globals_in_isolation();
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );

		$this->expectException( \Exception::class );
		Product_Purchase_Restriction::refuse_plan_less_cart_item( [], $hybrid->get_id(), 0 );
	}

	/**
	 * A Store API request is never refused here, where a throw would be a 500:
	 * WooCommerce Subscriptions puts a plan-less add on the product's default plan.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_plan_less_cart_item_is_not_refused_in_the_store_api() {
		global $newspack_test_wc;
		$newspack_test_wc = new class() {
			/**
			 * Cart double.
			 *
			 * @var object|null
			 */
			public $cart = null;

			/**
			 * The request is a Store API request.
			 */
			public function is_store_api_request() {
				return true;
			}
		};
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-cart-global-mock.php';
		$this->init_mock_globals_in_isolation();
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );

		$this->assertSame( [], Product_Purchase_Restriction::refuse_plan_less_cart_item( [], $hybrid->get_id(), 0 ) );
	}

	/**
	 * Purchase options are per viewer: a reader who logs in mid-request (modal
	 * checkout registration) is offered what their account allows, not the list
	 * built while they were logged out.
	 */
	public function test_purchase_options_follow_the_current_reader() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );
		$this->assertSame( [ 'plan:1_month' ], wp_list_pluck( \Newspack\Subscription_Products::get_purchase_options( $hybrid ), 'key' ) );

		wp_set_current_user( $this->subscriber_id );
		$this->assertSame(
			[ 'one_time', 'plan:1_month' ],
			wp_list_pluck( \Newspack\Subscription_Products::get_purchase_options( $hybrid ), 'key' ),
			'Same request, another reader.'
		);
	}

	/**
	 * A reader in the payment-recovery window subscribes inside a gate that allows the
	 * grace and not inside one that doesn't, so what a product sold both ways offers
	 * them follows the evaluation context, whichever context asks first.
	 */
	public function test_purchase_options_follow_the_payment_recovery_context() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( $this->non_subscriber_id );
		$in_grace = function ( $has_subscription, $user_id ) {
			return $has_subscription || ( $user_id === $this->non_subscriber_id && \Newspack\Access_Rules::get_evaluation_context( 'payment_recovery_grace', true ) );
		};
		add_filter( 'newspack_access_rules_has_active_subscription', $in_grace, 20, 2 );
		$strict = fn( $callback ) => \Newspack\Access_Rules::with_evaluation_context( [ 'payment_recovery_grace' => false ], $callback );

		$this->assertSame(
			[ 'plan:1_month' ],
			$strict( fn() => wp_list_pluck( \Newspack\Subscription_Products::get_purchase_options( $hybrid ), 'key' ) ),
			'Without the grace, the one-time option is withdrawn.'
		);
		$this->assertTrue( $strict( fn() => \Newspack\Subscription_Products::only_sells_as_subscription( $hybrid ) ) );
		$this->assertSame(
			[ 'one_time', 'plan:1_month' ],
			wp_list_pluck( \Newspack\Subscription_Products::get_purchase_options( $hybrid ), 'key' ),
			'With the grace, it is kept.'
		);
		$this->assertFalse( \Newspack\Subscription_Products::only_sells_as_subscription( $hybrid ) );
	}

	/**
	 * The one-time purchase migrations read what a product is configured to sell, not
	 * what the viewer running them may buy: WP-CLI runs as user 0, whom an "all
	 * subscribers" rule refuses the one-time option of a product sold both ways.
	 */
	public function test_migration_split_ignores_the_viewer() {
		require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		wp_set_current_user( 0 );
		$this->assertTrue( Product_Purchase_Restriction::is_one_time_restricted( $hybrid, 0 ), 'User 0 may not buy it once.' );

		$method = new \ReflectionMethod( \Newspack\CLI\Membership_Gates_Migration::class, 'classify_product_ids' );
		$method->setAccessible( true );
		$split = $method->invoke( null, [ $hybrid->get_id() ] );

		$this->assertSame( [ $hybrid->get_id() ], $split['subscription'] );
		$this->assertSame( [ $hybrid->get_id() ], $split['one_time'], 'Its one-time buyers keep a rule.' );
	}

	/**
	 * Tier eligibility is a product's configuration too: a product sold both ways
	 * stays out of the product-only pickers whoever asks.
	 */
	public function test_tier_eligibility_ignores_the_viewer() {
		$hybrid = $this->hybrid_under_all_subscribers_rule();
		update_post_meta( $hybrid->get_id(), '_wcsatt_schemes_status', 'override' );
		wp_set_current_user( 0 );
		$this->assertTrue( Product_Purchase_Restriction::is_one_time_restricted( $hybrid, 0 ), 'User 0 may not buy it once.' );

		$ids = array_map( fn( $p ) => $p->get_id(), \Newspack\Subscriptions_Tiers::get_tier_eligible_products() );
		$this->assertNotContains( $hybrid->get_id(), $ids );
	}

	/**
	 * A product no "all subscribers" rule covers is never looked up for plans:
	 * the rule check is the cheap one, and it settles the answer.
	 */
	public function test_one_time_restriction_skips_the_plan_lookup_when_no_rule_covers_the_product() {
		WCS_ATT_Product_Schemes::$mock_lookups = 0;
		$this->assertFalse( Product_Purchase_Restriction::is_one_time_restricted( $this->open_product, $this->non_subscriber_id ) );
		$this->assertSame( 0, WCS_ATT_Product_Schemes::$mock_lookups );
	}
}
