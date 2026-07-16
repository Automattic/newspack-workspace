/**
 * A Redux store for ESP newsletter data to be used across editor components.
 * This store is a centralized place for all data fetched from or updated via the ESP's API.
 *
 * Import use* hooks to read store data from any component.
 * Import fetch* hooks to fetch updated ESP data from any component.
 * Import update* hooks to update store data from any component.
 */

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, dispatch, register, useSelect, select as coreSelect } from '@wordpress/data';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { isManualESP, isSupportedESP } from './utils';
import { getServiceProvider } from '../service-providers';
import type { NewsletterData, SendList } from '../service-providers/types';

/**
 * External dependencies
 */
import { debounce, sortBy } from 'lodash';

export const STORE_NAMESPACE = 'newspack/newsletters';

/** The shape of the newsletter-editor Redux store state. */
interface NewsletterState {
	hasRetrievedData: boolean;
	hasRetrievedLists: boolean;
	hasRetrievedSyncErrors: boolean;
	isRetrievingData: boolean;
	isRetrievingLists: boolean;
	isRetrievingSyncErrors: boolean;
	isRefreshingHtml: boolean;
	lastRefreshHadError: boolean;
	newsletterData: NewsletterData;
	shouldSendTest: boolean;
	error: unknown;
}

/** A dispatched store action. Payload is dynamic per action type. */
interface Action {
	type: string;
	payload?: unknown;
}

/** Options accepted by the debounced `fetchSendLists` dispatcher. */
interface FetchSendListsOpts {
	ids?: string | number | Array< string | number >;
	search?: string | Array< string >;
	type?: string;
	parent_id?: string | number;
	provider?: string;
}

const DEFAULT_STATE: NewsletterState = {
	hasRetrievedData: false,
	hasRetrievedLists: false,
	hasRetrievedSyncErrors: false,
	isRetrievingData: false,
	isRetrievingLists: false,
	isRetrievingSyncErrors: false,
	isRefreshingHtml: false,
	// Pairs with `isRefreshingHtml` so consumers waiting on the
	// true→false transition can tell success from failure.
	lastRefreshHadError: false,
	newsletterData: {},
	shouldSendTest: false,
	error: null,
};
const createAction =
	( type: string ) =>
	( payload: unknown ): Action => ( { type, payload } );
const reducer = ( state: NewsletterState = DEFAULT_STATE, { type, payload = {} }: Action ): NewsletterState => {
	switch ( type ) {
		case 'SET_IS_RETRIEVING_DATA':
			return { ...state, isRetrievingData: payload as boolean };
		case 'SET_IS_RETRIEVING_LISTS':
			return { ...state, isRetrievingLists: payload as boolean };
		case 'SET_IS_RETRIEVING_SYNC_ERRORS':
			return { ...state, isRetrievingSyncErrors: payload as boolean };
		case 'SET_HAS_RETRIEVED_DATA':
			return { ...state, hasRetrievedData: payload as boolean };
		case 'SET_HAS_RETRIEVED_LISTS':
			return { ...state, hasRetrievedLists: payload as boolean };
		case 'SET_HAS_RETRIEVED_SYNC_ERRORS':
			return { ...state, hasRetrievedSyncErrors: payload as boolean };
		case 'SET_IS_REFRESHING_HTML':
			return { ...state, isRefreshingHtml: payload as boolean };
		case 'SET_LAST_REFRESH_HAD_ERROR':
			return { ...state, lastRefreshHadError: payload as boolean };
		case 'SET_DATA':
			const updatedNewsletterData = { ...state.newsletterData, ...( payload as Partial< NewsletterData > ) };
			return { ...state, newsletterData: updatedNewsletterData };
		case 'SET_ERROR':
			return { ...state, error: payload };
		default:
			return state;
	}
};

const actions = {
	// Regular actions.
	setIsRetrievingData: createAction( 'SET_IS_RETRIEVING_DATA' ),
	setIsRetrievingLists: createAction( 'SET_IS_RETRIEVING_LISTS' ),
	setIsRetrievingSyncErrors: createAction( 'SET_IS_RETRIEVING_SYNC_ERRORS' ),
	setHasRetrievedData: createAction( 'SET_HAS_RETRIEVED_DATA' ),
	setHasRetrievedLists: createAction( 'SET_HAS_RETRIEVED_LISTS' ),
	setHasRetrievedSyncErrors: createAction( 'SET_HAS_RETRIEVED_SYNC_ERRORS' ),
	setIsRefreshingHtml: createAction( 'SET_IS_REFRESHING_HTML' ),
	setLastRefreshHadError: createAction( 'SET_LAST_REFRESH_HAD_ERROR' ),
	setData: createAction( 'SET_DATA' ),
	setError: createAction( 'SET_ERROR' ),
};

