import { dateI18n, getDate, getSettings as getDateSettings } from '@wordpress/date';

import type { PostItem } from '../types';

/**
 * Render a WP REST date string in the site's configured timezone. `getDate` re-anchors the
 * (offset-less) REST string to `wp.date.settings.timezone` so admins outside the site
 * timezone see the same calendar date the editor stored.
 *
 * @param item      DataView row.
 * @param fieldName Date field key on the row (default `date`).
 * @param opts
 * @param opts.kind `wp.date.settings.formats` entry (default `datetime`).
 * @return Localised date string, or '' when the field is empty.
 */
export function formatPostDate( item: PostItem, fieldName = 'date', { kind = 'datetime' }: { kind?: 'date' | 'datetime' } = {} ): string {
	const value = item?.[ fieldName ];
	if ( ! value ) {
		return '';
	}
	const settings = getDateSettings();
	const format = settings.formats?.[ kind ] || ( 'date' === kind ? 'M j, Y' : 'M j, Y g:ia' );
	// REST date fields (`date`, `modified`) are always strings; the index-signature read widens to `unknown`.
	return dateI18n( format, getDate( value as string ) );
}
