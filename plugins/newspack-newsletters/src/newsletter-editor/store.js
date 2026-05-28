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

/**
 * External dependencies
 */
import { debounce, sortBy } from 'lodash';

export const STORE_NAMESPACE = 'newspack/newsletters';

const DEFAULT_STATE = {
	hasRetrievedData: false,
	hasRetrievedLists: false,
	hasRetrievedSyncErrors: false,
	isRetrievingData: false,
	isRetrievingLists: false,
	isRetrievingSyncErrors: false,
	isRefreshingHtml: false,
	newsletterData: {},
	shouldSendTest: false,
	error: null,
};
const createAction = type => payload => ( { type, payload } );
const reducer = ( state = DEFAULT_STATE, { type, payload = {} } ) => {
	switch ( type ) {
		case 'SET_IS_RETRIEVING_DATA':
			return { ...state, isRetrievingData: payload };
		case 'SET_IS_RETRIEVING_LISTS':
			return { ...state, isRetrievingLists: payload };
		case 'SET_IS_RETRIEVING_SYNC_ERRORS':
			return { ...state, isRetrievingSyncErrors: payload };
		case 'SET_HAS_RETRIEVED_DATA':
			return { ...state, hasRetrievedData: payload };
		case 'SET_HAS_RETRIEVED_LISTS':
			return { ...state, hasRetrievedLists: payload };
		case 'SET_HAS_RETRIEVED_SYNC_ERRORS':
			return { ...state, hasRetrievedSyncErrors: payload };
		case 'SET_IS_REFRESHING_HTML':
			return { ...state, isRefreshingHtml: payload };
		case 'SET_DATA':
			const updatedNewsletterData = { ...state.newsletterData, ...payload };
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
	setData: createAction( 'SET_DATA' ),
	setError: createAction( 'SET_ERROR' ),
};

const selectors = {
	getIsRetrievingData: state => state.isRetrievingData,
	getIsRetrievingLists: state => state.isRetrievingLists,
	getIsRetrievingSyncErrors: state => state.isRetrievingSyncErrors,
	getHasRetrievedData: state => state.hasRetrievedData,
	getHasRetrievedLists: state => state.hasRetrievedLists,
	getHasRetrievedSyncErrors: state => state.hasRetrievedSyncErrors,
	getIsRefreshingHtml: state => state.isRefreshingHtml,
	getData: state => state.newsletterData || {},
	getError: state => state.error,
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
		const { getIsRetrievingData, getIsRetrievingLists, getIsRetrievingSyncErrors } = select( STORE_NAMESPACE );
		return getIsRetrievingData() || getIsRetrievingLists() || getIsRetrievingSyncErrors();
	} );

// Hook to use the refresh HTML status from any editor component.
export const useIsRefreshingHtml = () => useSelect( select => select( STORE_NAMESPACE ).getIsRefreshingHtml() );

// Hook to use the newsletter data from any editor component.
export const useNewsletterData = () =>
	useSelect( select => {
		const { getData, getIsRetrievingData, getIsRetrievingLists } = select( STORE_NAMESPACE );
		return {
			newsletterData: getData(),
			isRetrievingData: getIsRetrievingData(),
			isRetrievingLists: getIsRetrievingLists(),
			hasRetrievedData: select( STORE_NAMESPACE ).getHasRetrievedData(),
			hasRetrievedLists: select( STORE_NAMESPACE ).getHasRetrievedLists(),
		};
	} );

// Hook to use newsletter data fetch errors from any editor component.
export const useNewsletterDataError = () => useSelect( select => select( STORE_NAMESPACE ).getError() );

// Dispatcher to update data retrieval status in the store.
export const updateIsRetrievingData = isRetrieving => dispatch( STORE_NAMESPACE ).setIsRetrievingData( isRetrieving );

// Dispatcher to update data retrieval status in the store.
export const updateIsRetrievingLists = isRetrieving => dispatch( STORE_NAMESPACE ).setIsRetrievingLists( isRetrieving );