const selectors = {
	getIsRetrievingData: ( state: NewsletterState ) => state.isRetrievingData,
	getIsRetrievingLists: ( state: NewsletterState ) => state.isRetrievingLists,
	getIsRetrievingSyncErrors: ( state: NewsletterState ) => state.isRetrievingSyncErrors,
	getHasRetrievedData: ( state: NewsletterState ) => state.hasRetrievedData,
	getHasRetrievedLists: ( state: NewsletterState ) => state.hasRetrievedLists,
	getHasRetrievedSyncErrors: ( state: NewsletterState ) => state.hasRetrievedSyncErrors,
	getIsRefreshingHtml: ( state: NewsletterState ) => state.isRefreshingHtml,
	getLastRefreshHadError: ( state: NewsletterState ) => state.lastRefreshHadError,
	getData: ( state: NewsletterState ) => state.newsletterData || {},
	getError: ( state: NewsletterState ) => state.error,
};

const store = createReduxStore( STORE_NAMESPACE, {
	reducer,
	actions,
	selectors,
} );

// Register the editor store.
export const registerStore = () => register( store );

// Hook to use the retrieval status from any editor component.
export const useIsRetrieving = () =>
	useSelect( select => {
		const { getIsRetrievingData, getIsRetrievingLists, getIsRetrievingSyncErrors } = select( store );
		return getIsRetrievingData() || getIsRetrievingLists() || getIsRetrievingSyncErrors();
	} );

// Hook to use the refresh HTML status from any editor component.
export const useIsRefreshingHtml = () => useSelect( select => select( store ).getIsRefreshingHtml() );

// Hook to read whether the most recent refresh-HTML cycle ended in error.
export const useLastRefreshHadError = () => useSelect( select => select( store ).getLastRefreshHadError() );

// Hook to use the newsletter data from any editor component.
export const useNewsletterData = () =>
	useSelect( select => {
		const { getData, getIsRetrievingData, getIsRetrievingLists } = select( store );
		return {
			newsletterData: getData(),
			isRetrievingData: getIsRetrievingData(),
			isRetrievingLists: getIsRetrievingLists(),
			hasRetrievedData: select( store ).getHasRetrievedData(),
			hasRetrievedLists: select( store ).getHasRetrievedLists(),
		};
	} );

// Hook to use newsletter data fetch errors from any editor component.
export const useNewsletterDataError = () => useSelect( select => select( store ).getError() );

// Dispatcher to update data retrieval status in the store.
export const updateIsRetrievingData = ( isRetrieving: boolean ) => dispatch( store ).setIsRetrievingData( isRetrieving );

// Dispatcher to update data retrieval status in the store.
export const updateIsRetrievingLists = ( isRetrieving: boolean ) => dispatch( store ).setIsRetrievingLists( isRetrieving );

// Dispatcher to update error retrieval status in the store.
export const updateIsRetrievingSyncErrors = ( isRetrieving: boolean ) => dispatch( store ).setIsRetrievingSyncErrors( isRetrieving );

// Dispatcher to update data retrieved status in the store.
export const updateHasRetrievedData = ( hasRetrieved: boolean ) => dispatch( store ).setHasRetrievedData( hasRetrieved );

// Dispatcher to update lists retrieved status in the store.
export const updateHasRetrievedLists = ( hasRetrieved: boolean ) => dispatch( store ).setHasRetrievedLists( hasRetrieved );

// Dispatcher to update sync errors retrieved status in the store.
export const updateHasRetrievedSyncErrors = ( hasRetrieved: boolean ) => dispatch( store ).setHasRetrievedSyncErrors( hasRetrieved );

// Dispatcher to update refreshing HTML status in the store.
export const updateIsRefreshingHtml = ( isRetrieving: boolean ) => dispatch( store ).setIsRefreshingHtml( isRetrieving );

// Dispatcher to record whether the most recent refresh ended in error.
export const updateLastRefreshHadError = ( hadError: boolean ) => dispatch( store ).setLastRefreshHadError( hadError );

// Dispatcher to update newsletter data in the store.
export const updateNewsletterData = ( data: Partial< NewsletterData > ) => dispatch( store ).setData( data );

// Dispatcher to update newsletter error in the store.
export const updateNewsletterDataError = ( error: unknown ) => dispatch( store ).setError( error );

