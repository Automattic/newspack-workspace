import { INITIAL_STATE } from '../constants';
import type { SearchState, StoreAction } from '../types';

export default ( state: SearchState = INITIAL_STATE.search, action: StoreAction ): SearchState => {
	switch ( action.type ) {
		case 'SEARCH_SUCCESS':
			return {
				...state,
				[ action.payload.type ]: action.payload.ids,
			};
		case 'SEARCH_CLEAR':
			return {
				...state,
				[ action.payload.type ]: [],
			};
		default:
			return state;
	}
};