// Dispatcher to update error retrieval status in the store.
export const updateIsRetrievingSyncErrors = isRetrieving => dispatch( STORE_NAMESPACE ).setIsRetrievingSyncErrors( isRetrieving );

// Dispatcher to update data retrieved status in the store.
export const updateHasRetrievedData = hasRetrieved => dispatch( STORE_NAMESPACE ).setHasRetrievedData( hasRetrieved );

// Dispatcher to update lists retrieved status in the store.
export const updateHasRetrievedLists = hasRetrieved => dispatch( STORE_NAMESPACE ).setHasRetrievedLists( hasRetrieved );

// Dispatcher to update sync errors retrieved status in the store.
export const updateHasRetrievedSyncErrors = hasRetrieved => dispatch( STORE_NAMESPACE ).setHasRetrievedSyncErrors( hasRetrieved );

// Dispatcher to update refreshing HTML status in the store.
export const updateIsRefreshingHtml = isRetrieving => dispatch( STORE_NAMESPACE ).setIsRefreshingHtml( isRetrieving );

// Dispatcher to update newsletter data in the store.
export const updateNewsletterData = data => dispatch( STORE_NAMESPACE ).setData( data );

// Dispatcher to update newsletter error in the store.
export const updateNewsletterDataError = error => dispatch( STORE_NAMESPACE ).setError( error );

// Dispatcher to fetch newsletter data from the server.
export const fetchNewsletterData = async postId => {
	if ( ! isSupportedESP() || isManualESP() ) {
		return;
	}

	const isRetrieving = coreSelect( STORE_NAMESPACE ).getIsRetrievingData();
	if ( isRetrieving ) {
		return;
	}
	updateHasRetrievedData( false );
	updateIsRetrievingData( true );
	updateNewsletterDataError( null );
	try {
		const { name } = getServiceProvider();
		const response = await apiFetch( {
			path: `/newspack-newsletters/v1/${ name }/${ postId }/retrieve`,
		} );

		// If we've already fetched list or sublist info, retain it.
		const newsletterData = coreSelect( STORE_NAMESPACE ).getData();
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
export const fetchSyncErrors = async postId => {
	if ( ! isSupportedESP() || isManualESP() ) {
		return;
	}

	const isRetrieving = coreSelect( STORE_NAMESPACE ).getIsRetrievingSyncErrors();
	if ( isRetrieving ) {
		return;
	}
	updateIsRetrievingSyncErrors( true );
	updateNewsletterDataError( null );
	try {
		const response = await apiFetch( {
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
export const fetchSendLists = debounce( async ( opts, replace = false ) => {
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

		const newsletterData = coreSelect( STORE_NAMESPACE ).getData();
		const sendLists = 'list' === args.type ? [ ...newsletterData?.lists ] || [] : [ ...newsletterData?.sublists ] || [];

		// If we already have a matching result, no need to fetch more.
		const foundItems = sendLists.filter( item => {
			const ids = args.ids && ! Array.isArray( args.ids ) ? [ args.ids ] : args.ids;
			const search = args.search && ! Array.isArray( args.search ) ? [ args.search ] : args.search;
			let found = false;
			if ( ids?.length ) {
				ids.forEach( id => {
					found = item.id.toString() === id.toString();
				} );
			}
			if ( search?.length ) {
				search.forEach( term => {
					if ( item.label.toLowerCase().includes( term.toLowerCase() ) ) {
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
		const updatedSendLists = replace ? [] : [ ...sendLists ];

		// If no existing items found, fetch from the ESP.
		const isRetrieving = coreSelect( STORE_NAMESPACE ).getIsRetrievingLists();
		if ( isRetrieving ) {
			return;
		}
		updateHasRetrievedLists( false );
		updateIsRetrievingLists( true );
		const response = await apiFetch( {
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
