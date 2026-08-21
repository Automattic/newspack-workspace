/**
 * Shared price and count formatting for the impact previews (catalog-wide panel
 * and the per-rule editor preview). Currency shaping is the client's job.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatCount } from '../../../../../packages/components/src/breadcrumbs/format-count';

// Re-exported, not reimplemented: only this helper normalises the locales
// WordPress ships that Intl rejects.
export { formatCount };

export const EM_DASH = '—';

/**
 * The engine is a separate plugin, so its numbers arrive as `json_encode` made
 * them: an int, a `$wpdb` string, or null. Anything else is refused rather than
 * coerced, because `Number()` turns `false` and `[]` into a confident zero.
 */
export function finiteNumber( value: unknown ): number | null {
	if ( 'number' !== typeof value && ( 'string' !== typeof value || '' === value.trim() ) ) {
		return null;
	}
	const count = Number( value );
	return Number.isFinite( count ) ? count : null;
}

// `toFixed` throws rather than degrading, and the wizard has no error boundary,
// so an unexpected shape costs one cell instead of the whole page.
export function formatPrice( amount: EngineCount, currency: PricingRulesCurrency ): string {
	const value = finiteNumber( amount );
	return null === value ? EM_DASH : currency.symbol + value.toFixed( currency.decimals );
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
 * The caption for a table the engine capped, or null when it did not. The engine
 * subtracts the products it skipped from the total, so its own flag settles it.
 */
export function sampleNote( payload: CatalogImpactResponse ): string | null {
	const shown = finiteNumber( payload.sample_count );
	if ( ! payload.preview_limited || null === shown ) {
		return null;
	}
	return sprintf(
		/* translators: %s: how many products the table lists. */
		_n( 'Showing a sample of %s product.', 'Showing a sample of %s products.', shown, 'newspack-plugin' ),
		formatCount( shown )
	);
}
