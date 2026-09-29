/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	RichText,
	useBlockProps,
	/* eslint-disable @wordpress/no-unsafe-wp-apis */
	__experimentalUseBorderProps as useBorderProps,
	__experimentalUseColorProps as useColorProps,
	__experimentalGetSpacingClassesAndStyles as useSpacingProps,
	/* eslint-enable @wordpress/no-unsafe-wp-apis */
} from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';

function RepublishButtonEdit( { attributes, setAttributes } ) {
	const { buttonText, showLicense } = attributes;
	const borderProps = useBorderProps( attributes );
	const colorProps = useColorProps( attributes );
	const spacingProps = useSpacingProps( attributes );

	// License data is injected by the server via wp_add_inline_script
	// (see Republication_Tracker_Tool_Republish_Button_Block::enqueue_editor_data).
	// Null when no recognizable license is configured for the site.
	const licenseData = window.republicationTrackerToolEditor?.license || null;

	const innerClassNames = [
		'wp-block-button__link',
		'wp-element-button',
		'wp-block-republication-tracker-tool-republish-button',
		colorProps.className,
		borderProps.className,
	]
		.filter( Boolean )
		.join( ' ' );

	const blockProps = useBlockProps( {
		className: innerClassNames,
		style: {
			...borderProps.style,
			...colorProps.style,
			...spacingProps.style,
		},
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Display Settings', 'republication-tracker-tool' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show license badge', 'republication-tracker-tool' ) }
						checked={ showLicense }
						onChange={ val => setAttributes( { showLicense: val } ) }
						help={ __( 'Display the site’s configured Creative Commons license badge below the button.', 'republication-tracker-tool' ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div className="wp-block-buttons is-layout-flex">
				<div className="wp-block-button">
					<div { ...blockProps }>
						<RichText
							tagName="span"
							value={ buttonText }
							onChange={ val => setAttributes( { buttonText: val } ) }
							placeholder={ __( 'Republish This Story', 'republication-tracker-tool' ) }
							allowedFormats={ [] }
							aria-label={ __( 'Button text', 'republication-tracker-tool' ) }
						/>
					</div>
				</div>
			</div>
			{ showLicense && (
				<div className="wp-block-republication-tracker-tool-republish-button__license">
					{ licenseData ? (
						<img alt={ licenseData.description } src={ licenseData.badge } style={ { borderWidth: 0 } } />
					) : (
						<em>{ __( 'No Creative Commons license is configured.', 'republication-tracker-tool' ) }</em>
					) }
				</div>
			) }
		</>
	);
}

export default RepublishButtonEdit;
