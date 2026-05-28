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
import { TextControl } from '../../../../../../packages/components/src';
import WizardsToggleHeaderCard from '../../../../wizards-toggle-header-card';

const XPixel = () => {
	return (
		<WizardsToggleHeaderCard< PixelData >
			title={ __( 'X Pixel', 'newspack-plugin' ) }
			namespace={ `${ PAGE_NAMESPACE }/social/pixel/x` }
			description={ __( 'Add the X pixel (formerly known as Twitter pixel) to your site.', 'newspack-plugin' ) }
			path="/newspack/v1/wizard/newspack-settings/social/x_pixel"
			defaultValue={ {
				active: false,
				pixel_id: '',
			} }
			fieldValidationMap={ [
				[
					'pixel_id',
					{
						callback: 'isId',
					},
				],
			] }
			renderProp={ ( { settingsUpdates, setSettingsUpdates, isFetching } ) => (
				<TextControl
					value={ settingsUpdates?.pixel_id ?? '' }
					label={ __( 'Pixel ID', 'newspack-plugin' ) }
					onChange={ ( pixel_id: string ) => setSettingsUpdates( { ...settingsUpdates, pixel_id } ) }
					help={ createInterpolateElement(
						__(
							'The X Pixel ID. You only need to add the number, not the full code. Example: 123456789123456789. You can get this information <linkToFb>here</linkToFb>.',
							'newspack-plugin'
						),
						{
							linkToFb: (
								/* eslint-disable-next-line jsx-a11y/anchor-has-content */
								<a href="https://www.facebook.com/ads/manager/pixel/facebook_pixel" target="_blank" rel="noopener noreferrer" />
							),
						}
					) }
					disabled={ isFetching }
					autoComplete="one-time-code"
				/>
			) }
		/>
	);
};

export default XPixel;
