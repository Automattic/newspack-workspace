/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { search as searchIcon } from '@wordpress/icons';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __unstableStripHTML as stripHTML } from '@wordpress/dom';
import {
	InspectorControls,
	RichText,
	useBlockProps,
	useSettings,
	/* eslint-disable @wordpress/no-unsafe-wp-apis */
	__experimentalColorGradientSettingsDropdown as ColorGradientSettingsDropdown,
	__experimentalGetSpacingClassesAndStyles as useSpacingProps,
	/* eslint-enable @wordpress/no-unsafe-wp-apis */
} from '@wordpress/block-editor';
import { Icon } from '@wordpress/components';

export default function OverlaySearchEdit( { attributes, setAttributes, clientId } ) {
	const { triggerText, className: blockClassName, overlayColor } = attributes;

	const spacingProps = useSpacingProps( attributes );
	const [ colorSettings ] = useSettings( 'color.palette' );

	const classes = ( blockClassName || '' ).split( ' ' );
	const isIconOnly = classes.includes( 'is-style-icon-only' );
	const isTextOnly = classes.includes( 'is-style-text-only' );
	const isLabelVisible = ! isIconOnly;
	const isIconVisible = ! isTextOnly;

	const blockProps = useBlockProps( {
		className: classnames( 'wp-element-button', 'wp-block-button__link', 'newspack-overlay-search__trigger' ),
		style: {
			...spacingProps.style,
		},
	} );

	return (
		<>
			<InspectorControls group="color">
				<ColorGradientSettingsDropdown
					settings={ [
						{
							colorValue: overlayColor,
							label: __( 'Overlay', 'newspack-plugin' ),
							onColorChange: value => setAttributes( { overlayColor: value || '' } ),
							hasValue: () => !! overlayColor,
							onDeselect: () => setAttributes( { overlayColor: '' } ),
							isShownByDefault: true,
							resetAllFilter: () => ( { overlayColor: '' } ),
						},
					] }
					panelId={ clientId }
					colors={ colorSettings }
					gradients={ [] }
					enableAlpha
					disableCustomGradients
					__experimentalIsRenderedInSidebar
				/>
			</InspectorControls>
			<div className="wp-block-buttons is-layout-flex">
				<div className="wp-block-button">
					<button { ...blockProps } type="button" onClick={ e => e.preventDefault() }>
						{ isIconVisible && (
							<span className="newspack-overlay-search__icon" aria-hidden="true">
								<Icon icon={ searchIcon } />
							</span>
						) }
						<RichText
							tagName="span"
							className={ ! isLabelVisible ? 'screen-reader-text' : undefined }
							aria-label={ __( 'Button text', 'newspack-plugin' ) }
							placeholder={ __( 'Search', 'newspack-plugin' ) }
							value={ triggerText || '' }
							onChange={ val => setAttributes( { triggerText: stripHTML( val ) } ) }
							withoutInteractiveFormatting
						/>
					</button>
				</div>
			</div>
		</>
	);
}
