import type { Filter, InitialViewPatch } from '../types';

const readSearch = ( search?: string ): string => search ?? ( typeof window === 'undefined' ? '' : window.location.search );

interface MakeGetInitialViewConfig {
	/** Map of REST `orderby` values to DataView field IDs. */
	orderbyMap?: Record< string, string >;
	/** Map of `post_status` values to status-filter values. */
	postStatusMap?: Record< string, string > | null;
	/** Map of URL params to DataView filter field IDs. */
	urlParamToFilterField?: Record< string, string > | null;
	/** DataView field ID for the status filter. Defaults to `'status'`. */
	statusFilterField?: string;
	/** `'asc'` or `'desc'`. Defaults to `'desc'`. */
	defaultSortDirection?: 'asc' | 'desc';
}

interface InitialViewAccessors {
	getInitialFilters: ( search?: string ) => Filter[];
	getInitialView: ( search?: string ) => InitialViewPatch;
}

/**
 * Build a `{ getInitialFilters, getInitialView }` pair from URL → view bindings.
 *
 * Config fields are documented on `MakeGetInitialViewConfig` above.
 *
 * @return URL-driven view accessors.
 */
export function makeGetInitialView( {
	orderbyMap = {},
	postStatusMap = null,
	urlParamToFilterField = null,
	statusFilterField = 'status',
	defaultSortDirection = 'desc',
}: MakeGetInitialViewConfig = {} ): InitialViewAccessors {
	const defaultIsAsc = 'asc' === defaultSortDirection;

	function getInitialFilters( search?: string ): Filter[] {
		const params = new URLSearchParams( readSearch( search ) );
		const filters: Filter[] = [];

		if ( postStatusMap ) {
			const postStatus = params.get( 'post_status' );
			if ( postStatus ) {
				const value = postStatusMap[ postStatus ];
				if ( value ) {
					filters.push( { field: statusFilterField, operator: 'isAny', value: [ value ] } );
				}
			}
		}

		if ( urlParamToFilterField ) {
			for ( const [ urlParam, fieldId ] of Object.entries( urlParamToFilterField ) ) {
				const raw = params.get( urlParam );
				if ( ! raw ) {
					continue;
				}
				const values = raw
					.split( ',' )
					.map( v => v.trim() )
					.filter( Boolean );
				if ( values.length > 0 ) {
					filters.push( { field: fieldId, operator: 'isAny', value: values } );
				}
			}
		}

		return filters;
	}

	function getInitialView( search?: string ): InitialViewPatch {
		const resolved = readSearch( search );
		const params = new URLSearchParams( resolved );
		const patch: InitialViewPatch = {};

		const filters = getInitialFilters( resolved );
		if ( filters.length > 0 ) {
			patch.filters = filters;
		}

		const term = params.get( 's' );
		if ( term ) {
			patch.search = term;
		}

		const orderby = params.get( 'orderby' );
		const sortField = orderby && orderbyMap[ orderby ];
		if ( sortField ) {
			const order = ( params.get( 'order' ) || '' ).toLowerCase();
			let direction: 'asc' | 'desc';
			if ( defaultIsAsc ) {
				direction = 'desc' === order ? 'desc' : 'asc';
			} else {
				direction = 'asc' === order ? 'asc' : 'desc';
			}
			patch.sort = { field: sortField, direction };
		}

		return patch;
	}

	return { getInitialFilters, getInitialView };
}
