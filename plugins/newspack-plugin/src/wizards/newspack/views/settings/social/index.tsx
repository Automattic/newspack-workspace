/**
 * Newspack > Settings > Social
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import XPixel from './x-pixel';
import MetaPixel from './meta-pixel';
import Nextdoor from './nextdoor';

/**
 * Internal dependencies
 */
import Section from '../../../../wizards-section';
import Publicize from './publicize';

function Social() {
	return (
		<div className="newspack-wizard__sections">
			<h2 className="newspack-wizard__heading">{ __( 'Social', 'newspack-plugin' ) }</h2>

			<Section>
				<Publicize />
				<MetaPixel />
				<XPixel />
				<Nextdoor />
			</Section>
		</div>
	);
}

export default Social;
