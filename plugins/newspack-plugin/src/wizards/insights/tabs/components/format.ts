/**
 * Insights formatting helpers (NPPD-1616).
 *
 * Wrappers around Intl.NumberFormat. Everything is formatted in one fixed
 * locale (`en-US`) so the display is consistent regardless of the admin's own
 * browser locale — and, critically, so the currency symbol is unambiguous:
 * en-US renders USD as "$" and non-US dollars as "CA$" / "NZ$" / "A$", whereas
 * a non-US admin's own locale renders USD as "US$" / "$US" (DSGNEWS-188). The
 * currency code is the site's WooCommerce currency from the boot config
 * (`window.newspackInsights.currency`), falling back to USD.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

const LOCALE = 'en-US';
// ISO 4217 code (Intl requires uppercase); WooCommerce already returns uppercase
// but normalize defensively.
const SITE_CURRENCY = ( ( typeof window !== 'undefined' && window.newspackInsights?.currency ) || 'USD' ).toUpperCase();

const numberFormatter = new Intl.NumberFormat( LOCALE, {
	maximumFractionDigits: 0,
} );

const decimalFormatter = new Intl.NumberFormat( LOCALE, {
	minimumFractionDigits: 1,
	maximumFractionDigits: 1,
} );

// Three tiers keep large dashboard values readable (NPPD-1684): small amounts
// show the currency's natural minor units, thousands+ round to whole units, and
// millions+ abbreviate with the full value in a tooltip. Formatted in the site's
// Woo currency (see file header). `CURRENCY_FULL` sets no fraction count, so Intl
// uses each currency's default minor units — 0 (JPY), 2 (USD), 3 (BHD).
const CURRENCY_FULL = new Intl.NumberFormat( LOCALE, {
	style: 'currency',
	currency: SITE_CURRENCY,
} );

const CURRENCY_ROUNDED = new Intl.NumberFormat( LOCALE, {
	style: 'currency',
	currency: SITE_CURRENCY,
	minimumFractionDigits: 0,
	maximumFractionDigits: 0,
} );

const CURRENCY_COMPACT = new Intl.NumberFormat( LOCALE, {
	style: 'currency',
	currency: SITE_CURRENCY,
	notation: 'compact',
	maximumFractionDigits: 1,
} );

const NUMBER_COMPACT = new Intl.NumberFormat( LOCALE, {
	notation: 'compact',
	maximumFractionDigits: 1,
} );

const percentFormatter = new Intl.NumberFormat( LOCALE, {
	style: 'percent',
	maximumFractionDigits: 1,
} );

const signedPercentFormatter = new Intl.NumberFormat( LOCALE, {
	style: 'percent',
	signDisplay: 'exceptZero',
	maximumFractionDigits: 1,
} );

const shortDateFormatter = new Intl.DateTimeFormat( LOCALE, {
	month: 'short',
	day: 'numeric',
} );

export const formatNumber = ( n: number ): string => numberFormatter.format( n );

/** Format a GA4 `YYYYMMDD` date string as a short date: "20260510" -> "May 10". Falls back to the raw string. */
export const formatShortDate = ( ymd: string ): string => {
	const match = /^(\d{4})(\d{2})(\d{2})$/.exec( ymd );
	if ( ! match ) {
		return ymd;
	}
	const date = new Date( Number( match[ 1 ] ), Number( match[ 2 ] ) - 1, Number( match[ 3 ] ) );
	return shortDateFormatter.format( date );
};

/** Format a number with exactly one decimal place: 0 -> "0.0", 1.23 -> "1.2". */
export const formatDecimal = ( n: number ): string => decimalFormatter.format( n );

export interface FormattedCurrency {
	/** The value to render (cents / no-cents / compact, per magnitude). */
	display: string;
	/** Full-precision value for a tooltip when `display` is abbreviated, else null. */
	title: string | null;
}

