import { dateI18n, getDate, getSettings as getDateSettings } from '@wordpress/date';

/**
 * Render a WP REST date string in the site's configured timezone. `getDate` re-anchors the
 * (offset-less) REST string to `wp.date.settings.timezone` so admins outside the site
 * timezone see the same calendar date the editor stored.
 *
 * @param {Object}            item        DataView row.
 * @param {string}            [fieldName] Date field key on the row (default `date`).
 * @param {Object}            [opts]
 * @param {'date'|'datetime'} [opts.kind] `wp.date.settings.formats` entry (default `datetime`).
 * @return {string} Localised date string, or '' when the field is empty.
 */
export function formatPostDate( item, fieldName = 'date', { kind = 'datetime' } = {} ) {
	const value = item?.[ fieldName ];
	if ( ! value ) {
		return '';
	}
	const settings = getDateSettings();
	const format = settings.formats?.[ kind ] || ( 'date' === kind ? 'M j, Y' : 'M j, Y g:ia' );
	return dateI18n( format, getDate( value ) );
}
