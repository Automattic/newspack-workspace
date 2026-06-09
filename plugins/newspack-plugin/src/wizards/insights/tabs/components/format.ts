/**
 * Tab 6 formatting helpers (NPPD-1616).
 *
 * Lightweight wrappers around Intl.NumberFormat. Locale is taken from
 * the browser; currency code is hardcoded to USD for v1 (the publisher
 * may have multiple Woo currencies, and v1 sums them naively — a
 * multi-currency rollup is v1.1+ and is documented in the formula doc).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

const numberFormatter = new Intl.NumberFormat( undefined, {
	maximumFractionDigits: 0,
} );

const decimalFormatter = new Intl.NumberFormat( undefined, {
	minimumFractionDigits: 1,
	maximumFractionDigits: 1,
} );

const currencyFormatter = new Intl.NumberFormat( undefined, {
	style: 'currency',
	currency: 'USD',
	maximumFractionDigits: 2,
} );

const percentFormatter = new Intl.NumberFormat( undefined, {
	style: 'percent',
	maximumFractionDigits: 1,
} );

const signedPercentFormatter = new Intl.NumberFormat( undefined, {
	style: 'percent',
	signDisplay: 'exceptZero',
	maximumFractionDigits: 1,
} );

const shortDateFormatter = new Intl.DateTimeFormat( undefined, {
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

export const formatCurrency = ( n: number ): string => currencyFormatter.format( n );

/** Format a fraction in [0, 1] as a percent: 0.123 -> "12.3%". */
export const formatPercent = ( fraction: number ): string => percentFormatter.format( fraction );

/** Format a duration in seconds as m:ss (or h:mm:ss past an hour): 142 -> "2:22". */
export const formatDuration = ( seconds: number ): string => {
	const total = Math.max( 0, Math.round( seconds ) );
	const hrs = Math.floor( total / 3600 );
	const mins = Math.floor( ( total % 3600 ) / 60 );
	const secs = total % 60;
	const pad = ( n: number ): string => String( n ).padStart( 2, '0' );
	if ( hrs > 0 ) {
		return `${ hrs }:${ pad( mins ) }:${ pad( secs ) }`;
	}
	return `${ mins }:${ pad( secs ) }`;
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
