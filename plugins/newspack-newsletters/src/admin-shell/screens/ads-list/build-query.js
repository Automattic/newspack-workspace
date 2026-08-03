/**
 * Translate a DataViews `view` into the `/wp/v2/newspack_nl_ads_cpt`
 * query string.
 *
 * Status filter passes kind values through the custom param
 * `newspack_newsletters_ad_status`; `Ads_List_REST::filter_rest_query`
 * turns each into the matching `post_status` set + date-driven SQL.
 */

import { buildQueryParams as baseBuildQueryParams, toQueryString } from '../../utils/build-query';

// `future` covers WP-scheduled ads (the standard Publish-Schedule UI) —
// the React Ads list and the consolidated status payload both treat
// these as `kind=scheduled` regardless of `start_date` meta. Without
// `future` in the default set, WP-scheduled rows would silently
// disappear from the list (the classic CPT list showed them).
// `auto-draft` keeps an abandoned "Add new" visible.
const DEFAULT_STATUSES = 'publish,private,future,draft,pending,auto-draft';

// Each value is the WP REST taxonomy filter param — i.e. the
// taxonomy's `rest_base`, which defaults to the taxonomy slug when
// not explicitly set. Advertiser has no override (param matches the
// slug); Ad placement is registered with `rest_base => 'ad_placement'`
// (see `class-ads-placements.php`), so the filter param is the short
// form, not the taxonomy slug.
const FIELD_TO_QUERY_PARAM = {
	advertiser: 'newspack_nl_advertiser',
	ad_placement: 'ad_placement',
};

// Meta-backed values are virtual tokens; the server applies the
// sort via a posts_clauses LEFT JOIN on the underlying meta key.
const SORT_FIELD_TO_ORDERBY = {
	title: 'title',
	date: 'date',
	start_date: 'start_date',
	expiry_date: 'expiry_date',
	price: 'price',
	impressions: 'impressions',
	clicks: 'clicks',
};

export function buildQueryParams( view = {} ) {
	return baseBuildQueryParams( view, {
		fieldToQueryParam: FIELD_TO_QUERY_PARAM,
		sortFieldToOrderby: SORT_FIELD_TO_ORDERBY,
		defaultStatuses: DEFAULT_STATUSES,
		// Active kind filter → custom REST param; no filter → wide post_status default.
		statusFilterParam: 'newspack_newsletters_ad_status',
		defaultStatusParam: 'status',
		extraParams: { _embed: 'wp:term' },
	} );
}

export { toQueryString };
