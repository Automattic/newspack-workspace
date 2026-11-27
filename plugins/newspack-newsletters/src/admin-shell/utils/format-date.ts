import { formatSiteDate, formatSiteDateTime } from 'newspack-components';

import type { PostItem } from '../types';

/**
 * Render a WP REST date string in the site's date and time formats and timezone.
 * The offset-less REST string is read as site time, so admins outside the site
 * timezone see the same date the editor stored.
 *
 * @param item      DataView row.
 * @param fieldName Date field key on the row (default `date`).
 * @param opts
 * @param opts.kind Date only, or date and time (default `datetime`).
 * @return Localised date string, or '' when the field is empty.
 */
export function formatPostDate( item: PostItem, fieldName = 'date', { kind = 'datetime' }: { kind?: 'date' | 'datetime' } = {} ): string {
	// REST date fields arrive as strings (or numeric timestamps); the index-signature read widens to `unknown`.
	const value = item?.[ fieldName ] as string | number | null | undefined;
	return 'date' === kind ? formatSiteDate( value ) : formatSiteDateTime( value );
}
