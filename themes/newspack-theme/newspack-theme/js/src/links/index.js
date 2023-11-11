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
import LinkEditor from './LinkEditor';
import { appendLinkToDomElement, connectWithSelect } from './utils';

/**
 * Component to be used as a panel in the Document tab of the Editor.
 *
 * https://developer.wordpress.org/block-editor/developers/slotfills/plugin-document-setting-panel/
 */
const NewspackLinkPanel = ( { caption, credit, mode } ) => {
	// Update the DOM when subtitle value changes or editor mode is switched
	useEffect( () => {
		appendLinkToDomElement( link, mode === 'text' );
	}, [ link, mode ] );

	return (
		<PluginDocumentSettingPanel
			name="newspack-external-link"
			title={ __( 'External Link', 'newspack' ) }
			className="newspack-external-link"
		>
			<LinkEditor />
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'plugin-document-setting-panel-newspack-external-link', {
	render: connectWithSelect( NewspackLinkPanel ),
	icon: null,
} );
