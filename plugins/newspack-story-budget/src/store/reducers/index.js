import { combineReducers } from 'redux';

import budgets from './budgets';
import stories from './stories';
import fields from './fields';
import search from './search';
import meta from './meta';
import view from './view';
import budgetsView from './budgets-view';
import errors from './errors';

import { STORAGE_KEYS, setCache, canUseCache } from '../cache';
import cachedActions from '../utils/cached-actions';

const appReducer = combineReducers( {
	budgets,
	stories,
	fields,
	search,
	meta,
	view,
	budgetsView,
	errors,
} );

const reducer = ( state, action ) => {
	// Just return the appReducer if cache can't be used.
	if ( ! canUseCache() ) {
		return appReducer( state, action );
	}

	let newState;

	if ( action.type === 'HYDRATE' ) {
		newState = {
			...state,
			...{
				[ action.payload.key ]: action.payload.data,
			},
		};
	}

	newState = appReducer( newState ?? state, action );

	// Store cacheable state.
	for ( const key in STORAGE_KEYS ) {
		if ( cachedActions[ key ]?.[ action.type ] ) {
			setCache( key, newState[ key ] );
		}
	}

	return newState;
};

export default reducer;
