/**
 * Settings Wizard: Connections > Plugins
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import WizardsPluginCard from '../../../../wizards-plugin-card';

const PLUGINS: Record< string, PluginCard > = {
	'publish-to-apple-news': {
		slug: 'publish-to-apple-news',
		title: __( 'Publish to Apple News', 'newspack-plugin' ),
		editLink: 'admin.php?page=apple-news-options',
		isConfigurable: true,
		reloadOnActivation: false,
		description: __( 'Export and synchronize posts to Apple format.', 'newspack-plugin' ),
	},
	distributor: {
		slug: 'distributor',
		title: __( 'Distributor', 'newspack-plugin' ),
		editLink: 'admin.php?page=distributor',
		reloadOnActivation: false,
		description: __(
			'Distributor is a WordPress plugin that makes it easy to syndicate and reuse content across your websites — whether in a single multisite or across the web.',
			'newspack-plugin'
		),
	},
};

function Plugins() {
	return (
		<Fragment>
			{ Object.keys( PLUGINS ).map( pluginKey => {
				return <WizardsPluginCard key={ pluginKey } isTogglable isStatusPrepended={ false } isMedium={ false } { ...PLUGINS[ pluginKey ] } />;
			} ) }
		</Fragment>
	);
}

export default Plugins;
