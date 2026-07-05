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
import type { APIFetchOptions } from '@wordpress/api-fetch';
import { createReduxStore, register, dispatch, select } from '@wordpress/data';

import { createAction } from './utils';

export const WIZARD_STORE_NAMESPACE = 'newspack/wizards';

/**
 * An error returned by a wizard API request.
 */
export type WizardApiError = {
	message?: string;
	code?: string;
	data?: { level?: string };
} | null;

/**
 * A wizard header action button.
 */
export interface WizardHeaderAction {
	/** Where the action is displayed: as a main button, or in the "more" menu. */
	type: 'primary' | 'secondary' | 'more';
	/** The action's label. */
	label: React.ReactNode;
	/** URL the action links to. */
	href?: string;
	/** The action's icon: an element, or the name of a registered icon. */
	icon?: string | JSX.Element | null;
	/** Called when the action is clicked. */
	action?(): void;
	/** Whether the action is disabled. */
	disabled?: boolean;
	/** Whether the action is destructive. */
	destructive?: boolean;
}

/**
 * A badge displayed next to the wizard section title.
 */
export interface WizardBadge {
	/** The badge's text. */
	label: string;
	/** Badge level, e.g., 'success', 'info', 'warning', 'error'. */
	level?: 'default' | 'info' | 'success' | 'warning' | 'error';
}

/**
 * An item of the wizard section's more-options menu.
 */
export interface WizardMenuItem {
	/** The menu item's label. */
	label: React.ReactNode;
	/** Icon displayed next to the label. */
	icon?: JSX.Element | string;
	/** URL the menu item links to. */
	href?: string;
	/** Called when the menu item is clicked. */
	action?(): void;
	/** Whether the menu item is disabled. */
	disabled?: boolean;
	/** Whether the menu item is destructive. */
	destructive?: boolean;
}

/**
 * A wizard section action (primary or secondary).
 */
export interface WizardSectionAction {
	/** The action's label. */
	label: React.ReactNode;
	/** URL the action links to. */
	href?: string;
	/** Called when the action is clicked. */
	action?(): void;
}

/**
 * Data rendered in the wizard header and section header, set by the active
 * section via the `setHeaderData` action.
 */
export interface WizardHeaderData {
	actions?: WizardHeaderAction[];
	backNav?: string;
	badges?: WizardBadge[];
	sectionDescription?: React.ReactNode | ( () => React.ReactNode );
	sectionName?: string;
	sectionTitle?: string;
	sectionMenu?: WizardMenuItem[];
	sectionPrimaryAction?: WizardSectionAction;
	sectionSecondaryAction?: WizardSectionAction;
}

/**
 * A snackbar notice displayed by the wizard.
 */
export interface WizardNotice {
	/** Unique ID, used to remove the notice. */
	id?: string;
	/** The notice type: 'info', 'success', 'warning', or 'error'. */
	type?: string;
	/** The notice content. */
	message?: React.ReactNode;
	/** Action buttons displayed in the snackbar. */
	actions?: { label: string; url?: string; onClick?(): void }[];
}

/**
 * A single wizard's API data. The shape depends on the wizard.
 */
export type WizardData = Record< string, unknown >;

export interface WizardsState {
	headerData: WizardHeaderData;
	isLoading: boolean;
	isQuietLoading: boolean;
	apiData: Record< string, WizardData >;
	notices: WizardNotice[];
	error: WizardApiError;
}

/**
 * Configuration for a wizard API fetch: `apiFetch` options plus wizard
 * loading/error-handling flags.
 */
export type WizardApiFetchConfig = APIFetchOptions & {
	/** Whether errors are left to the caller instead of being set on the store. */
	isLocalError?: boolean;
	/** Whether to use the quiet loading state. */
	isQuietFetch?: boolean;
};

type SaveWizardSettingsConfig = {
	/** The wizard's slug. */
	slug: string;
	/** The settings section to save. */
	section?: string;
	/** Path (lodash `get`-style) to the payload within the wizard data. */
	payloadPath?: ( string | number )[] | false;
	/** Additional data merged into the payload. */
	auxData?: Record< string, unknown >;
	/** Optional wizard-data update applied before saving. */
	updatePayload?: { path: ( string | number )[]; value: unknown } | null;
};

