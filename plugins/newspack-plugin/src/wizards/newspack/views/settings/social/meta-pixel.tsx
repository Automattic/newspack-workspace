/**
 * Meta Pixel component. Used in Settings > Social > Meta Pixel.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { PAGE_NAMESPACE } from '../constants';
import PixelCard from './pixel-card';

const validate = ( value: string ) => {
	const trimmed = value.trim();
	if ( trimmed === '' ) {
		return __( 'Value cannot be empty!', 'newspack-plugin' );
	}
	if ( ! /^[0-9]+$/.test( trimmed ) ) {
		return __( 'Value may only contain numbers!', 'newspack-plugin' );
	}
	if ( trimmed === '0' ) {
		return __( 'Value cannot be zero!', 'newspack-plugin' );
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
						/* eslint-disable-next-line jsx-a11y/anchor-has-content */
						<a href="https://www.facebook.com/ads/manager/pixel/facebook_pixel" target="_blank" rel="noopener noreferrer" />
					),
				}
			)
		}
	/>
);

export default MetaPixel;