/**
 * Tiered currency: below 1,000 shows the currency's natural minor units (e.g.
 * `$89.42`, `¥523`); 1,000–<1M rounds to whole units (`$41,690`); >=1M
 * abbreviates (`$1.2M`) and carries the full value as `title`. The sign is
 * handled by the formatter; the tier is chosen by magnitude, so negatives tier
 * the same. All in the site's Woo currency.
 */
export const formatCurrency = ( value: number ): FormattedCurrency => {
	const abs = Math.abs( value );
	if ( abs < 1000 ) {
		return { display: CURRENCY_FULL.format( value ), title: null };
	}
	if ( abs < 1_000_000 ) {
		return { display: CURRENCY_ROUNDED.format( value ), title: null };
	}
	return {
		display: CURRENCY_COMPACT.format( value ),
		title: CURRENCY_FULL.format( value ),
	};
};

/**
 * Tiered count: `< 1M` renders in full with thousands separators; `>= 1M`
 * abbreviates (e.g. "2.4M") and carries the full value as `title` for a tooltip.
 * Mirrors {@see formatCurrency}'s millions tier (NPPD-1684) so large counts —
 * ad impressions especially — don't overflow a scorecard at 7+ digits. Shares
 * `FormattedCurrency`'s `{ display, title }` shape so MetricCard treats an
 * abbreviated count exactly like an abbreviated amount.
 */
export const formatCount = ( value: number ): FormattedCurrency => {
	if ( Math.abs( value ) < 1_000_000 ) {
		return { display: numberFormatter.format( value ), title: null };
	}
	return {
		display: NUMBER_COMPACT.format( value ),
		title: numberFormatter.format( value ),
	};
};

/**
 * Format a fraction in [0, 1] as a percent: 0.123 -> "12.3%".
 *
 * A positive-but-tiny fraction that would round to "0%" at the displayed precision
 * (i.e. < 0.05%) renders "<0.1%" instead (NPPD-1746), so a real-but-small rate is
 * never shown as none — e.g. a paywall gate with a handful of subscriptions over
 * 100k+ mostly-registration impressions. Genuine zero still renders "0%".
 */
export const formatPercent = ( fraction: number ): string => {
	if ( fraction > 0 && fraction < 0.0005 ) {
		return `<${ percentFormatter.format( 0.001 ) }`;
	}
	return percentFormatter.format( fraction );
};

/** Format a duration in seconds with unit suffixes: 142 -> "2m 22s", 9573 -> "2h 39m 33s", 5 -> "5s". */
export const formatDuration = ( seconds: number ): string => {
	const total = Math.max( 0, Math.round( seconds ) );
	const hrs = Math.floor( total / 3600 );
	const mins = Math.floor( ( total % 3600 ) / 60 );
	const secs = total % 60;
	if ( hrs > 0 ) {
		return `${ hrs }h ${ mins }m ${ secs }s`;
	}
	if ( mins > 0 ) {
		return `${ mins }m ${ secs }s`;
	}
	return `${ secs }s`;
};

/**
 * Percent change between current and previous, formatted with sign.
 * Returns null when previous is 0 (no defined ratio).
 */
export const formatDelta = ( current: number, previous: number ): string | null => {
	if ( previous === 0 ) {
		return null;
	}
	return signedPercentFormatter.format( ( current - previous ) / previous );
};

/**
 * Compute the user-meaningful tone of a delta. "Positive" means the
 * change is good news for the publisher; "negative" means bad news.
 * lowerIsBetter inverts the mapping for metrics where a decrease is the
 * desired direction (refund rate, churn count, etc.).
 */
export const deltaTone = ( current: number, previous: number, lowerIsBetter = false ): 'positive' | 'negative' | 'neutral' => {
	if ( current === previous ) {
		return 'neutral';
	}
	const improved = lowerIsBetter ? current < previous : current > previous;
	return improved ? 'positive' : 'negative';
};

export const noDataLabel = (): string => __( 'No data', 'newspack-plugin' );
