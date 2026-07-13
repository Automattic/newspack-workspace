/**
 * URL-driven initial view state for the Ads list DataView.
 *
 * Translates the curated args forwarded by the legacy-list redirect
 * (status, search, sort) into DataViews-shaped state.
 */

import { makeGetInitialView } from '../../utils/initial-view';

// Map legacy post_status values onto a kind-based filter value. The
// classic ads list only meaningfully exposes draft / trash via this
// query arg; "publish" rows split across active / scheduled / expired
// kinds, so we don't translate that case (the React default already
// shows them).
const POST_STATUS_TO_KIND = {
	trash: 'trash',
	draft: 'draft',
	pending: 'draft',
	'auto-draft': 'draft',
	// `Ads_List_REST::filter_rest_query`'s `scheduled` bucket includes
	// `future` rows, so deep links from WP's Publish-Schedule UI land
	// on the same chip the date-driven scheduled rows do.
	future: 'scheduled',
};

// Inverse of `SORT_FIELD_TO_ORDERBY` in build-query: map legacy ads
// `orderby` values back onto the DataView field id.
const ORDERBY_TO_SORT_FIELD = {
	title: 'title',
	date: 'date',
	start_date: 'start_date',
	expiry_date: 'expiry_date',
	price: 'price',
	impressions: 'impressions',
	clicks: 'clicks',
};

export const { getInitialFilters, getInitialView } = makeGetInitialView( {
	orderbyMap: ORDERBY_TO_SORT_FIELD,
	postStatusMap: POST_STATUS_TO_KIND,
} );
