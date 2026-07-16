/**
 * URL-driven initial view state for the Advertisers list DataView.
 *
 * Translates the curated args forwarded by the legacy-list redirect
 * (search, sort) into DataViews-shaped state.
 */

import { makeGetInitialView } from '../../utils/initial-view';

// Map the WP REST terms controller `orderby` values that the React
// DataView fields here also expose. `id` / `include` / `term_group`
// are accepted by REST but the DataView has no field for them, so a
// legacy URL using those values falls through to the default sort
// rather than producing an invalid state.
const ORDERBY_TO_SORT_FIELD = {
	name: 'name',
	slug: 'slug',
	count: 'count',
};

// Alphabetical lists default to ascending; everywhere else the default
// is descending.
export const { getInitialView } = makeGetInitialView( {
	orderbyMap: ORDERBY_TO_SORT_FIELD,
	defaultSortDirection: 'asc',
} );
