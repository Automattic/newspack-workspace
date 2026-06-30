<?php
/**
 * Newspack-specific bridges into the Dynamic Pricing engine.
 *
 * The engine lives in the standalone woocommerce-dynamic-pricing plugin and
 * has no Newspack imports — these filter callbacks add Newspack-specific
 * exclusions on top of its WC/WCS-native checks. Inert when that plugin is
 * not active (nothing applies the filter). See the project docs (specs 09).
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack bridges for the Dynamic Pricing engine.
 *
 * Registers callbacks against the `woocommerce_dynamic_pricing_is_excluded`
 * filter to opt specific products / subscriptions out of dynamic pricing:
 *
 *  - Donation products (via Newspack\Donations::is_donation_product).
 *  - Group subscriptions (via Newspack\Group_Subscription::is_group_subscription).
 *
 * Also bridges the standalone plugin's reader-facing annotation onto the
 * Newspack Blocks Modal Checkout summary line — the modal's JS does
 * `textContent = price_summary`, so the plugin's HTML-filter annotations
 * never reach it; we hook the modal's own summary filter and emit plain text
 * built from the surface's public API.
 */
final class Dynamic_Pricing_Bridges {
	/**
	 * Register all bridge filter callbacks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_dynamic_pricing_is_excluded', [ __CLASS__, 'exclude_donations' ], 10, 3 );
		add_filter( 'woocommerce_dynamic_pricing_is_excluded', [ __CLASS__, 'exclude_group_subscriptions' ], 10, 3 );
		add_filter( 'newspack_modal_checkout_price_summary', [ __CLASS__, 'annotate_modal_checkout_summary' ], 20, 2 );
		add_action( 'woocommerce_dynamic_pricing_register', [ __CLASS__, 'register_conditions' ] );
		add_filter( 'woocommerce_dynamic_pricing_preview_segment_groups', [ __CLASS__, 'preview_segment_groups' ], 10, 2 );
	}

	/**
	 * Register Newspack condition matchers into the dynamic-pricing engine. Fires
	 * only when the engine is active (the action is the engine's own extension
	 * seam), so the matcher — which implements the engine interface — is
	 * referenced, and therefore autoloaded, only when it can load.
	 *
	 * @param \Automattic\WooCommerce\DynamicPricing\Pricing_Engine $engine Engine instance.
	 */
	public static function register_conditions( $engine ): void {
		if ( ! is_object( $engine ) || ! method_exists( $engine, 'register_condition' ) ) {
			return;
		}
		$engine->register_condition( new Reader_Segment_Condition_Matcher() );
	}

	/**
	 * Exclude donation products from dynamic pricing.
	 *
	 * @param bool        $excluded Whether the engine has already excluded this context.
	 * @param \WC_Product $product  Product being priced.
	 * @param mixed       $target   Optional target (e.g. a WC_Subscription).
	 */
	public static function exclude_donations( bool $excluded, \WC_Product $product, mixed $target ): bool {
		if ( $excluded ) {
			return true;
		}
		if ( class_exists( '\Newspack\Donations' ) && Donations::is_donation_product( $product->get_id() ) ) {
			return true;
		}
		return $excluded;
	}

	/**
	 * Exclude group subscriptions from dynamic pricing.
	 *
	 * @param bool        $excluded Whether the engine has already excluded this context.
	 * @param \WC_Product $product  Product being priced.
	 * @param mixed       $target   Optional target (e.g. a WC_Subscription).
	 */
	public static function exclude_group_subscriptions( bool $excluded, \WC_Product $product, mixed $target ): bool {
		if ( $excluded ) {
			return true;
		}
		if (
			$target instanceof \WC_Subscription
			&& class_exists( '\Newspack\Group_Subscription' )
			&& method_exists( '\Newspack\Group_Subscription', 'is_group_subscription' )
			&& Group_Subscription::is_group_subscription( $target )
		) {
			return true;
		}
		return $excluded;
	}

