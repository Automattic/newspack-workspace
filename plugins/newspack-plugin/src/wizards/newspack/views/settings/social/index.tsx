/**
 * Newspack > Settings > Social
 */

/**
 * WordPress dependencies
 */
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { SocialCardsProvider } from './context';
import Publicize from './publicize';
import MetaPixel from './meta-pixel';
import XPixel from './x-pixel';
import Nextdoor from './nextdoor';

/**
 * Styles
 */
import './style.scss';

function Social() {
	return (
		<div className="newspack-wizard__sections newspack-wizard__sections--narrow newspack-social-settings">
			<SocialCardsProvider>
				<VStack spacing={ 4 }>
					<Publicize />
					<MetaPixel />
					<XPixel />
					<Nextdoor />
				</VStack>
			</SocialCardsProvider>
		</div>
	);
}

export default Social;
