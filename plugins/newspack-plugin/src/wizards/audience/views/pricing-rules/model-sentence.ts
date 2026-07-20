/**
 * A plain-language sentence describing a rule's pricing model, for the Pricing
 * Rules list's "Pricing model" column. A flat rule reads as a single adjustment
 * (e.g. "50% of regular price", "$5.00 off regular price"); a stepped rule reads
 * as a per-cycle progression naming each step's starting cycle, so cycle skips
 * show (e.g. "$80.00 → $90.00 from cycle 3"). Falls back to the bare strategy
 * label when the params are missing or use an unknown
 * calc type.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatPrice } from './impact-format';

/** A flat adjustment as a standalone phrase. Null for an unknown calc type. */
function flatPhrase( calcType: string, value: number, currency: PricingRulesCurrency ): string | null {
	switch ( calcType ) {
		case 'fixed_price':
			/* translators: %s: a formatted price. */
			return sprintf( __( 'Set price to %s', 'newspack-plugin' ), formatPrice( value, currency ) );
		case 'percent_of_base':
			/* translators: %s: a percentage number, e.g. "80". */
			return sprintf( __( '%s%% of regular price', 'newspack-plugin' ), String( value ) );
		case 'discount_fixed':
			/* translators: %s: a formatted price. */
			return sprintf( __( '%s off regular price', 'newspack-plugin' ), formatPrice( value, currency ) );
		default:
			return null;
	}
}

/** One step's compact value expression, for the stepped progression. Null for an unknown calc type. */
function stepExpr( calcType: string, value: number, currency: PricingRulesCurrency ): string | null {
	switch ( calcType ) {
		case 'fixed_price':
			return formatPrice( value, currency );
		case 'percent_of_base':
			/* translators: %s: a percentage number, e.g. "80". */
			return sprintf( __( '%s%%', 'newspack-plugin' ), String( value ) );
		case 'discount_fixed':
			/* translators: %s: a formatted price. */
			return sprintf( __( '%s off', 'newspack-plugin' ), formatPrice( value, currency ) );
		default:
			return null;
	}
}

export function pricingModelSentence( item: PricingRuleRow, currency: PricingRulesCurrency ): string {
	const { steps, simple } = item;

	// Stepped: a per-cycle progression. Every step past the first names its starting
	// cycle (as does a first step that starts after cycle 1), so cycle skips — e.g.
	// steps at cycles 1, 3, 6 — read truthfully instead of as 1, 2, 3. Sorted by
	// starting cycle so a rule's storage order can't mislead.
	if ( item.is_stepped && steps && steps.length ) {
		const ordered = [ ...steps ].sort( ( a, b ) => a.at - b.at );
		const parts = ordered.map( ( step, index ) => {
			const expr = stepExpr( step.calc_type, step.value, currency );
			if ( expr === null ) {
				return null;
			}
			if ( index === 0 && step.at <= 1 ) {
				return expr;
			}
			return sprintf(
				/* translators: 1: a price expression like "$90.00", 2: a billing cycle number. */
				__( '%1$s from cycle %2$d', 'newspack-plugin' ),
				expr,
				step.at
			);
		} );
		if ( parts.some( part => part === null ) ) {
			return item.strategy_label;
		}
		return parts.join( ' → ' );
	}

	// Flat: a single adjustment, optionally limited to the first N cycles.
	if ( simple ) {
		const phrase = flatPhrase( simple.calc_type, simple.value, currency );
		if ( ! phrase ) {
			return item.strategy_label;
		}
		if ( simple.cycles_limit > 0 ) {
			return sprintf(
				/* translators: 1: a pricing phrase, 2: a number of billing cycles. */
				_n( '%1$s · first %2$d cycle', '%1$s · first %2$d cycles', simple.cycles_limit, 'newspack-plugin' ),
				phrase,
				simple.cycles_limit
			);
		}
		return phrase;
	}

	return item.strategy_label;
}
