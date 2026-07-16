/* eslint-disable import/no-extraneous-dependencies */

/**
 * External dependencies
 */
import { includes, debounce } from 'lodash';
import { AutocompleteTokenField } from 'newspack-components';
import type { ComponentProps, ComponentType, ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, QueryControls, FormTokenField as FormTokenFieldBase, SelectControl, ToggleControl, Spinner } from '@wordpress/components';
import { addQueryArgs } from '@wordpress/url';
import { Fragment, useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies
 */
import type { InserterBlockAttributes } from './index';

// `FormTokenField`'s `TokenItem` type isn't exported from `@wordpress/components`, so it's
// derived from its `onChange` prop (`(tokens: (string | TokenItem)[]) => void`).
type TokenFieldToken = Parameters< NonNullable< ComponentProps< typeof FormTokenFieldBase >[ 'onChange' ] > >[ 0 ][ number ];
type TokenItem = Exclude< TokenFieldToken, string >;

interface WPCategory {
	id: number;
	name: string;
	parent: number;
}

interface WPTag {
	id: number;
	name: string;
}

interface WPPostType {
	slug: string;
	name: string;
	viewable?: boolean;
	visibility?: { show_ui?: boolean };
}

interface PostSuggestion {
	id: number;
	title: string;
}

interface SelectOption {
	value: string | number;
	label: string;
}

const fetchPostSuggestions = ( postType: string ) => ( search: string ) =>
	apiFetch< PostSuggestion[] >( {
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

// `FormTokenField`'s type declares `label` as `string`-only, but it's rendered as a plain React
// child (`{ label }`) and readily accepts a `ReactNode` at runtime -- widen it at this
// @wordpress/components boundary rather than fight the (under-typed) declared shape.
const FormTokenField = FormTokenFieldBase as ComponentType< Omit< ComponentProps< typeof FormTokenFieldBase >, 'label' > & { label?: ReactNode } >;

const SEPARATOR = '--';
const encodePosts = ( posts: Array< { id: number | string; title?: string } > ) => posts.map( post => [ post.id, post.title ].join( SEPARATOR ) );
const decodePost = ( encodedPost: string ): [ string, string ] | string => {
	const match = encodedPost.match( new RegExp( `^([\\d]*)${ SEPARATOR }(.*)` ) );
	if ( match ) {
		return [ match[ 1 ], match[ 2 ] ];
	}
	return encodedPost;
};

interface QueryControlsSettingsProps {
	attributes: InserterBlockAttributes;
	setAttributes: ( attributes: Partial< InserterBlockAttributes > ) => void;
}

// NOTE: Mostly copied from Gutenberg's Posts Inserter block.
const QueryControlsSettings = ( { attributes, setAttributes }: QueryControlsSettingsProps ) => {
	const [ categoriesList, setCategoriesList ] = useState< WPCategory[] >( [] );
	const [ postTypesList, setPostTypesList ] = useState< Array< { value: string; label: string } > >( [ { value: 'post', label: 'Posts' } ] );
	const [ showAdvancedFilters, setShowAdvancedFilters ] = useState( false );

	const { categoryExclusions, tags, tagExclusions } = attributes;

	useEffect( () => {
		apiFetch< WPCategory[] >( {
			path: addQueryArgs( `/wp/v2/categories`, {
				per_page: -1,
			} ),
		} ).then( setCategoriesList );
		fetchPostTypes().then( setPostTypesList );
	}, [] );

	const categorySuggestions = categoriesList.reduce< Record< string, WPCategory > >(
		( accumulator, category ) => ( {
			...accumulator,
			[ category.name ]: category,
		} ),
		{}
	);

	// `onCategoryChange` (passed straight through to `FormTokenField`'s `onChange`) can hand back
	// either a suggestion-matched name (`string`) or the library's own `TokenItem` shape for
	// already-selected tokens; the latter is treated as a `WPCategory` here, matching how this
	// code has always consumed it.
	const selectCategories = ( tokens: Array< string | TokenItem > ) => {
		const hasNoSuggestion = tokens.some( token => typeof token === 'string' && ! categorySuggestions[ token ] );
		if ( hasNoSuggestion ) {
			return;
		}
		// Categories that are already will be objects, while new additions will be strings (the name).
		// allCategories nomalizes the array so that they are all objects.
		const allCategories = tokens.map( token => {
			return typeof token === 'string' ? categorySuggestions[ token ] : ( token as object as WPCategory );
		} );
		// We do nothing if the category is not selected
		// from suggestions.
		if ( includes( allCategories, null ) ) {
			return false;
		}
		setAttributes( { categories: allCategories } );
	};

	const selectTags = ( tokens: Array< string | number > ) => {
		const validTags = tokens.filter( token => !! token );

		setAttributes( { tags: validTags } );
	};

	const selectExcludedTags = ( tokens: Array< string | number > ) => {
		const validTags = tokens.filter( token => !! token );

		setAttributes( { tagExclusions: validTags } );
	};

	const selectExcludedCategories = ( tokens: Array< string | number > ) => {
		const validCats = tokens.filter( token => !! token );

		setAttributes( { categoryExclusions: validCats } );
	};

	const [ isFetchingPosts, setIsFetchingPosts ] = useState( false );
	const [ foundPosts, setFoundPosts ] = useState< PostSuggestion[] >( [] );
	const handleSpecificPostsInput = ( search: string ) => {
		if ( isFetchingPosts || search.length === 0 ) {
			return;
		}
		setIsFetchingPosts( true );
		fetchPostSuggestions( attributes.postType )( search ).then( posts => {
			setIsFetchingPosts( false );
			setFoundPosts( posts );
		} );
	};

	// `FormTokenField`'s `onChange` type allows non-string `TokenItem` tokens (for consumers that
	// pass object suggestions); this field always feeds it plain encoded strings (see `encodePosts`
	// below), so tokens are narrowed back down to `string` here.
	const handleSpecificPostsSelection = ( postTitles: TokenFieldToken[] ) => {
		setAttributes( {
			specificPosts: postTitles.map( token => {
				const encodedTitle = token as string;
				const [ id, title ] = decodePost( encodedTitle );
				return { id: parseInt( id ), title };
			} ),
		} );
	};

	const fetchCategorySuggestions = ( search: string ): Promise< SelectOption[] > => {
		return apiFetch< WPCategory[] >( {
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
		return apiFetch< Record< string, WPPostType > >( {
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

	const fetchSavedCategories = ( categoryIDs: ( string | number )[] ) => {
		return apiFetch< WPCategory[] >( {
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

	const fetchTagSuggestions = ( search: string ) => {
		return apiFetch< WPTag[] >( {
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

	const fetchSavedTags = ( tagIDs: ( string | number )[] ) => {
		return apiFetch< WPTag[] >( {
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
						// The block attribute's `categories` shape (`{ id; name? }`) predates `QueryControls`'
						// stricter `Category` (`{ id; name; parent }`); passed through unchanged here.
						selectedCategories={
							attributes.categories as Extract<
								ComponentProps< typeof QueryControls >,
								{ selectedCategories?: unknown }
							>[ 'selectedCategories' ]
						}
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
								// `orderBy`/`order` are persisted as plain `string` attributes, wider than the
								// four combinations `options` below actually offers.
								value={ `${ attributes.orderBy }/${ attributes.order }` as 'date/desc' | 'date/asc' | 'title/asc' | 'title/desc' }
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
