import { formatSiteDate, formatSiteDateTime } from 'newspack-components';

/**
 * Render a WP REST date string in the site's date and time formats and timezone.
 * The offset-less REST string is read as site time, so admins outside the site
 * timezone see the same date the editor stored.
 *
 * @param {Object}            item        DataView row.
 * @param {string}            [fieldName] Date field key on the row (default `date`).
 * @param {Object}            [opts]
 * @param {'date'|'datetime'} [opts.kind] Date only, or date and time (default `datetime`).
 * @return {string} Localised date string, or '' when the field is empty.
 */
export function formatPostDate( item, fieldName = 'date', { kind = 'datetime' } = {} ) {
	const value = item?.[ fieldName ];
	return 'date' === kind ? formatSiteDate( value ) : formatSiteDateTime( value );
}
