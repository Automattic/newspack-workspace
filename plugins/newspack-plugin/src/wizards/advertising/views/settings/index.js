/**
 * Ad Settings view.
 */

/**
 * Internal dependencies
 */
import { __ } from '@wordpress/i18n';
import { PluginSettings, withWizardScreen } from '../../../../../packages/components/src';
import Suppression from '../../components/suppression';
import AdRefreshControlSettings from '../../components/ad-refresh-control';
import MediaKitToggle from '../../components/media-kit';

/**
 * Advertising management screen.
 */
function Settings() {
	return (
		<>
			<PluginSettings pluginSlug="newspack-ads" title={ __( 'Settings', 'newspack-plugin' ) }>
				<Suppression />
				<AdRefreshControlSettings />
				<MediaKitToggle />
			</PluginSettings>
		</>
	);
}

export default withWizardScreen( Settings );
