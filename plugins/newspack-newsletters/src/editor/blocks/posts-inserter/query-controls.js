/* eslint-disable import/no-extraneous-dependencies */

/**
 * External dependencies
 */
import { includes, debounce } from 'lodash';
import { AutocompleteTokenField } from 'newspack-components';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, QueryControls, FormTokenField, SelectControl, ToggleControl, Spinner } from '@wordpress/components';
import { addQueryArgs } from '@wordpress/url';
import { Fragment, useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';

const fetchPostSuggestions = postType => search =>
	apiFetch( {
		path: addQueryArgs( '/wp/v2/search', {
			search,
			per_page: 20,
			_fields: 'id,title',
			subtype: postType,
		} ),
	} ).then( posts =>
		posts.map( post => ( {
			id: post.id,
			title: decodeEntities( post.title ) || __( '(no title)', 'newspack-newsletters' ),
		} ) )
	);

const SEPARATOR = '--';
const encodePosts = posts => posts.map( post => [ post.id, post.title ].join( SEPARATOR ) );
const decodePost = encodedPost => {
	const match = encodedPost.match( new RegExp( `^([\\d]*)${ SEPARATOR }(.*)` ) );
	if ( match ) {
		return [ match[ 1 ], match[ 2 ] ];
	}
	return encodedPost;
};

// NOTE: Mostly copied from Gutenberg's Posts Inserter block.
const QueryControlsSettings = ( { attributes, setAttributes } ) => {
	const [ categoriesList, setCategoriesList ] = useState( [] );
	const [ postTypesList, setPostTypesList ] = useState( [ { value: 'post', label: 'Posts' } ] );
	const [ showAdvancedFilters, setShowAdvancedFilters ] = useState( false );

	const { categoryExclusions, tags, tagExclusions } = attributes;

	useEffect( () => {
		apiFetch( {
			path: addQueryArgs( `/wp/v2/categories`, {
				per_page: -1,
			} ),
		} ).then( setCategoriesList );
		fetchPostTypes().then( setPostTypesList );
	}, [] );

	const categorySuggestions = categoriesList.reduce(
		( accumulator, category ) => ( {
			...accumulator,
			[ category.name ]: category,
		} ),
		{}
	);

	const selectCategories = tokens => {
		const hasNoSuggestion = tokens.some( token => typeof token === 'string' && ! categorySuggestions[ token ] );
		if ( hasNoSuggestion ) {
			return;
		}
		// Categories that are already will be objects, while new additions will be strings (the name).
		// allCategories nomalizes the array so that they are all objects.
		const allCategories = tokens.map( token => {
			return typeof token === 'string' ? categorySuggestions[ token ] : token;
		} );
		// We do nothing if the category is not selected
		// from suggestions.
		if ( includes( allCategories, null ) ) {
			return false;
		}
		setAttributes( { categories: allCategories } );
	};

	const selectTags = tokens => {
		const validTags = tokens.filter( token => !! token );

		setAttributes( { tags: validTags } );
	};

	const selectExcludedTags = tokens => {
		const validTags = tokens.filter( token => !! token );

		setAttributes( { tagExclusions: validTags } );
	};

	const selectExcludedCategories = tokens => {
		const validCats = tokens.filter( token => !! token );

		setAttributes( { categoryExclusions: validCats } );
	};

	const [ isFetchingPosts, setIsFetchingPosts ] = useState( false );
	const [ foundPosts, setFoundPosts ] = useState( [] );
	const handleSpecificPostsInput = search => {
		if ( isFetchingPosts || search.length === 0 ) {
			return;
		}
		setIsFetchingPosts( true );
		fetchPostSuggestions( attributes.postType )( search ).then( posts => {
			setIsFetchingPosts( false );
			setFoundPosts( posts );
		} );
	};

	const handleSpecificPostsSelection = postTitles => {
		setAttributes( {
			specificPosts: postTitles.map( encodedTitle => {
				const [ id, title ] = decodePost( encodedTitle );
				return { id: parseInt( id ), title };
			} ),
		} );
	};

	const fetchCategorySuggestions = search => {
		return apiFetch( {
			path: addQueryArgs( '/wp/v2/categories', {
				search,
				per_page: 20,
				_fields: 'id,name',
				orderby: 'count',
				order: 'desc',
			} ),
		} ).then( categories => {
			return categories.map( category => ( {
				value: category.id,
				label: decodeEntities( category.name ) || __( '(no title)', 'newspack-newsletters' ),
			} ) );
		} );
	};

	const fetchPostTypes = () => {
		return apiFetch( {
			path: addQueryArgs( '/wp/v2/types', { context: 'edit' } ),
		} ).then( postTypes => {
			return Object.values( postTypes )
				.filter( postType => postType.viewable === true && postType.visibility?.show_ui === true )
				.map( postType => ( {
					value: postType.slug,
					label: decodeEntities( postType.name ) || __( '(no title)', 'newspack-newsletters' ),
				} ) );
		} );
	};

	const fetchSavedCategories = categoryIDs => {
		return apiFetch( {
			path: addQueryArgs( '/wp/v2/categories', {
				per_page: 100,
				_fields: 'id,name',
				include: categoryIDs.join( ',' ),
			} ),
		} ).then( categories => {
			return categories.map( category => ( {
				value: category.id,
				label: decodeEntities( category.name ) || __( '(no title)', 'newspack-newsletters' ),
			} ) );
		} );
	};

	const fetchTagSuggestions = search => {
		return apiFetch( {
			path: addQueryArgs( '/wp/v2/tags', {
				search,
				per_page: 20,
				_fields: 'id,name',
				orderby: 'count',
				order: 'desc',
			} ),
		} ).then( fetchedTags => {
			return fetchedTags.map( tag => ( {
				value: tag.id,
				label: decodeEntities( tag.name ) || __( '(no title)', 'newspack-newsletters' ),
			} ) );
		} );
	};

	const fetchSavedTags = tagIDs => {
		return apiFetch( {
			path: addQueryArgs( '/wp/v2/tags', {
				per_page: 100,
				_fields: 'id,name',
				include: tagIDs.join( ',' ),
			} ),
		} ).then( fetchedTags => {
			return fetchedTags.map( tag => ( {
				value: tag.id,
				label: decodeEntities( tag.name ) || __( '(no title)', 'newspack-newsletters' ),
			} ) );
		} );
	};

	return (
		<div className="newspack-newsletters-query-controls">
			<SelectControl
				label={ __( 'Post type', 'newspack-newsletters' ) }
				options={ postTypesList }
				value={ attributes.postType }
				onChange={ postType => setAttributes( { postType } ) }
				__next40pxDefaultSize
			/>
			<ToggleControl
				label={ __( 'Display specific posts', 'newspack-newsletters' ) }
				checked={ attributes.isDisplayingSpecificPosts }
				onChange={ value => setAttributes( { isDisplayingSpecificPosts: value } ) }
			/>
			{ ! attributes.isDisplayingSpecificPosts && (
				<ToggleControl
					label={ __( 'Display sponsored posts', 'newspack-newsletters' ) }
					checked={ attributes.displaySponsoredPosts }
					onChange={ value => setAttributes( { displaySponsoredPosts: value } ) }
				/>
			) }
			{ attributes.isDisplayingSpecificPosts ? (
				<FormTokenField
					label={
						<div>
							{ __( 'Add posts', 'newspack-newsletters' ) }
							{ isFetchingPosts && <Spinner /> }
						</div>
					}
					onChange={ handleSpecificPostsSelection }
					value={ encodePosts( attributes.specificPosts ) }
					suggestions={ encodePosts( foundPosts ) }
					displayTransform={ string => {
						const [ id, title ] = decodePost( string );
						return title || id || '';
					} }
					onInputChange={ debounce( handleSpecificPostsInput, 400 ) }
				/>
			) : (
				<Fragment>
					<QueryControls
						numberOfItems={ attributes.postsToShow }
						onNumberOfItemsChange={ value => setAttributes( { postsToShow: value } ) }
						categorySuggestions={ categorySuggestions }
						onCategoryChange={ selectCategories }
						selectedCategories={ attributes.categories }
						minItems={ 1 }
						maxItems={ 20 }
					/>
					<p key="toggle-advanced-filters">
						<Button isLink onClick={ () => setShowAdvancedFilters( ! showAdvancedFilters ) }>
							{ showAdvancedFilters
								? __( 'Hide Advanced Filters', 'newspack-newsletters' )
								: __( 'Show Advanced Filters', 'newspack-newsletters' ) }
						</Button>
					</p>
					{ showAdvancedFilters && (
						<Fragment>
							<AutocompleteTokenField
								key="tags"
								tokens={ tags }
								onChange={ selectTags }
								fetchSuggestions={ fetchTagSuggestions }
								fetchSavedInfo={ fetchSavedTags }
								label={ __( 'Tags', 'newspack-newsletters' ) }
								__next40pxDefaultSize
							/>
							<AutocompleteTokenField
								key="category-exclusion"
								tokens={ categoryExclusions }
								onChange={ selectExcludedCategories }
								fetchSuggestions={ fetchCategorySuggestions }
								fetchSavedInfo={ fetchSavedCategories }
								label={ __( 'Excluded Categories', 'newspack-newsletters' ) }
								__next40pxDefaultSize
							/>
							<AutocompleteTokenField
								key="tag-exclusion"
								tokens={ tagExclusions }
								onChange={ selectExcludedTags }
								fetchSuggestions={ fetchTagSuggestions }
								fetchSavedInfo={ fetchSavedTags }
								label={ __( 'Excluded Tags', 'newspack-newsletters' ) }
								__next40pxDefaultSize
							/>
							<SelectControl
								key="query-controls-order-select"
								label={ __( 'Order by', 'newspack-newsletters' ) }
								value={ `${ attributes.orderBy }/${ attributes.order }` }
								options={ [
									{
										label: __( 'Newest to oldest', 'newspack-newsletters' ),
										value: 'date/desc',
									},
									{
										label: __( 'Oldest to newest', 'newspack-newsletters' ),
										value: 'date/asc',
									},
									{
										/* translators: label for ordering posts by title in ascending order */
										label: __( 'A → Z', 'newspack-newsletters' ),
										value: 'title/asc',
									},
									{
										/* translators: label for ordering posts by title in descending order */
										label: __( 'Z → A', 'newspack-newsletters' ),
										value: 'title/desc',
									},
								] }
								onChange={ value => {
									const [ newOrderBy, newOrder ] = value.split( '/' );
									if ( newOrder !== attributes.order ) {
										setAttributes( { order: newOrder } );
									}
									if ( newOrderBy !== attributes.orderBy ) {
										setAttributes( { orderBy: newOrderBy } );
									}
								} }
								__next40pxDefaultSize
							/>
						</Fragment>
					) }
				</Fragment>
			) }
		</div>
	);
};

export default QueryControlsSettings;
