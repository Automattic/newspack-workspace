/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { menu as menuIcon } from '@wordpress/icons';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __unstableStripHTML as stripHTML } from '@wordpress/dom';
import { useEffect, useState } from '@wordpress/element';
import { RichText, useBlockProps } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import PanelPreviewToggle from '../panel-preview-toggle';
import { panelToggles, subscribeToPanel } from '../preview-refs';

/**
 * Edit component for the Overlay Menu Trigger block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @param {string}   props.clientId      Block client ID.
 * @param {Object}   props.context       Block context (instanceId from parent).
 *
 * @return {JSX.Element} The block editor UI.
 */
export default function OverlayMenuTriggerEdit( { attributes, setAttributes, clientId, context } ) {
	const { triggerText, className: blockClassName } = attributes;
	const instanceId = context[ 'newspack-overlay-menu/instanceId' ] ?? '';

	const classes = ( blockClassName || '' ).split( ' ' );
	const isIconOnly = classes.includes( 'is-style-icon-only' );
	const isTextOnly = classes.includes( 'is-style-text-only' );
	const showTriggerIcon = ! isTextOnly;

	// The panel registers its toggle under the parent's clientId.
	const parentClientId = useSelect( select => select( 'core/block-editor' ).getBlockRootClientId( clientId ), [ clientId ] );

	// Mirror the panel's open state so the toolbar button label and isPressed stay correct.
	const [ isPanelOpen, setIsPanelOpen ] = useState( false );
	useEffect( () => {
		if ( ! parentClientId ) {
			return;
		}
		return subscribeToPanel( parentClientId, setIsPanelOpen );
	}, [ parentClientId ] );

	const blockProps = useBlockProps( {
		className: 'overlay-menu__trigger wp-block-button__link wp-element-button',
	} );

	return (
		<>
			<PanelPreviewToggle isOpen={ isPanelOpen } onToggle={ () => panelToggles.get( parentClientId )?.() } />

			<div className="wp-block-buttons is-layout-flex">
				<div className="wp-block-button">
					<button
						{ ...blockProps }
						type="button"
						aria-controls={ instanceId ? `newspack-overlay-panel-${ instanceId }` : undefined }
						aria-label={ ! triggerText || ! triggerText.trim() ? __( 'Menu', 'newspack-plugin' ) : undefined }
						onClick={ e => e.preventDefault() }
					>
						{ showTriggerIcon && (
							<span className="overlay-menu__icon" aria-hidden="true">
								{ menuIcon }
							</span>
						) }
						<RichText
							tagName="span"
							className={ isIconOnly ? 'screen-reader-text' : undefined }
							aria-label={ __( 'Button text', 'newspack-plugin' ) }
							placeholder={ __( 'Menu', 'newspack-plugin' ) }
							value={ triggerText }
							onChange={ val => setAttributes( { triggerText: stripHTML( val ) } ) }
							withoutInteractiveFormatting
						/>
					</button>
				</div>
			</div>
		</>
	);
}
