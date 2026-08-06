/**
 * Newspack > Settings > Social
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Grid } from '../../../../../../packages/components/src';
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
		<div className="newspack-wizard__sections newspack-social-settings">
			<SocialCardsProvider>
				<Grid columns={ 12 } noMargin gutter={ 0 }>
					<h2 className="newspack-wizard__heading" style={ { gridColumn: 'span 4' } }>
						{ __( 'Social', 'newspack-plugin' ) }
					</h2>
					<VStack spacing={ 4 } style={ { gridColumn: 'span 8' } }>
						<Publicize />
						<MetaPixel />
						<XPixel />
						<Nextdoor />
					</VStack>
				</Grid>
			</SocialCardsProvider>
		</div>
	);
}

export default Social;
