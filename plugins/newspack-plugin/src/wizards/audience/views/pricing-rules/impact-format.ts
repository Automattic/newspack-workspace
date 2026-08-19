/**
 * Shared price and count formatting for the impact previews (catalog-wide panel
 * and the per-rule editor preview). The contract's prices are plain numbers;
 * currency shaping is the client's job.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

export function formatPrice( amount: number, currency: PricingRulesCurrency ): string {
	return currency.symbol + amount.toFixed( currency.decimals );
}

/**
 * The cycle marker leads so prices land at the same offset on every row and can be
 * read down the column.
 */
export function formatSegment( seg: ImpactSegment, currency: PricingRulesCurrency ): string {
	return sprintf(
		/* translators: 1: a billing cycle number, 2: a formatted price. The "c" prefix is short for cycle. */
		__( 'c%1$d %2$s', 'newspack-plugin' ),
		seg.from_cycle,
		formatPrice( seg.amount, currency )
	);
}

/**
 * The resulting-price cell as a single string: flat rules show the adjusted price;
 * scheduled rules chain each cycle with ` → `.
 */
export function describeResulting( row: CatalogImpactRow, currency: PricingRulesCurrency ): string {
	if ( row.segments.length <= 1 ) {
		return formatPrice( row.adjusted, currency );
	}
	return row.segments.map( seg => formatSegment( seg, currency ) ).join( ' → ' );
}

/**
 * Group a count's digits. The externalized @wordpress/i18n has no numberFormat, and
 * WordPress ships locales Intl rejects (pt_PT_ao90), hence the fall back.
 */
export function formatCount( value: number ): string {
	const n = Number( value );
	try {
		return new Intl.NumberFormat( document.documentElement.lang || undefined ).format( n );
	} catch {
		return n.toLocaleString();
	}
}
