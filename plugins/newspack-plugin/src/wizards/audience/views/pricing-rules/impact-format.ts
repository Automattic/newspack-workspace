/**
 * Shared price formatting for the impact previews (catalog-wide panel and the
 * per-rule editor preview). The contract's prices are plain numbers; currency
 * shaping is the client's job.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

export function formatPrice( amount: number, currency: PricingRulesCurrency ): string {
	return currency.symbol + amount.toFixed( currency.decimals );
}

/**
 * One cycle's contribution to the resulting-price phrasing: cycle 1 is the bare
 * price; later cycles read "then $X from cycle N".
 */
export function formatSegment( seg: ImpactSegment, currency: PricingRulesCurrency ): string {
	return seg.from_cycle === 1
		? formatPrice( seg.amount, currency )
		: sprintf(
				/* translators: 1: a formatted price, 2: a billing cycle number. */
				__( 'then %1$s from cycle %2$d', 'newspack-plugin' ),
				formatPrice( seg.amount, currency ),
				seg.from_cycle
		  );
}

/**
 * The resulting-price cell as a single string: flat rules show the adjusted price;
 * scheduled rules join each cycle with ` · `.
 */
export function describeResulting( row: CatalogImpactRow, currency: PricingRulesCurrency ): string {
	if ( row.segments.length <= 1 ) {
		return formatPrice( row.adjusted, currency );
	}
	return row.segments.map( seg => formatSegment( seg, currency ) ).join( ' · ' );
}
