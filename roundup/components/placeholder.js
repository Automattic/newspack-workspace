/**
 * WordPress dependencies
 */
import { Placeholder, Button, Spinner, Notice, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * External dependencies
 */
// eslint-disable-next-line import/no-unresolved
import AutocompleteWithLatestPosts from 'newspack-components/autocomplete-with-latest-posts';
import { aiText } from 'newspack-icons';

/**
 * Internal dependencies
 */
import { generateRoundup, fetchTones } from '../utils/api';

/**
 * Placeholder component for the Roundup block.
 *
 * Shows the initial UI for selecting posts and generating content.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to set block attributes.
 * @param {boolean}  props.isLoading     Whether content is being generated.
 * @param {Function} props.setIsLoading  Function to set loading state.
 * @param {string}   props.error         Error message if any.
 * @param {Function} props.setError      Function to set error message.
 * @return {JSX.Element} The placeholder component.
 */
export default function RoundupPlaceholder( {
	attributes,
	setAttributes,
	isLoading,
	setIsLoading,
	error,
	setError,
} ) {
	const { selectedPosts, tone } = attributes;
	const [ localSelectedPosts, setLocalSelectedPosts ] = useState(
		selectedPosts.map( post => ( { value: post.id, label: post.title } ) )
	);
	const [ availableTones, setAvailableTones ] = useState( [] );
	const [ selectedTone, setSelectedTone ] = useState( tone || 'follow-posts' );

	/**
	 * Fetch available tones on mount.
	 */
	useEffect( () => {
		fetchTones()
			.then( tones => {
				setAvailableTones( tones );
			} )
			.catch( err => {
				// eslint-disable-next-line no-console
				console.error( 'Error fetching tones:', err );
			} );
	}, [] );

	/**
	 * Fetch suggestions for AutocompleteWithLatestPosts.
	 *
	 * @param {string} search Search term.
	 * @param {number} offset Offset for pagination.
	 * @return {Promise<Array>} Array of suggestions with value and label.
	 */
	const fetchSuggestions = async ( search = null, offset = 0 ) => {
		const response = await apiFetch( {
			path: '/wp/v2/newspack-roundup/posts',
			method: 'POST',
			data: {
				search: search || '',
				page: Math.floor( offset / 20 ) + 1,
				per_page: 20,
			},
		} );

		// Format suggestions for AutocompleteWithLatestPosts.
		return response.posts.map( post => ( {
			value: parseInt( post.id ),
			label: post.title,
		} ) );
	};

	/**
	 * Handle post selection changes.
	 *
	 * @param {Array} selections Selected items with value and label.
	 */
	const handlePostSelection = selections => {
		setLocalSelectedPosts( selections );
		// Convert to the format expected by attributes (with id and title)
		const posts = selections.map( s => ( { id: s.value, title: s.label } ) );
		setAttributes( { selectedPosts: posts } );
	};

	/**
	 * Handle generate button click.
	 */
	const handleGenerate = async () => {
		if ( localSelectedPosts.length === 0 ) {
			setError( __( 'Please select at least one post.', 'newspack-manager' ) );
			return;
		}

		setIsLoading( true );
		setError( null );

		try {
			const postIds = localSelectedPosts.map( post => post.value );
			const result = await generateRoundup( {
				postIds,
				imageIds: [],
				options: { tone: selectedTone },
			} );

			if ( result.success && result.content ) {
				setAttributes( {
					generatedContent: result.content,
					isGenerated: true,
					tone: selectedTone,
				} );
			} else {
				setError(
					__( 'Failed to generate roundup content. Please try again.', 'newspack-manager' )
				);
			}
		} catch ( err ) {
			setError( err.message || __( 'An error occurred. Please try again.', 'newspack-manager' ) );
		} finally {
			setIsLoading( false );
		}
	};

	if ( isLoading ) {
		return (
			<Placeholder
				icon={ aiText }
				label={ __( 'Roundup', 'newspack-manager' ) }
				className="roundup-placeholder roundup-loading"
			>
				<Spinner />
				<p>{ __( 'Generating your roundup content…', 'newspack-manager' ) }</p>
			</Placeholder>
		);
	}

	return (
		<Placeholder
			icon={ aiText }
			label={ __( 'Roundup', 'newspack-manager' ) }
			instructions={ __(
				'Select posts to include in your AI-powered roundup',
				'newspack-manager'
			) }
			className="roundup-placeholder"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ availableTones.length > 0 && (
				<SelectControl
					label={ __( 'Tone', 'newspack-manager' ) }
					value={ selectedTone }
					options={ availableTones }
					onChange={ setSelectedTone }
					help={ __( 'Select the tone for your roundup content.', 'newspack-manager' ) }
				/>
			) }

			<AutocompleteWithLatestPosts
				label={ __( 'Select Posts', 'newspack-manager' ) }
				help={ __(
					'Begin typing to search or select from recent posts below.',
					'newspack-manager'
				) }
				hideHelp={ false }
				hideFormTokenHelp={ true }
				multiSelect={ true }
				selectedItems={ localSelectedPosts }
				onChange={ handlePostSelection }
				fetchSuggestions={ fetchSuggestions }
				maxItemsToSuggest={ 0 }
			/>

			{ localSelectedPosts.length > 0 && (
				<div className="roundup-placeholder__actions">
					<Button isPrimary onClick={ handleGenerate } disabled={ isLoading }>
						{ __( 'Generate Roundup', 'newspack-manager' ) }
					</Button>
					<p className="roundup-placeholder__selected-count">
						{ localSelectedPosts.length }{ ' ' }
						{ localSelectedPosts.length === 1
							? __( 'post selected', 'newspack-manager' )
							: __( 'posts selected', 'newspack-manager' ) }
					</p>
				</div>
			) }
		</Placeholder>
	);
}
