/**
 * Shared price and count formatting for the impact previews (catalog-wide panel
 * and the per-rule editor preview). The contract's prices are plain numbers;
 * currency shaping is the client's job.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatCount } from '../../../../../packages/components/src/breadcrumbs/format-count';

// Re-exported rather than reimplemented: the header count beside these figures
// uses the same helper, and only that one normalises the locales WordPress ships
// that Intl rejects.
export { formatCount };

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
 * The legend for the `c1`/`c2` markers a stepped rule puts on its prices. Shared
 * so the editor's section header and the catalog panel cannot drift apart.
 */
export function cycleMarkerNote(): string {
	return __( 'Each price is marked with the billing cycle it starts from: c1 is the initial purchase, c2 the first renewal.', 'newspack-plugin' );
}

/**
 * The caption a truncated table carries. Shared so the modal and the editor
 * preview cannot drift into two msgids the translators have to service twice.
 */
export function sampleNote( sampleCount: number ): string {
	return sprintf(
		/* translators: %s: how many products the table lists. */
		_n( 'Showing a sample of %s product.', 'Showing a sample of %s products.', sampleCount, 'newspack-plugin' ),
		formatCount( sampleCount )
	);
}
