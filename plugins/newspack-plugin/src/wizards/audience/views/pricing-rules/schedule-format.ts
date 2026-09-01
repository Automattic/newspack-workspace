/**
 * Cell strings for the schedule table. The engine's calculation labels run to
 * "Percentage of regular price", which is why a cell shows the resulting value
 * instead of the calculation's name.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatPrice } from './impact-format';

/**
 * Cycle order is what makes "until the next one takes over" mean anything, so every
 * holder of the list orders it the same way.
 */
export function byCycle( a: SchedulePriceInput, b: SchedulePriceInput ): number {
	return Number( a.at ) - Number( b.at );
}

/**
 * The cycles a price covers, as a compact string to show and a spoken one to
 * announce. Only a genuine range differs: the arrow reads as a symbol, so the
 * accessible name spells it out.
 */
export function cycleRange( at: number, nextAt: number | null ): { display: string; label: string } {
	if ( null === nextAt ) {
		/* translators: %d: a billing cycle number. */
		const onward = sprintf( __( '%d onward', 'newspack-plugin' ), at );
		return { display: onward, label: onward };
	}
	if ( nextAt <= at + 1 ) {
		return { display: String( at ), label: String( at ) };
	}
	return {
		/* translators: 1: the first billing cycle of a range, 2: the last. → is an arrow. */
		display: sprintf( __( '%1$d → %2$d', 'newspack-plugin' ), at, nextAt - 1 ),
		/* translators: 1: the first billing cycle of a range, 2: the last. */
		label: sprintf( __( '%1$d to %2$d', 'newspack-plugin' ), at, nextAt - 1 ),
	};
}

/**
 * The engine's calculation vocabulary is server-supplied, so a type this cell has no
 * wording for falls back to naming it beside the raw value. Formatting an unknown
 * type as currency would read as a price the reader never pays.
 */
export function priceSummary( price: SchedulePriceInput, currency: PricingRulesCurrency, calcLabel = '' ): string {
	const amount = Number( price.value ) || 0;
	if ( 'percent_of_base' === price.calc_type ) {
		/* translators: %s: the percentage of the regular price a reader pays, for example 80. */
		return sprintf( __( 'Pay %s%%', 'newspack-plugin' ), String( amount ) );
	}
	if ( 'discount_fixed' === price.calc_type ) {
		/* translators: %s: a formatted price, for example $2.00. */
		return sprintf( __( '%s off', 'newspack-plugin' ), formatPrice( amount, currency ) );
	}
	if ( 'fixed_price' === price.calc_type ) {
		return formatPrice( amount, currency );
	}
	/* translators: 1: the name of a price calculation, 2: its raw value. */
	return sprintf( __( '%1$s: %2$s', 'newspack-plugin' ), calcLabel || price.calc_type, String( amount ) );
}
