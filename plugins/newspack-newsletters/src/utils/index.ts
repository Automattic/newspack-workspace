/**
 * Internal dependencies
 */
import { LAYOUT_CPT_SLUG } from './consts';

/**
 * A newsletter layout post, as returned by the layouts REST endpoint. Layouts
 * are consumed by the layout picker and the admin-shell layouts list, so this
 * shape is shared. Members mirror the raw post payload and are optional
 * because prebuilt and user-defined layouts populate different subsets.
 */
export interface Layout {
	ID?: number;
	post_type?: string;
	post_title?: string;
	post_content?: string;
	post_author?: number | string;
	post_modified?: string;
	meta?: Record< string, unknown >;
	_embedded?: {
		author?: Array< {
			name?: string;
			avatar_urls?: Record< string, string >;
			[ key: string ]: unknown;
		} >;
		[ key: string ]: unknown;
	};
	[ key: string ]: unknown;
}

export const isUserDefinedLayout = ( layout?: Layout ) => layout && layout.post_type === LAYOUT_CPT_SLUG;
