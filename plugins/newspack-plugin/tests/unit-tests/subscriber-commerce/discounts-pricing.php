<?php
/**
 * Tests how subscriber discounts reach WooCommerce prices.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce;

use Newspack\Subscriber_Commerce;
use Newspack\Subscriber_Discounts;
use Newspack\Subscriber_Discounts_Pricing;
use Newspack\Tests\Subscriber_Commerce\Traits\Trait_Subscriber_Discounts_Fixtures;

/**
 * Pricing decisions: who gets a discount, on what, and how it is presented.
 *
 * The store and the subscription are the shared fixtures — see
 * {@see Trait_Subscriber_Discounts_Fixtures}.
 *
 * @group subscriber-commerce
 * @group Subscriber_Discounts
 */
class Test_Subscriber_Discounts_Pricing extends \WP_UnitTestCase {

	use Trait_Subscriber_Discounts_Fixtures;

	/**
	 * A discounted store product.
	 *
	 * @var \WC_Product
	 */
	private $book;

	/**
	 * Reader who holds no subscription.
	 *
	 * @var int
	 */
	private $non_subscriber_id;

	/**
	 * Load the WooCommerce mocks, plans included: a product_grants() decision can
	 * turn on whether a plan is applied to the instance being priced, so this
	 * suite needs the plans API mocked, not just plain WooCommerce.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/mocks/wcs-plans-mocks.php';
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

		// WP_UnitTestCase snapshots $wp_filter once, at the first test of the whole
		// run, and restores that snapshot after every test's tear_down() — so a
		// filter added once at require_once time (by wcs-plans-mocks.php, loaded
		// only in setUpBeforeClass()) survives only if this class happens to run
		// first. Re-adding it per test makes that independent of suite order.
		add_filter( 'woocommerce_is_subscription', [ 'WCS_ATT_Product_Schemes', 'filter_is_subscription' ], 10, 3 );

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
		$this->reset_products_database();
		\WCS_ATT_Product_Schemes::mock_reset();
		\Newspack\Subscription_Products::flush_cache();
		parent::tear_down();
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

	/**
	 * The point of the "all subscriptions" mode: a publisher marks a product
	 * discounted for subscribers without naming the tiers, and every subscriber
	 * gets it — including ones on a subscription no rule mentions.
	 */
	public function test_all_subscriptions_rule_discounts_every_subscriber() {
		$this->add_book_discount(
			[
				'subscription_targeting'   => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
				'subscription_product_ids' => [],
			]
		);

		$this->assertSame(
			90.0,
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id ),
			'Any reader with an active subscription gets the discount.'
		);
		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->non_subscriber_id ),
			'A reader holding no subscription still pays the list price.'
		);
	}

	/**
	 * With "apply at checkout" on, a subscription in the cart grants an
	 * "all subscriptions" discount before the reader has finished buying it —
	 * the cart branch asks WooCommerce what each line *is*, where the named mode
	 * only compares ids.
	 */
	public function test_all_subscriptions_rule_reads_a_subscription_in_the_cart() {
		Subscriber_Discounts::save_settings( [ 'apply_at_checkout' => true ] );
		$this->add_book_discount(
			[
				'subscription_targeting'   => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
				'subscription_product_ids' => [],
			]
		);
		$subscription    = $this->create_product( 50.0, null, 0, 'subscription' );
		$ordinary_pledge = $this->create_product( 50.0 );

		$this->set_cart_contents( [ $ordinary_pledge->get_id() ] );
		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->non_subscriber_id ),
			'An ordinary product in the cart is not a subscription.'
		);

		$this->set_cart_contents( [ $subscription->get_id() ] );
		$this->assertSame(
			90.0,
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->non_subscriber_id ),
			'A subscription in the cart grants the discount before checkout completes.'
		);
	}

	/**
	 * Stub WC() with a cart of real line items, in this process only.
	 *
	 * Only callable from tests annotated `@runInSeparateProcess` — defining WC()
	 * in the main suite process would flip `function_exists( 'WC' )` gates for
	 * every later test in the run. Needed so the cart branch can be exercised
	 * against a line item's own product instance (with a plan applied) rather
	 * than through the `newspack_subscriber_discounts_cart_product_ids` filter's
	 * IDs, which is how the rest of this suite simulates a cart. The stub itself
	 * lives in mocks/wc-cart-global-mock.php: this file is namespaced, and a
	 * `function WC() {}` declared in here would land in this namespace, not the
	 * global one production code actually calls.
	 *
	 * @param array $cart_items Cart line items, keyed however WooCommerce would key them.
	 */
	private function stub_wc_cart( array $cart_items ) {
		if ( ! $this->isInIsolation() ) {
			$this->fail( 'stub_wc_cart() may only be called from @runInSeparateProcess tests — defining WC() in the main suite process would flip function_exists( "WC" ) gates for every later test in the run.' );
		}
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-cart-global-mock.php';
		\WC()->cart = new \WC_Cart( $cart_items );
	}

	/**
	 * A plan applied to a cart line counts as a subscription in the cart for an
	 * "apply at checkout" all-subscribers discount: the cart branch asks each line
	 * what it is, because re-fetching the product by ID would drop the plan applied
	 * to that line. Runs in its own process because it needs a real WC()->cart stub.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_all_subscriptions_rule_reads_a_plan_applied_cart_line() {
		require_once dirname( __DIR__, 2 ) . '/mocks/wcs-plans-mocks.php';
		add_filter( 'woocommerce_is_subscription', [ 'WCS_ATT_Product_Schemes', 'filter_is_subscription' ], 10, 3 );
		// A fresh process has never run whichever other test file's top-level
		// require_once first populated these globals in the main run, so the
		// mocks that read them need them initialized here.
		global $subscriptions_database, $orders_database, $order_items_database;
		$subscriptions_database = $subscriptions_database ?? [];
		$orders_database        = $orders_database ?? [];
		$order_items_database   = $order_items_database ?? [];

		Subscriber_Discounts::save_settings( [ 'apply_at_checkout' => true ] );
		$this->add_book_discount(
			[
				'subscription_targeting'   => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
				'subscription_product_ids' => [],
				'targeting'                => 'all',
				'product_ids'              => [],
			]
		);

		$plan_product = $this->create_product( 50.0, null, 0, 'simple' );
		\WCS_ATT_Product_Schemes::mock_register(
			$plan_product->get_id(),
			[
				'1_month' => [
					'period'   => 'month',
					'interval' => 1,
				],
			]
		);
		$options       = \Newspack\Subscription_Products::get_purchase_options( $plan_product );
		$plan_instance = \Newspack\Subscription_Products::get_option_product( $options[1] );

		$this->stub_wc_cart(
			[
				'line' => [
					'product_id' => $plan_product->get_id(),
					'data'       => $plan_instance,
				],
			]
		);
		$this->flush_caches();

		$this->assertSame(
			90.0,
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->non_subscriber_id ),
			'A plan applied to the cart line grants the all-subscribers discount before checkout.'
		);

		$this->stub_wc_cart(
			[
				'line' => [
					'product_id' => $plan_product->get_id(),
					'data'       => $plan_product,
				],
			]
		);
		$this->flush_caches();

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->non_subscriber_id ),
			'The same cart holding the bare one-time instance does not grant the discount.'
		);
	}

	/**
	 * The cart is not fixed for the life of a request — WooCommerce mutates it and
	 * then prices the rest of the page. A reader who removes the subscription from
	 * their cart must lose the discount on everything priced afterwards, and the
	 * per-product memo does not cover that: the next product priced is a different
	 * key, so only the cart answer itself stands between them and a price they are
	 * no longer entitled to. The variation-price hash folds in that same answer.
	 */
	public function test_an_all_subscriptions_rule_follows_a_cart_changed_mid_request() {
		Subscriber_Discounts::save_settings( [ 'apply_at_checkout' => true ] );
		$this->add_book_discount(
			[
				'subscription_targeting'   => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
				'subscription_product_ids' => [],
				'targeting'                => 'all',
				'product_ids'              => [],
			]
		);
		$subscription   = $this->create_product( 50.0, null, 0, 'subscription' );
		$second_product = $this->create_product( 100.0 );

		$this->set_cart_contents( [ $subscription->get_id() ] );
		$this->assertSame( 90.0, Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->non_subscriber_id ) );

		// Swap the cart the way WooCommerce does mid-request, without touching the
		// pricing memos, then price a product nothing has priced yet.
		remove_all_filters( 'newspack_subscriber_discounts_cart_product_ids' );

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $second_product, $this->non_subscriber_id ),
			'Emptying the cart takes the discount away for whatever is priced next.'
		);
	}

	/**
	 * The same product, priced twice in one request either side of a cart change.
	 * This is the path the per-product memo covers and the cart memo does not: its
	 * key is the reader and the product, both unchanged, so without the cart in it
	 * the second pricing serves the first one's verdict and the reader keeps a
	 * discount they no longer qualify for.
	 */
	public function test_a_cart_change_repricing_the_same_product_is_not_served_from_the_memo() {
		Subscriber_Discounts::save_settings( [ 'apply_at_checkout' => true ] );
		$this->add_book_discount(
			[
				'subscription_targeting'   => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
				'subscription_product_ids' => [],
			]
		);
		$subscription = $this->create_product( 50.0, null, 0, 'subscription' );

		$this->set_cart_contents( [ $subscription->get_id() ] );
		$this->assertSame( 90.0, Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->non_subscriber_id ) );

		// Empty the cart the way WooCommerce does mid-request, leaving every memo
		// in place, then price the very same product again.
		remove_all_filters( 'newspack_subscriber_discounts_cart_product_ids' );

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->non_subscriber_id ),
			'The discount goes away as soon as the cart no longer grants it.'
		);
	}

	/**
	 * A discount never cuts the price of the thing that grants it. With no named
	 * grantor the guard widens to every subscription, so an "all subscriptions,
	 * all products" rule cannot quietly discount every renewal on the site.
	 */
	public function test_all_subscriptions_rule_never_discounts_a_subscription() {
		$subscription = $this->create_product( 100.0, null, 0, 'subscription' );
		$this->add_book_discount(
			[
				'subscription_targeting'   => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
				'subscription_product_ids' => [],
				'targeting'                => 'all',
				'product_ids'              => [],
			]
		);

		$this->assertSame(
			90.0,
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id ),
			'The store-wide rule still discounts an ordinary product.'
		);
		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $subscription, $this->subscriber_id ),
			'A subscription product keeps its price.'
		);
	}

	/**
	 * A plan-based product forced onto its plans has no one-time price to
	 * protect, so an all-subscribers rule must not discount it either — the
	 * bare catalog instance reads as `is_purchased_as_subscription() === false`
	 * (no plan applied yet), so the guard needs is_subscription_only() to catch it.
	 */
	public function test_all_subscriptions_rule_never_discounts_a_subscription_only_plan_product() {
		$plan_product = wc_create_mock_product(
			[
				'id'            => 505,
				'type'          => 'simple',
				'regular_price' => '100',
				'price'         => '100',
			]
		);
		\WCS_ATT_Product_Schemes::mock_register(
			$plan_product->get_id(),
			[
				'1_month' => [
					'period'   => 'month',
					'interval' => 1,
				],
			],
			true // Forced: no one-time option.
		);
		$this->add_book_discount(
			[
				'subscription_targeting'   => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
				'subscription_product_ids' => [],
				'targeting'                => 'all',
				'product_ids'              => [],
			]
		);

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $plan_product, $this->subscriber_id ),
			'A subscription-only plan product keeps its price, even on the bare catalog instance no plan has been applied to.'
		);
	}

	/**
	 * A product sold both one-time and on a subscription plan keeps its
	 * one-time price open to an all-subscribers rule, but a plan chosen on an
	 * instance of it reads as a subscription and the same rule leaves it alone
	 * — the guard `product_grants()` applies against the instance being priced,
	 * not the catalog product, so it must see the plan once it is applied.
	 * Runs the real WooCommerce filter chain, not the `get_subscriber_price()`
	 * shortcut the rest of this suite uses, because that is the seam
	 * `product_grants()`'s instance-vs-catalog decision can only be observed on.
	 */
	public function test_hybrid_one_time_price_is_discounted_and_plan_price_is_not() {
		$hybrid = wc_create_mock_product(
			[
				'id'            => 501,
				'type'          => 'simple',
				'regular_price' => '100',
				'price'         => '100',
			]
		);
		\WCS_ATT_Product_Schemes::mock_register(
			$hybrid->get_id(),
			[
				'1_month' => [
					'period'   => 'month',
					'interval' => 1,
					'price'    => 10,
				],
			]
		);
		Subscriber_Discounts::save_rule(
			[
				'subscription_targeting'   => Subscriber_Commerce::SUBSCRIPTION_TARGETING_ALL,
				'subscription_product_ids' => [],
				'targeting'                => 'products',
				'product_ids'              => [ $hybrid->get_id() ],
				'discount_type'            => 'percent',
				'amount'                   => 20,
			]
		);
		$this->flush_caches();
		\Newspack\Subscription_Products::flush_cache();
		wp_set_current_user( $this->subscriber_id );
		$this->enable_gates();
		Subscriber_Discounts_Pricing::register_price_filters();

		try {
			$this->assertEquals( 80.0, (float) $hybrid->get_price(), 'One-time price is discounted.' );

			$options   = \Newspack\Subscription_Products::get_purchase_options( $hybrid );
			$plan_line = \Newspack\Subscription_Products::get_option_product( $options[1] );
			$this->assertTrue(
				\Newspack\Subscription_Products::is_purchased_as_subscription( $plan_line ),
				'The plan instance reads as a subscription once the plan is applied.'
			);
			$this->assertEquals( 100.0, (float) $plan_line->get_price(), 'A chosen plan is never discounted by an all-subscribers rule.' );
		} finally {
			self::remove_price_filters();
		}
	}

	/**
	 * Plan products for the Audience preview flag: sold both ways, and forced onto
	 * its plans.
	 *
	 * @return array[]
	 */
	public function plan_products_for_the_audience_flag() {
		return [
			'sold both ways'     => [ 502, false, false ],
			'forced onto a plan' => [ 506, true, true ],
		];
	}

	/**
	 * The Audience wizard's price-preview flag agrees with product_grants() on the
	 * bare catalog instance, where no plan is applied yet: a product sold both ways
	 * previews as not-a-subscription, the verdict that leaves its one-time price
	 * open to discount at checkout, and a product forced onto its plans can never
	 * be bought one-time, so it previews as a subscription.
	 *
	 * @dataProvider plan_products_for_the_audience_flag
	 *
	 * @param int  $product_id Mock product ID.
	 * @param bool $forced     Whether the product is forced onto its plans.
	 * @param bool $expected   The expected preview flag.
	 */
	public function test_audience_flag_matches_product_grants_for_plan_products( $product_id, $forced, $expected ) {
		$product = wc_create_mock_product(
			[
				'id'    => $product_id,
				'type'  => 'simple',
				'price' => '50',
			]
		);
		\WCS_ATT_Product_Schemes::mock_register(
			$product->get_id(),
			[
				'1_month' => [
					'period'   => 'month',
					'interval' => 1,
				],
			],
			$forced
		);

		$get_product_data = new \ReflectionMethod( \Newspack\Audience_Subscriptions::class, 'get_product_data' );
		$get_product_data->setAccessible( true );

		$this->assertSame( $expected, $get_product_data->invoke( null, $product )['is_subscription'] );
	}

	/**
	 * With no active discount rule there is nothing to decide, so pricing a product
	 * never asks whether it is a subscription: that question runs on every price
	 * read on the storefront.
	 */
	public function test_no_active_rules_skip_the_subscription_check() {
		// Written, not just deleted: the rules memo is flushed by the option write
		// hooks, and a rolled-back row from an earlier test fires none.
		update_option( Subscriber_Discounts::OPTION_NAME, [] );
		$checks  = 0;
		$counter = function ( $is_subscription ) use ( &$checks ) {
			++$checks;
			return $is_subscription;
		};
		add_filter( 'woocommerce_is_subscription', $counter );
		\WCS_ATT_Product_Schemes::$mock_lookups = 0;

		$this->assertNull( Subscriber_Discounts_Pricing::get_subscriber_price( 100.0, $this->book, $this->subscriber_id ) );
		$this->assertSame( 0, $checks, 'No subscription check.' );
		$this->assertSame( 0, \WCS_ATT_Product_Schemes::$mock_lookups, 'No plan lookup.' );
	}
}
