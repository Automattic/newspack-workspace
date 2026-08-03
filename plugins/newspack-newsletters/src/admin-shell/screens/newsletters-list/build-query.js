/**
 * Translate a DataViews `view` into the `/wp/v2/newspack_nl_cpt`
 * query string.
 *
 * Filters map to native WP params (`status`, `author`); the Status
 * column derives the kind in `fields.js`. `status=any` excludes
 * trash, so we name the writable statuses explicitly when no filter
 * is set.
 */

import { buildQueryParams as baseBuildQueryParams, toQueryString } from '../../utils/build-query';

// `auto-draft` so an abandoned "Add new" still shows in the list.
const DEFAULT_STATUSES = 'publish,private,future,draft,pending,auto-draft';

// `status` is handled separately by the shared util's status-filter branch, not here.
const FIELD_TO_QUERY_PARAM = {
	author: 'author',
	categories: 'categories',
	tags: 'tags',
	// `Newsletters_List_REST::filter_send_list_query` consumes this.
	send_list: 'newspack_newsletters_send_list_id',
	// `public_page` filter values are `'1'` / `'0'` (see `getFields`).
	// `Newsletters_List_REST::filter_rest_query` consumes the same param.
	public_page: 'newspack_newsletters_is_public',
};

const SORT_FIELD_TO_ORDERBY = {
	title: 'title',
	date: 'date',
	send_date: 'date',
	author: 'author',
};

export function buildQueryParams( view = {} ) {
	return baseBuildQueryParams( view, {
		fieldToQueryParam: FIELD_TO_QUERY_PARAM,
		sortFieldToOrderby: SORT_FIELD_TO_ORDERBY,
		defaultStatuses: DEFAULT_STATUSES,
		extraParams: { _embed: 'author,wp:term' },
	} );
}

export { toQueryString };
