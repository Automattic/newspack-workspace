/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, SelectControl } from '@wordpress/components';
import { decodeEntities } from '@wordpress/html-entities';
import { useEffect, useState } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * External dependencies.
 */
import { AutocompleteTokenField } from '../';
import './style.scss';

const AutocompleteWithSuggestions = ( {
	fetchSavedPosts = false, // If passed, will use this function to fetch saved data.
	fetchSuggestions = false, // If passed, will use this function to fetch suggestions data.
	help = __( 'Begin typing search term, click autocomplete result to select.', 'newspack-plugin' ),
	hideHelp = false, // If true, all help text will be hidden.
	label = __( 'Search', 'newspack-plugin' ),
	maxItemsToSuggest = 0, // If passed, will be used to determine "load more" state. Necessary if you want "load more" functionality when using a custom `fetchSuggestions` function.
	multiSelect = false, // If true, component can select multiple values at once.
	onChange = false, // Function to call when selections change.
	onPostTypeChange = false, // Funciton to call when the post type selector is changed.
	postTypes = [ { slug: 'post', label: 'Post' } ], // If passed, will render a selector to change the post type queried for suggestions.
	postTypeLabel = __( 'item', 'newspack-plugin' ), // String to describe the data being shown.
	postTypeLabelPlural = __( 'items', 'newspack-plugin' ), // Plural string to describe the data being shown.
	selectedItems = [], // Array of saved items.
	selectedPost = 0, // Legacy prop when single-select was the only option.
	suggestionsToFetch = 10, // Number of suggestions to fetch per query.
} ) => {
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isLoadingMore, setIsLoadingMore ] = useState( false );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ maxSuggestions, setMaxSuggestions ] = useState( 0 );
	const [ postTypeToSearch, setPostTypeToSearch ] = useState( postTypes[ 0 ].slug );
	const classNames = [ 'newspack-autocomplete-with-suggestions' ];

	if ( hideHelp ) {
		classNames.push( 'hide-help' );
	}

	/**
	 * Fetch recent posts to show as suggestions.
	 */
	useEffect( () => {
		if ( onPostTypeChange ) {
			onPostTypeChange( postTypeToSearch );
		}

		setIsLoading( true );
		handleFetchSuggestions( null, 0, postTypeToSearch )
			.then( _suggestions => {
				if ( 0 < _suggestions.length ) {
					setSuggestions( _suggestions );
				}
			} )
			.finally( () => setIsLoading( false ) );
	}, [ postTypeToSearch ] );

	/**
	 * Fetch more suggestions.
	 */
	useEffect( () => {
		if ( isLoadingMore ) {
			handleFetchSuggestions( null, suggestions.length, postTypeToSearch )
				.then( _suggestions => {
					if ( 0 < _suggestions.length ) {
						setSuggestions( suggestions.concat( _suggestions ) );
					}
				} )
				.finally( () => setIsLoadingMore( false ) );
		}
	}, [ isLoadingMore ] );

	/**
	 * If passed a `fetchSavedPosts` prop, use that, otherwise, build it based on the selected post type.
	 */
	const handleFetchSaved = fetchSavedPosts
		? fetchSavedPosts
		: async ( postIds = [], searchSlug = null ) => {
				const postTypeSlug = searchSlug || postTypeToSearch;
				const endpoint =
					'post' === postTypeSlug || 'page' === postTypeSlug
						? postTypeSlug + 's' // Default post type endpoints are plural.
						: postTypeSlug; // Custom post type endpoints are singular.
				const posts = await apiFetch( {
					path: addQueryArgs( '/wp/v2/' + endpoint, {
						per_page: 100,
						include: postIds.join( ',' ),
						_fields: 'id,title',
					} ),
				} );

				return posts.map( post => ( {
					value: parseInt( post.id ),
					label: decodeEntities( post.title ) || __( '(no title)', 'newspack-plugin' ),
				} ) );
		  };

	/**
	 * If passed a `fetchSuggestions` prop, use that, otherwise, build it based on the selected post type.
	 */
	const handleFetchSuggestions = fetchSuggestions
		? fetchSuggestions
		: async ( search = null, offset = 0, searchSlug = null ) => {
				const postTypeSlug = searchSlug || postTypeToSearch;
				const endpoint =
					'post' === postTypeSlug || 'page' === postTypeSlug
						? postTypeSlug + 's' // Default post type endpoints are plural.
						: postTypeSlug; // Custom post type endpoints are singular.
				const response = await apiFetch( {
					parse: false,
					path: addQueryArgs( '/wp/v2/' + endpoint, {
						search,
						offset,
						per_page: suggestionsToFetch,
						_fields: 'id,title',
					} ),
				} );

				const total = parseInt( response.headers.get( 'x-wp-total' ) || 0 );
				const posts = await response.json();

				setMaxSuggestions( total );

				// Format suggestions for FormTokenField display.
				return posts.reduce( ( acc, post ) => {
					acc.push( {
						value: parseInt( post.id ),
						label: decodeEntities( post?.title.rendered ) || __( '(no title)', 'newspack-plugin' ),
					} );

					return acc;
				}, [] );
		  };

	/**
	 * Intercept onChange callback so we can decide whether to allow multiple selections.
	 */
	const handleOnChange = _selections => {
		// If only allowing one selection, just return the one selected item.
		if ( ! multiSelect ) {
			return onChange( _selections );
		}

		// Handle legacy `selectedPost` prop.
		const selections = selectedPost ? [ ...selectedItems, selectedPost ] : [ ...selectedItems ];

		// Loop through new selections to determine whether to add or remove them.
		_selections.forEach( _selection => {
			const existingSelection = selections.findIndex( selection => parseInt( selection.value ) === parseInt( _selection.value ) );

			if ( -1 < existingSelection ) {
				// If the selection is already selected, remove it.
				selections.splice( existingSelection, 1 );
			} else {
				// Otherwise, add it.
				selections.push( _selection );
			}
		} );

		// Include currently selected post type in selection results.
		onChange( selections.map( selection => ( { ...selection, postType: postTypeToSearch } ) ) );
	};

	/**
	 * Render selected item(s) for this component. Clicking on a selection deselects it.
	 */
	const renderSelections = () => {
		// Handle legacy `selectedPost` prop.
		const selections = selectedPost ? [ ...selectedItems, selectedPost ] : selectedItems;
		const selectedMessage = multiSelect
			? sprintf(
					// Translators: %1: the length of selections. %2: the selection leabel.
					__( '%1$s %2$s selected', 'newspack-plugin' ),
					selections.length,
					selections.length > 1 ? postTypeLabelPlural : postTypeLabel
			  )
			: sprintf(
					// Translators: %s: The label for the selection.
					__( 'Selected %s', 'newspack-plugin' ),
					postTypeLabel
			  );

		return (
			<div className="newspack-autocomplete-with-suggestions__selected-items">
				<p className="newspack-autocomplete-with-suggestions__label">
					{ selectedMessage }
					{ 1 < selections.length && _x( ' – ', 'separator character', 'newspack-plugin' ) }
					{ 1 < selections.length && (
						<Button onClick={ () => onChange( [] ) } isLink isDestructive>
							{ __( 'Clear all', 'newspack-plugin' ) }
						</Button>
					) }
				</p>
				{ selections.map( selection => (
					<Button
						key={ selection.value }
						className="newspack-autocomplete-with-suggestions__selected-item-button"
						isTertiary
						onClick={ () => onChange( selections.filter( _selection => _selection.value !== selection.value ) ) }
					>
						{ selection.label }
					</Button>
				) ) }
			</div>
		);
	};

	/**
	 * Render post type select dropdown.
	 */
	const renderPostTypeSelect = () => {
		return (
			<SelectControl
				label={ sprintf(
					// Translators: %s: The name of the type.
					__( '%s type', 'newspack-plugin' ),
					postTypeLabel.charAt( 0 ).toUpperCase() + postTypeLabel.slice( 1 )
				) }
				value={ postTypeToSearch }
				options={ postTypes.map( postTypeOption => ( {
					label: postTypeOption.label,
					value: postTypeOption.slug,
				} ) ) }
				onChange={ _postType => setPostTypeToSearch( _postType ) }
			/>
		);
	};

	/**
	 * Render a single suggestion object that can be clicked to select it immediately.
	 *
	 * @param {Object} suggestion Suggestion object with value and label keys.
	 */
	const renderSuggestion = suggestion => {
		if ( multiSelect ) {
			const selections = selectedPost ? [ ...selectedItems, selectedPost ] : [ ...selectedItems ];
			const isSelected = !! selections.find(
				_selection => parseInt( _selection.value ) === parseInt( suggestion.value ) && _selection.label === suggestion.label
			);
			return (
				<CheckboxControl
					key={ suggestion.value }
					checked={ isSelected }
					onChange={ () => handleOnChange( [ suggestion ] ) }
					label={ suggestion.label }
				/>
			);
		}
		return (
			<Button isLink key={ suggestion.value } onClick={ () => handleOnChange( [ suggestion ] ) }>
				{ suggestion.label }
			</Button>
		);
	};

	/**
	 * Render a list of suggestions that can be clicked to select instead of searching by title.
	 */
	const renderSuggestions = () => {
		if ( 0 === suggestions.length ) {
			return null;
		}

		const className = multiSelect
			? 'newspack-autocomplete-with-suggestions__search-suggestions-multiselect'
			: 'newspack-autocomplete-with-suggestions__search-suggestions';

		return (
			<>
				{ ! hideHelp && (
					<p className="newspack-autocomplete-with-suggestions__label">
						{
							/* Translators: %s: the name of a post type. */ sprintf(
								__( 'Or, select a recent %s:', 'newspack-plugin' ),
								postTypeLabel
							)
						}
					</p>
				) }
				<div className={ className }>
					{ suggestions.map( renderSuggestion ) }
					{ suggestions.length < ( maxItemsToSuggest || maxSuggestions ) && (
						<Button disabled={ isLoadingMore } isSecondary onClick={ () => setIsLoadingMore( true ) }>
							{ isLoadingMore ? __( 'Loading…', 'newspack-plugin' ) : __( 'Load more', 'newspack-plugin' ) }
						</Button>
					) }
				</div>
			</>
		);
	};

	return (
		<div className={ classNames.join( ' ' ) }>
			{ 0 < selectedItems.length && renderSelections() }
			{ 1 < postTypes.length && renderPostTypeSelect() }
			<AutocompleteTokenField
				tokens={ [] }
				onChange={ handleOnChange }
				fetchSuggestions={ async search => handleFetchSuggestions( search, 0, postTypeToSearch ) }
				fetchSavedInfo={ postIds => handleFetchSaved( postIds ) }
				label={ label }
				loading={ isLoading }
				help={ ! hideHelp && help }
				returnFullObjects
			/>
			{ renderSuggestions() }
		</div>
	);
};

export default AutocompleteWithSuggestions;
