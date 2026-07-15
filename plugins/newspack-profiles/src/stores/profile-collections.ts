import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { type ProfileCollection } from '../types/profile-collection';

const STORE_KEY = 'newspack-profiles/collections';

export type ProfileCollectionState = {
	collections: ProfileCollection[];
};

const DEFAULT_STATE: ProfileCollectionState = {
	collections: [],
};

const reducer = (
	state: ProfileCollectionState = DEFAULT_STATE,
	action: any
): ProfileCollectionState => {
	switch ( action.type ) {
		case 'SET_PROFILE_COLLECTIONS':
			return {
				...state,
				collections: action.collections,
			};
		default:
			return state;
	}
};

const actions = {
	setProfileCollections( collections: ProfileCollection[] ) {
		return {
			type: 'SET_PROFILE_COLLECTIONS',
			collections,
		};
	},
};

const selectors = {
	getCollections( state: ProfileCollectionState ) {
		return state.collections;
	},
};

const resolvers = {
	getCollections() {
		return async ( { dispatch }: { dispatch: any } ) => {
			try {
				const collections = await apiFetch< any[] >( {
					method: 'GET',
					path: '/newspack-profiles/v1/profile-collections',
				} );

				if ( ! Array.isArray( collections ) ) {
					dispatch.setProfileCollections( [] );
					return;
				}

				dispatch.setProfileCollections( collections );
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error( 'Failed to fetch profiles', error );
			}
		};
	},
};

/**
 * Redux store for profiles management.
 */
export const store = createReduxStore( STORE_KEY, {
	reducer,
	actions,
	selectors,
	resolvers,
} );

register( store );
