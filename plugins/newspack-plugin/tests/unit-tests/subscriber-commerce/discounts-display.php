<?php
/**
 * Tests how the subscriber discount badge survives a site's own sale-badge rules.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce;

use Newspack\Product_Targeting;
use Newspack\Subscriber_Discounts;
use Newspack\Subscriber_Discounts_Display;
use Newspack\Tests\Subscriber_Commerce\Traits\Trait_Subscriber_Discounts_Fixtures;

/**
 * A subscriber discount is not a sale, so the badge saying so must not depend on
 * where a site's own `woocommerce_sale_flash` callback sits, nor on whether the
 * shop is built from the classic templates or from blocks. These tests pin that
 * independence, and the filter that replaces WooCommerce's as the way to
 * suppress or reword the badge.
 *
 * The store and the subscription are the shared fixtures — see
 * {@see Trait_Subscriber_Discounts_Fixtures}.
 *
 * @group subscriber-commerce
 * @group Subscriber_Discounts
 */
class Test_Subscriber_Discounts_Display extends \WP_UnitTestCase {

	use Trait_Subscriber_Discounts_Fixtures;

	/**
	 * A discounted store product.
	 *
	 * @var \WC_Product
	 */
	private $book;

	/**
	 * Load the WooCommerce mocks.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Build a store with one discounted product, and sign in as the subscriber
	 * the discount is for.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}

		register_post_type( 'product', [ 'public' => true ] );

		delete_option( Subscriber_Discounts::OPTION_NAME );
		delete_option( Subscriber_Discounts::SETTINGS_OPTION_NAME );

		$this->subscriber_id = $this->factory->user->create();
		wp_set_current_user( $this->subscriber_id );

		$this->book = $this->create_product( 100.0 );
		add_filter( 'newspack_access_rules_has_active_subscription', [ $this, 'grant_subscription_to_subscriber' ], 10, 3 );
		$this->discount_the_book();
	}

	/**
	 * Drop the products the mocks keep in a global, which is the one piece of
	 * state `WP_UnitTestCase` does not roll back for us (it restores `$wp_filter`
	 * wholesale, so the hooks these tests register need no cleanup here).
	 */
	public function tear_down() {
		$this->flush_caches();
		$this->reset_products_database();
		parent::tear_down();
	}

	/**
	 * The headline case: a site that hides WooCommerce's "Sale!" badge keeps the
	 * subscriber badge, wherever its own callback sits.
	 *
	 * The site's callback runs at PHP_INT_MAX - 1, so the test fails for any
	 * finite priority rather than only for the priority 10 the bug was found at.
	 */
	public function test_badge_survives_a_site_hiding_sale_badges() {
		add_filter( 'woocommerce_sale_flash', '__return_false', PHP_INT_MAX - 1 );
		Subscriber_Discounts_Display::register_display_hooks();

		$this->assertStringContainsString(
			'Subscriber discount',
			(string) $this->apply_sale_flash( $this->book ),
			'A site callback hiding sale badges does not hide the subscriber badge.'
		);
	}

	/**
	 * The one case the ceiling does not cover, recorded so it stays a known limit
	 * rather than a surprise. WordPress runs equal priorities in registration
	 * order, so a site registering at PHP_INT_MAX after the plugin has — that is,
	 * after `wp_loaded` priority 15 — runs later and hides the badge again.
	 * Suppressing it deliberately goes through
	 * `newspack_subscriber_discounts_badge`, which no priority can outrun.
	 */
	public function test_a_site_registering_at_the_same_priority_later_still_wins() {
		Subscriber_Discounts_Display::register_display_hooks();
		add_filter( 'woocommerce_sale_flash', '__return_false', PHP_INT_MAX );

		$this->assertFalse(
			$this->apply_sale_flash( $this->book ),
			'A tie at PHP_INT_MAX is broken by registration order, so the later callback wins.'
		);
	}

	/**
	 * The badge does not turn a site's suppressed "Sale!" badge back on for
	 * products no subscriber discount touches. Running last makes this the case
	 * to protect: ours is now the callback that receives the site's `false` and
	 * has to hand it back.
	 */
	public function test_undiscounted_products_keep_the_sites_own_decision() {
		$undiscounted = $this->create_product( 50.0 );
		add_filter( 'woocommerce_sale_flash', '__return_false', PHP_INT_MAX - 1 );
		Subscriber_Discounts_Display::register_display_hooks();

		$this->assertFalse(
			$this->apply_sale_flash( $undiscounted ),
			'A product with no subscriber discount is left to the site.'
		);
		$this->assertStringContainsString(
			'Subscriber discount',
			(string) $this->apply_sale_flash( $this->book ),
			'…while the discounted product in the same request still gets the badge, so the assertion above is not passing on inert hooks.'
		);
	}

	/**
	 * A site suppresses the badge through a filter of ours rather than by
	 * reaching for WooCommerce's sale badge.
	 */
	public function test_the_newspack_filter_suppresses_the_badge() {
		Subscriber_Discounts_Display::register_display_hooks();
		add_filter( 'newspack_subscriber_discounts_badge', '__return_empty_string' );

		$this->assertSame( '', $this->apply_sale_flash( $this->book ), 'An empty string removes the badge.' );
	}

