import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies
 */
import type { TokenValue } from '../../../../packages/components/src/autocomplete-tokenfield';

/**
 * A value/label option for the AutocompleteTokenField.
 */
type SuggestionOption = {
	value: number;
	label: string;
};

/**
 * A newspack_collection_category term as returned by the REST API.
 */
type CategoryRecord = {
	id: number;
	name: string;
	parent: number;
};

/**
 * A newspack_collection post as returned by the REST API (fields subset).
 */
type CollectionRecord = {
	id: number;
	title: {
		rendered: string;
	};
};

export const ENDPOINTS = {
	categories: '/wp/v2/newspack_collection_category',
	collections: '/wp/v2/newspack_collection',
};

export const fetchCategorySuggestions = ( search: string ): Promise< SuggestionOption[] > => {
	return apiFetch< CategoryRecord[] >( {
		path: addQueryArgs( ENDPOINTS.categories, {
			search,
			per_page: 20,
			_fields: 'id,name,parent',
			orderby: 'count',
			order: 'desc',
		} ),
	} ).then( categories =>
		Promise.all(
			categories.map( category => {
				if ( category.parent > 0 ) {
					return apiFetch< Pick< CategoryRecord, 'name' > >( {
						path: addQueryArgs( `${ ENDPOINTS.categories }/${ category.parent }`, {
							_fields: 'name',
						} ),
					} ).then( parentCategory => ( {
						value: category.id,
						label: `${ decodeEntities( category.name ) } – ${ decodeEntities( parentCategory.name ) }`,
					} ) );
				}
				return Promise.resolve( {
					value: category.id,
					label: decodeEntities( category.name ),
				} );
			} )
		)
	);
};

export const fetchSavedCategories = ( categoryIDs: TokenValue[] ): Promise< SuggestionOption[] > => {
	if ( ! categoryIDs.length ) {
		return Promise.resolve( [] );
	}

	return apiFetch< CategoryRecord[] >( {
		path: addQueryArgs( ENDPOINTS.categories, {
			per_page: 100,
			_fields: 'id,name',
			include: categoryIDs.join( ',' ),
		} ),
	} ).then( function ( categories ) {
		const allCats = categories.map( category => ( {
			value: category.id,
			label: decodeEntities( category.name ),
		} ) );

		categoryIDs.forEach( catID => {
			if ( ! allCats.find( cat => cat.value === parseInt( String( catID ) ) ) ) {
				allCats.push( {
					value: parseInt( String( catID ) ),
					label: `(Deleted category - ID: ${ catID })`,
				} );
			}
		} );

		return allCats;
	} );
};

export const fetchCollectionSuggestions = ( search: string ): Promise< SuggestionOption[] > => {
	return apiFetch< CollectionRecord[] >( {
		path: addQueryArgs( ENDPOINTS.collections, {
			search,
			per_page: 20,
			_fields: 'id,title',
			orderby: 'title',
			order: 'asc',
			status: 'publish',
		} ),
	} ).then( collections =>
		collections.map( collection => ( {
			value: collection.id,
			label: decodeEntities( collection.title.rendered ),
		} ) )
	);
};

export const fetchSavedCollections = ( collectionIDs: TokenValue[] ): Promise< SuggestionOption[] > => {
	if ( ! collectionIDs.length ) {
		return Promise.resolve( [] );
	}

	return apiFetch< CollectionRecord[] >( {
		path: addQueryArgs( ENDPOINTS.collections, {
			per_page: 100,
			_fields: 'id,title',
			include: collectionIDs.join( ',' ),
			status: 'publish',
		} ),
	} ).then( collections => {
		const allCollections = collections.map( collection => ( {
			value: collection.id,
			label: decodeEntities( collection.title.rendered ),
		} ) );

		collectionIDs.forEach( collectionID => {
			if ( ! allCollections.find( collection => collection.value === parseInt( String( collectionID ) ) ) ) {
				allCollections.push( {
					value: parseInt( String( collectionID ) ),
					label: `(Deleted collection - ID: ${ collectionID })`,
				} );
			}
		} );

		return allCollections;
	} );
};
