<?php
/**
 * Server-side facts the promotional URL generator needs (NPPD-1707).
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Config for the promo link generator: which plan children a link may name, and
 * the frequency/amount options a donation link may use. Links work over any
 * page (newspack-blocks renders the block the trigger needs), so nothing here
 * inspects page content.
 */
final class Promo_Url_Config {

	/**
	 * Build the product "family" a plan row spans: the row product itself plus
	 * its variation children (variable) or bundled members (grouped).
	 *
	 * @param int $product_id Plan row product ID.
	 * @return array{parent:int,variations:int[],members:int[],picker_members:int[]}
	 */
	public static function get_product_family( $product_id ) {
		$family = [
			'parent'         => (int) $product_id,
			'variations'     => [],
			'members'        => [],
			'picker_members' => [],
		];
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $family;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $family;
		}
		$children = array_map( 'intval', $product->get_children() );
		if ( $product->is_type( 'grouped' ) ) {
			$family['members']        = $children;
			$family['picker_members'] = self::get_picker_members( $children );
		} else {
			$family['variations'] = $children;
		}
		return $family;
	}

	/**
	 * Reduce a grouped product's children to the ids its picker will render,
	 * mirroring Subscriptions_Tiers::get_tiers_by_frequency(). Offering anything
	 * else emits a URL whose radio never renders.
	 *
	 * @param int[] $child_ids Grouped product child IDs.
	 * @return int[] Ids the picker will render.
	 */
	public static function get_picker_members( $child_ids ) {
		$members = [];
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $members;
		}
		foreach ( $child_ids as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child || ! in_array( $child->get_type(), [ 'subscription', 'variable-subscription' ], true ) ) {
				continue;
			}
			if ( 'private' === $child->get_status() ) {
				continue;
			}
			if ( $child->is_type( 'variable-subscription' ) ) {
				foreach ( $child->get_available_variations() as $variation ) {
					if ( ! empty( $variation['variation_id'] ) ) {
						$members[] = (int) $variation['variation_id'];
					}
				}
				continue;
			}
			$members[] = (int) $child_id;
		}
		return array_values( array_unique( $members ) );
	}

	/**
	 * Child ids a promo URL may name for a plan: variations for a variable plan,
	 * picker-servable members for a grouped one.
	 *
	 * @param array $family See get_product_family().
	 * @return int[] Eligible child IDs.
	 */
	public static function get_eligible_children( $family ) {
		$variations = isset( $family['variations'] ) ? array_map( 'intval', $family['variations'] ) : [];
		$members    = isset( $family['picker_members'] ) ? array_map( 'intval', $family['picker_members'] ) : [];
		return array_values( array_unique( array_merge( $variations, $members ) ) );
	}

	/**
	 * Map a resolved Donate renderer configuration to the promo-URL contract,
	 * encoding the layout-param semantics of triggerDonationForm() in modal.js:
	 * `tiered` is the tiers block, `untiered` the Name Your Price input, anything
	 * else the frequency radios. Getting it wrong fails silently (NPPM-2815).
	 *
	 * @param array $configuration Output of Newspack_Blocks_Donate_Renderer_Base::get_configuration().
	 * @param bool  $can_use_nyp   Whether Name-Your-Price is available.
	 * @return array Promo donate config (layout_param, frequencies, default_frequency, minimum).
	 */
	public static function map_donate_configuration( $configuration, $can_use_nyp ) {
		$is_tiers_based = ! empty( $configuration['is_tier_based_layout'] );
		$tiered         = ! empty( $configuration['tiered'] );
		if ( $is_tiers_based ) {
			$layout_param = 'tiered';
		} elseif ( $tiered ) {
			$layout_param = 'frequency';
		} else {
			$layout_param = $can_use_nyp ? 'untiered' : 'frequency';
		}
		$frequencies = [];
		foreach ( [ 'once', 'month', 'year' ] as $slug ) {
			$amounts   = isset( $configuration['amounts'][ $slug ] ) ? array_map( 'floatval', (array) $configuration['amounts'][ $slug ] ) : [];
			$suggested = isset( $amounts[3] ) ? $amounts[3] : null;
			if ( 'untiered' === $layout_param ) {
				$preset_amounts  = [];
				$supports_custom = true;
			} elseif ( 'tiered' === $layout_param ) {
				$preset_amounts  = array_slice( $amounts, 0, 3 );
				$supports_custom = false;
			} elseif ( $tiered ) {
				$preset_amounts  = array_slice( $amounts, 0, 3 );
				$supports_custom = $can_use_nyp;
			} else {
				$preset_amounts  = null === $suggested ? [] : [ $suggested ];
				$supports_custom = false;
			}
			$frequencies[ $slug ] = [
				'enabled'         => isset( $configuration['frequencies'][ $slug ] ),
				'amounts'         => $preset_amounts,
				'supports_custom' => $supports_custom,
				'suggested'       => $suggested,
			];
		}
		return [
			'layout_param'      => $layout_param,
			'frequencies'       => $frequencies,
			'default_frequency' => isset( $configuration['defaultFrequency'] ) ? (string) $configuration['defaultFrequency'] : 'month',
			'minimum'           => isset( $configuration['minimumDonation'] ) ? (float) $configuration['minimumDonation'] : 5.0,
		];
	}

	/**
	 * Gate + map a Donate configuration. Split out to be testable without
	 * newspack-blocks loaded.
	 *
	 * @param array|\WP_Error $configuration Renderer configuration or error.
	 * @param bool            $can_use_nyp   Whether Name-Your-Price is available.
	 * @return array|null Promo donate config, or null when unusable.
	 */
	public static function evaluate_donate_configuration( $configuration, $can_use_nyp ) {
		if ( is_wp_error( $configuration ) || ! is_array( $configuration ) || 'wc' !== ( $configuration['platform'] ?? '' ) ) {
			return null;
		}
		return self::map_donate_configuration( $configuration, $can_use_nyp );
	}

	/**
	 * Promo config for a donation link. The block the link opens is rendered from
	 * schema defaults (Modal_Checkout::maybe_setup_url_triggered_checkout()), so
	 * resolving those same defaults describes what the reader's block accepts.
	 *
	 * @return array|null Promo donate config, or null when donations can't take one.
	 */
	public static function get_donate_config() {
		if ( ! class_exists( '\Newspack_Blocks_Donate_Renderer_Base' ) || ! class_exists( '\Newspack_Blocks' ) ) {
			return null;
		}
		$attrs      = [];
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'newspack-blocks/donate' );
		if ( $block_type ) {
			$attrs = $block_type->prepare_attributes_for_render( $attrs );
		}
		return self::evaluate_donate_configuration(
			\Newspack_Blocks_Donate_Renderer_Base::get_configuration( $attrs ),
			\Newspack_Blocks::can_use_name_your_price()
		);
	}

	/**
	 * Decide whether a coupon is usable for a promoted product, from plain
	 * extracted values. Pure so it is testable without WooCommerce.
	 *
	 * Mirrors the context-free subset of WC_Discounts::is_coupon_valid() plus
	 * manual product/amount checks: a bare WC_Discounts with no cart rejects
	 * product-restricted and minimum-spend coupons outright, and those are the
	 * primary promotional shapes.
	 *
	 * @param array $coupon_data     Extracted WC_Coupon state: expired,
	 *                                usage_exceeded, product_ids, excluded_ids,
	 *                                category_ids, excluded_category_ids,
	 *                                minimum_amount. Empty id lists mean no
	 *                                restriction.
	 * @param array $product_context Promoted-product context (family_ids,
	 *                               family_category_ids, reference_price); an
	 *                               empty array skips product-dependent checks.
	 * @return array { valid: bool, reason?: string }
	 */
	public static function evaluate_coupon( $coupon_data, $product_context = [] ) {
		if ( ! empty( $coupon_data['expired'] ) ) {
			return [
				'valid'  => false,
				'reason' => __( 'This coupon has expired.', 'newspack-plugin' ),
			];
		}
		if ( ! empty( $coupon_data['usage_exceeded'] ) ) {
			return [
				'valid'  => false,
				'reason' => __( 'This coupon has reached its usage limit.', 'newspack-plugin' ),
			];
		}
		$family_ids = isset( $product_context['family_ids'] ) ? array_map( 'intval', $product_context['family_ids'] ) : [];
		if ( empty( $family_ids ) ) {
			return [ 'valid' => true ];
		}
		$allowed = isset( $coupon_data['product_ids'] ) ? array_map( 'intval', $coupon_data['product_ids'] ) : [];
		if ( ! empty( $allowed ) && empty( array_intersect( $allowed, $family_ids ) ) ) {
			return [
				'valid'  => false,
				'reason' => __( 'This coupon does not apply to this plan.', 'newspack-plugin' ),
			];
		}
		$excluded = isset( $coupon_data['excluded_ids'] ) ? array_map( 'intval', $coupon_data['excluded_ids'] ) : [];
		if ( ! empty( $excluded ) && empty( array_diff( $family_ids, $excluded ) ) ) {
			return [
				'valid'  => false,
				'reason' => __( 'This coupon excludes this plan.', 'newspack-plugin' ),
			];
		}
		$family_cats   = isset( $product_context['family_category_ids'] ) ? array_map( 'intval', $product_context['family_category_ids'] ) : [];
		$allowed_cats  = isset( $coupon_data['category_ids'] ) ? array_map( 'intval', $coupon_data['category_ids'] ) : [];
		if ( ! empty( $allowed_cats ) && empty( array_intersect( $allowed_cats, $family_cats ) ) ) {
			return [
				'valid'  => false,
				'reason' => __( 'This coupon is limited to product categories this plan is not in.', 'newspack-plugin' ),
			];
		}
		$excluded_cats = isset( $coupon_data['excluded_category_ids'] ) ? array_map( 'intval', $coupon_data['excluded_category_ids'] ) : [];
		if ( ! empty( $excluded_cats ) && ! empty( array_intersect( $excluded_cats, $family_cats ) ) ) {
			return [
				'valid'  => false,
				'reason' => __( 'This coupon excludes a product category this plan is in.', 'newspack-plugin' ),
			];
		}
		$minimum = isset( $coupon_data['minimum_amount'] ) ? (float) $coupon_data['minimum_amount'] : 0.0;
		$price   = isset( $product_context['reference_price'] ) ? $product_context['reference_price'] : null;
		if ( $minimum > 0 && null !== $price && (float) $price < $minimum ) {
			return [
				'valid'  => false,
				'reason' => __( 'The plan price is below this coupon’s minimum spend.', 'newspack-plugin' ),
			];
		}
		return [ 'valid' => true ];
	}
}
