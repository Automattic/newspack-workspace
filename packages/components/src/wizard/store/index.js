/**
 * External dependencies.
 */
import set from 'lodash/set';
import get from 'lodash/get';
import isEmpty from 'lodash/isEmpty';

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register, dispatch, select } from '@wordpress/data';

import { createAction } from './utils.js';

export const WIZARD_STORE_NAMESPACE = 'newspack/wizards';

const DEFAULT_STATE = {
	headerData: {
		actions: [],
		backNav: '',
		badges: [],
		sectionDescription: '',
		sectionName: '',
		sectionTitle: '',
	},
	isLoading: false,
	isQuietLoading: false,
	apiData: {},
	notices: [],
	error: null,
};

/**
 * wordpress/data does not trigger a component re-render
 * on deep state change (via lodash's set function)
 * unless the state was cloned first.
 */
const clone = objectToClone => JSON.parse( JSON.stringify( objectToClone ) );

const reducer = ( state = DEFAULT_STATE, { type, payload = {} } ) => {
	switch ( type ) {
		case 'SET_HEADER_DATA':
			return { ...state, headerData: { ...state.headerData, ...payload } };
		case 'RESET_HEADER_DATA':
			return { ...state, headerData: { ...DEFAULT_STATE.headerData } };
		case 'START_LOADING_DATA':
			if ( payload.isQuietLoading ) {
				return { ...state, isQuietLoading: true };
			}
			return { ...state, isLoading: true };
		case 'FINISH_LOADING_DATA':
			return { ...state, isLoading: false, isQuietLoading: false };
		case 'SET_API_DATA':
			return { ...state, apiData: set( clone( state.apiData ), [ payload.slug ], payload.data ) };
		case 'UPDATE_WIZARD_SETTINGS':
			return { ...state, apiData: set( clone( state.apiData ), [ payload.slug, ...payload.path ], payload.value ) };
		case 'ADD_NOTICE':
			return { ...state, notices: [ ...state.notices, payload ] };
		case 'REMOVE_NOTICE':
			return { ...state, notices: state.notices.filter( notice => notice.id !== payload ) };
		case 'SET_ERROR':
			return { ...state, error: payload };
		case 'RESET_NOTICES':
			return { ...state, notices: DEFAULT_STATE.notices };
		default:
			return state;
	}
};

const actions = {
	// Regular actions.
	setHeaderData: createAction( 'SET_HEADER_DATA' ),
	resetHeaderData: createAction( 'RESET_HEADER_DATA' ),
	startLoadingData: createAction( 'START_LOADING_DATA' ),
	finishLoadingData: createAction( 'FINISH_LOADING_DATA' ),
	fetchFromAPI: createAction( 'FETCH_FROM_API' ),
	setAPIDataForWizard: createAction( 'SET_API_DATA' ),
	updateWizardSettings: createAction( 'UPDATE_WIZARD_SETTINGS' ),
	addNotice: createAction( 'ADD_NOTICE' ),
	removeNotice: createAction( 'REMOVE_NOTICE' ),
	resetNotices: createAction( 'RESET_NOTICES' ),
	setError: createAction( 'SET_ERROR' ),

	// Async actions. These will not show up in Redux devtools.
	*saveWizardSettings( { slug, section = '', payloadPath = false, auxData = {}, updatePayload = null } ) {
		// Optionally data can be updated before saving - an immediate update case
		// (without an explicit "save" action).
		if ( updatePayload ) {
			yield actions.updateWizardSettings( { slug, ...updatePayload } );
		}
		const wizardState = select( WIZARD_STORE_NAMESPACE ).getWizardAPIData( slug );
		const data = payloadPath ? get( wizardState, payloadPath ) : wizardState;
		const updatedData = yield actions.fetchFromAPI( {
			path: `/newspack/v1/wizard/${ slug }/${ section }`,
			method: 'POST',
			data: { ...data, ...auxData },
			isQuietFetch: true,
		} );
		if ( ! isEmpty( updatedData ) && ! updatedData.error ) {
			return actions.setAPIDataForWizard( { slug, data: updatedData } );
		}
	},
	*wizardApiFetch( fetchConfig ) {
		// Just a proxy to fetchFromAPI, but it has to be a generator.
		const result = yield actions.fetchFromAPI( fetchConfig );
		return result;
	},
};

const selectors = {
	getHeaderData: state => state.headerData,
	isLoading: state => state.isLoading,
	isQuietLoading: state => state.isQuietLoading,
	getWizardAPIData: ( state, slug ) => state.apiData[ slug ] || {},
	getWizardData: ( state, slug ) => state.apiData[ slug ] ?? {},
	getNotices: state => state.notices,
	getError: state => state.error,
};

const store = createReduxStore( WIZARD_STORE_NAMESPACE, {
	reducer,
	actions,
	selectors,

	controls: {
		FETCH_FROM_API: action => {
			const { isLocalError = false, isQuietFetch = false } = action.payload;
			dispatch( WIZARD_STORE_NAMESPACE ).startLoadingData( {
				isQuietLoading: Boolean( isQuietFetch ),
			} );
			return apiFetch( action.payload )
				.then( data => {
					dispatch( WIZARD_STORE_NAMESPACE ).setError( null );
					return data;
				} )
				.catch( error => {
					if ( isLocalError ) {
						throw error;
					}
					dispatch( WIZARD_STORE_NAMESPACE ).setError( error );
				} )
				.finally( result => {
					dispatch( WIZARD_STORE_NAMESPACE ).finishLoadingData();
					return result;
				} );
		},
	},

	resolvers: {
		*getWizardAPIData( slug ) {
			if ( slug ) {
				const data = yield actions.fetchFromAPI( {
					path: `/newspack/v1/wizard/${ slug }`,
				} );
				return actions.setAPIDataForWizard( { slug, data } );
			}
			return actions.finishLoadingData();
		},
	},
} );

export default () => register( store );
