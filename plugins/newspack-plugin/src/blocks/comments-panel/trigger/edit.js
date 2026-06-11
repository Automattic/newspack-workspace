/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
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
import { comments as commentsIcon } from '../../../../packages/icons';

/**
 * Edit component for the Comments Panel Trigger block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @param {string}   props.clientId      Block client ID.
 *
 * @return {JSX.Element} The block editor UI.
 */
export default function CommentsPanelTriggerEdit( { attributes, setAttributes, clientId } ) {
	const { triggerText, className: blockClassName } = attributes;

	const classes = ( blockClassName || '' ).split( ' ' );
	const isIconOnly = classes.includes( 'is-style-icon-only' );
	const isTextOnly = classes.includes( 'is-style-text-only' );
	const showTriggerIcon = ! isTextOnly;

	// The content block registers its toggle under the parent's clientId.
	const parentClientId = useSelect( select => select( 'core/block-editor' ).getBlockRootClientId( clientId ), [ clientId ] );

	// Only the first Comments Panel renders a real panel, so only its trigger shows the toggle.
	const isFirstInstance = useSelect(
		select => {
			const ids = select( 'core/block-editor' ).getBlocksByName( 'newspack/comments-panel' );
			return ! ids.length || ids[ 0 ] === parentClientId;
		},
		[ parentClientId ]
	);

	// Mirror the panel's open state so the toolbar button label and isPressed stay correct.
	const [ isPanelOpen, setIsPanelOpen ] = useState( false );
	useEffect( () => {
		if ( ! parentClientId ) {
			return;
		}
		return subscribeToPanel( parentClientId, setIsPanelOpen );
	}, [ parentClientId ] );

	const blockProps = useBlockProps( {
		className: 'comments-panel__trigger wp-block-button__link wp-element-button',
	} );

	return (
		<>
			{ isFirstInstance && <PanelPreviewToggle isOpen={ isPanelOpen } onToggle={ () => panelToggles.get( parentClientId )?.() } /> }

			<div className="wp-block-buttons is-layout-flex">
				<div className="wp-block-button">
					<button { ...blockProps } type="button" aria-controls="newspack-comments-panel" onClick={ e => e.preventDefault() }>
						{ showTriggerIcon && (
							<span className="comments-panel__icon" aria-hidden="true">
								{ commentsIcon }
							</span>
						) }
						<RichText
							tagName="span"
							className={ isIconOnly ? 'screen-reader-text' : undefined }
							aria-label={ __( 'Button text', 'newspack-plugin' ) }
							placeholder={ __( 'Comments', 'newspack-plugin' ) }
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
