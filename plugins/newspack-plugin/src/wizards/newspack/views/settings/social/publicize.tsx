/**
 * Newspack > Settings > Social
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import WizardsPluginCard from '../../../../wizards-plugin-card';

function Publicize() {
	return (
		<WizardsPluginCard
			title={ __( 'Publicize', 'newspack-plugin' ) }
			badge={ __( 'Jetpack', 'newspack-plugin' ) }
			slug="jetpack"
			description={ __(
				"Publicize makes it easy to share your site's posts on several social media networks automatically when you publish a new post.",
				'newspack-plugin'
			) }
			actionText={ {
				complete: __( 'Configure', 'newspack-plugin' ),
				activate: __( 'Activate Jetpack', 'newspack-plugin' ),
			} }
			editLink="admin.php?page=jetpack#/sharing"
		/>
	);
}

export default Publicize;