const DEFAULT_STATE: WizardsState = {
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

type WizardReducerAction =
	| { type: 'SET_HEADER_DATA'; payload?: WizardHeaderData }
	| { type: 'RESET_HEADER_DATA'; payload?: undefined }
	| { type: 'START_LOADING_DATA'; payload?: { isQuietLoading?: boolean } }
	| { type: 'FINISH_LOADING_DATA'; payload?: undefined }
	| { type: 'SET_API_DATA'; payload: { slug: string; data?: WizardData } }
	| { type: 'UPDATE_WIZARD_SETTINGS'; payload: { slug: string; path: ( string | number )[]; value: unknown } }
	| { type: 'ADD_NOTICE'; payload: WizardNotice }
	| { type: 'REMOVE_NOTICE'; payload?: string }
	| { type: 'SET_ERROR'; payload?: WizardApiError }
	| { type: 'RESET_NOTICES'; payload?: undefined };

/**
 * wordpress/data does not trigger a component re-render
 * on deep state change (via lodash's set function)
 * unless the state was cloned first.
 */
const clone = < T >( objectToClone: T ): T => JSON.parse( JSON.stringify( objectToClone ) );

const reducer = ( state: WizardsState = DEFAULT_STATE, action: WizardReducerAction ): WizardsState => {
	switch ( action.type ) {
		case 'SET_HEADER_DATA':
			return { ...state, headerData: { ...state.headerData, ...action.payload } };
		case 'RESET_HEADER_DATA':
			return { ...state, headerData: { ...DEFAULT_STATE.headerData } };
		case 'START_LOADING_DATA':
			if ( action.payload?.isQuietLoading ) {
				return { ...state, isQuietLoading: true };
			}
			return { ...state, isLoading: true };
		case 'FINISH_LOADING_DATA':
			return { ...state, isLoading: false, isQuietLoading: false };
		case 'SET_API_DATA':
			return { ...state, apiData: set( clone( state.apiData ), [ action.payload.slug ], action.payload.data ) };
		case 'UPDATE_WIZARD_SETTINGS':
			return { ...state, apiData: set( clone( state.apiData ), [ action.payload.slug, ...action.payload.path ], action.payload.value ) };
		case 'ADD_NOTICE':
			return { ...state, notices: [ ...state.notices, action.payload ] };
		case 'REMOVE_NOTICE':
			return { ...state, notices: state.notices.filter( notice => notice.id !== action.payload ) };
		case 'SET_ERROR':
			return { ...state, error: action.payload ?? null };
		case 'RESET_NOTICES':
			return { ...state, notices: DEFAULT_STATE.notices };
		default:
			return state;
	}
};

const actions = {
	// Regular actions.
	setHeaderData: createAction< WizardHeaderData >( 'SET_HEADER_DATA' ),
	resetHeaderData: createAction( 'RESET_HEADER_DATA' ),
	startLoadingData: createAction< { isQuietLoading?: boolean } >( 'START_LOADING_DATA' ),
	finishLoadingData: createAction( 'FINISH_LOADING_DATA' ),
	fetchFromAPI: createAction< WizardApiFetchConfig >( 'FETCH_FROM_API' ),
	setAPIDataForWizard: createAction< { slug: string; data?: WizardData } >( 'SET_API_DATA' ),
	updateWizardSettings: createAction< { slug: string; path: ( string | number )[]; value: unknown } >( 'UPDATE_WIZARD_SETTINGS' ),
	addNotice: createAction< WizardNotice >( 'ADD_NOTICE' ),
	removeNotice: createAction< string >( 'REMOVE_NOTICE' ),
	resetNotices: createAction( 'RESET_NOTICES' ),
	setError: createAction< WizardApiError >( 'SET_ERROR' ),

	// Async actions. These will not show up in Redux devtools.
	*saveWizardSettings( {
		slug,
		section = '',
		payloadPath = false,
		auxData = {},
		updatePayload = null,
	}: SaveWizardSettingsConfig ): Generator< unknown, unknown, ( WizardData & { error?: unknown } ) | undefined > {
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
		if ( ! isEmpty( updatedData ) && ! updatedData?.error ) {
			return actions.setAPIDataForWizard( { slug, data: updatedData } );
		}
	},
	*wizardApiFetch( fetchConfig: WizardApiFetchConfig ): Generator< unknown, unknown, unknown > {
		// Just a proxy to fetchFromAPI, but it has to be a generator.
		const result = yield actions.fetchFromAPI( fetchConfig );
		return result;
	},
};

const selectors = {
	getHeaderData: ( state: WizardsState ) => state.headerData,
	isLoading: ( state: WizardsState ) => state.isLoading,
	isQuietLoading: ( state: WizardsState ) => state.isQuietLoading,
	getWizardAPIData: ( state: WizardsState, slug: string ) => state.apiData[ slug ] || {},
	getWizardData: ( state: WizardsState, slug: string ) => state.apiData[ slug ] ?? {},
	getNotices: ( state: WizardsState ) => state.notices,
	getError: ( state: WizardsState ) => state.error,
};

const store = createReduxStore( WIZARD_STORE_NAMESPACE, {
	reducer,
	actions,
	selectors,

	controls: {
		FETCH_FROM_API: ( action: { payload: WizardApiFetchConfig } ) => {
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
				.finally( () => {
					dispatch( WIZARD_STORE_NAMESPACE ).finishLoadingData();
				} );
		},
	},

	resolvers: {
		*getWizardAPIData( slug: string ): Generator< unknown, unknown, WizardData | undefined > {
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
