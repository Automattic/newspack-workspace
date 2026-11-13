/**
 * Server-side subscriber list.
 *
 * The subscriber list is server-paginated: the reader table can run to tens of
 * thousands of rows, so filtering, sorting and paging all happen in the REST
 * endpoint. This hook translates the DataViews `view` object into query params
 * and returns the `{ items, total, pages }` envelope the list renders, plus a
 * loading flag while a page is in flight.
 */

/**
 * Internal dependencies.
 */
import { usePagedList } from './use-paged-list';

const PATH = '/newspack/v1/wizard/newspack-subscribers/subscribers';

// Only name and member-since are server-sortable in this slice; any other sort
// field falls back to member-since so the request stays valid (the endpoint
// enums orderby to these two).
const SORTABLE_FIELDS = [ 'name', 'memberSince' ];

/**
 * Translate the DataViews view into the endpoint's query params. Filters are
 * matched by field id — `status` maps to the subscription-status filter, `plans`
 * (the Subscription column) to the endpoint's `plan` arg, `access` to its
 * `access` arg, and `managedOn` to its `managed_on` arg; the group-role, tag and
 * newsletter filters arrive in later slices and are ignored here.
 *
 * @param {Object} view The DataViews view.
 * @return {Object} Query params for the subscribers endpoint.
 */
export const viewToParams = view => {
	const params = {
		page: view.page || 1,
		per_page: view.perPage || 20,
		orderby: SORTABLE_FIELDS.includes( view.sort?.field ) ? view.sort.field : 'memberSince',
		order: 'asc' === view.sort?.direction ? 'asc' : 'desc',
	};
	const search = ( view.search || '' ).trim();
	if ( search ) {
		params.search = search;
	}
	const statusFilter = ( view.filters || [] ).find( f => 'status' === f.field );
	if ( statusFilter?.value?.length ) {
		params.status = statusFilter.value;
	}
	// The column is `plans`; the endpoint arg is the singular `plan`.
	const planFilter = ( view.filters || [] ).find( f => 'plans' === f.field );
	if ( planFilter?.value?.length ) {
		params.plan = planFilter.value;
	}
	const accessFilter = ( view.filters || [] ).find( f => 'access' === f.field );
	if ( accessFilter?.value ) {
		params.access = [ accessFilter.value ];
	}
	const managedOnFilter = ( view.filters || [] ).find( f => 'managedOn' === f.field );
	if ( managedOnFilter?.value?.length ) {
		params.managed_on = managedOnFilter.value;
	}
	return params;
};

/**
 * Fetch one server-paginated page of subscribers for the given view. See
 * usePagedList() for the loading, `settled` and error contract.
 *
 * @param {Object} view The DataViews view (page, perPage, sort, search, filters).
 * @return {{ items: Array, total: number, pages: number, loading: boolean, settled: boolean, error: string, reload: Function }} The page.
 */
export function useSubscribers( view ) {
	const { response, ...state } = usePagedList( PATH, viewToParams( view ) );
	return { items: response.items, total: response.total, pages: response.pages, ...state };
}
