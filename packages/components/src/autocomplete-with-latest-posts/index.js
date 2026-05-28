/**
 * External dependencies
 */
import debounce from 'lodash/debounce';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, FormTokenField, Spinner } from '@wordpress/components';
import { decodeEntities } from '@wordpress/html-entities';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import './style.scss';

const AutocompleteWithLatestPosts = ( {
	fetchSuggestions = false, // If passed, will use this function to fetch suggestions data.
	help = __( 'Begin typing search term, click autocomplete result to select.', 'newspack-plugin' ),
	hideHelp = true, // If true, all help text will be hidden.
	hideFormTokenHelp = true, // If true, hides FormTokenField's built-in help text.
	label = __( 'Search', 'newspack-plugin' ),
	maxItemsToSuggest = 0, // If passed, will be used to determine "load more" state. Necessary if you want "load more" functionality when using a custom `fetchSuggestions` function.
	multiSelect = false, // If true, component can select multiple values at once.
	onChange = false, // Function to call when selections change.
	selectedItems = [], // Array of saved items.
	suggestionsToFetch = 20, // Number of suggestions to fetch per query.
} ) => {
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isLoadingMore, setIsLoadingMore ] = useState( false );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ maxSuggestions, setMaxSuggestions ] = useState( 0 );
	const [ searchSuggestions, setSearchSuggestions ] = useState( [] );
	const [ validValues, setValidValues ] = useState( {} );
	const [ isSearching, setIsSearching ] = useState( false );
	// Leaving this in here so we can expand to use different post types in the future; right now it defaults to 'post'.
	const postTypeToSearch = 'post';
	const classNames = [ 'newspack-autocomplete-with-latest-posts' ];

	if ( hideFormTokenHelp ) {
		classNames.push( 'hide-form-token-help' );
	}

	if ( hideHelp ) {
		classNames.push( 'hide-help' );
	}

	/**
	 * Debounced function to update search suggestions.
	 */
	const debouncedUpdateSuggestions = debounce( input => {
		if ( ! input || input.length < 2 ) {
			setSearchSuggestions( [] );
			setIsSearching( false );
			return;
		}

		setIsSearching( true );
		handleFetchSuggestions( input, 0, postTypeToSearch )
			.then( _suggestions => {
				setSearchSuggestions( _suggestions );
				// Update valid values for token conversion
				const newValidValues = { ...validValues };
				_suggestions.forEach( suggestion => {
					newValidValues[ suggestion.value ] = suggestion.label;
				} );
				setValidValues( newValidValues );
			} )
			.finally( () => setIsSearching( false ) );
	}, 500 );

	/**
	 * Fetch recent posts to show as suggestions.
	 */
	useEffect( () => {
		setIsLoading( true );
		handleFetchSuggestions( null, 0, postTypeToSearch )
			.then( _suggestions => {
				if ( 0 < _suggestions.length ) {
					setSuggestions( _suggestions );
					// Update valid values for token conversion
					const newValidValues = { ...validValues };
					_suggestions.forEach( suggestion => {
						newValidValues[ suggestion.value ] = suggestion.label;
					} );
					setValidValues( newValidValues );
				}
			} )
			.finally( () => setIsLoading( false ) );
	}, [] );

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
	 * Get labels for token values.
	 */
	const getLabelsForValues = values => {
		return values.reduce( ( accumulator, value ) => {
			if ( ! value ) {
				return accumulator;
			}
			if ( value.label ) {
				return [ ...accumulator, value.label ];
			}
			return validValues[ value ] ? [ ...accumulator, validValues[ value ] ] : accumulator;
		}, [] );
	};

	/**
	 * Get values for token labels.
	 */
	const getValuesForLabels = labels => {
		// eslint-disable-next-line @typescript-eslint/no-shadow
		return labels.reduce( ( acc, label ) => {
			Object.keys( validValues ).forEach( key => {
				if ( validValues[ key ] === label ) {
					const value = isNaN( parseInt( key ) ) ? key.toString() : parseInt( key );
					acc.push( { value, label } );
				}
			} );
			return acc;
		}, [] );
	};

	/**
	 * Handle FormTokenField onChange.
	 */
	const handleTokenChange = tokenStrings => {
		const newSelections = getValuesForLabels( tokenStrings );

		// If only allowing one selection, just return the one selected item.
		if ( ! multiSelect ) {
			const newSelection = newSelections[ newSelections.length - 1 ]; // Get the last selected item
			if ( newSelection ) {
				return onChange( [ { ...newSelection, postType: postTypeToSearch } ] );
			}
			return onChange( [] );
		}

		// For multi-select, include currently selected post type in selection results.
		onChange( newSelections.map( selection => ( { ...selection, postType: postTypeToSearch } ) ) );
	};

	/**
	 * Get tokens for FormTokenField.
	 */
	const getTokens = () => {
		return getLabelsForValues( selectedItems );
	};

	/**
	 * Render a single suggestion object that can be clicked to select it immediately.
	 *
	 * @param {Object} suggestion Suggestion object with value and label keys.
	 */
	const renderSuggestion = suggestion => {
		if ( multiSelect ) {
			const isSelected = !! selectedItems.find(
				_selection => parseInt( _selection.value ) === parseInt( suggestion.value ) && _selection.label === suggestion.label
			);
			return (
				<CheckboxControl
					key={ suggestion.value }
					checked={ isSelected }
					onChange={ () => {
						// For multi-select, we need to toggle the selection
						const currentTokens = getTokens();
						const suggestionLabel = suggestion.label;

						if ( isSelected ) {
							// Remove the suggestion
							const newTokens = currentTokens.filter( token => token !== suggestionLabel );
							handleTokenChange( newTokens );
						} else {
							// Add the suggestion
							handleTokenChange( [ ...currentTokens, suggestionLabel ] );
						}
					} }
					label={ suggestion.label }
				/>
			);
		}
		return (
			<Button isLink key={ suggestion.value } onClick={ () => handleTokenChange( [ ...getTokens(), suggestion.label ] ) }>
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
			? 'newspack-autocomplete-with-latest-posts__search-suggestions-multiselect'
			: 'newspack-autocomplete-with-latest-posts__search-suggestions';

		return (
			<>
				<div className="newspack-autocomplete-with-latest-posts__search-suggestions-container">
					<p className="newspack-autocomplete-with-suggestions__label">{ __( 'Latest Posts', 'newspack-plugin' ) }</p>
					<div className={ className }>
						{ suggestions.map( renderSuggestion ) }
						{ suggestions.length < ( maxItemsToSuggest || maxSuggestions ) && (
							<Button disabled={ isLoadingMore } isSecondary onClick={ () => setIsLoadingMore( true ) }>
								{ isLoadingMore ? __( 'Loading…', 'newspack-plugin' ) : __( 'Load more', 'newspack-plugin' ) }
							</Button>
						) }
					</div>
				</div>
			</>
		);
	};

	return (
		<div className={ classNames.join( ' ' ) }>
			<div className="newspack-autocomplete-with-latest-posts__input-container">
				<FormTokenField
					value={ getTokens() }
					suggestions={ searchSuggestions.map( suggestion => suggestion.label ) }
					onChange={ handleTokenChange }
					onInputChange={ debouncedUpdateSuggestions }
					label={ label }
					help={ ! hideHelp && help }
					__next40pxDefaultSize={ true }
					__nextHasNoMarginBottom={ true }
				/>
				{ ( isLoading || isSearching ) && <Spinner /> }
			</div>
			{ renderSuggestions() }
		</div>
	);
};

export default AutocompleteWithLatestPosts;
