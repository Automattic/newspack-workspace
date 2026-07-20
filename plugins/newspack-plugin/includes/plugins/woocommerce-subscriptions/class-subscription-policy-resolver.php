<?php
/**
 * Subscription Policy Resolver — the pricing-rule integration seam.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the pricing-rule stack and effective price for a subscription product.
 *
 * ============================================================================
 * INTEGRATION SEAM
 * ============================================================================
 * This class is the SINGLE boundary between the Subscription Products UI and the
 * standalone pricing-rule engine (woocommerce-dynamic-pricing). get_resolution()
 * reads the live engine: it composes all active rules over the product's purchase
 * cycle for the effective price, and lists the rules that apply. When the engine
 * plugin is inactive it reports the base price with no rules.
 *
 * The returned array shape is the contract the DataViews UI consumes; keep it
 * stable. Nothing else should call the engine for this read directly — route it
 * through resolve() (and the `newspack_subscription_policy_resolution` filter).
 * ============================================================================
 */
class Subscription_Policy_Resolver {

	/**
	 * Whether the resolver returns mock data. Now that get_resolution() reads the
	 * live engine, this is always false; the UI's mock-data notice never shows.
	 *
	 * @var bool
	 */
	const IS_MOCK = false;

	/**
	 * Resolve the policy stack and effective price for a product (and optional cycle context).
	 *
	 * @param int   $product_id The subscription product (or variation) ID.
	 * @param array $context    Optional resolution context. Recognised keys:
	 *                          - base_price (float)  Base recurring price for the cycle.
	 *                          - cycle      (string) Billing period slug, e.g. 'month'.
	 *                          - currency   (string) ISO currency code.
	 *
	 * @return array {
	 *     Resolved pricing for the product.
	 *
	 *     @type bool   $is_mock         Whether this is mock data.
	 *     @type float  $base_price      The unmodified base price.
	 *     @type float  $effective_price The price after the winning policy is applied.
	 *     @type string $currency        ISO currency code.
	 *     @type string $cycle           Billing period slug.
	 *     @type array  $policies        List of applied policies, each: {
	 *         @type string $id               Stable policy id.
	 *         @type string $slug             Machine slug.
	 *         @type string $label            Human label.
	 *         @type string $type             Policy type: promo|season|winback|loyalty.
	 *         @type string $adjustment_label Short description of the adjustment.
	 *     }
	 * }
	 */
	public static function resolve( $product_id, $context = [] ) {
		$resolution = self::get_resolution( (int) $product_id, $context );

		/**
		 * Filters the resolved subscription pricing-policy stack for a product.
		 *
		 * The policy engine may hook here instead of replacing get_resolution(). The
		 * returned array must match the shape documented on
		 * Subscription_Policy_Resolver::resolve().
		 *
		 * @param array $resolution The resolved pricing array.
		 * @param int   $product_id The product/variation ID.
		 * @param array $context    The resolution context.
		 */
		return apply_filters( 'newspack_subscription_policy_resolution', $resolution, (int) $product_id, $context );
	}

