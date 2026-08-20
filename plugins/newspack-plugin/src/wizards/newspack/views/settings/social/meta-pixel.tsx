/**
 * Meta Pixel component. Used in Settings > Social > Meta Pixel.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import { ExternalLink } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { PAGE_NAMESPACE } from '../constants';
import PixelCard from './pixel-card';

const validate = ( value: string ) => {
	const trimmed = value.trim();
	if ( trimmed === '' ) {
		return __( 'Enter your Meta pixel ID.', 'newspack-plugin' );
	}
	if ( ! /^[0-9]+$/.test( trimmed ) ) {
		return __( 'The Meta pixel ID is numbers only.', 'newspack-plugin' );
	}
	if ( trimmed === '0' ) {
		return __( 'That is not a valid Meta pixel ID.', 'newspack-plugin' );
	}
	return null;
};

const MetaPixel = () => (
	<PixelCard
		title={ __( 'Meta Pixel', 'newspack-plugin' ) }
		description={ __( 'Add the Meta pixel to your site to measure the results of Facebook and Instagram ad campaigns.', 'newspack-plugin' ) }
		namespace={ `${ PAGE_NAMESPACE }/social/pixels/meta` }
		path="/newspack/v1/wizard/newspack-settings/social/meta_pixel"
		validate={ validate }
		renderHelp={ () =>
			createInterpolateElement(
				__(
					'The Meta Pixel ID. You only need to add the number, not the full code. Example: 123456789123456789. You can get this information <linkToFb>here</linkToFb>.',
					'newspack-plugin'
				),
				{
					linkToFb: (
						// createInterpolateElement replaces the child with the tagged text.
						<ExternalLink href="https://www.facebook.com/ads/manager/pixel/facebook_pixel">{ '' }</ExternalLink>
					),
				}
			)
		}
	/>
);

export default MetaPixel;
