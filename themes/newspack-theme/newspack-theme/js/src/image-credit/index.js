'use strict';

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import FeaturedImageEditor from './FeaturedImageEditor';
import { appendFeaturedImageToDomElement, connectWithSelect } from './utils';

/**
 * Component to be used as a panel in the Document tab of the Editor.
 *
 * https://developer.wordpress.org/block-editor/developers/slotfills/plugin-document-setting-panel/
 */
const NewspackFeaturedImagePanel = ( { caption, credit, mode } ) => {
	// Update the DOM when subtitle value changes or editor mode is switched
	useEffect( () => {
		appendFeaturedImageToDomElement( caption, credit, mode === 'text' );
	}, [ caption, credit, mode ] );

	return (
		<PluginDocumentSettingPanel
			name="newspack-featured-image-credit-caption"
			title={ __( 'Credit/Caption', 'newspack' ) }
			className="newspack-credit-caption"
		>
			{ __( 'Set Featured Image Credit & Caption', 'newspack' ) }
			<FeaturedImageEditor />
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'plugin-document-setting-panel-newspack-credit-caption', {
	render: connectWithSelect( NewspackFeaturedImagePanel ),
	icon: null,
} );