	/**
	 * Produce the resolution payload by reading the live pricing-rule engine.
	 *
	 * Composes all active rules over the product's purchase (acquisition) cycle for
	 * the effective price, and lists the rules that apply — the same engine the
	 * storefront uses, so the table matches what buyers see.
	 * Without the engine (plugin inactive), an invalid product, or an
	 * engine-excluded product (e.g. donations), it reports the base price and no
	 * rules.
	 *
	 * @param int   $product_id The product/variation ID.
	 * @param array $context    The resolution context.
	 *
	 * @return array Resolution payload (see resolve()).
	 */
	private static function get_resolution( $product_id, $context ) {
		$currency = isset( $context['currency'] ) ? $context['currency'] : get_woocommerce_currency();
		$cycle    = isset( $context['cycle'] ) ? $context['cycle'] : 'month';
		$base     = isset( $context['base_price'] ) ? (float) $context['base_price'] : 0.0;

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
		$engine  = class_exists( '\Automattic\WooCommerce\DynamicPricing\Pricing_Engine' )
			? \Automattic\WooCommerce\DynamicPricing\Pricing_Engine::instance()
			: null;

		// No engine / invalid product / engine-excluded product (e.g. donations the
		// engine never prices) → base price, no rules, rather than a fabricated one.
		if ( ! $engine || ! $product instanceof \WC_Product || $engine->is_excluded( $product ) ) {
			return self::build( $base, $base, $currency, $cycle, [], [] );
		}

		// Derive the base the way every other engine surface does, instead of trusting
		// the wizard's injected `_subscription_price` — which diverges from the catalog
		// recurring price for some products (e.g. APFS), so a percent rule would show a
		// Plans price that disagrees with the storefront/My-Account schedule.
		$base = \Automattic\WooCommerce\DynamicPricing\Amount_Calculator::base_price_for( $product );

		// Project the composed price across the subscription's cycles with the engine's
		// public projector — the same walk the admin inspector uses, sharing its
		// per-request memo. Segment 0 (the purchase cycle) is the headline effective price.
		$schedule  = \Automattic\WooCommerce\DynamicPricing\Schedule_Projector::project_for_product( $product );
		$effective = ! empty( $schedule ) ? (float) $schedule[0]['amount'] : $base;

		// Chips list the rules that actually apply: matching_rules() runs the condition
		// gate (unlike a raw scope+window lookup), so segment/reader-gated rules that
		// don't apply to this product no longer over-list as chips.
		$rules = [];
		foreach ( $engine->matching_rules( self::context_for( $product, $base, 1 ) ) as $rule ) {
			$rule_id = (string) $rule->id;
			$rules[] = self::policy(
				$rule_id,
				(string) $rule->strategy_id,
				// get_the_title() runs wptexturize, so decode entities (e.g. `A & B` →
				// `A &#038; B`) before the chip renders the label as a plain text node.
				html_entity_decode( get_the_title( (int) $rule_id ), ENT_QUOTES ),
				self::strategy_label( (string) $rule->strategy_id )
			);
		}

		return self::build( $base, $effective, $currency, $cycle, $rules, $schedule );
	}

	/**
	 * Build the acquisition pricing context for a product at a given cycle.
	 *
	 * @param \WC_Product $product The product.
	 * @param float       $base    The base recurring price.
	 * @param int         $cycle   The cycle number (1 = purchase).
	 *
	 * @return \Automattic\WooCommerce\DynamicPricing\Pricing_Context
	 */
	private static function context_for( $product, $base, $cycle ) {
		return new \Automattic\WooCommerce\DynamicPricing\Pricing_Context(
			'subscription_products',
			$product,
			null,
			(float) $base,
			[ 'completed_cycles' => (int) $cycle ],
			null,
			\Automattic\WooCommerce\DynamicPricing\Pricing_Context::INTENT_ACQUISITION,
			false
		);
	}

	/**
	 * Assemble a resolution payload in the shape resolve() documents.
	 *
	 * @param float  $base_price      The unmodified base price.
	 * @param float  $effective_price The composed price after rules.
	 * @param string $currency        ISO currency code.
	 * @param string $cycle           Billing period slug.
	 * @param array  $rules           Applied-rule entries (see policy()).
	 * @param array  $schedule        Per-cycle price trajectory (from Schedule_Projector).
	 *
	 * @return array The resolution payload.
	 */
	private static function build( $base_price, $effective_price, $currency, $cycle, $rules, $schedule = [] ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return [
			'is_mock'         => self::IS_MOCK,
			'base_price'      => $base_price,
			'effective_price' => round( (float) $effective_price, $decimals ),
			'currency'        => $currency,
			'cycle'           => $cycle,
			'policies'        => $rules,
			'schedule'        => $schedule,
		];
	}

	/**
	 * Short, human pricing-model label for a rule's strategy (shown in the chip tooltip).
	 *
	 * @param string $strategy_id The rule's strategy id.
	 *
	 * @return string Human label.
	 */
	private static function strategy_label( $strategy_id ) {
		switch ( $strategy_id ) {
			case 'simple_price':
				return __( 'Flat adjustment', 'newspack-plugin' );
			case 'stepped_by_cycle':
				return __( 'Price schedule', 'newspack-plugin' );
			default:
				return (string) $strategy_id;
		}
	}

	/**
	 * Build a single policy entry.
	 *
	 * @param string $id               Stable policy id.
	 * @param string $type             Policy type.
	 * @param string $label            Human label.
	 * @param string $adjustment_label Short description of the adjustment.
	 *
	 * @return array The policy entry.
	 */
	private static function policy( $id, $type, $label, $adjustment_label ) {
		return [
			'id'               => $id,
			'slug'             => $id,
			'label'            => $label,
			'type'             => $type,
			'adjustment_label' => $adjustment_label,
		];
	}
}
