/**
 * Shared formatting helpers for the Subscribers Demo wizard.
 */

/**
 * WordPress dependencies.
 */
import { gmdateI18n, getSettings, humanTimeDiff } from '@wordpress/date';

// "2 days ago" — defers to core's localized relative-time formatting (the same
// helper wp-admin uses) rather than a bespoke ladder.
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

// Currency presentation, mirroring the publisher's WooCommerce settings when
// localized onto window by the wizard PHP (symbol + position); defaults to a
// left-positioned "$" with two decimals.
const currencyCfg = ( ( typeof window !== 'undefined' && window.newspackSubscribersDemo ) || {} ).currency || {};
const CURRENCY_SYMBOL = currencyCfg.symbol || '$';
const CURRENCY_POSITION = currencyCfg.position || 'left';

export const fmtCurrency = amount => {
	const formatted = Number( amount || 0 ).toLocaleString( undefined, {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	} );
	switch ( CURRENCY_POSITION ) {
		case 'right':
			return `${ formatted }${ CURRENCY_SYMBOL }`;
		case 'right_space':
			return `${ formatted } ${ CURRENCY_SYMBOL }`;
		case 'left_space':
			return `${ CURRENCY_SYMBOL } ${ formatted }`;
		default:
			return `${ CURRENCY_SYMBOL }${ formatted }`;
	}
};
