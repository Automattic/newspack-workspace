<?php
/**
 * Legacy subscription product types.
 *
 * @package Newspack
 */

namespace Newspack\Subscription_Products;

defined( 'ABSPATH' ) || exit;

/**
 * The `subscription` / `variable-subscription` product types. A product of these
 * types is a subscription by definition, so every answer here comes from its type
 * and its `_subscription_*` data.
 *
 * @internal Only Newspack\Subscription_Products calls this.
 */
final class Legacy_Model {
	const TYPES = [ 'subscription', 'variable-subscription' ];

	/**
	 * Whether this legacy model handles the product.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function applies_to( \WC_Product $product ): bool {
		return $product->is_type( self::TYPES ) || 'subscription_variation' === $product->get_type();
	}

	/**
	 * The purchase options for the product: one, or one per variation.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return Purchase_Option[]
	 */
	public static function get_options( \WC_Product $product ): array {
		if ( ! $product->is_type( 'variable-subscription' ) ) {
			return [ self::option_for( $product ) ];
		}
		$options = [];
		foreach ( $product->get_children() as $child_id ) {
			$child = \wc_get_product( $child_id );
			if ( $child instanceof \WC_Product ) {
				$options[] = self::option_for( $child );
			}
		}
		return $options;
	}

	/**
	 * Top-level legacy subscription products in the given statuses.
	 *
	 * @param string[] $statuses Post statuses.
	 * @return \WC_Product[]
	 */
	public static function find_products( array $statuses ): array {
		return \wc_get_products(
			[
				'type'   => self::TYPES,
				'status' => $statuses,
				'limit'  => -1,
			]
		);
	}

	/**
	 * Build the legacy purchase option for one purchasable product.
	 *
	 * @param \WC_Product $product Purchasable legacy product or variation.
	 */
	private static function option_for( \WC_Product $product ): Purchase_Option {
		return new Purchase_Option(
			[
				'key'          => 'legacy',
				'kind'         => Purchase_Option::KIND_LEGACY,
				'product_id'   => (int) $product->get_id(),
				'parent_id'    => (int) $product->get_parent_id(),
				'period'       => (string) \WC_Subscriptions_Product::get_period( $product ),
				'interval'     => max( 1, (int) \WC_Subscriptions_Product::get_interval( $product ) ),
				'length'       => (int) \WC_Subscriptions_Product::get_length( $product ),
				'trial_period' => (string) \WC_Subscriptions_Product::get_trial_period( $product ),
				'trial_length' => (int) \WC_Subscriptions_Product::get_trial_length( $product ),
				'sign_up_fee'  => (float) \WC_Subscriptions_Product::get_sign_up_fee( $product ),
				'price'        => (float) \WC_Subscriptions_Product::get_price( $product ),
			]
		);
	}
}
