<?php
/**
 * Tests how subscriber discounts reach WooCommerce prices.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce;

use Newspack\Product_Targeting;
use Newspack\Subscriber_Discounts;
use Newspack\Subscriber_Discounts_Pricing;
use Newspack\Subscriber_Eligibility;

/**
 * Pricing decisions: who gets a discount, on what, and how it is presented.
 *
 * WooCommerce is not loaded in the test suite, so products are the repo's
 * `WC_Product` mocks backed by real `product` posts, following the same pattern
 * as the shared targeting tests. Subscription ownership is simulated through
 * the access-rules filter rather than by building real subscriptions.
 *
 * @group subscriber-commerce
 * @group Subscriber_Discounts
 */
class Test_Subscriber_Discounts_Pricing extends \WP_UnitTestCase {

	/**
	 * The subscription product that grants the discount.
	 */
	const GRANTING_SUBSCRIPTION_ID = 4242;

	/**
	 * A discounted store product.
	 *
	 * @var \WC_Product
	 */
	private $book;

	/**
	 * Reader who holds the granting subscription.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Reader who holds no subscription.
	 *
	 * @var int
	 */
	private $non_subscriber_id;

	/**
	 * Load the WooCommerce mocks.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Build the store and the two readers, and make the subscriber's ownership
	 * of the granting subscription the only thing that distinguishes them.
	 */
	public function set_up() {
		parent::set_up();

		register_post_type( 'product', [ 'public' => true ] );
		register_post_type( 'product_variation', [ 'public' => false ] );
		register_taxonomy( 'product_cat', 'product', [ 'hierarchical' => true ] );

		delete_option( Subscriber_Discounts::OPTION_NAME );
		delete_option( Subscriber_Discounts::SETTINGS_OPTION_NAME );

		$this->subscriber_id     = $this->factory->user->create();
		$this->non_subscriber_id = $this->factory->user->create();

		$this->book = $this->create_product( 100.0 );
		$this->set_cart_contents( [] );

		add_filter( 'newspack_access_rules_has_active_subscription', [ $this, 'grant_subscription_to_subscriber' ], 10, 3 );

		$this->flush_caches();
	}

