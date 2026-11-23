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
import SpecificPostsControl from './specific-posts-control';

const getCategoryTitle = category => decodeEntities( category.name ) || __( '(no title)', 'newspack-content-blocks' );

const getPostStatusName = status =>
	( {
		publish: __( 'Published', 'newspack-content-blocks' ),
		draft: __( 'Draft', 'newspack-content-blocks' ),
		future: __( 'Scheduled', 'newspack-content-blocks' ),
	} )[ status ];

const getTermTitle = term => decodeEntities( term.name ) || __( '(no title)', 'newspack-content-blocks' );

class QueryControls extends Component {
	fetchPostSuggestions = search => {
		const { postType, includedPostStatuses } = this.props;
		const restUrl = window.newspack_content_blocks_data.specific_posts_rest_url;
		return apiFetch( {
			url: addQueryArgs( restUrl, {
				search,
				postsToShow: 20,
				_fields: 'id,title,post_status',
				type: 'post',
				postType,
				includedPostStatuses,
			} ),
		} ).then( function ( posts ) {
			const result = posts.map( post => ( {
				value: post.id,
				label: decodeEntities( post.title ) || __( '(no title)', 'newspack-content-blocks' ),
				clashNote: getPostStatusName( post.post_status ),
			} ) );
			return result;
		} );
	};
	fetchSavedPosts = postIDs => {
		const { postType, includedPostStatuses } = this.props;
		const restUrl = window.newspack_content_blocks_data.posts_rest_url;
		// An empty `include` is dropped server-side, which would answer with the
		// most recent post rather than nothing.
		if ( ! postIDs.length ) {
			return Promise.resolve( [] );
		}
		return apiFetch( {
			url: addQueryArgs( restUrl, {
				// These params use the block query parameters (see Newspack_Blocks::build_articles_query).
				postsToShow: postIDs.length,
				include: postIDs.join( ',' ),
				_fields: 'id,title,post_status',
				postType,
				includedPostStatuses,
			} ),
		} ).then( function ( posts ) {
			const allPosts = posts.map( post => ( {
				value: post.id,
				label: decodeEntities( post.title.rendered ) || __( '(no title)', 'newspack-content-blocks' ),
				clashNote: getPostStatusName( post.post_status ),
			} ) );
			// An ID the endpoint did not return still needs an entry, or the token
			// field drops it from the block on the next edit. The ID goes in the
			// label because labels are matched back to IDs, and a shared label
			// would collapse two items onto one.
			postIDs.forEach( postID => {
				const id = parseInt( postID );
				if ( ! allPosts.find( post => post.value === id ) ) {
					allPosts.push( {
						value: id,
						label: sprintf(
							/* translators: %d: numeric ID of content that could not be found. */
							__( 'Unavailable content (%d)', 'newspack-content-blocks' ),
							id
						),
					} );
				}
			} );
			return allPosts;
		} );
	};

	fetchAuthorSuggestions = search => {
		const restUrl = window.newspack_content_blocks_data.authors_rest_url;
		return apiFetch( {
			url: addQueryArgs( restUrl, {
				search,
				per_page: 20,
				fields: 'id,name',
			} ),
		} ).then( function ( users ) {
			return users.map( user => ( {
				value: user.id,
				label: decodeEntities( user.name ) || __( '(no name)', 'newspack-content-blocks' ),
			} ) );
		} );
	};
	fetchSavedAuthors = userIDs => {
		const restUrl = window.newspack_content_blocks_data.authors_rest_url;
		return apiFetch( {
			url: addQueryArgs( restUrl, {
				per_page: 100,
				include: userIDs.join( ',' ),
				fields: 'id,name',
			} ),
		} ).then( function ( users ) {
			return users.map( user => ( {
				value: user.id,
				label: decodeEntities( user.name ) || __( '(no name)', 'newspack-content-blocks' ),
			} ) );
		} );
	};

