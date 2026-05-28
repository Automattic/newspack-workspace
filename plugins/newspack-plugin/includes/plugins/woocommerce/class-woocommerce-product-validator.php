<?php
/**
 * WooCommerce Product Validator.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Validates WooCommerce products for purchasability and restrictions.
 */
class WooCommerce_Product_Validator {
	/**
	 * Validate if a product can be purchased by checking various conditions.
	 * This checks standard WooCommerce conditions and also WooCommerce Memberships restrictions.
	 *
	 * @param int|\WC_Product $product Product ID or product object.
	 *
	 * @return array|\WP_Error Array with validation results or WP_Error on failure.
	 */
	public static function validate_product_purchasability( $product ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error( 'woocommerce_inactive', __( 'WooCommerce inactive.', 'newspack-plugin' ) );
		}
		// Get the product object.
		if ( ! is_a( $product, '\WC_Product' ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! $product ) {
			return new \WP_Error( 'invalid_product', __( 'Invalid product.', 'newspack-plugin' ) );
		}

		// Basic WooCommerce checks.
		$issues = self::validate_woocommerce_conditions( $product );

		// WooCommerce Memberships checks.
		if ( function_exists( 'wc_memberships' ) ) {
			$issues = array_merge( $issues, self::validate_membership_restrictions( $product ) );
		}

		return [
			'product_id'   => $product->get_id(),
			'product_name' => $product->get_name(),
			'issues'       => $issues,
		];
	}

	/**
	 * Validate basic WooCommerce conditions.
	 *
	 * @param \WC_Product $product Product object.
	 *
	 * @return array Issues and warnings.
	 */
	private static function validate_woocommerce_conditions( $product ) {
		$issues = [];

		// Check if product is published.
		if ( 'publish' !== $product->get_status() ) {
			$issues[] = sprintf(
				/* translators: %s: product status */
				__( 'Product is not published (status: %s).', 'newspack-plugin' ),
				$product->get_status()
			);
		}

		// Check if product has a price.
		$price = $product->get_price();
		if ( '' === $price || null === $price ) {
			$issues[] = __( 'Product has no price set.', 'newspack-plugin' );
		}

		// Check stock status.
		if ( ! $product->is_in_stock() ) {
			$issues[] = __( 'Product is out of stock.', 'newspack-plugin' );
		}

		// Check if product is purchasable.
		if ( ! $product->is_purchasable() ) {
			$issues[] = __( 'Product is not purchasable (WooCommerce setting).', 'newspack-plugin' );
		}

		return $issues;
	}

	/**
	 * Validate WooCommerce Memberships restrictions.
	 *
	 * @param \WC_Product $product Product object.
	 *
	 * @return array Issues.
	 */
	private static function validate_membership_restrictions( $product ) {
		$issues      = [];
		$product_id  = $product->get_id();
		$parent_id   = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product_id;

		// Check viewing restrictions.
		if ( function_exists( 'wc_memberships_is_product_viewing_restricted' ) ) {
			$is_viewing_restricted = wc_memberships_is_product_viewing_restricted( $product_id );
			if ( ! $is_viewing_restricted && $parent_id !== $product_id ) {
				$is_viewing_restricted = wc_memberships_is_product_viewing_restricted( $parent_id );
			}

			if ( $is_viewing_restricted ) {
				$issues[] = __( 'Product is restricted by membership rules.', 'newspack-plugin' );
			}
		}

		// Check purchasing restrictions.
		if ( function_exists( 'wc_memberships_is_product_purchasing_restricted' ) ) {
			$is_purchasing_restricted = wc_memberships_is_product_purchasing_restricted( $product_id );
			if ( ! $is_purchasing_restricted && $parent_id !== $product_id ) {
				$is_purchasing_restricted = wc_memberships_is_product_purchasing_restricted( $parent_id );
			}

			if ( $is_purchasing_restricted ) {
				$issues[] = __( 'Product purchasing is restricted by membership rules.', 'newspack-plugin' );

				// Get the membership plans that restrict this product.
				if ( function_exists( 'wc_memberships' ) && method_exists( wc_memberships(), 'get_rules_instance' ) ) {
					$rules      = wc_memberships()->get_rules_instance()->get_product_restriction_rules( $parent_id );
					$plan_names = [];

					foreach ( $rules as $rule ) {
						if ( 'purchase' === $rule->get_access_type() ) {
							$plan = wc_memberships_get_membership_plan( $rule->get_membership_plan_id() );
							if ( $plan ) {
								$plan_names[] = $plan->get_name();
							}
						}
					}

					if ( ! empty( $plan_names ) ) {
						$issues[] = sprintf(
							/* translators: %s: list of membership plan names */
							__( 'Restricted by membership plans: %s', 'newspack-plugin' ),
							implode( ', ', array_unique( $plan_names ) )
						);
					}
				}
			}
		}

		return $issues;
	}
}