	/**
	 * Detach the simulated subscription and clear memoized state.
	 */
	public function tear_down() {
		$this->set_cart_contents( [] );
		$this->clear_rest_route();
		remove_filter( 'newspack_access_rules_has_active_subscription', [ $this, 'grant_subscription_to_subscriber' ], 10 );
		$this->flush_caches();
		global $products_database;
		$products_database = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * Only the subscriber holds the granting subscription.
	 *
	 * @param bool  $has_subscription Whether the reader has one.
	 * @param int   $user_id          Reader.
	 * @param int[] $product_ids      Subscription products that would grant it.
	 * @return bool
	 */
	public function grant_subscription_to_subscriber( $has_subscription, $user_id, $product_ids ) {
		return (int) $user_id === $this->subscriber_id && in_array( self::GRANTING_SUBSCRIPTION_ID, array_map( 'absint', $product_ids ), true );
	}

	/**
	 * Put products in the reader's cart, through the seam the pricing layer
	 * reads the cart from.
	 *
	 * @param int[] $product_ids Products in the cart.
	 */
	private function set_cart_contents( $product_ids ) {
		remove_all_filters( 'newspack_subscriber_discounts_cart_product_ids' );
		add_filter(
			'newspack_subscriber_discounts_cart_product_ids',
			function () use ( $product_ids ) {
				return $product_ids;
			}
		);
		$this->flush_caches();
	}

	/**
	 * Make the request look like a REST call to a given route.
	 *
	 * @param string $route Route, leading slash included.
	 */
	private function set_rest_route( $route ) {
		add_filter( 'wp_is_rest_endpoint', '__return_true' );
		$GLOBALS['wp']->query_vars['rest_route'] = $route;
		$this->flush_caches();
	}

	/**
	 * Stop the request looking like a REST call.
	 */
	private function clear_rest_route() {
		remove_all_filters( 'wp_is_rest_endpoint' );
		unset( $GLOBALS['wp']->query_vars['rest_route'] );
		$this->flush_caches();
	}

	/**
	 * Reset every memoized layer between assertions.
	 */
	private function flush_caches() {
		Product_Targeting::flush_cache();
		Subscriber_Eligibility::flush_cache();
		Subscriber_Discounts_Pricing::flush_cache();
	}

	/**
	 * Create a product post plus its mock, registered so wc_get_product() finds it.
	 *
	 * @param float $price      Product price.
	 * @param float $sale_price Sale price, when the product is on sale.
	 * @param int   $product_id Explicit post ID, when the test needs a known one.
	 * @return \WC_Product
	 */
	private function create_product( $price, $sale_price = null, $product_id = 0 ) {
		$post_id = $product_id ? $product_id : $this->factory->post->create( [ 'post_type' => 'product' ] );
		$data    = [
			'id'            => $post_id,
			'price'         => $price,
			'regular_price' => $price,
		];
		if ( null !== $sale_price ) {
			$data['price']      = $sale_price;
			$data['sale_price'] = $sale_price;
		}
		$product = new \WC_Product( $data );
		global $products_database;
		$products_database[ $post_id ] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		return $product;
	}

	/**
	 * Store a rule discounting the book for holders of the granting subscription.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function add_book_discount( $overrides = [] ) {
		$rule = Subscriber_Discounts::save_rule(
			array_merge(
				[
					'subscription_product_ids' => [ self::GRANTING_SUBSCRIPTION_ID ],
					'targeting'                => 'products',
					'product_ids'              => [ $this->book->get_id() ],
					'discount_type'            => 'percent',
					'amount'                   => 10,
				],
				$overrides
			)
		);
		$this->flush_caches();
		return $rule;
	}

	/**
	 * The headline behaviour: a subscriber pays less, everyone else pays the
	 * list price.
	 */
	public function test_only_qualifying_subscribers_are_discounted() {
		$this->add_book_discount();

		$this->assertSame(
			90.0,
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id ),
			'A subscriber of the granting subscription gets 10% off.'
		);
		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->non_subscriber_id ),
			'A logged-in reader without the subscription pays the list price.'
		);
		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, 0 ),
			'A logged-out visitor pays the list price — there is no reader to check.'
		);
	}

	/**
	 * A rule that does not cover the product leaves it alone, so a store-wide
	 * price drop can never be caused by an unrelated rule.
	 */
	public function test_products_outside_the_rule_are_untouched() {
		$this->add_book_discount();
		$unrelated_product = $this->create_product( 50.0 );

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 50.0, $unrelated_product, $this->subscriber_id ),
			'A product no rule targets keeps its price even for a subscriber.'
		);
	}

	/**
	 * Pausing a rule takes effect on the storefront, not just in the admin list.
	 */
	public function test_paused_rules_do_not_discount() {
		$rule = $this->add_book_discount();

		Subscriber_Discounts::set_rule_active( $rule['id'], false );
		$this->flush_caches();

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id ),
			'A paused rule stops discounting immediately.'
		);
	}

	/**
	 * By default a subscriber discount stacks on top of a sale price, matching
	 * Memberships. Turning the setting off leaves a product that is already on
	 * sale at its sale price, so a promotion and a subscriber discount cannot
	 * compound.
	 */
	public function test_on_sale_products_are_discounted_unless_the_setting_forbids_it() {
		$discounted_book = $this->create_product( 100.0, 80.0 );
		Subscriber_Discounts::save_rule(
			[
				'subscription_product_ids' => [ self::GRANTING_SUBSCRIPTION_ID ],
				'targeting'                => 'products',
				'product_ids'              => [ $discounted_book->get_id() ],
				'discount_type'            => 'percent',
				'amount'                   => 10,
			]
		);
		$this->flush_caches();

		$this->assertSame(
			72.0,
			Subscriber_Discounts_Pricing::get_subscriber_price( 80.0, $discounted_book, $this->subscriber_id ),
			'By default the subscriber discount applies on top of the sale price.'
		);

		Subscriber_Discounts::save_settings( [ 'apply_on_sale' => false ] );
		$this->flush_caches();

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 80.0, $discounted_book, $this->subscriber_id ),
			'With the setting off, a product already on sale is left at its sale price.'
		);
	}

	/**
	 * WooCommerce caches a variable product's price range under a hash. If that
	 * hash did not vary by reader, the first reader to warm the cache would fix
	 * the prices every other reader sees — a subscriber's discounted range could
	 * leak to the public, or the public range could hide a subscriber's
	 * discount.
	 */
	public function test_variation_price_cache_key_varies_by_reader() {
		$this->add_book_discount();

		wp_set_current_user( $this->subscriber_id );
		$subscriber_hash = Subscriber_Discounts_Pricing::filter_variation_prices_hash( [ 'base' => 1 ], $this->book );

		wp_set_current_user( $this->non_subscriber_id );
		$non_subscriber_hash = Subscriber_Discounts_Pricing::filter_variation_prices_hash( [ 'base' => 1 ], $this->book );

		$this->assertNotEquals(
			$subscriber_hash,
			$non_subscriber_hash,
			'Two readers must not share a cached variation price range.'
		);
	}

	/**
	 * The cache key also changes when the rules change, so editing a discount
	 * does not leave readers on prices computed under the old rules.
	 */
	public function test_variation_price_cache_key_varies_by_rule_set() {
		wp_set_current_user( $this->subscriber_id );
		$hash_before_any_rule = Subscriber_Discounts_Pricing::filter_variation_prices_hash( [ 'base' => 1 ], $this->book );

		$this->add_book_discount();
		$hash_with_rule = Subscriber_Discounts_Pricing::filter_variation_prices_hash( [ 'base' => 1 ], $this->book );

		$this->assertNotEquals( $hash_before_any_rule, $hash_with_rule, 'Adding a rule must invalidate cached variation prices.' );
	}

	/**
	 * Reading an undiscounted price re-enters these filters. While suspended
	 * they must report the price unchanged, or working out "was this already on
	 * sale?" would recurse.
	 */
	public function test_suspension_stands_the_filters_down() {
		$this->add_book_discount();

		Subscriber_Discounts_Pricing::suspend();
		$price_while_suspended = Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id );
		Subscriber_Discounts_Pricing::resume();

		$this->assertNull( $price_while_suspended, 'Suspended filters report no discount.' );
		$this->assertSame(
			90.0,
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id ),
			'Resuming restores the discount.'
		);
	}

	/**
	 * The discount is presented as a sale so WooCommerce and the theme render
	 * the original struck through beside the subscriber price without any
	 * bespoke markup.
	 */
	public function test_discounted_product_reports_itself_as_on_sale() {
		$this->add_book_discount();
		wp_set_current_user( $this->subscriber_id );

		$this->assertTrue(
			Subscriber_Discounts_Pricing::filter_is_on_sale( false, $this->book ),
			'A discounted product reports as on sale for the subscriber.'
		);

		wp_set_current_user( $this->non_subscriber_id );
		$this->flush_caches();

		$this->assertFalse(
			Subscriber_Discounts_Pricing::filter_is_on_sale( false, $this->book ),
			'It does not report as on sale for a reader who gets no discount.'
		);
	}

	/**
	 * With "apply discounts at checkout" on, a subscription sitting in the cart
	 * is enough — a reader buying a subscription and a discounted product in the
	 * same order sees the subscriber price before they have checked out.
	 */
	public function test_a_subscription_in_the_cart_can_grant_the_discount() {
		$this->add_book_discount();

		$non_subscriber_with_subscription_in_cart = $this->non_subscriber_id;
		$this->set_cart_contents( [ self::GRANTING_SUBSCRIPTION_ID ] );

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $non_subscriber_with_subscription_in_cart ),
			'Off by default: what is in the cart does not yet make anyone a subscriber.'
		);

		Subscriber_Discounts::save_settings( [ 'apply_at_checkout' => true ] );
		$this->flush_caches();

		$this->assertSame(
			90.0,
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $non_subscriber_with_subscription_in_cart ),
			'With the setting on, the subscription in the cart grants the discount.'
		);

		$this->set_cart_contents( [] );

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $non_subscriber_with_subscription_in_cart ),
			'An empty cart grants nothing.'
		);
	}

	/**
	 * The subscription that grants a discount is never discounted by its own
	 * rule: the reader is not a subscriber of it yet, and discounting the thing
	 * that grants the discount is circular.
	 */
	public function test_the_granting_subscription_is_not_discounted_by_its_own_rule() {
		Subscriber_Discounts::save_rule(
			[
				'subscription_product_ids' => [ self::GRANTING_SUBSCRIPTION_ID ],
				'targeting'                => 'all',
				'discount_type'            => 'percent',
				'amount'                   => 10,
			]
		);
		Subscriber_Discounts::save_settings( [ 'apply_at_checkout' => true ] );

		$granting_subscription = $this->create_product( 120.0, null, self::GRANTING_SUBSCRIPTION_ID );
		$this->set_cart_contents( [ self::GRANTING_SUBSCRIPTION_ID ] );

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 120.0, $granting_subscription, $this->non_subscriber_id ),
			'The subscription in the cart is not discounted by the rule it grants.'
		);
		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 120.0, $granting_subscription, $this->subscriber_id ),
			'Nor is it for a reader who already holds it — otherwise a whole-catalogue rule cuts its own renewal price.'
		);
		$this->assertSame(
			90.0,
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->non_subscriber_id ),
			'Everything else the rule covers still is.'
		);
	}

	/**
	 * Prices are not adjusted on the REST routes that read a price in order to
	 * manage it.
	 *
	 * A REST request is neither an admin screen nor AJAX, so nothing above
	 * catches it. The discount editor's own product search reports the price a
	 * rule is composed over, and would otherwise report one this rule had
	 * already discounted — the editor's preview then discounts it twice. The
	 * storefront's Store API is the opposite case and must keep discounting.
	 */
	public function test_prices_are_not_adjusted_on_management_rest_routes() {
		$this->add_book_discount();

		$this->set_rest_route( '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience/products-search' );
		$price_in_the_discount_editor = Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id );

		$this->set_rest_route( '/wc/v3/products/' . $this->book->get_id() );
		$price_in_the_woocommerce_api = Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id );

		$this->set_rest_route( '/wc/store/v1/products/' . $this->book->get_id() );
		$price_in_the_store_api = Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id );

		$this->assertNull( $price_in_the_discount_editor, 'The discount editor is shown the price a rule discounts from, not the discounted one.' );
		$this->assertNull( $price_in_the_woocommerce_api, 'WooCommerce\'s management API reports the stored price, which is what it writes back.' );
		$this->assertSame( 90.0, $price_in_the_store_api, 'The storefront\'s own API reports what the reader is charged.' );
	}

	/**
	 * Prices are not adjusted on admin screens.
	 *
	 * A subscriber discount belongs to the storefront. In wp-admin the same
	 * price reads are how the catalogue is edited: an administrator who also
	 * holds a subscription would see their own discounted price in the product
	 * editor, and Quick Edit would save it back as the product's real price for
	 * everyone.
	 */
	public function test_prices_are_not_adjusted_on_admin_screens() {
		$this->add_book_discount();

		set_current_screen( 'edit-product' );
		$price_in_admin = Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id );
		set_current_screen( 'front' );
		$price_on_storefront = Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id );

		$this->assertNull( $price_in_admin, 'An admin screen sees the product\'s real price, not a subscriber\'s.' );
		$this->assertSame( 90.0, $price_on_storefront, 'The storefront still discounts for the same reader.' );
	}

	/**
	 * Exercised through the real filter chain rather than by calling the
	 * decision method directly.
	 *
	 * `get_price()` is itself filtered, so any callback that reads it while
	 * computing a price sees an already-discounted number. Reporting the
	 * subscriber price on the sale-price filter without standing the
	 * adjustments down applies the rule twice, and the product then advertises
	 * a sale price lower than it charges — invisible in `get_price_html()`,
	 * which renders from `get_price()`, but exposed directly by the Store API.
	 */
	public function test_price_and_sale_price_agree_through_the_filter_chain() {
		$this->add_book_discount();
		wp_set_current_user( $this->subscriber_id );

		// The stand-down check has to answer yes for real here: its filter can
		// only turn enforcement off, so this satisfies the two conditions it
		// actually reads. WooCommerce is present via the mocks and Memberships is
		// absent, leaving the content-gates flag as the one thing to switch on.
		$this->enable_gates();
		Subscriber_Discounts_Pricing::register_price_filters();

		$price      = (float) $this->book->get_price();
		$sale_price = (float) $this->book->get_sale_price();
		$on_sale    = $this->book->is_on_sale();

		self::remove_price_filters();

		$this->assertSame( 90.0, $price, 'The reader is charged 10% off.' );
		$this->assertSame( 90.0, $sale_price, 'The advertised sale price is the same figure, not the discount applied a second time.' );
		$this->assertTrue( $on_sale, 'The product presents as on sale so the original renders struck through.' );
	}

	/**
	 * Turn the content-gates flag on, which is what makes
	 * Subscriber_Commerce::is_enforcement_active() answer yes here.
	 *
	 * A constant, so it cannot be switched back off — only the test that needs
	 * enforcement genuinely active calls this.
	 */
	private function enable_gates() {
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Detach the price filters again so they cannot affect other assertions.
	 */
	private static function remove_price_filters() {
		$priority = apply_filters( 'newspack_subscriber_discounts_price_filter_priority', 999 );
		remove_filter( 'woocommerce_product_get_price', [ Subscriber_Discounts_Pricing::class, 'filter_price' ], $priority );
		remove_filter( 'woocommerce_product_variation_get_price', [ Subscriber_Discounts_Pricing::class, 'filter_price' ], $priority );
		remove_filter( 'woocommerce_product_get_sale_price', [ Subscriber_Discounts_Pricing::class, 'filter_sale_price' ], $priority );
		remove_filter( 'woocommerce_product_variation_get_sale_price', [ Subscriber_Discounts_Pricing::class, 'filter_sale_price' ], $priority );
		remove_filter( 'woocommerce_variation_prices_price', [ Subscriber_Discounts_Pricing::class, 'filter_variation_prices' ], $priority );
		remove_filter( 'woocommerce_variation_prices_sale_price', [ Subscriber_Discounts_Pricing::class, 'filter_variation_sale_prices' ], $priority );
		remove_filter( 'woocommerce_get_variation_prices_hash', [ Subscriber_Discounts_Pricing::class, 'filter_variation_prices_hash' ], $priority );
		remove_filter( 'woocommerce_product_is_on_sale', [ Subscriber_Discounts_Pricing::class, 'filter_is_on_sale' ], $priority );
	}

	/**
	 * An empty price (a product with no price set) is left alone rather than
	 * being coerced to a discounted zero.
	 */
	public function test_products_without_a_price_are_left_alone() {
		$this->add_book_discount();

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( '', $this->book, $this->subscriber_id ),
			'A product with no price has nothing to discount.'
		);
	}
}
