/**
 * Shared formatting helpers for the Subscribers wizard.
 */

/**
 * WordPress dependencies.
 */
import { dateI18n, gmdateI18n, getSettings, humanTimeDiff } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

// "2 days ago" — defers to core's localized relative-time formatting (the same
// helper wp-admin uses) rather than a bespoke ladder.
//
// The bare date needs no anchoring, unlike in fmtDate below: humanTimeDiff reads
// it as midnight in the *site's* timezone and measures from now, so it already
// speaks the same calendar the absolute date beside it prints. Anchoring it at
// UTC to match fmtDate's mechanism would break that agreement rather than
// complete it — on a site ahead of UTC, a date on the site's current day is still
// in the future in UTC, and a join date would read "in 12 hours".
export const fmtRelative = date => ( date ? humanTimeDiff( date ) : '' );

// Calendar-date presentation, using the publisher's WordPress date format. The
// wizard stores dates as date-only strings (YYYY-MM-DD) with no time or zone, so
// they are anchored at UTC midnight and formatted in UTC: this keeps the shown
// day stable no matter the viewer's timezone (a browser ahead of the site's zone
// would otherwise roll a bare date back to the previous day).
export const fmtDate = value => {
	if ( ! value ) {
		return '';
	}
	const anchored = /^\d{4}-\d{2}-\d{2}$/.test( value ) ? `${ value }T00:00:00+00:00` : value;
	return gmdateI18n( getSettings().formats.date, anchored );
};

// Fallback dash for an empty value, matching the list columns (e.g. "Last seen —").
export const orDash = value => value || '—';

/**
 * Currency presentation for a subscription amount.
 *
 * The amount and its ISO currency code both come from the subscription itself
 * (WooCommerce stores a currency per order), so formatting is delegated to
 * Intl via toLocaleString rather than to a site-wide symbol/position config:
 * that keeps a store selling in more than one currency honest, and gets the
 * viewer's locale conventions for free.
 *
 * @param {number|string} amount   The amount.
 * @param {string}        currency ISO 4217 currency code, e.g. 'USD'.
 * @return {string} The formatted amount, or '' when there is no numeric amount.
 */
export const fmtCurrency = ( amount, currency ) => {
	const value = Number( amount );
	if ( ! Number.isFinite( value ) || null === amount || '' === amount || undefined === amount ) {
		return '';
	}
	if ( currency ) {
		try {
			return value.toLocaleString( undefined, { style: 'currency', currency } );
		} catch ( e ) {
			// An unrecognised currency code throws; fall through to the plain
			// two-decimal amount rather than blanking the price entirely.
		}
	}
	return value.toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
};

// Period names for a one-period billing cycle — "$12.00 / month". The count is
// left off deliberately: "/ 1 month" reads as a quantity where the design wants
// a rate.
const PERIOD_LABELS = {
	day: __( 'day', 'newspack-plugin' ),
	week: __( 'week', 'newspack-plugin' ),
	month: __( 'month', 'newspack-plugin' ),
	year: __( 'year', 'newspack-plugin' ),
};

/**
 * The period name for a cycle spanning more than one period — "3 months". Kept
 * as whole _n() calls per period rather than one assembled string so every form
 * stays extractable and independently pluralizable.
 *
 * @param {string} period   WooCommerce billing period ('day'|'week'|'month'|'year').
 * @param {number} interval How many of those periods each billing cycle spans.
 * @return {string} The localized, counted period.
 */
const periodPlural = ( period, interval ) => {
	switch ( period ) {
		case 'day':
			// translators: %d is the number of days in one billing cycle.
			return sprintf( _n( '%d day', '%d days', interval, 'newspack-plugin' ), interval );
		case 'week':
			// translators: %d is the number of weeks in one billing cycle.
			return sprintf( _n( '%d week', '%d weeks', interval, 'newspack-plugin' ), interval );
		case 'year':
			// translators: %d is the number of years in one billing cycle.
			return sprintf( _n( '%d year', '%d years', interval, 'newspack-plugin' ), interval );
		default:
			// translators: %d is the number of months in one billing cycle.
			return sprintf( _n( '%d month', '%d months', interval, 'newspack-plugin' ), interval );
	}
};

/**
 * The billing period of a subscription — "month", "3 months". Only WooCommerce
 * Subscriptions' four periods are known here; an unrecognised one yields '' so
 * the caller shows the bare amount rather than an invented cadence.
 *
 * @param {string} period   WooCommerce billing period ('day'|'week'|'month'|'year').
 * @param {number} interval How many of those periods each billing cycle spans.
 * @return {string} The localized period, or '' when the period is unknown.
 */
const periodLabel = ( period, interval ) => {
	if ( ! PERIOD_LABELS[ period ] ) {
		return '';
	}
	return interval > 1 ? periodPlural( period, interval ) : PERIOD_LABELS[ period ];
};

/**
 * "$12.00 / month" — the billing rate shown on a subscription or group card.
 *
 * Takes the billing fields as the single-entity endpoints return them, so the
 * person profile and the group detail drawer render the same string from the
 * same source without either restating the format.
 *
 * @param {Object}        entry                 A subscription or group entry.
 * @param {number|string} entry.amount          The recurring amount.
 * @param {string}        entry.currency        ISO 4217 currency code.
 * @param {string}        entry.billingPeriod   WooCommerce billing period.
 * @param {number}        entry.billingInterval Billing interval.
 * @return {string} The billing rate, or '—' when there is no amount on file.
 */
export const billingText = ( { amount, currency, billingPeriod, billingInterval } = {} ) => {
	const price = fmtCurrency( amount, currency );
	if ( ! price ) {
		return '—';
	}
	const period = periodLabel( billingPeriod, billingInterval > 0 ? billingInterval : 1 );
	if ( ! period ) {
		return price;
	}
	// translators: 1: a formatted price, 2: a billing period such as "month".
	return sprintf( __( '%1$s / %2$s', 'newspack-plugin' ), price, period );
};

/**
 * The schedule row that closes a subscription card: when it next bills, or when
 * it ends. Derived from the dates, not the status, because a subscription that
 * WooCommerce is winding down (pending-cancel) still maps to the "active" status
 * — WCS deletes its next-payment date and sets an end date in the prepaid term,
 * so showing "Next billing —" there would tell the admin nothing while the plan
 * silently expires. So: a next-billing date wins (it is still billing); else an
 * end date reads as "Ends" while it is in the future and "Ended" once past; else
 * a dash. Shared with the group-detail drawer so both render the row identically.
 *
 * @param {Object} entry                   A subscription or group entry.
 * @param {string} [entry.nextBillingDate] The next-billing date, if any.
 * @param {string} [entry.endDate]         The access-end date, if any.
 * @return {{ label: string, value: string }} The row's label and value.
 */
export const scheduleRow = ( { nextBillingDate, endDate } = {} ) => {
	if ( nextBillingDate ) {
		return { label: __( 'Next billing', 'newspack-plugin' ), value: fmtDate( nextBillingDate ) };
	}
	if ( endDate ) {
		// Compared as bare YYYY-MM-DD strings, which sort chronologically. "Today"
		// is the site's civil date, not the viewer's browser day: the endpoint
		// derives endDate in the site timezone (wp_date), so an admin working from
		// another zone would otherwise see the label flip a day early or late.
		const today = dateI18n( 'Y-m-d' );
		const label = endDate >= today ? __( 'Ends', 'newspack-plugin' ) : __( 'Ended', 'newspack-plugin' );
		return { label, value: fmtDate( endDate ) };
	}
	return { label: __( 'Next billing', 'newspack-plugin' ), value: '—' };
};