	/**
	 * The filter carries what a site needs to write its own badge: the label to
	 * reword, the product, and the markup the badge displaced.
	 */
	public function test_the_newspack_filter_can_reword_the_badge() {
		Subscriber_Discounts_Display::register_display_hooks();
		add_filter(
			'newspack_subscriber_discounts_badge',
			function ( $badge, $label, $product, $html ) {
				return sprintf( '<span data-product="%d" data-replaced="%s">%s</span>', $product->get_id(), esc_attr( $html ), strtoupper( $label ) );
			},
			10,
			4
		);

		$this->assertSame(
			sprintf( '<span data-product="%d" data-replaced="&lt;span class=&quot;onsale&quot;&gt;Sale!&lt;/span&gt;">SUBSCRIBER DISCOUNT</span>', $this->book->get_id() ),
			$this->apply_sale_flash( $this->book ),
			'The filter receives the label, the product and the markup it replaced.'
		);
	}

	/**
	 * A block-built shop calls the discount what it is. WooCommerce's Product
	 * Sale Badge block never applies `woocommerce_sale_flash`, so without its own
	 * hook the same reader would read "Sale" on a Product Collection shop and
	 * "Subscriber discount" on a classic one.
	 */
	public function test_the_block_badge_is_relabelled() {
		Subscriber_Discounts_Display::register_display_hooks();

		$this->assertSame(
			'Subscriber discount',
			apply_filters( 'woocommerce_sale_badge_text', 'Sale', $this->book ),
			'A discounted product relabels the block badge.'
		);
		$this->assertSame(
			'Sale',
			apply_filters( 'woocommerce_sale_badge_text', 'Sale', $this->create_product( 50.0 ) ),
			'A product no subscriber discount touches keeps WooCommerce’s wording.'
		);
	}

	/**
	 * The block badge answers to the same filter as the classic one, so a site
	 * cannot suppress the badge on one shop template and still ship it on the
	 * other. It also carries the feature's class, so one selector styles both.
	 */
	public function test_the_block_badge_is_filtered_like_the_classic_one() {
		Subscriber_Discounts_Display::register_display_hooks();

		$badge = $this->render_badge_block( $this->book );
		$this->assertStringContainsString(
			'class="wc-block-components-product-sale-badge newspack-subscriber-discount-badge"',
			$badge,
			'The feature class lands on the badge itself, not the block wrapper around it.'
		);
		$this->assertStringContainsString(
			'aria-hidden="true">Subscriber discount<',
			$badge,
			'The badge reads as a subscriber discount even where WooCommerce is too old to have relabelled it.'
		);
		$this->assertStringContainsString(
			'<span class="screen-reader-text">Subscriber discount</span>',
			$badge,
			'The announcement a screen reader hears says what the badge shows.'
		);

		add_filter( 'newspack_subscriber_discounts_badge', '__return_empty_string' );
		$this->assertSame(
			'',
			$this->render_badge_block( $this->book ),
			'Suppressing the badge suppresses it on a block shop too.'
		);
	}

	/**
	 * Run the filter the way WooCommerce's badge template does.
	 *
	 * @param \WC_Product $product Product being badged.
	 * @return string|false
	 */
	private function apply_sale_flash( $product ) {
		return apply_filters( 'woocommerce_sale_flash', '<span class="onsale">Sale!</span>', get_post( $product->get_id() ), $product );
	}

	/**
	 * Run the filter the way WordPress does after the Product Sale Badge block
	 * renders, with the `postId` context the block reads its product from.
	 *
	 * The markup mirrors `ProductSaleBadge::render()`, both spans included. It
	 * carries WooCommerce's own wording in each, which is what the block emits
	 * on a WooCommerce too old for `woocommerce_sale_badge_text`: relabelling
	 * has to hold on its own here, or the two audiences read different things.
	 *
	 * @param \WC_Product $product Product being badged.
	 * @return string
	 */
	private function render_badge_block( $product ) {
		$block          = new \stdClass();
		$block->context = [ 'postId' => $product->get_id() ];

		$rendered_block = '<div class="wp-block-woocommerce-product-sale-badge">'
			. '<div class="wc-block-components-product-sale-badge">'
			. '<span class="wc-block-components-product-sale-badge__text" aria-hidden="true">Sale</span>'
			. '<span class="screen-reader-text">Product on sale</span>'
			. '</div></div>';

		return apply_filters(
			'render_block_woocommerce/product-sale-badge', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- WordPress core's own dynamic hook name.
			$rendered_block,
			[],
			$block
		);
	}

	/**
	 * Discount the book by 10% for holders of the granting subscription.
	 */
	private function discount_the_book() {
		Subscriber_Discounts::save_rule(
			[
				'subscription_product_ids' => [ self::GRANTING_SUBSCRIPTION_ID ],
				'targeting'                => Product_Targeting::TARGETING_PRODUCTS,
				'product_ids'              => [ $this->book->get_id() ],
				'discount_type'            => 'percent',
				'amount'                   => 10,
			]
		);
		$this->flush_caches();
	}
}
