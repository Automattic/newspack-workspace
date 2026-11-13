/**
 * Server-side group list.
 *
 * A site can hold thousands of groups: when its personal plan is itself a team
 * product, every personal subscription is a group. So, like the subscriber list,
 * the group list is server-paginated. This hook translates the DataViews `view`
 * into the groups endpoint's query params and returns one page.
 */

/**
 * Internal dependencies.
 */
import { usePagedList } from './use-paged-list';

const PATH = '/newspack/v1/wizard/newspack-subscribers/groups';

// The fields the endpoint can sort on. Any other sort falls back to the created
// date, so the request stays valid.
const SORTABLE_FIELDS = [ 'name', 'owner', 'createdAt' ];

/**
 * Translate the DataViews view into the groups endpoint's query params. The
 * `status` filter maps to `status`, and the Subscription column (`product`)
 * to `product`.
 *
 * @param {Object} view The DataViews view.
 * @return {Object} Query params for the groups endpoint.
 */
export const viewToParams = view => {
	const params = {
		page: view.page || 1,
		per_page: view.perPage || 20,
		orderby: SORTABLE_FIELDS.includes( view.sort?.field ) ? view.sort.field : 'createdAt',
		order: 'asc' === view.sort?.direction ? 'asc' : 'desc',
	};
	const search = ( view.search || '' ).trim();
	if ( search ) {
		params.search = search;
	}
	for ( const field of [ 'status', 'product' ] ) {
		const filter = ( view.filters || [] ).find( f => field === f.field );
		if ( filter?.value?.length ) {
			params[ field ] = filter.value;
		}
	}
	return params;
};

/**
 * Fetch one page of groups for the given view. See usePagedList() for the
 * loading, `settled` and error contract.
 *
 * `products` lists every product behind a group on the site, for the
 * Subscription filter's options: the page alone would only offer the products
 * on it.
 *
 * @param {Object} view The DataViews view (page, perPage, sort, search, filters).
 * @return {{ groups: Array, total: number, pages: number, products: string[], loading: boolean, settled: boolean, error: string, reload: Function }} The page.
 */
export function useGroups( view ) {
	const { response, ...state } = usePagedList( PATH, viewToParams( view ) );
	return { groups: response.items, total: response.total, pages: response.pages, products: response.products || [], ...state };
}
