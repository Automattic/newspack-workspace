/**
 * WordPress dependencies
 */
import { PanelBody, SelectControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { fetchTones } from '../utils/api';

/**
 * Inspector component for the Roundup block sidebar controls.
 *
 * Provides settings and actions for the generated roundup.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to set block attributes.
 * @param {Function} props.onRegenerate  Function to regenerate content.
 * @param {Function} props.onDetach      Function to detach the block.
 * @return {JSX.Element} The inspector component.
 */
export default function Inspector( { attributes, setAttributes, onRegenerate, onDetach } ) {
	const { tone, selectedPosts } = attributes;
	const [ availableTones, setAvailableTones ] = useState( [] );

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

	return (
		<>
			<PanelBody title={ __( 'Roundup Settings', 'newspack-manager' ) } initialOpen={ true }>
				{ availableTones.length > 0 && (
					<SelectControl
						label={ __( 'Tone', 'newspack-manager' ) }
						value={ tone }
						options={ availableTones }
						onChange={ newTone => setAttributes( { tone: newTone } ) }
						help={ __( 'The tone of voice for the generated content.', 'newspack-manager' ) }
					/>
				) }

				<div className="roundup-inspector__summary">
					<p>
						<strong>{ __( 'Selected Posts:', 'newspack-manager' ) }</strong>{ ' ' }
						{ selectedPosts.length }
					</p>
				</div>

				<Button isSecondary onClick={ onRegenerate } className="roundup-inspector__regenerate">
					{ __( 'Regenerate Content', 'newspack-manager' ) }
				</Button>
			</PanelBody>

			<PanelBody title={ __( 'Actions', 'newspack-manager' ) }>
				<p className="roundup-inspector__help-text">
					{ __(
						'Detaching will convert this block to editable core blocks (paragraphs, headings, images).',
						'newspack-manager'
					) }
				</p>
				<Button isDestructive onClick={ onDetach } className="roundup-inspector__detach">
					{ __( 'Detach Block', 'newspack-manager' ) }
				</Button>
			</PanelBody>
		</>
	);
}
