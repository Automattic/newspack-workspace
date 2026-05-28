/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';

/**
 * Internal dependencies
 */
import { Sidebar } from './sidebar';
import { TaxonomyPanel } from './taxonomy-panel';

const { post_type: postType, cpt } = window.newspack_sponsors_data;

/**
 * Filter the PostTaxonomies component.
 */
addFilter( 'editor.PostTaxonomyType', 'newspack-sponsors-editor', TaxonomyPanel );

/**
 * Register plugin editor settings.
 * For sponsor posts, this is a separate sidebar panel.
 * For all other post types, it is pre-pended to the Sponsors sidebar panel.
 */
if ( cpt === postType ) {
	registerPlugin( 'newspack-sponsors-editor', {
		render: () => (
			<PluginDocumentSettingPanel
				className="newspack-sponsors"
				name="newspack-sponsors"
				title={ __( 'Sponsor Settings', 'newspack-sponsors' ) }
			>
				<Sidebar />
			</PluginDocumentSettingPanel>
		),
		icon: null,
	} );
}