// Dispatcher to fetch newsletter data from the server.
export const fetchNewsletterData = async ( postId: number ) => {
	if ( ! isSupportedESP() || isManualESP() ) {
		return;
	}

	const isRetrieving = coreSelect( store ).getIsRetrievingData();
	if ( isRetrieving ) {
		return;
	}
	updateHasRetrievedData( false );
	updateIsRetrievingData( true );
	updateNewsletterDataError( null );
	try {
		const { name } = getServiceProvider();
		const response = await apiFetch< NewsletterData >( {
			path: `/newspack-newsletters/v1/${ name }/${ postId }/retrieve`,
		} );

		// If we've already fetched list or sublist info, retain it.
		const newsletterData = coreSelect( store ).getData();
		const updatedNewsletterData = { ...response };
		if ( newsletterData?.lists ) {
			updatedNewsletterData.lists = newsletterData.lists;
		}
		if ( newsletterData?.sublists ) {
			updatedNewsletterData.sublists = newsletterData.sublists;
		}
		updateNewsletterData( updatedNewsletterData );
		updateHasRetrievedData( true );
	} catch ( error ) {
		updateNewsletterDataError( error );
		updateHasRetrievedData( false );
	}
	updateIsRetrievingData( false );
	return true;
};

// Dispatcher to fetch any errors from the most recent sync attempt.
export const fetchSyncErrors = async ( postId: number ) => {
	if ( ! isSupportedESP() || isManualESP() ) {
		return;
	}

	const isRetrieving = coreSelect( store ).getIsRetrievingSyncErrors();
	if ( isRetrieving ) {
		return;
	}
	updateIsRetrievingSyncErrors( true );
	updateNewsletterDataError( null );
	try {
		const response = await apiFetch< { message?: string } >( {
			path: `/newspack-newsletters/v1/${ postId }/sync-error`,
		} );
		if ( response?.message ) {
			updateNewsletterDataError( response );
		}
	} catch ( error ) {
		updateNewsletterDataError( error );
	}
	updateIsRetrievingSyncErrors( false );
	return true;
};

// Dispatcher to fetch send lists and sublists from the connected ESP and update the newsletterData in store.
export const fetchSendLists = debounce( async ( opts?: FetchSendListsOpts, replace = false ) => {
	if ( ! isSupportedESP() || isManualESP() ) {
		return [];
	}

	updateNewsletterDataError( null );
	try {
		const { name } = getServiceProvider();
		const args = {
			type: 'list',
			provider: name,
			...opts,
		};

		const newsletterData = coreSelect( store ).getData();
		const sendLists = 'list' === args.type ? [ ...( newsletterData?.lists || [] ) ] : [ ...( newsletterData?.sublists || [] ) ];

		// If we already have a matching result, no need to fetch more.
		const foundItems = sendLists.filter( item => {
			// Normalize the dynamic `ids`/`search` args (scalar or array) to arrays.
			const ids = ( args.ids && ! Array.isArray( args.ids ) ? [ args.ids ] : args.ids ) as Array< string | number > | undefined;
			const search = ( args.search && ! Array.isArray( args.search ) ? [ args.search ] : args.search ) as string[] | undefined;
			let found = false;
			if ( ids?.length ) {
				ids.forEach( id => {
					found = item.id.toString() === id.toString();
				} );
			}
			if ( search?.length ) {
				search.forEach( term => {
					// Send-list items always carry a label from the ESP; the shared type marks it optional.
					if ( ( item.label as string ).toLowerCase().includes( term.toLowerCase() ) ) {
						found = true;
					}
				} );
			}

			return found;
		} );

		if ( foundItems.length ) {
			return sendLists;
		}

		const updatedNewsletterData = { ...newsletterData };
		const updatedSendLists: SendList[] = replace ? [] : [ ...sendLists ];

		// If no existing items found, fetch from the ESP.
		const isRetrieving = coreSelect( store ).getIsRetrievingLists();
		if ( isRetrieving ) {
			return;
		}
		updateHasRetrievedLists( false );
		updateIsRetrievingLists( true );
		const response = await apiFetch< SendList[] >( {
			path: addQueryArgs( '/newspack-newsletters/v1/send-lists', args ),
		} );

		response.forEach( item => {
			if ( ! updatedSendLists.find( listItem => listItem.id === item.id ) ) {
				updatedSendLists.push( item );
			}
		} );
		if ( 'list' === args.type ) {
			updatedNewsletterData.lists = sortBy( updatedSendLists, 'label' );
		} else {
			updatedNewsletterData.sublists = sortBy( updatedSendLists, 'label' );
		}

		updateNewsletterData( updatedNewsletterData );
		updateHasRetrievedLists( true );
	} catch ( error ) {
		updateNewsletterDataError( error );
		updateHasRetrievedLists( false );
	}
	updateIsRetrievingLists( false );
}, 500 );
