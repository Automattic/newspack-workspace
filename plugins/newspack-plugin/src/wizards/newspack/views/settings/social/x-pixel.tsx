/**
 * X Pixel component. Used in Settings > Social > X Pixel.
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
	if ( ! /^[a-zA-Z0-9]+$/.test( trimmed ) ) {
		return __( 'Value may only contain numbers and letters.', 'newspack-plugin' );
	}
	return null;
};

const XPixel = () => (
	<PixelCard
		title={ __( 'X (Twitter) Pixel', 'newspack-plugin' ) }
		description={ __( 'Add the X pixel to your site to measure the results of ad campaigns running on X.', 'newspack-plugin' ) }
		namespace={ `${ PAGE_NAMESPACE }/social/pixels/x` }
		path="/newspack/v1/wizard/newspack-settings/social/x_pixel"
		validate={ validate }
		renderHelp={ () =>
			createInterpolateElement(
				__(
					'The X Pixel ID, not the full code. You can get this information from your X Ads events manager <linkToX>here</linkToX>.',
					'newspack-plugin'
				),
				{
					linkToX: (
						/* eslint-disable-next-line jsx-a11y/anchor-has-content */
						<a href="https://ads.x.com/" target="_blank" rel="noopener noreferrer" />
					),
				}
			)
		}
	/>
);

export default XPixel;
