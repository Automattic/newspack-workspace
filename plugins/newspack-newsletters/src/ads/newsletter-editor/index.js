/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';
import DisableAutoAds from './disable-auto-ads';
import { useIsEditingOpenedPost } from '../../utils/opened-post';

function NewslettersAdsSettings() {
	// The setting belongs to the newsletter, not to a synced pattern opened through "Edit original".
	if ( ! useIsEditingOpenedPost() ) {
		return null;
	}
	return (
		<PluginDocumentSettingPanel name="newsletters-ads-settings-panel" title={ __( 'Advertising', 'newspack-newsletters' ) }>
			<DisableAutoAds />
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'newspack-newsletters-ads-settings', {
	render: NewslettersAdsSettings,
	icon: null,
} );
