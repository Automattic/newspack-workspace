/**
 * Server-side subscriber list.
 *
 * The subscriber list is server-paginated: unlike the group list (which loads
 * in full and filters client-side), the reader table can run to tens of
 * thousands of rows, so filtering, sorting and paging all happen in the REST
 * endpoint. This hook translates the DataViews `view` object into query params
 * and returns the `{ items, total, pages }` envelope the list renders, plus a
 * loading flag while a page is in flight.
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const PATH = '/newspack/v1/wizard/newspack-subscribers/subscribers';

// Only name and member-since are server-sortable in this slice; any other sort
// field falls back to member-since so the request stays valid (the endpoint
// enums orderby to these two).
const SORTABLE_FIELDS = [ 'name', 'memberSince' ];

/**
 * Translate the DataViews view into the endpoint's query params. Filters are
 * matched by field id — `status` maps to the subscription-status filter; the
 * plan / group-role / tag / newsletter filters arrive in later slices and are
 * ignored here.
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
	return params;
};

/**
 * Fetch one server-paginated page of subscribers for the given view.
 *
 * @param {Object} view The DataViews view (page, perPage, sort, search, filters).
 * @return {{ items: Array, total: number, pages: number, loading: boolean }} The page.
 */
export function useSubscribers( view ) {
	const [ result, setResult ] = useState( { items: [], total: 0, pages: 0 } );
	const [ loading, setLoading ] = useState( true );

	// Serialize the params so the effect re-runs only when a query-affecting bit
	// of the view actually changes (the view object is a fresh reference each
	// time DataViews reports a change). Rebuilt from the key inside the effect so
	// the effect closes over no unstable object.
	const key = useMemo( () => JSON.stringify( viewToParams( view ) ), [ view ] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		apiFetch( { path: addQueryArgs( PATH, JSON.parse( key ) ) } )
			.then( response => {
				if ( cancelled ) {
					return;
				}
				setResult( {
					items: response?.items || [],
					total: response?.total || 0,
					pages: response?.pages || 0,
				} );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setResult( { items: [], total: 0, pages: 0 } );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ key ] );

	return { ...result, loading };
}
