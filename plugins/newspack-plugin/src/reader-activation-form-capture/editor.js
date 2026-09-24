/**
 * Internal dependencies
 */
import '../shared/js/public-path';

/**
 * WordPress dependencies
 */
import { InspectorControls } from '@wordpress/block-editor';
import { Notice, PanelBody, ToggleControl } from '@wordpress/components';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

export const GF_BLOCK = 'gravityforms/form';

// Must match Form_Capture::BLOCK_ATTRIBUTE, which registers the same
// attribute with Gravity Forms' block schema so the block preview validates.
export const ATTRIBUTE = 'newspackFormCapture';

/**
 * Declare the capture toggle on the Gravity Forms block.
 *
 * @param {Object} settings Block settings.
 * @param {string} name     Block name.
 * @return {Object} The settings, with the attribute added for the GF block.
 */
export const addAttribute = ( settings, name ) => {
	if ( name !== GF_BLOCK ) {
		return settings;
	}
	return {
		...settings,
		attributes: {
			...settings.attributes,
			[ ATTRIBUTE ]: { type: 'boolean', default: false },
		},
	};
};

/**
 * The "Register readers" panel. The toggle stays usable while the integration
 * is inactive so the choice is saved and takes effect once it is enabled; the
 * notice says why nothing happens yet and where to fix that.
 *
 * @param {Object}   props               Component props.
 * @param {boolean}  props.checked       Current attribute value.
 * @param {Function} props.setAttributes The block's setAttributes.
 * @return {JSX.Element} The inspector panel.
 */
export const CapturePanel = ( { checked, setAttributes } ) => {
	const config = window.newspack_form_capture_editor || {};
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Newspack', 'newspack-plugin' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Register readers', 'newspack-plugin' ) }
					help={ __( 'Submitting this form registers a reader account with the email address and name entered.', 'newspack-plugin' ) }
					checked={ !! checked }
					onChange={ value => setAttributes( { [ ATTRIBUTE ]: value } ) }
				/>
				{ ! config.active && (
					<Notice status="warning" isDismissible={ false }>
						{ __( "The Gravity Forms integration isn't active, so this form won't register readers yet.", 'newspack-plugin' ) }{ ' ' }
						<a href={ config.integrations_url }>{ __( 'Check it in Integrations', 'newspack-plugin' ) }</a>
					</Notice>
				) }
			</PanelBody>
		</InspectorControls>
	);
};

const withCapturePanel = BlockEdit => props => (
	<>
		<BlockEdit { ...props } />
		{ props.name === GF_BLOCK && <CapturePanel checked={ props.attributes[ ATTRIBUTE ] } setAttributes={ props.setAttributes } /> }
	</>
);

addFilter( 'blocks.registerBlockType', 'newspack-plugin/form-capture', addAttribute );
addFilter( 'editor.BlockEdit', 'newspack-plugin/form-capture', withCapturePanel );
