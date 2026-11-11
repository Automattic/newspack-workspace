/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { BlockControls, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { media } from '@wordpress/icons';

/**
 * External dependencies
 */
import { ai } from 'newspack-icons';

/**
 * Internal dependencies
 */
import RoundupPlaceholder from './components/placeholder';
import ContentPreview from './components/content-preview';
import Inspector from './components/inspector';
import { detachRoundupBlock } from './utils/detach';

/**
 * Edit component for the Roundup block.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to set block attributes.
 * @param {string}   props.clientId      Block client ID.
 * @return {JSX.Element} The edit component.
 */
export default function Edit( { attributes, setAttributes, clientId } ) {
	const { isGenerated, generatedContent } = attributes;
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const blockProps = useBlockProps();

	/**
	 * Handle regenerate action.
	 */
	const handleRegenerate = () => {
		setIsLoading( true );
		setError( null );
		// Regenerate will be handled by the Placeholder component
		// Set isGenerated to false to show placeholder again
		setAttributes( {
			isGenerated: false,
			generatedContent: '',
		} );
		setIsLoading( false );
	};

	/**
	 * Handle detach action.
	 */
	const handleDetach = () => {
		detachRoundupBlock( clientId, generatedContent );
	};

	// Show placeholder if content hasn't been generated yet
	if ( ! isGenerated ) {
		return (
			<div { ...blockProps }>
				<RoundupPlaceholder
					attributes={ attributes }
					setAttributes={ setAttributes }
					isLoading={ isLoading }
					setIsLoading={ setIsLoading }
					error={ error }
					setError={ setError }
				/>
			</div>
		);
	}

	// Show generated content with controls
	return (
		<>
			<InspectorControls>
				<Inspector
					attributes={ attributes }
					setAttributes={ setAttributes }
					onRegenerate={ handleRegenerate }
					onDetach={ handleDetach }
				/>
			</InspectorControls>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarButton
						icon={ ai }
						label={ __( 'Regenerate', 'newspack-manager' ) }
						onClick={ handleRegenerate }
					/>
					<ToolbarButton
						icon={ media }
						label={ __( 'Detach', 'newspack-manager' ) }
						onClick={ handleDetach }
					/>
				</ToolbarGroup>
			</BlockControls>
			<div { ...blockProps }>
				<ContentPreview content={ generatedContent } />
			</div>
		</>
	);
}
