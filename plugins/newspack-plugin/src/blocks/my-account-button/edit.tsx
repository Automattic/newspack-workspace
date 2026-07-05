/**
 * External dependencies
 */
/* globals newspack_blocks */
import classnames from 'classnames';
import { account as icon } from '../../../packages/icons';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __unstableStripHTML as stripHTML } from '@wordpress/dom';
import { useState } from '@wordpress/element';
import {
	BlockControls,
	RichText,
	useBlockProps,
	/* eslint-disable @wordpress/no-unsafe-wp-apis */
	__experimentalUseBorderProps as useBorderProps,
	__experimentalUseColorProps as useColorProps,
	__experimentalGetSpacingClassesAndStyles as useSpacingProps,
	/* eslint-enable @wordpress/no-unsafe-wp-apis */
} from '@wordpress/block-editor';
import { ToolbarButton, ToolbarGroup } from '@wordpress/components';

function MyAccountButtonEdit( { attributes, setAttributes } ) {
	const { signedInLabel, signedOutLabel, style, className: blockClassName } = attributes;
	const borderProps = useBorderProps( attributes );
	const colorProps = useColorProps( attributes );
	const spacingProps = useSpacingProps( attributes );
	const classes = ( blockClassName || '' ).split( ' ' );
	const isIconOnly = classes.includes( 'is-style-icon-only' );
	const isTextOnly = classes.includes( 'is-style-text-only' );
	const isLabelVisible = ! isIconOnly;
	const isIconVisible = ! isTextOnly;

	const blockProps = useBlockProps( {
		className: classnames(
			blockClassName,
			'wp-block-button__link',
			'newspack-reader__account-link',
			'wp-block-newspack-my-account-button__link',
			colorProps.className,
			borderProps.className,
			{
				// For backwards compatibility add style that isn't
				// provided via block support.
				'no-border-radius': style?.border?.radius === 0,
			}
		),
		style: {
			...borderProps.style,
			...colorProps.style,
			...spacingProps.style,
		},
	} );

	const isReaderActivationEnabled = typeof newspack_blocks === 'undefined' || newspack_blocks.has_reader_activation;
	const [ previewState, setPreviewState ] = useState( 'signedout' );
	const isSignedOutPreview = previewState === 'signedout';
	const activeLabel = isSignedOutPreview ? signedOutLabel : signedInLabel;
	const placeholderText = isSignedOutPreview ? __( 'Sign in', 'newspack-plugin' ) : __( 'My Account', 'newspack-plugin' );

	function setButtonText( newText ) {
		setAttributes( isSignedOutPreview ? { signedOutLabel: stripHTML( newText ) } : { signedInLabel: stripHTML( newText ) } );
	}

	if ( ! isReaderActivationEnabled ) {
		return <div { ...blockProps } style={ { ...blockProps.style, display: 'none' } } />;
	}

	return (
		<>
			{ isLabelVisible && (
				<BlockControls>
					<ToolbarGroup>
						<ToolbarButton
							icon={ false }
							isPressed={ isSignedOutPreview }
							label={ __( 'Signed out', 'newspack-plugin' ) }
							onClick={ () => setPreviewState( 'signedout' ) }
							style={ { paddingLeft: '12px', paddingRight: '12px' } }
						>
							{ __( 'Signed out', 'newspack-plugin' ) }
						</ToolbarButton>
						<ToolbarButton
							icon={ false }
							isPressed={ ! isSignedOutPreview }
							label={ __( 'Signed in', 'newspack-plugin' ) }
							onClick={ () => setPreviewState( 'signedin' ) }
							style={ { paddingLeft: '12px', paddingRight: '12px' } }
						>
							{ __( 'Signed in', 'newspack-plugin' ) }
						</ToolbarButton>
					</ToolbarGroup>
				</BlockControls>
			) }
			<div className={ classnames( 'wp-block-buttons', 'is-layout-flex', blockClassName ) }>
				<div className="wp-block-button">
					<div { ...blockProps }>
						{ isIconVisible && (
							<span className="wp-block-newspack-my-account-button__icon" aria-hidden="true">
								{ icon }
							</span>
						) }
						<RichText
							tagName="span"
							className={ ! isLabelVisible ? 'screen-reader-text' : undefined }
							aria-label={ __( 'Button text', 'newspack-plugin' ) }
							placeholder={ placeholderText }
							value={ activeLabel || '' }
							onChange={ setButtonText }
							withoutInteractiveFormatting
						/>
					</div>
				</div>
			</div>
		</>
	);
}

export default MyAccountButtonEdit;
