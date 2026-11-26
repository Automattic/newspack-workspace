/**
 * WordPress dependencies
 */
import { dateI18n, getDate, getSettings, gmdateI18n } from '@wordpress/date';

export type SiteDateValue = string | number | Date | null | undefined;

const BARE_DATE = /^\d{4}-\d{2}-\d{2}$/;
const UNIX_SECONDS = /^\d+$/;

function formatWith( format: string, value: SiteDateValue ): string {
	if ( value === null || value === undefined || value === '' || value === 0 ) {
		return '';
	}
	if ( value instanceof Date ) {
		return Number.isNaN( value.getTime() ) ? '' : dateI18n( format, value );
	}
	if ( typeof value === 'number' ) {
		return Number.isFinite( value ) ? dateI18n( format, value * 1000 ) : '';
	}
	const trimmed = value.trim();
	if ( trimmed === '0' ) {
		return '';
	}
	// A bare date names a calendar day, not an instant: anchored at UTC midnight
	// and formatted in UTC, it shows the same day in every browser and site zone.
	if ( BARE_DATE.test( trimmed ) ) {
		return gmdateI18n( format, `${ trimmed }T00:00:00Z` );
	}
	if ( UNIX_SECONDS.test( trimmed ) ) {
		return dateI18n( format, Number( trimmed ) * 1000 );
	}
	const date = getDate( trimmed );
	return Number.isNaN( date.getTime() ) ? '' : dateI18n( format, date );
}

// Core's `formats.datetime` is a fixed translated string, not the site's
// settings, so the date and time formats are joined instead.
function siteDateTimeFormat(): string {
	const { formats } = getSettings();
	return `${ formats.date } ${ formats.time }`;
}

/**
 * Format a value as a date in the site's date format and timezone.
 *
 * @param value A bare `YYYY-MM-DD` date, an ISO 8601 or MySQL date string, a Unix timestamp in seconds, or a Date.
 * @return The formatted date, or '' when the value is empty or invalid.
 */
export function formatSiteDate( value: SiteDateValue ): string {
	return formatWith( getSettings().formats.date, value );
}

/**
 * Format a value as a date and time in the site's formats and timezone.
 *
 * @param value An ISO 8601 or MySQL date string, a Unix timestamp in seconds, or a Date.
 * @return The formatted date and time, or '' when the value is empty or invalid.
 */
export function formatSiteDateTime( value: SiteDateValue ): string {
	return formatWith( siteDateTimeFormat(), value );
}

/**
 * Format a value as a time in the site's time format and timezone.
 *
 * @param value An ISO 8601 or MySQL date string, a Unix timestamp in seconds, or a Date.
 * @return The formatted time, or '' when the value is empty or invalid.
 */
export function formatSiteTime( value: SiteDateValue ): string {
	return formatWith( getSettings().formats.time, value );
}
