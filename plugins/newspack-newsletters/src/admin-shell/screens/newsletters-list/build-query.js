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

// `auto-draft` is excluded: any save promotes the row to `draft`, so one still
// at `auto-draft` is always an abandoned "Add new" with nothing in it.
const DEFAULT_STATUSES = 'publish,private,future,draft,pending';

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
	// Term embeds cost ~2 internal REST dispatches per row and only the
	// (hidden-by-default) Categories/Tags columns read them.
	const visibleFields = Array.isArray( view.fields ) ? view.fields : null;
	const needsTerms = ! visibleFields || visibleFields.includes( 'categories' ) || visibleFields.includes( 'tags' );

	return baseBuildQueryParams( view, {
		fieldToQueryParam: FIELD_TO_QUERY_PARAM,
		sortFieldToOrderby: SORT_FIELD_TO_ORDERBY,
		defaultStatuses: DEFAULT_STATUSES,
		// `_fields` short-circuits `content.rendered` / `excerpt.rendered`
		// (the full `the_content` chain, incl. synchronous oEmbed fetches)
		// and the unused editor REST fields — per-item cost the list never
		// reads. `_links` must stay in the list: `_embed` only expands
		// links that survive the `_fields` filter.
		extraParams: {
			_embed: needsTerms ? 'author,wp:term' : 'author',
			// `categories`/`tags`: Quick Edit needs them when the embed is skipped.
			_fields: 'id,status,title,date,link,meta,categories,tags,newspack_newsletters_status,_links',
		},
	} );
}

export { toQueryString };
