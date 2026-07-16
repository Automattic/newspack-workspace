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
import type { CacheableStateKey, State, StoreAction } from '../types';

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

/**
 * Merge a single cached slice into state. A small generic wrapper (rather
 * than a plain `{ ...state, [key]: data }` spread) so the compiler can
 * correlate `key` and `data` to the same slice of `State` -- `key`/`data`
 * come from the same `HydrateAction` payload, but a bare computed-property
 * spread would only see their (uncorrelated) union types.
 */
function hydrateSlice< K extends keyof State >( state: State, key: K, data: State[ K ] ): State {
	return { ...state, [ key ]: data };
}

const reducer = ( state: State | undefined, action: StoreAction ): State => {
	// Just return the appReducer if cache can't be used.
	if ( ! canUseCache() ) {
		return appReducer( state, action );
	}

	let newState: State | undefined;

	if ( action.type === 'HYDRATE' ) {
		// `state` is always already initialized by the time HYDRATE is dispatched
		// (well after the store's initial setup dispatch), but the reducer's own
		// parameter type must allow `undefined` per Redux's `Reducer` signature.
		// The cast doesn't change behavior: spreading `undefined` (`{ ...undefined }`)
		// is a no-op either way.
		newState = hydrateSlice( state as State, action.payload.key, action.payload.data );
	}

	newState = appReducer( newState ?? state, action );

	// Store cacheable state.
	for ( const key in STORAGE_KEYS ) {
		if ( cachedActions[ key as CacheableStateKey ]?.[ action.type ] ) {
			setCache( key, newState[ key as CacheableStateKey ] );
		}
	}

	return newState;
};

export default reducer;
