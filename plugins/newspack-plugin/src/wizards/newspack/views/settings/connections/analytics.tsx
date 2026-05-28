/**
 * Settings Wizard: Connections > Analytics
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import WizardsPluginCard from '../../../../wizards-plugin-card';

const { analytics } = window.newspackSettings.connections.sections;

/**
 * Analytics Plugins Section
 */
function Analytics() {
	return (
		<WizardsPluginCard
			{ ...{
				editLink: analytics.editLink,
				slug: 'google-site-kit',
				title: __( 'Google Analytics', 'newspack-plugin' ),
				actionText: { complete: __( 'View', 'newspack-plugin' ) },
			} }
		/>
	);
}

export default Analytics;
