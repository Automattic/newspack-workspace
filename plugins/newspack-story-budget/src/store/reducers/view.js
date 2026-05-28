export const actions = {
	VIEW_SET: 'VIEW_SET',
	FIELDS_SET: 'FIELDS_SET',
};

export default ( state = {}, action ) => {
	switch ( action.type ) {
		case 'HYDRATE':
			if ( action.payload.key === 'view' ) {
				return {
					...action.payload.data,
					// Reset page, filters, and search when hydrating from cache.
					page: 1,
					filters: [],
					search: '',
				};
			}
			return state;
		case actions.VIEW_SET:
			return action.payload;
		case actions.FIELDS_SET:
			if ( state.fields?.length ) {
				return state;
			}
			return {
				...state,
				fields: action.payload.filter( field => field.show_in_table ).map( field => field.slug ),
			};
		default:
			return state;
	}
};
