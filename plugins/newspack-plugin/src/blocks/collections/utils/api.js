import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';

export const ENDPOINTS = {
	categories: '/wp/v2/newspack_collection_category',
	collections: '/wp/v2/newspack_collection',
};

export const fetchCategorySuggestions = search => {
	return apiFetch( {
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
					return apiFetch( {
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

export const fetchSavedCategories = categoryIDs => {
	if ( ! categoryIDs.length ) {
		return Promise.resolve( [] );
	}

	return apiFetch( {
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
			if ( ! allCats.find( cat => cat.value === parseInt( catID ) ) ) {
				allCats.push( {
					value: parseInt( catID ),
					label: `(Deleted category - ID: ${ catID })`,
				} );
			}
		} );

		return allCats;
	} );
};

export const fetchCollectionSuggestions = search => {
	return apiFetch( {
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

export const fetchSavedCollections = collectionIDs => {
	if ( ! collectionIDs.length ) {
		return Promise.resolve( [] );
	}

	return apiFetch( {
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
			if ( ! allCollections.find( collection => collection.value === parseInt( collectionID ) ) ) {
				allCollections.push( {
					value: parseInt( collectionID ),
					label: `(Deleted collection - ID: ${ collectionID })`,
				} );
			}
		} );

		return allCollections;
	} );
};
