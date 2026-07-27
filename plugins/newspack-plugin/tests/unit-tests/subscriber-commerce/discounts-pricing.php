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

		add_filter( 'newspack_access_rules_has_active_subscription', [ $this, 'grant_subscription_to_subscriber' ], 10, 3 );

		$this->flush_caches();
	}

	/**
	 * Detach the simulated subscription and clear memoized state.
	 */
	public function tear_down() {
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
	 * @param float $price     Product price.
	 * @param float $sale_price Sale price, when the product is on sale.
	 * @return \WC_Product
	 */
	private function create_product( $price, $sale_price = null ) {
		$post_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
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
	 * By default a product already on sale is left at its sale price, so a
	 * promotion and a subscriber discount cannot silently compound. Turning the
	 * setting on opts into exactly that.
	 */
	public function test_on_sale_products_are_skipped_unless_the_setting_allows_them() {
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

		$this->assertNull(
			Subscriber_Discounts_Pricing::get_subscriber_price( 80.0, $discounted_book, $this->subscriber_id ),
			'A product already on sale is not discounted again by default.'
		);

		Subscriber_Discounts::save_settings( [ 'apply_on_sale' => true ] );
		$this->flush_caches();

		$this->assertSame(
			72.0,
			Subscriber_Discounts_Pricing::get_subscriber_price( 80.0, $discounted_book, $this->subscriber_id ),
			'With the setting on, the subscriber discount applies on top of the sale price.'
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
