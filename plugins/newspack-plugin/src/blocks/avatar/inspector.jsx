/**
 * WordPress dependencies
 */
import { InspectorControls } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { PanelBody, RangeControl, ToggleControl } from '@wordpress/components';

/**
 * Inspector controls for the Avatar block.
 *
 * @param {Object}   props               Component props.
 * @param {Function} props.setAttributes Function to update block attributes.
 * @param {Object}   props.attributes    Block attributes.
 * @return {JSX.Element} The inspector controls panel.
 */
const AvatarInspectorControls = ( { setAttributes, attributes } ) => (
	<InspectorControls>
		<PanelBody title={ __( 'Settings', 'newspack-plugin' ) }>
			<RangeControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Image size', 'newspack-plugin' ) }
				onChange={ newSize =>
					setAttributes( {
						size: newSize,
					} )
				}
				min={ 16 }
				max={ 128 }
				initialPosition={ attributes.size }
				value={ attributes.size }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Link to author archive', 'newspack-plugin' ) }
				onChange={ () => setAttributes( { linkToAuthorArchive: ! attributes.linkToAuthorArchive } ) }
				checked={ attributes.linkToAuthorArchive }
			/>
		</PanelBody>
	</InspectorControls>
);

export default AvatarInspectorControls;
