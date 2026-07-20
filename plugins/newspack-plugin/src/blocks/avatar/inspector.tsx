/**
 * WordPress dependencies
 */
import { InspectorControls } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { PanelBody, RangeControl, ToggleControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import type { AvatarAttributes } from './utils';

/**
 * Props for the Avatar block inspector controls.
 */
type AvatarInspectorControlsProps = {
	/** Block attributes. */
	attributes: AvatarAttributes;
	/** Function to update block attributes. */
	setAttributes: ( attributes: Partial< AvatarAttributes > ) => void;
};

/**
 * Inspector controls for the Avatar block.
 *
 * @param props               Component props.
 * @param props.setAttributes Function to update block attributes.
 * @param props.attributes    Block attributes.
 * @return The inspector controls panel.
 */
const AvatarInspectorControls = ( { setAttributes, attributes }: AvatarInspectorControlsProps ) => (
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
