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
	// A post carries one `wp:term` link per REST-visible taxonomy on its post
	// type, and `embed_links()` dispatches each link the `_embed` list matches,
	// caching by href — which differs per row. So `wp:term` costs a dispatch per
	// row per taxonomy, and it is not just the two this screen shows:
	// newsletters also carry the advertiser taxonomy (shared with the ads CPT)
	// and, with Co-Authors Plus active, its `author` taxonomy. Only the
	// hidden-by-default Categories/Tags columns read `wp:term`, which is why it
	// is gated; the `author` rel is separate and stays, since the Author column
	// reads `_embedded.author`.
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
