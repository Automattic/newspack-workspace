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
	 * @param int  $product_id            Plan row product ID.
	 * @param bool $expand_picker_members Whether to resolve `picker_members`. The
	 *                                    expansion calls get_available_variations()
	 *                                    per variable child — the heaviest read
	 *                                    here — so callers that only need ids
	 *                                    (the coupon pre-check) pass false.
	 * @return array{parent:int,variations:int[],members:int[],picker_members:int[]}
	 */
	public static function get_product_family( $product_id, $expand_picker_members = true ) {
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
			$family['members'] = $children;
			if ( $expand_picker_members ) {
				$family['picker_members'] = self::get_picker_members( $children );
			}
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
	 * Whether the checkout-button renderer resolves a price for a product,
	 * mirroring view.php: the product's own price, or — under Name Your Price —
	 * the suggested price falling back to the minimum. A product with none
	 * renders no form, so a link naming it would do nothing.
	 *
	 * @param \WC_Product|false|null $product The product.
	 * @return bool Whether a price resolves.
	 */
	private static function has_resolvable_price( $product ) {
		if ( ! $product ) {
			return false;
		}
		$price = $product->get_price();
		if ( class_exists( '\WC_Name_Your_Price_Helpers' ) && \WC_Name_Your_Price_Helpers::is_nyp( $product->get_id() ) ) {
			$price = \WC_Name_Your_Price_Helpers::get_suggested_price( $product->get_id() );
			if ( ! $price ) {
				$price = \WC_Name_Your_Price_Helpers::get_minimum_price( $product->get_id() );
			}
		}
		return (bool) $price;
	}

	/**
	 * Whether a direct link naming this product yields a working checkout
	 * button, mirroring the render gate in the block's view.php: a variable-type
	 * parent always renders (its button opens the picker), anything else needs a
	 * resolvable price.
	 *
	 * @param \WC_Product $product The product.
	 * @return bool Whether a direct link can serve the product.
	 */
	public static function is_direct_button_servable( $product ) {
		if ( in_array( $product->get_type(), [ 'variable', 'variable-subscription' ], true ) ) {
			return true;
		}
		return self::has_resolvable_price( $product );
	}

	/**
	 * Child ids the generator may offer as a DIRECT plan option — as opposed to
	 * get_eligible_children(), which describes what the reader-chooses picker
	 * serves. A direct link names the child itself, so it must survive the
	 * URL-trigger's own checks (publish status) and the renderer's price gate,
	 * and a grouped member must be subscription-shaped: the Plans row promotes a
	 * plan, and a bundled non-subscription extra has no standing as one.
	 *
	 * @param array $family See get_product_family().
	 * @return int[] Ids offerable as direct choices.
	 */
	public static function get_offerable_children( $family ) {
		$offerable = [];
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $offerable;
		}
		$variations = isset( $family['variations'] ) ? array_map( 'intval', $family['variations'] ) : [];
		foreach ( $variations as $variation_id ) {
			// A locked button collapses onto the variation, and view.php's price
			// gate reads the variation's own resolvable price (NYP fallback
			// included) — the parent's synced price only proves a priced sibling
			// exists somewhere, not that this child renders a form.
			$variation = wc_get_product( $variation_id );
			if ( $variation && 'publish' === $variation->get_status() && self::has_resolvable_price( $variation ) ) {
				$offerable[] = $variation_id;
			}
		}
		$members = isset( $family['members'] ) ? array_map( 'intval', $family['members'] ) : [];
		foreach ( $members as $member_id ) {
			$member = wc_get_product( $member_id );
			if ( ! $member || 'publish' !== $member->get_status() ) {
				continue;
			}
			if ( ! in_array( $member->get_type(), [ 'subscription', 'variable-subscription' ], true ) ) {
				continue;
			}
			if ( ! self::is_direct_button_servable( $member ) ) {
				continue;
			}
			$offerable[] = $member_id;
		}
		return array_values( array_unique( $offerable ) );
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
	 * Disable config frequencies whose donation child product is missing. The
	 * renderer's frequency list reflects settings only, while the checkout bails
	 * without a cart when the frequency's product ID is absent (see the `$is_wc`
	 * branch of Donations::process_donation_request()) — so a link must not
	 * offer such a frequency. Pure so the intersection is testable.
	 *
	 * @param array|null $config      Promo donate config (see map_donate_configuration()), or null.
	 * @param array      $product_ids Frequency-slug-to-product-id map; an empty
	 *                                value means the frequency has no product.
	 * @return array|null Config with productless frequencies disabled, or null
	 *                    when none remain enabled.
	 */
	public static function filter_frequencies_without_products( $config, $product_ids ) {
		if ( null === $config ) {
			return null;
		}
		$any_enabled = false;
		foreach ( $config['frequencies'] as $slug => $frequency ) {
			if ( ! empty( $frequency['enabled'] ) && empty( $product_ids[ $slug ] ) ) {
				$config['frequencies'][ $slug ]['enabled'] = false;
			}
			$any_enabled = $any_enabled || ! empty( $config['frequencies'][ $slug ]['enabled'] );
		}
		return $any_enabled ? $config : null;
	}

	/**
	 * Promo config for a donation link. The block the link opens is rendered from
	 * schema defaults (Modal_Checkout::maybe_setup_url_triggered_checkout()), so
	 * resolving those same defaults describes what the reader's block accepts.
	 * Frequencies are then intersected with the donation products that actually
	 * exist, mirroring the checkout's own bail.
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
		return self::filter_frequencies_without_products(
			self::evaluate_donate_configuration(
				\Newspack_Blocks_Donate_Renderer_Base::get_configuration( $attrs ),
				\Newspack_Blocks::can_use_name_your_price()
			),
			Donations::get_donation_product_child_products_ids()
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