	fetchCategorySuggestions = search => {
		return apiFetch( {
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
						return apiFetch( {
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
	fetchSavedCategories = categoryIDs => {
		return apiFetch( {
			path: addQueryArgs( '/wp/v2/categories', {
				per_page: 100,
				_fields: 'id,name',
				include: categoryIDs.join( ',' ),
			} ),
		} ).then( function ( categories ) {
			const allCats = categories.map( category => ( {
				value: category.id,
				label: decodeEntities( category.name ) || __( '(no title)', 'newspack-content-blocks' ),
			} ) );
			// Look for categoryIDs that were not returned (deleted categories) and add them to the list.
			categoryIDs.forEach( catID => {
				if ( ! allCats.find( cat => cat.value === parseInt( catID ) ) ) {
					allCats.push( {
						value: parseInt( catID ),
						label: __( 'Deleted category', 'newspack-content-blocks' ),
					} );
				}
			} );
			return allCats;
		} );
	};

	fetchTagSuggestions = search => {
		return apiFetch( {
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
				label: decodeEntities( tag.name ) || __( '(no title)', 'newspack-content-blocks' ),
			} ) );
		} );
	};
	fetchSavedTags = tagIDs => {
		return apiFetch( {
			path: addQueryArgs( '/wp/v2/tags', {
				per_page: 100,
				_fields: 'id,name',
				include: tagIDs.join( ',' ),
			} ),
		} ).then( function ( tags ) {
			const allTags = tags.map( tag => ( {
				value: tag.id,
				label: decodeEntities( tag.name ) || __( '(no title)', 'newspack-content-blocks' ),
			} ) );
			// Look for tagIDs that were not returned (deleted tags) and add them to the list.
			tagIDs.forEach( tagID => {
				if ( ! allTags.find( tag => tag.value === parseInt( tagID ) ) ) {
					allTags.push( {
						value: parseInt( tagID ),
						label: __( 'Deleted tag', 'newspack-content-blocks' ),
					} );
				}
			} );
			return allTags;
		} );
	};

	fetchCustomTaxonomiesSuggestions = ( taxSlug, search ) => {
		return apiFetch( {
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
						return apiFetch( {
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
	fetchSavedCustomTaxonomies = ( taxSlug, termIDs ) => {
		return apiFetch( {
			path: addQueryArgs( `/wp/v2/${ taxSlug }`, {
				per_page: 100,
				_fields: 'id,name',
				include: termIDs.join( ',' ),
			} ),
		} ).then( function ( terms ) {
			return terms.map( term => ( {
				value: term.id,
				label: decodeEntities( term.name ) || __( '(no title)', 'newspack-content-blocks' ),
			} ) );
		} );
	};

	render = () => {
		const {
			specificMode,
			onSpecificModeChange,
			onLoopModeChange,
			specificPosts,
			includedPostStatuses,
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

		const registeredCustomTaxonomies = window.newspack_content_blocks_data?.custom_taxonomies;

		const customTaxonomiesPrepareChange = ( taxArr, taxHandler, taxSlug, value ) => {
			let newValue = taxArr.filter( tax => tax.slug !== taxSlug );
			newValue = [ ...newValue, { slug: taxSlug, terms: value } ];
			taxHandler( newValue );
		};

		const getTermsOfCustomTaxonomy = ( taxArr, taxSlug ) => {
			const tax = taxArr.find( taxObj => taxObj.slug === taxSlug );
			return tax ? tax.terms : [];
		};

		return (
			<>
				{ enableSpecific && (
					<ToggleGroupControl
						label={ __( 'Mode', 'newspack-content-blocks' ) }
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
								? __( 'The block will display only the specifically selected content.', 'newspack-content-blocks' )
								: __( 'The block will display content based on the filtering settings below.', 'newspack-content-blocks' )
						}
						__next40pxDefaultSize
					>
						<ToggleGroupControlOption label={ __( 'Dynamic', 'newspack-content-blocks' ) } value="dynamic" />
						<ToggleGroupControlOption label={ __( 'Static', 'newspack-content-blocks' ) } value="specific" />
					</ToggleGroupControl>
				) }
				{ specificMode ? (
					<SpecificPostsControl
						// The statuses decide which saved posts resolve, and the token field only fetches on mount.
						key={ includedPostStatuses.join() }
						postIds={ specificPosts || [] }
						onChange={ onSpecificPostsChange }
						fetchSuggestions={ this.fetchPostSuggestions }
						fetchSavedInfo={ this.fetchSavedPosts }
					/>
				) : (
					<>
						<BasicQueryControls { ...this.props } maxItems={ 30 } />
						{ onCategoriesChange && (
							<BaseControl id="newspack-block__category-control">
								<div className="components-base-control__label-dropdown">
									<BaseControl.VisualLabel>{ __( 'Category', 'newspack-content-blocks' ) }</BaseControl.VisualLabel>
									<SelectControl
										size="small"
										value={ categoryJoinType }
										options={ [
											{ label: __( 'is one of', 'newspack-content-blocks' ), value: 'or' },
											{ label: __( 'is all of', 'newspack-content-blocks' ), value: 'all' },
										] }
										onChange={ value => {
											if ( 'all' === value ) {
												onIncludeSubcategoriesChange( false );
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
								label={ __( 'Include subcategories', 'newspack-content-blocks' ) }
							/>
						) }
						{ onTagsChange && (
							<AutocompleteTokenField
								tokens={ tags || [] }
								onChange={ onTagsChange }
								fetchSuggestions={ this.fetchTagSuggestions }
								fetchSavedInfo={ this.fetchSavedTags }
								label={ __( 'Tags', 'newspack-content-blocks' ) }
							/>
						) }
						{ onAuthorsChange && (
							<AutocompleteTokenField
								tokens={ authors || [] }
								onChange={ onAuthorsChange }
								fetchSuggestions={ this.fetchAuthorSuggestions }
								fetchSavedInfo={ this.fetchSavedAuthors }
								label={ __( 'Authors', 'newspack-content-blocks' ) }
							/>
						) }
						{ onCustomTaxonomiesChange &&
							registeredCustomTaxonomies.map( ( tax, index ) => (
								<AutocompleteTokenField
									key={ index }
									tokens={ getTermsOfCustomTaxonomy( customTaxonomies, tax.slug ) }
									onChange={ value => {
										customTaxonomiesPrepareChange( customTaxonomies, onCustomTaxonomiesChange, tax.slug, value );
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
								label={ __( 'Excluded categories', 'newspack-content-blocks' ) }
							/>
						) }
						{ onTagExclusionsChange && (
							<AutocompleteTokenField
								tokens={ tagExclusions || [] }
								onChange={ onTagExclusionsChange }
								fetchSuggestions={ this.fetchTagSuggestions }
								fetchSavedInfo={ this.fetchSavedTags }
								label={ __( 'Excluded tags', 'newspack-content-blocks' ) }
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
										__( 'Excluded %s', 'newspack-content-blocks' ),
										label
									) }
									onChange={ value =>
										customTaxonomiesPrepareChange( customTaxonomyExclusions, onCustomTaxonomyExclusionsChange, slug, value )
									}
									tokens={ getTermsOfCustomTaxonomy( customTaxonomyExclusions, slug ) }
								/>
							) ) }
					</>
				) }
				{ onAllowDedupeChange && (
					<ToggleControl
						label={ __( 'Allow duplicate content', 'newspack-content-blocks' ) }
						help={ __( "Exclude this block from the page's deduplication logic.", 'newspack-content-blocks' ) }
						checked={ allowDedupeCurrentValue }
						onChange={ value => onAllowDedupeChange( value ) }
					/>
				) }
			</>
		);
	};
}

QueryControls.defaultProps = {
	enableSpecific: true,
	specificPosts: [],
	includedPostStatuses: [],
	authors: [],
	categories: [],
	tags: [],
	customTaxonomies: [],
	tagExclusions: [],
	customTaxonomyExclusions: [],
};

export default QueryControls;
