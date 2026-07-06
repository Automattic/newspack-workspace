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
import SubtitleEditor from './SubtitleEditor';
import { appendSubtitleToTitleDOMElement, connectWithSelect } from './utils';

/**
 * Component to be used as a panel in the Document tab of the Editor.
 *
 * https://developer.wordpress.org/block-editor/developers/slotfills/plugin-document-setting-panel/
 */
const NewspackSubtitlePanel = ( { subtitle }: { subtitle: string } ) => {
	// Update the DOM when subtitle value changes.
	useEffect( () => {
		appendSubtitleToTitleDOMElement( subtitle );
	}, [ subtitle ] );

	return (
		<PluginDocumentSettingPanel name="newspack-subtitle" title={ __( 'Article Subtitle', 'newspack-theme' ) } className="newspack-subtitle">
			{ __( 'Set a Subtitle for the Article', 'newspack-theme' ) }
			<SubtitleEditor />
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'plugin-document-setting-panel-newspack-subtitle', {
	render: connectWithSelect( NewspackSubtitlePanel ),
	// `null` suppresses registerPlugin's default plugins icon at runtime, but the
	// upstream WPPlugin type only admits `IconType` -- boundary assertion to keep it.
	icon: null as never,
} );
