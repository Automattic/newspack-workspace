/**
 * URL-driven initial view state for the Newsletters list DataView.
 *
 * Translates the curated args forwarded by the legacy-list redirect
 * (status, search, sort, author, terms, send-list) into
 * DataViews-shaped state.
 */

import { makeGetInitialView } from '../../utils/initial-view';

const POST_STATUS_TO_FILTER_VALUE = {
	trash: 'trash',
	draft: 'draft,pending,auto-draft',
	pending: 'draft,pending,auto-draft',
	'auto-draft': 'draft,pending,auto-draft',
	future: 'future',
	publish: 'publish,private',
	private: 'publish,private',
};

// Inverse of the JS-side `SORT_FIELD_TO_ORDERBY` in build-query: map
// REST `orderby` values back onto the DataView field id our `getFields`
// configuration uses.
const ORDERBY_TO_SORT_FIELD = {
	title: 'title',
	date: 'date',
	author: 'author',
};

// URL param → DataView filter field; mirrors build-query's reverse mapping.
const URL_PARAM_TO_FILTER_FIELD = {
	author: 'author',
	categories: 'categories',
	tags: 'tags',
	newspack_newsletters_send_list_id: 'send_list',
};

export const { getInitialFilters, getInitialView } = makeGetInitialView( {
	orderbyMap: ORDERBY_TO_SORT_FIELD,
	postStatusMap: POST_STATUS_TO_FILTER_VALUE,
	urlParamToFilterField: URL_PARAM_TO_FILTER_FIELD,
} );
