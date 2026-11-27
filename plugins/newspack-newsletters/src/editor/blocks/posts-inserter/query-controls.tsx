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
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies
 */
import { fetchPostSuggestions, formatPostLabel } from './post-search';
import { selectSpecificPosts } from './specific-posts';
import type { InserterBlockAttributes } from './index';

// `FormTokenField`'s `TokenItem` type isn't exported from `wp/components`, so it's
// derived from its `onChange` prop (`( tokens: ( string | TokenItem )[] ) => void`).
type TokenFieldToken = Parameters< NonNullable< ComponentProps< typeof FormTokenFieldBase >[ 'onChange' ] > >[ 0 ][ number ];
type TokenItem = Exclude< TokenFieldToken, string >;

interface WPCategory {
	id: number;
	name: string;
	parent?: number;
}

interface WPPostType {
	slug: string;
	name: string;
	rest_base?: string;
	viewable?: boolean;
	visibility?: { show_ui?: boolean };
}

interface SelectOption {
	value: string | number;
	label: string;
}

// `FormTokenField`'s type declares `label` as `string`-only, but it's rendered as a plain React
// child (`{ label }`) and readily accepts a `ReactNode` at runtime -- widen it at this
// wp/components boundary rather than fight the (under-typed) declared shape.
const FormTokenField = FormTokenFieldBase as ComponentType< Omit< ComponentProps< typeof FormTokenFieldBase >, 'label' > & { label?: ReactNode } >;

// `QueryControls` is typed as a discriminated union whose category-selection variant expects
// `wp/components`' own `Category` shape. Re-type it to this unit's category shapes at this
// wp/components boundary so its props read in this file's own terms.
const QueryControlsCompat = QueryControls as ComponentType< {
	numberOfItems?: number;
	onNumberOfItemsChange?: ( value?: number ) => void;
	categorySuggestions?: Record< string, WPCategory >;
	onCategoryChange?: ( tokens: Array< string | TokenItem > ) => void;
	selectedCategories?: InserterBlockAttributes[ 'categories' ];
	minItems?: number;
	maxItems?: number;
} >;

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
	const [ postTypes, setPostTypes ] = useState< WPPostType[] >( [ { slug: 'post', name: 'Posts', rest_base: 'posts' } ] );
	const [ showAdvancedFilters, setShowAdvancedFilters ] = useState( false );

	const { categoryExclusions, tags, tagExclusions } = attributes;
	const restBase = postTypes.find( postType => attributes.postType === postType.slug )?.rest_base;
	const postTypeOptions = postTypes.map( postType => ( {
		value: postType.slug,
		label: decodeEntities( postType.name ) || __( '(no title)', 'newspack-newsletters' ),
	} ) );

	useEffect( () => {
		apiFetch< WPCategory[] >( {
			path: addQueryArgs( `/wp/v2/categories`, {
				per_page: -1,
			} ),
		} ).then( setCategoriesList );
		fetchPostTypes().then( setPostTypes );
	}, [] );

	const categorySuggestions = categoriesList.reduce< Record< string, WPCategory > >(
		( accumulator, category ) => ( {
			...accumulator,
			[ category.name ]: category,
		} ),
		{}
	);

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
	const [ foundPosts, setFoundPosts ] = useState< Array< { id: number; title: string; status: string } > >( [] );
	const handleSpecificPostsInput = ( search: string ) => {
		if ( isFetchingPosts || search.length === 0 || ! restBase ) {
			return;
		}
		setIsFetchingPosts( true );
		fetchPostSuggestions( restBase, search ).then( posts => {
			setIsFetchingPosts( false );
			setFoundPosts( posts );
		} );
	};

	// The block already fetches the posts saved on it, so reading their statuses back out of
	// core-data costs no extra request — and a draft that has since been published stops
	// being labelled as one.
	const specificPostIds = attributes.specificPosts.map( post => post.id );
	const savedPosts = useSelect(
		select => selectSpecificPosts( select, attributes.postType, specificPostIds ),
		[ attributes.postType, specificPostIds.join() ]
	);

	// Statuses for the saved tokens and for the current suggestions alike. A post still being
	// looked up simply has none yet, and shows as an unlabelled title.
	const postStatuses = [ ...foundPosts, ...( savedPosts || [] ) ].reduce< Record< string, string | undefined > >( ( all, post ) => {
		all[ post.id ] = post.status;
		return all;
	}, {} );

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

	const fetchPostTypes = (): Promise< WPPostType[] > => {
		return apiFetch< Record< string, WPPostType > >( {
			path: addQueryArgs( '/wp/v2/types', { context: 'edit' } ),
		} ).then( fetchedPostTypes => {
			return Object.values( fetchedPostTypes ).filter( postType => postType.viewable === true && postType.visibility?.show_ui === true );
		} );
	};

	const fetchSavedCategories = ( categoryIDs: Array< string | number > ): Promise< SelectOption[] > => {
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

	const fetchTagSuggestions = ( search: string ): Promise< SelectOption[] > => {
		return apiFetch< Array< { id: number; name: string } > >( {
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

	const fetchSavedTags = ( tagIDs: Array< string | number > ): Promise< SelectOption[] > => {
		return apiFetch< Array< { id: number; name: string } > >( {
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
				options={ postTypeOptions }
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
						return formatPostLabel( title || id || '', postStatuses[ id ] );
					} }
					onInputChange={ debounce( handleSpecificPostsInput, 400 ) }
				/>
			) : (
				<Fragment>
					<QueryControlsCompat
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
								options={
									[
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
									] as Array< { label: string; value: string } >
								}
								onChange={ ( value: string ) => {
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
