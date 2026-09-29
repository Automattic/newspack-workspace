<?php
/**
 * One way a reader can buy a product.
 *
 * @package Newspack
 */

namespace Newspack\Subscription_Products;

defined( 'ABSPATH' ) || exit;

/**
 * A purchase option: buy once, subscribe on a plan, or subscribe to a legacy
 * subscription product. Unique per ( product_id, key ): a variable product's
 * variations each carry the same plan keys.
 *
 * Newspack stores and matches products, never options. Options exist so that
 * anything a reader chooses from can list every choice and post the right one.
 * An option carries no price: prices are read from the product instance
 * get_option_product() returns, where WooCommerce applies the plan and any price
 * filters for the reader looking at it.
 */
final class Purchase_Option {
	const KIND_ONE_TIME = 'one_time';
	const KIND_PLAN     = 'plan';
	const KIND_LEGACY   = 'legacy';

	/**
	 * Stable within the product.
	 *
	 * @var string `one_time`, `legacy`, or `plan:<plan key>`.
	 */
	public string $key = '';

	/**
	 * One of the KIND_* constants.
	 *
	 * @var string
	 */
	public string $kind = self::KIND_ONE_TIME;

	/**
	 * Product or variation that goes in the cart.
	 *
	 * @var int
	 */
	public int $product_id = 0;

	/**
	 * Parent product ID for a variation, else 0.
	 *
	 * @var int
	 */
	public int $parent_id = 0;

	/**
	 * WooCommerce Subscriptions plan key.
	 *
	 * @var string|null
	 */
	public ?string $plan_key = null;

	/**
	 * Billing period, null when one-time.
	 *
	 * @var string|null
	 */
	public ?string $period = null;

	/**
	 * Billing interval.
	 *
	 * @var int
	 */
	public int $interval = 1;

	/**
	 * Number of periods, 0 for never-ending.
	 *
	 * @var int
	 */
	public int $length = 0;

	/**
	 * Trial period.
	 *
	 * @var string
	 */
	public string $trial_period = '';

	/**
	 * Trial length.
	 *
	 * @var int
	 */
	public int $trial_length = 0;

	/**
	 * Sign-up fee.
	 *
	 * @var float
	 */
	public float $sign_up_fee = 0.0;

	/**
	 * Constructor.
	 *
	 * @param array $props Any of the public properties.
	 */
	public function __construct( array $props ) {
		foreach ( $props as $name => $value ) {
			if ( property_exists( $this, $name ) ) {
				$this->$name = $value;
			}
		}
	}

	/**
	 * Whether buying this option starts a subscription.
	 */
	public function is_recurring(): bool {
		return self::KIND_ONE_TIME !== $this->kind;
	}

	/**
	 * The `<period>_<interval>` key subscription tiers bucket by; `once_1` for one-time,
	 * matching the value tiers already used for products with no period.
	 */
	public function get_frequency(): string {
		return ( $this->period ? $this->period : 'once' ) . '_' . max( 1, (int) $this->interval );
	}

	/**
	 * The request fields that select this option at add-to-cart.
	 *
	 * Plans are selected by `convert_to_sub_<parent or product ID>`. Posting nothing
	 * lets WooCommerce fall back to a default, which for a product sold both ways is a
	 * one-time charge, so every form that sells a plan must post this.
	 *
	 * @return array<string, string>
	 */
	public function get_plan_request_args(): array {
		if ( self::KIND_LEGACY === $this->kind ) {
			return [];
		}
		$field = 'convert_to_sub_' . ( $this->parent_id ? $this->parent_id : $this->product_id );
		return [ $field => self::KIND_PLAN === $this->kind ? (string) $this->plan_key : '0' ];
	}
}