	/**
	 * Annotate the Newspack Blocks Modal Checkout price summary with the
	 * dynamic-pricing rule (regular-price comparison, rule label, first-cycle
	 * qualifier when the charged price doesn't recur). Output is plain text —
	 * the modal's JS assigns it via `textContent`, so HTML would be stripped.
	 *
	 * Inert when the standalone plugin isn't active (the surface class won't
	 * exist) and a no-op when no annotation applies to the displayed product.
	 *
	 * @param string $summary    Pre-formatted summary like "Sub: $5.00 / month".
	 * @param int    $product_id Product (or variation) id displayed in the modal.
	 */
	public static function annotate_modal_checkout_summary( $summary, $product_id ): string {
		$summary = (string) $summary;
		$surface = '\\Automattic\\WooCommerce\\DynamicPricing\\WooProduct_Surface';
		if ( ! class_exists( $surface ) ) {
			return $summary;
		}
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return $summary;
		}
		$pid = (int) $product_id;
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$item_pid = (int) ( ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : ( $cart_item['product_id'] ?? 0 ) );
			if ( $item_pid !== $pid ) {
				continue;
			}
			$annotation = $surface::get_annotation_for( (string) $cart_item_key );
			if ( ! $annotation || abs( $annotation['original'] - $annotation['amount'] ) < 0.01 ) {
				return $summary;
			}
			// Match the cart/product surfaces: keep the WCS period suffix on the
			// summary and append a "(Label — regularly $X)" annotation. The
			// schedule disclosure owns the first-cycle-vs-renewals story; the
			// summary line stays focused on what's charged with its native suffix.
			$original = wp_strip_all_tags( html_entity_decode( wc_price( (float) $annotation['original'] ), ENT_QUOTES ) );
			$parts    = [];
			if ( '' !== (string) $annotation['label'] ) {
				$parts[] = $annotation['label'];
			}
			/* translators: %s: regular price */
			$parts[] = sprintf( __( 'regularly %s', 'newspack-plugin' ), $original );
			return sprintf( '%1$s (%2$s)', $summary, implode( ' — ', $parts ) );
		}
		return $summary;
	}

	/**
	 * Supply the reader-segment groups for the impact preview: the union of
	 * reader_segment ids across the previewed rule (when given) and all active
	 * rules, each as a group the engine will price as-if a reader in it.
	 *
	 * @param array $groups Groups so far (engine default []).
	 * @param mixed $rule   The previewed rule (engine Pricing_Rule) or null for the catalog.
	 * @return array<int, array{id:int,label:string,assume_segments:int[]}>
	 */
	public static function preview_segment_groups( $groups, $rule = null ): array {
		if ( ! is_array( $groups ) ) {
			$groups = [];
		}
		if ( ! class_exists( '\Newspack_Segments_Model' ) ) {
			return $groups;
		}
		$engine_class = '\Automattic\WooCommerce\DynamicPricing\Pricing_Engine';
		if ( ! class_exists( $engine_class ) ) {
			return $groups;
		}

		$rules = [];
		if ( is_object( $rule ) && isset( $rule->conditions ) ) {
			$rules[] = $rule;
		}
		$repo = call_user_func( [ $engine_class, 'instance' ] )->repository();
		if ( $repo ) {
			$rules = array_merge( $rules, $repo->active() );
		}

		$ids = [];
		foreach ( $rules as $r ) {
			foreach ( (array) ( $r->conditions ?? [] ) as $cond ) {
				if ( is_array( $cond ) && ( $cond['type'] ?? '' ) === 'reader_segment' ) {
					foreach ( (array) ( $cond['value'] ?? [] ) as $sid ) {
						$ids[ (int) $sid ] = true;
					}
				}
			}
		}
		if ( empty( $ids ) ) {
			return $groups;
		}

		$labels = [];
		foreach ( \Newspack_Segments_Model::get_segments() as $segment ) {
			if ( isset( $segment['id'], $segment['name'] ) ) {
				$labels[ (int) $segment['id'] ] = (string) $segment['name'];
			}
		}

		foreach ( array_keys( $ids ) as $sid ) {
			$groups[] = [
				'id'              => $sid,
				/* translators: %d: segment id, when the segment has no name. */
				'label'           => $labels[ $sid ] ?? sprintf( __( 'Segment %d', 'newspack-plugin' ), $sid ),
				'assume_segments' => [ $sid ],
			];
		}
		return $groups;
	}
}

Dynamic_Pricing_Bridges::init();
