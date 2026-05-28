/**
 * Settings Syndication: RSS, Apple News, and Distributor.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Rss from './rss';
import Plugins from './plugins';
import WizardsTab from '../../../../wizards-tab';
import WizardSection from '../../../../wizards-section';

function Syndication() {
	return (
		<WizardsTab title={ __( 'Syndication', 'newspack-plugin' ) }>
			<WizardSection>
				<Rss />
				<Plugins />
			</WizardSection>
		</WizardsTab>
	);
}

export default Syndication;
