/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Component } from '@wordpress/element';
import {
	BaseControl,
	CheckboxControl,
	SelectControl,
	QueryControls as BasicQueryControls,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies.
 */
import AutocompleteTokenField from './autocomplete-tokenfield';

type TokenValue = string | number;
type Suggestion = { value: number; label: string };
type CustomTaxonomy = { slug: string; terms: number[] };

type QueryControlsProps = {
	specificMode?: boolean;
	onSpecificModeChange: () => void;
	onLoopModeChange: () => void;
	specificPosts?: string[];
	onSpecificPostsChange: ( value: TokenValue[] ) => void;
	authors?: AuthorId[];
	onAuthorsChange?: ( value: TokenValue[] ) => void;
	categories?: CategoryId[];
	onCategoriesChange?: ( value: TokenValue[] ) => void;
	categoryJoinType?: string;
	onCategoryJoinTypeChange: ( value: string ) => void;
	includeSubcategories?: boolean;
	onIncludeSubcategoriesChange?: ( value: boolean ) => void;
	tags?: TagId[];
	onTagsChange?: ( value: TokenValue[] ) => void;
	customTaxonomies?: CustomTaxonomy[];
	onCustomTaxonomiesChange?: ( value: CustomTaxonomy[] ) => void;
	tagExclusions?: TagId[];
	onTagExclusionsChange?: ( value: TokenValue[] ) => void;
	categoryExclusions?: CategoryId[];
	onCategoryExclusionsChange?: ( value: TokenValue[] ) => void;
	customTaxonomyExclusions?: CustomTaxonomy[];
	onCustomTaxonomyExclusionsChange?: ( value: CustomTaxonomy[] ) => void;
	enableSpecific?: boolean;
	onAllowDedupeChange?: ( value: boolean ) => void;
	allowDedupeCurrentValue?: boolean;
	postType?: string[];
	numberOfItems?: number;
	onNumberOfItemsChange?: ( value?: number ) => void;
};

const getCategoryTitle = ( category: { name: string } ) => decodeEntities( category.name ) || __( '(no title)', 'newspack-blocks' );

const getTermTitle = ( term: { name: string } ) => decodeEntities( term.name ) || __( '(no title)', 'newspack-blocks' );

class QueryControls extends Component< QueryControlsProps > {
	static defaultProps: Partial< QueryControlsProps > = {
		enableSpecific: true,
		specificPosts: [],
		authors: [],
		categories: [],
		tags: [],
		customTaxonomies: [],
		tagExclusions: [],
		customTaxonomyExclusions: [],
	};

	fetchPostSuggestions = ( search: string ): Promise< Suggestion[] > => {
		const { postType } = this.props;
		const restUrl = window.newspack_blocks_data.specific_posts_rest_url;
		return apiFetch< { id: number; title: string }[] >( {
			url: addQueryArgs( restUrl, {
				search,
				postsToShow: 20,
				_fields: 'id,title',
				type: 'post',
				postType,
			} ),
		} ).then( function ( posts ) {
			const result = posts.map( post => ( {
				value: post.id,
				label: decodeEntities( post.title ) || __( '(no title)', 'newspack-blocks' ),
			} ) );
			return result;
		} );
	};
	fetchSavedPosts = ( postIDs: TokenValue[] ): Promise< Suggestion[] > => {
		const { postType } = this.props;
		const restUrl = window.newspack_blocks_data.posts_rest_url;
		return apiFetch< { id: number; title: { rendered: string } }[] >( {
			url: addQueryArgs( restUrl, {
				// These params use the block query parameters (see Newspack_Blocks::build_articles_query).
				postsToShow: 100,
				include: postIDs.join( ',' ),
				_fields: 'id,title',
				postType,
			} ),
		} ).then( function ( posts ) {
			return posts.map( post => ( {
				value: post.id,
				label: decodeEntities( post.title.rendered ) || __( '(no title)', 'newspack-blocks' ),
			} ) );
		} );
	};

	fetchAuthorSuggestions = ( search: string ): Promise< Suggestion[] > => {
		const restUrl = window.newspack_blocks_data.authors_rest_url;
		return apiFetch< { id: number; name: string }[] >( {
			url: addQueryArgs( restUrl, {
				search,
				per_page: 20,
				fields: 'id,name',
			} ),
		} ).then( function ( users ) {
			return users.map( user => ( {
				value: user.id,
				label: decodeEntities( user.name ) || __( '(no name)', 'newspack-blocks' ),
			} ) );
		} );
	};
	fetchSavedAuthors = ( userIDs: TokenValue[] ): Promise< Suggestion[] > => {
		const restUrl = window.newspack_blocks_data.authors_rest_url;
		return apiFetch< { id: number; name: string }[] >( {
			url: addQueryArgs( restUrl, {
				per_page: 100,
				include: userIDs.join( ',' ),
				fields: 'id,name',
			} ),
		} ).then( function ( users ) {
			return users.map( user => ( {
				value: user.id,
				label: decodeEntities( user.name ) || __( '(no name)', 'newspack-blocks' ),
			} ) );
		} );
	};

	fetchCategorySuggestions = ( search: string ): Promise< Suggestion[] > => {
		return apiFetch< { id: number; name: string; parent: number }[] >( {
			path: addQueryArgs( '/wp/v2/categories', {
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
						return apiFetch< { name: string } >( {
							path: addQueryArgs( `/wp/v2/categories/${ category.parent }`, {
								_fields: 'name',
							} ),
						} ).then( parentCategory => ( {
							value: category.id,
							label: `${ getCategoryTitle( category ) } – ${ getCategoryTitle( parentCategory ) }`,
						} ) );
					}
					return Promise.resolve( {
						value: category.id,
						label: getCategoryTitle( category ),
					} );
				} )
			)
		);
	};
	fetchSavedCategories = ( categoryIDs: TokenValue[] ): Promise< Suggestion[] > => {
		return apiFetch< { id: number; name: string }[] >( {
			path: addQueryArgs( '/wp/v2/categories', {
				per_page: 100,
				_fields: 'id,name',
				include: categoryIDs.join( ',' ),
			} ),
		} ).then( function ( categories ) {
			const allCats = categories.map( category => ( {
				value: category.id,
				label: decodeEntities( category.name ) || __( '(no title)', 'newspack-blocks' ),
			} ) );
			// Look for categoryIDs that were not returned (deleted categories) and add them to the list.
			categoryIDs.forEach( catID => {
				if ( ! allCats.find( cat => cat.value === parseInt( catID as string ) ) ) {
					allCats.push( {
						value: parseInt( catID as string ),
						label: __( 'Deleted category', 'newspack-blocks' ),
					} );
				}
			} );
			return allCats;
		} );
	};

	fetchTagSuggestions = ( search: string ): Promise< Suggestion[] > => {
		return apiFetch< { id: number; name: string }[] >( {
			path: addQueryArgs( '/wp/v2/tags', {
				search,
				per_page: 20,
				_fields: 'id,name',
				orderby: 'count',
				order: 'desc',
			} ),
		} ).then( function ( tags ) {
			return tags.map( tag => ( {
				value: tag.id,
				label: decodeEntities( tag.name ) || __( '(no title)', 'newspack-blocks' ),
			} ) );
		} );
	};
	fetchSavedTags = ( tagIDs: TokenValue[] ): Promise< Suggestion[] > => {
		return apiFetch< { id: number; name: string }[] >( {
			path: addQueryArgs( '/wp/v2/tags', {
				per_page: 100,
				_fields: 'id,name',
				include: tagIDs.join( ',' ),
			} ),
		} ).then( function ( tags ) {
			const allTags = tags.map( tag => ( {
				value: tag.id,
				label: decodeEntities( tag.name ) || __( '(no title)', 'newspack-blocks' ),
			} ) );
			// Look for tagIDs that were not returned (deleted tags) and add them to the list.
			tagIDs.forEach( tagID => {
				if ( ! allTags.find( tag => tag.value === parseInt( tagID as string ) ) ) {
					allTags.push( {
						value: parseInt( tagID as string ),
						label: __( 'Deleted tag', 'newspack-blocks' ),
					} );
				}
			} );
			return allTags;
		} );
	};

	fetchCustomTaxonomiesSuggestions = ( taxSlug: string, search: string ): Promise< Suggestion[] > => {
		return apiFetch< { id: number; name: string; parent: number }[] >( {
			path: addQueryArgs( `/wp/v2/${ taxSlug }`, {
				search,
				per_page: 20,
				_fields: 'id,name,parent',
				orderby: 'count',
				order: 'desc',
			} ),
		} ).then( terms =>
			Promise.all(
				terms.map( term => {
					if ( term.parent > 0 ) {
						return apiFetch< { name: string } >( {
							path: addQueryArgs( `/wp/v2/${ taxSlug }/${ term.parent }`, {
								_fields: 'name',
							} ),
						} ).then( parentTerm => ( {
							value: term.id,
							label: `${ getTermTitle( term ) } – ${ getTermTitle( parentTerm ) }`,
						} ) );
					}
					return Promise.resolve( {
						value: term.id,
						label: getTermTitle( term ),
					} );
				} )
			)
		);
	};
	fetchSavedCustomTaxonomies = ( taxSlug: string, termIDs: TokenValue[] ): Promise< Suggestion[] > => {
		return apiFetch< { id: number; name: string }[] >( {
			path: addQueryArgs( `/wp/v2/${ taxSlug }`, {
				per_page: 100,
				_fields: 'id,name',
				include: termIDs.join( ',' ),
			} ),
		} ).then( function ( terms ) {
			return terms.map( term => ( {
				value: term.id,
				label: decodeEntities( term.name ) || __( '(no title)', 'newspack-blocks' ),
			} ) );
		} );
	};

	render = () => {
		const {
			specificMode,
			onSpecificModeChange,
			onLoopModeChange,
			specificPosts,
			onSpecificPostsChange,
			authors,
			onAuthorsChange,
			categories,
			onCategoriesChange,
			categoryJoinType,
			onCategoryJoinTypeChange,
			includeSubcategories,
			onIncludeSubcategoriesChange,
			tags,
			onTagsChange,
			customTaxonomies,
			onCustomTaxonomiesChange,
			tagExclusions,
			onTagExclusionsChange,
			categoryExclusions,
			onCategoryExclusionsChange,
			customTaxonomyExclusions,
			onCustomTaxonomyExclusionsChange,
			enableSpecific,
			onAllowDedupeChange,
			allowDedupeCurrentValue,
		} = this.props;

		const registeredCustomTaxonomies = window.newspack_blocks_data?.custom_taxonomies;

		const customTaxonomiesPrepareChange = (
			taxArr: CustomTaxonomy[],
			taxHandler: ( value: CustomTaxonomy[] ) => void,
			taxSlug: string,
			value: number[]
		) => {
			let newValue = taxArr.filter( tax => tax.slug !== taxSlug );
			newValue = [ ...newValue, { slug: taxSlug, terms: value } ];
			taxHandler( newValue );
		};

		const getTermsOfCustomTaxonomy = ( taxArr: CustomTaxonomy[], taxSlug: string ) => {
			const tax = taxArr.find( taxObj => taxObj.slug === taxSlug );
			return tax ? tax.terms : [];
		};

		return (
			<>
				{ enableSpecific && (
					<ToggleGroupControl
						label={ __( 'Mode', 'newspack-blocks' ) }
						value={ specificMode ? 'specific' : 'dynamic' }
						onChange={ value => {
							if ( value === 'specific' ) {
								onSpecificModeChange();
							} else {
								onLoopModeChange();
							}
						} }
						isBlock
						help={
							specificMode
								? __( 'The block will display only the specifically selected content.', 'newspack-blocks' )
								: __( 'The block will display content based on the filtering settings below.', 'newspack-blocks' )
						}
						__next40pxDefaultSize
					>
						<ToggleGroupControlOption label={ __( 'Dynamic', 'newspack-blocks' ) } value="dynamic" />
						<ToggleGroupControlOption label={ __( 'Static', 'newspack-blocks' ) } value="specific" />
					</ToggleGroupControl>
				) }
				{ specificMode ? (
					<AutocompleteTokenField
						tokens={ specificPosts || [] }
						onChange={ onSpecificPostsChange }
						fetchSuggestions={ this.fetchPostSuggestions }
						fetchSavedInfo={ this.fetchSavedPosts }
						label={ __( 'Content', 'newspack-blocks' ) }
						help={ __( 'Begin typing any word in a title. Click on an autocomplete result to select it.', 'newspack-blocks' ) }
					/>
				) : (
					<>
						<BasicQueryControls { ...this.props } maxItems={ 30 } />
						{ onCategoriesChange && (
							<BaseControl id="newspack-block__category-control">
								<div className="components-base-control__label-dropdown">
									<BaseControl.VisualLabel>{ __( 'Category', 'newspack-blocks' ) }</BaseControl.VisualLabel>
									<SelectControl
										size="small"
										value={ categoryJoinType as 'all' | 'or' | undefined }
										options={ [
											{ label: __( 'is one of', 'newspack-blocks' ), value: 'or' },
											{ label: __( 'is all of', 'newspack-blocks' ), value: 'all' },
										] }
										onChange={ value => {
											if ( 'all' === value ) {
												onIncludeSubcategoriesChange!( false );
											}
											onCategoryJoinTypeChange( value );
										} }
										__nextHasNoMarginBottom
									/>
								</div>
								<AutocompleteTokenField
									tokens={ categories || [] }
									onChange={ onCategoriesChange }
									fetchSuggestions={ this.fetchCategorySuggestions }
									fetchSavedInfo={ this.fetchSavedCategories }
								/>
							</BaseControl>
						) }
						{ 'all' !== categoryJoinType && onIncludeSubcategoriesChange && (
							<CheckboxControl
								checked={ includeSubcategories }
								onChange={ onIncludeSubcategoriesChange }
								label={ __( 'Include subcategories', 'newspack-blocks' ) }
							/>
						) }
						{ onTagsChange && (
							<AutocompleteTokenField
								tokens={ tags || [] }
								onChange={ onTagsChange }
								fetchSuggestions={ this.fetchTagSuggestions }
								fetchSavedInfo={ this.fetchSavedTags }
								label={ __( 'Tags', 'newspack-blocks' ) }
							/>
						) }
						{ onAuthorsChange && (
							<AutocompleteTokenField
								tokens={ authors || [] }
								onChange={ onAuthorsChange }
								fetchSuggestions={ this.fetchAuthorSuggestions }
								fetchSavedInfo={ this.fetchSavedAuthors }
								label={ __( 'Authors', 'newspack-blocks' ) }
							/>
						) }
						{ onCustomTaxonomiesChange &&
							registeredCustomTaxonomies.map( ( tax, index ) => (
								<AutocompleteTokenField
									key={ index }
									tokens={ getTermsOfCustomTaxonomy( customTaxonomies || [], tax.slug ) }
									onChange={ value => {
										customTaxonomiesPrepareChange(
											customTaxonomies || [],
											onCustomTaxonomiesChange,
											tax.slug,
											value as number[]
										);
									} }
									fetchSuggestions={ search => this.fetchCustomTaxonomiesSuggestions( tax.slug, search ) }
									fetchSavedInfo={ termIds => this.fetchSavedCustomTaxonomies( tax.slug, termIds ) }
									label={ tax.label }
								/>
							) ) }
						{ onCategoryExclusionsChange && (
							<AutocompleteTokenField
								tokens={ categoryExclusions || [] }
								onChange={ onCategoryExclusionsChange }
								fetchSuggestions={ this.fetchCategorySuggestions }
								fetchSavedInfo={ this.fetchSavedCategories }
								label={ __( 'Excluded categories', 'newspack-blocks' ) }
							/>
						) }
						{ onTagExclusionsChange && (
							<AutocompleteTokenField
								tokens={ tagExclusions || [] }
								onChange={ onTagExclusionsChange }
								fetchSuggestions={ this.fetchTagSuggestions }
								fetchSavedInfo={ this.fetchSavedTags }
								label={ __( 'Excluded tags', 'newspack-blocks' ) }
							/>
						) }
						{ registeredCustomTaxonomies &&
							onCustomTaxonomyExclusionsChange &&
							registeredCustomTaxonomies.map( ( { label, slug } ) => (
								<AutocompleteTokenField
									fetchSavedInfo={ termIds => this.fetchSavedCustomTaxonomies( slug, termIds ) }
									fetchSuggestions={ search => this.fetchCustomTaxonomiesSuggestions( slug, search ) }
									key={ `${ slug }-exclusions-selector` }
									label={ sprintf(
										// translators: %s is the custom taxonomy label.
										__( 'Excluded %s', 'newspack-blocks' ),
										label
									) }
									onChange={ value =>
										customTaxonomiesPrepareChange(
											customTaxonomyExclusions || [],
											onCustomTaxonomyExclusionsChange,
											slug,
											value as number[]
										)
									}
									tokens={ getTermsOfCustomTaxonomy( customTaxonomyExclusions || [], slug ) }
								/>
							) ) }
					</>
				) }
				{ onAllowDedupeChange && (
					<ToggleControl
						label={ __( 'Allow duplicate content', 'newspack-blocks' ) }
						help={ __( "Exclude this block from the page's deduplication logic.", 'newspack-blocks' ) }
						checked={ allowDedupeCurrentValue }
						onChange={ value => onAllowDedupeChange( value ) }
					/>
				) }
			</>
		);
	};
}

export default QueryControls;
