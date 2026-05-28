/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Component, Fragment } from '@wordpress/element';
import { Button, QueryControls as BaseControl, RadioControl, RangeControl, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * External dependencies.
 */
import { AutocompleteTokenField } from 'newspack-components';
import './autocomplete-tokenfield.scss';

class QueryControls extends Component {
	state = {
		showAdvancedFilters: false,
	};

	fetchAuthorSuggestions = search => {
		if ( ! search ) {
			return new Promise( resolve => resolve( [] ) );
		}

		return apiFetch( {
			path: addQueryArgs( '/wp/v2/users', {
				search,
				per_page: 20,
				_fields: 'id,name',
			} ),
		} ).then( function ( users ) {
			return users.map( user => ( {
				value: user.id,
				label: decodeEntities( user.name ) || __( '(no name)', 'newspack-listings' ),
			} ) );
		} );
	};
	fetchSavedAuthors = userIDs => {
		return apiFetch( {
			path: addQueryArgs( '/wp/v2/users', {
				per_page: 100,
				include: userIDs.join( ',' ),
				_fields: 'id,name',
			} ),
		} ).then( function ( users ) {
			return users.map( user => ( {
				value: user.id,
				label: decodeEntities( user.name ) || __( '(no name)', 'newspack-listings' ),
			} ) );
		} );
	};

	fetchCategorySuggestions = search => {
		if ( ! search ) {
			return new Promise( resolve => resolve( [] ) );
		}

		return apiFetch( {
			path: addQueryArgs( '/newspack-listings/v1/terms', {
				search,
				per_page: 20,
				_fields: 'id,name',
				orderby: 'count',
				order: 'desc',
				taxonomy: 'category',
			} ),
		} ).then( function ( categories ) {
			return categories.map( category => ( {
				value: category.id,
				label: decodeEntities( category.name ) || __( '(no title)', 'newspack-listings' ),
			} ) );
		} );
	};
	fetchSavedCategories = categoryIDs => {
		return apiFetch( {
			path: addQueryArgs( '/newspack-listings/v1/terms', {
				per_page: 100,
				_fields: 'id,name',
				include: categoryIDs.join( ',' ),
				taxonomy: 'category',
			} ),
		} ).then( function ( categories ) {
			return categories.map( category => ( {
				value: category.id,
				label: decodeEntities( category.name ) || __( '(no title)', 'newspack-listings' ),
			} ) );
		} );
	};

	fetchTagSuggestions = search => {
		if ( ! search ) {
			return new Promise( resolve => resolve( [] ) );
		}

		return apiFetch( {
			path: addQueryArgs( '/newspack-listings/v1/terms', {
				search,
				per_page: 20,
				_fields: 'id,name',
				orderby: 'count',
				order: 'desc',
				taxonomy: 'post_tag',
			} ),
		} ).then( function ( tags ) {
			return tags.map( tag => ( {
				value: tag.id,
				label: decodeEntities( tag.name ) || __( '(no title)', 'newspack-listings' ),
			} ) );
		} );
	};
	fetchSavedTags = tagIDs => {
		return apiFetch( {
			path: addQueryArgs( '/newspack-listings/v1/terms', {
				per_page: 100,
				_fields: 'id,name',
				include: tagIDs.join( ',' ),
				taxonomy: 'post_tag',
			} ),
		} ).then( function ( tags ) {
			return tags.map( tag => ( {
				value: tag.id,
				label: decodeEntities( tag.name ) || __( '(no title)', 'newspack-listings' ),
			} ) );
		} );
	};

	render = () => {
		const { disabled, loadMoreText, queryOptions, setAttributes, showAuthor, showLoadMore } = this.props;

		if ( ! queryOptions || ! setAttributes ) {
			return null;
		}

		const { type, authors, categories, categoryExclusions, tags, tagExclusions, maxItems, sortBy, order } = queryOptions;
		const { showAdvancedFilters } = this.state;
		const { post_types } = window.newspack_listings_data;
		const isEventList = post_types.event.name === type;
		const sortOptions = [
			{ label: __( 'Publish Date', 'newspack-listings' ), value: 'date' },
			{ label: __( 'Title', 'newspack-listings' ), value: 'title' },
		];

		// Enable event date option only if the list is a list of events.
		if ( isEventList ) {
			sortOptions.unshift( {
				label: __( 'Event Date', 'newspack-listings' ),
				value: 'event_date',
			} );
		}

		// Enable author option only if showing authors.
		if ( showAuthor ) {
			sortOptions.push( { label: __( 'Author', 'newspack-listings' ), value: 'author' } );
		}

		// Enable listing type option only if there's more than one listing type in the list.
		if ( 'any' === type || ! type ) {
			sortOptions.push( { label: __( 'Listing Type', 'newspack-listings' ), value: 'type' } );
		}

		return [
			<BaseControl disabled={ disabled } key="queryControls" { ...this.props } />,
			<SelectControl
				key="type"
				disabled={ disabled }
				label={ __( 'Listing Type', 'newspack-listings' ) }
				value={ type }
				onChange={ value => setAttributes( { queryOptions: { ...queryOptions, type: value } } ) }
				options={ [
					{ label: __( 'Any', 'newspack-listings' ), value: 'any' },
					{ label: __( 'Event', 'newspack-listings' ), value: post_types.event.name },
					{ label: __( 'Generic', 'newspack-listings' ), value: post_types.generic.name },
					{ label: __( 'Marketplace', 'newspack-listings' ), value: post_types.marketplace.name },
					{ label: __( 'Place', 'newspack-listings' ), value: post_types.place.name },
				] }
			/>,
			<AutocompleteTokenField
				key="authors"
				disabled={ disabled }
				tokens={ authors || [] }
				onChange={ value => setAttributes( { queryOptions: { ...queryOptions, authors: value } } ) }
				fetchSuggestions={ this.fetchAuthorSuggestions }
				fetchSavedInfo={ this.fetchSavedAuthors }
				label={ __( 'Authors', 'newspack-listings' ) }
			/>,
			<AutocompleteTokenField
				key="categories"
				disabled={ disabled }
				tokens={ categories || [] }
				onChange={ value => setAttributes( { queryOptions: { ...queryOptions, categories: value } } ) }
				fetchSuggestions={ this.fetchCategorySuggestions }
				fetchSavedInfo={ this.fetchSavedCategories }
				label={ __( 'Categories', 'newspack-listings' ) }
			/>,
			<AutocompleteTokenField
				key="tags"
				disabled={ disabled }
				tokens={ tags || [] }
				onChange={ value => setAttributes( { queryOptions: { ...queryOptions, tags: value } } ) }
				fetchSuggestions={ this.fetchTagSuggestions }
				fetchSavedInfo={ this.fetchSavedTags }
				label={ __( 'Tags', 'newspack-listings' ) }
			/>,
			<RangeControl
				key="maxItems"
				disabled={ disabled }
				label={ __( 'Max number of items', 'newspack-listings' ) }
				help={ __(
					'Maximum number of listings to show at a time. If using the "load more" option, the results will be paginated by this number.',
					'newspack-listings'
				) }
				value={ maxItems }
				onChange={ value => setAttributes( { queryOptions: { ...queryOptions, maxItems: value } } ) }
				min={ 1 }
				max={ 50 }
				required
			/>,
			<ToggleControl
				key="loadMore"
				disabled={ disabled }
				help={ __( 'Will be shown only if there more listings to load.', 'newspack-listings' ) }
				label={ __( 'Show "load more" button', 'newspack-listings' ) }
				checked={ showLoadMore }
				onChange={ () => setAttributes( { showLoadMore: ! showLoadMore } ) }
			/>,
			showLoadMore && (
				<TextControl
					key="loadMoreText"
					disabled={ disabled }
					label={ __( '"Load more" button text', 'newspack-listings' ) }
					value={ loadMoreText }
					onChange={ value => setAttributes( { loadMoreText: value } ) }
				/>
			),
			<p key="toggle-advanced-filters">
				<Button isLink onClick={ () => this.setState( { showAdvancedFilters: ! showAdvancedFilters } ) }>
					{ showAdvancedFilters ? __( 'Hide Advanced Filters', 'newspack-listings' ) : __( 'Show Advanced Filters', 'newspack-listings' ) }
				</Button>
			</p>,
			showAdvancedFilters && (
				<Fragment>
					<SelectControl
						disabled={ disabled }
						label={ __( 'Sort By', 'newspack-listings' ) }
						value={ sortBy }
						onChange={ value => setAttributes( { queryOptions: { ...queryOptions, sortBy: value } } ) }
						options={ sortOptions }
					/>
					<RadioControl
						disabled={ disabled }
						label={ __( 'Sort Order', 'newspack-listings' ) }
						selected={ order }
						onChange={ value => setAttributes( { queryOptions: { ...queryOptions, order: value } } ) }
						options={ [
							{ label: __( 'Ascending', 'newspack-listings' ), value: 'ASC' },
							{ label: __( 'Descending', 'newspack-listings' ), value: 'DESC' },
						] }
					/>
					<AutocompleteTokenField
						key="category-exclusion"
						disabled={ disabled }
						tokens={ categoryExclusions || [] }
						onChange={ value => setAttributes( { queryOptions: { ...queryOptions, categoryExclusions: value } } ) }
						fetchSuggestions={ this.fetchCategorySuggestions }
						fetchSavedInfo={ this.fetchSavedCategories }
						label={ __( 'Excluded Categories', 'newspack-listings' ) }
					/>
					<AutocompleteTokenField
						key="tag-exclusion"
						disabled={ disabled }
						tokens={ tagExclusions || [] }
						onChange={ value => setAttributes( { queryOptions: { ...queryOptions, tagExclusions: value } } ) }
						fetchSuggestions={ this.fetchTagSuggestions }
						fetchSavedInfo={ this.fetchSavedTags }
						label={ __( 'Excluded Tags', 'newspack-listings' ) }
					/>
				</Fragment>
			),
		];
	};
}

export default QueryControls;
