function asArray( value ) {
	if ( Array.isArray( value ) ) {
		return value;
	}
	if ( value === undefined || value === null || value === '' ) {
		return [];
	}
	return [ value ];
}

/**
 * Translate a DataViews `view` into a flat REST params object.
 *
 * @param {Object}                                    view                         DataViews view state.
 * @param {Object}                                    options                      Screen-specific bindings.
 * @param {Object}                                    [options.fieldToQueryParam]  Map of non-status filter fields → REST query params.
 * @param {Object}                                    [options.sortFieldToOrderby] Map of view.sort.field → REST `orderby`. When omitted, sort is pass-through.
 * @param {number}                                    [options.defaultPerPage]     Default `per_page` when `view.perPage` is missing. Defaults to 25.
 * @param {string}                                    [options.defaultStatuses]    Comma-joined statuses to use when no status filter is active.
 * @param {string}                                    [options.statusFilterParam]  REST param to receive status-filter values. When omitted, `status` is used.
 * @param {string}                                    [options.defaultStatusParam] REST param to receive `defaultStatuses`. Defaults to `'status'`.
 * @param {Object}                                    [options.extraParams]        Fixed params merged into the result (e.g. `{ _embed: 'author,wp:term' }`).
 * @param {boolean}                                   [options.supportsOffset]     When `true`, `view.offset` overrides `page` so callers can address mid-collection windows.
 * @param {Array<{ viewKey: string, param: string }>} [options.arrayParams]        Pass-through bindings for array-valued view fields (e.g. `view.author`).
 * @return {Object} Flat REST query params.
 */
export function buildQueryParams( view = {}, options = {} ) {
	const {
		fieldToQueryParam = {},
		sortFieldToOrderby = null,
		defaultPerPage = 25,
		defaultStatuses = null,
		statusFilterParam = null,
		defaultStatusParam = 'status',
		extraParams = {},
		supportsOffset = false,
		arrayParams = [],
	} = options;

	const params = {
		per_page: view.perPage || defaultPerPage,
		context: 'edit',
		...extraParams,
	};

	if ( supportsOffset && typeof view.offset === 'number' ) {
		params.offset = view.offset;
	} else {
		params.page = view.page || 1;
	}

	if ( view.search ) {
		params.search = view.search;
	}

	if ( view.sort?.field ) {
		const orderby = sortFieldToOrderby ? sortFieldToOrderby[ view.sort.field ] : view.sort.field;
		if ( orderby ) {
			params.orderby = orderby;
			params.order = view.sort.direction === 'asc' ? 'asc' : 'desc';
		}
	}

	const filters = Array.isArray( view.filters ) ? view.filters : [];
	const statusFilter = filters.find( filter => filter.field === 'status' );

	if ( statusFilter ) {
		const values = asArray( statusFilter.value );
		if ( values.length > 0 ) {
			params[ statusFilterParam || 'status' ] = values.join( ',' );
		} else if ( defaultStatuses ) {
			params[ defaultStatusParam ] = defaultStatuses;
		}
	} else if ( defaultStatuses ) {
		params[ defaultStatusParam ] = defaultStatuses;
	}

	for ( const filter of filters ) {
		if ( filter.field === 'status' ) {
			continue;
		}
		const param = fieldToQueryParam[ filter.field ];
		if ( ! param ) {
			continue;
		}
		const values = asArray( filter.value );
		if ( values.length === 0 ) {
			continue;
		}
		params[ param ] = values.join( ',' );
	}

	for ( const { viewKey, param } of arrayParams ) {
		const value = view[ viewKey ];
		if ( Array.isArray( value ) && value.length > 0 ) {
			params[ param ] = value.join( ',' );
		}
	}

	return params;
}

/**
 * Serialise a params object into a query string suitable for apiFetch's `path`.
 *
 * @param {Object} params Flat key/value params.
 * @return {string} Query string starting with `?`.
 */
export function toQueryString( params ) {
	const search = new URLSearchParams();
	Object.entries( params ).forEach( ( [ key, value ] ) => {
		if ( value === undefined || value === null || value === '' ) {
			return;
		}
		search.append( key, String( value ) );
	} );
	return `?${ search.toString() }`;
}
