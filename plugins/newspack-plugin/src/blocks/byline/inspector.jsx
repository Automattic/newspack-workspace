/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';

/**
 * Inspector controls for the byline block.
 *
 * @param {Object}   props                Component props.
 * @param {Object}   props.attributes     Block attributes.
 * @param {Function} props.setAttributes  Set attributes function.
 * @param {boolean}  props.isCustomByline Whether custom byline is active.
 * @return {JSX.Element} Inspector controls.
 */
export function BylineInspectorControls( { attributes, setAttributes, isCustomByline } ) {
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Settings', 'newspack-plugin' ) }>
				{ ! isCustomByline && (
					<>
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Prefix', 'newspack-plugin' ) }
							help={ __( 'Text displayed before the author name(s).', 'newspack-plugin' ) }
							value={ attributes.prefix }
							onChange={ prefix => setAttributes( { prefix } ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Link to author archive', 'newspack-plugin' ) }
							checked={ attributes.linkToAuthorArchive }
							onChange={ () => setAttributes( { linkToAuthorArchive: ! attributes.linkToAuthorArchive } ) }
						/>
					</>
				) }
				{ isCustomByline && (
					<p className="components-base-control__help">
						{ __( 'Prefix and link settings are controlled by the custom byline and cannot be changed here.', 'newspack-plugin' ) }
					</p>
				) }
			</PanelBody>
		</InspectorControls>
	);
}

BylineInspectorControls.propTypes = {
	attributes: PropTypes.shape( {
		prefix: PropTypes.string,
		linkToAuthorArchive: PropTypes.bool,
	} ).isRequired,
	setAttributes: PropTypes.func.isRequired,
	isCustomByline: PropTypes.bool.isRequired,
};
