'use strict';

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ReleaseEditor from './ReleaseEditor';
import { connectWithSelect } from './utils';

/**
 * Component to be used as a panel in the Document tab of the Editor.
 *
 * https://developer.wordpress.org/block-editor/developers/slotfills/plugin-document-setting-panel/
 */
const NewspackReleasePanel = () => {
	return (
		<PluginDocumentSettingPanel
			name="newspack-external-link"
			title={ __( 'External Link', 'newspack' ) }
			className="newspack-external-link"
		>
			<ReleaseEditor />
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'plugin-document-setting-panel-newspack-release', {
	render: connectWithSelect( NewspackReleasePanel ),
	icon: null,
} );
