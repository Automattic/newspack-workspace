/**
 * Newspack > Settings > Advanced Settings > Media Credits
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { ToggleControl } from '@wordpress/components';
import { useEffect, useState, Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Grid, ImageUpload, TextControl } from '../../../../../../packages/components/src';

export default function MediaCredits( { data, update }: ThemeModComponentProps< AdvancedSettings > ) {
	const [ imageThumbnail, setImageThumbnail ] = useState< null | string >( null );
	useEffect( () => {
		if ( data.newspack_image_credits_placeholder_url ) {
			setImageThumbnail( data.newspack_image_credits_placeholder_url );
		}
	}, [ data.newspack_image_credits_placeholder_url ] );
	return (
		<Fragment>
			<Grid gutter={ 32 }>
				<Grid columns={ 1 } gutter={ 16 }>
					<TextControl
						label={ __( 'Credit Class Name', 'newspack-plugin' ) }
						help={ __(
							'A CSS class name to be applied to all image credit elements. Leave blank to display no class name.',
							'newspack-plugin'
						) }
						value={ data.newspack_image_credits_class_name }
						onChange={ ( newspack_image_credits_class_name: string ) =>
							update( {
								newspack_image_credits_class_name,
							} )
						}
					/>
					<TextControl
						label={ __( 'Credit Label', 'newspack-plugin' ) }
						help={ __( 'A label to prefix all media credits. Leave blank to display no prefix.', 'newspack-plugin' ) }
						value={ data.newspack_image_credits_prefix_label }
						onChange={ ( newspack_image_credits_prefix_label: string ) =>
							update( {
								newspack_image_credits_prefix_label,
							} )
						}
					/>
				</Grid>
				<Grid columns={ 1 } gutter={ 16 }>
					<ImageUpload
						image={
							imageThumbnail && data.newspack_image_credits_placeholder
								? {
										url: imageThumbnail,
								  }
								: null
						}
						label={ __( 'Image for Uncredited Media', 'newspack-plugin' ) }
						buttonLabel={ __( 'Select', 'newspack-plugin' ) }
						onChange={ ( image: null | PlaceholderImage ) => {
							setImageThumbnail( image?.url || null );
							update( {
								newspack_image_credits_placeholder: image?.id || null,
								newspack_image_credits_placeholder_url: image?.url,
							} );
						} }
						help={ __(
							'Image shown in place of images that do not have a credit attached. Leave empty to display uncredited images as normal.',
							'newspack-plugin'
						) }
					/>
					<ToggleControl
						label={ __( 'Auto-populate image credits', 'newspack-plugin' ) }
						help={ __( 'Automatically populate image credits from EXIF or IPTC metadata when uploading new images.', 'newspack-plugin' ) }
						checked={ data.newspack_image_credits_auto_populate }
						onChange={ newspack_image_credits_auto_populate =>
							update( {
								newspack_image_credits_auto_populate,
							} )
						}
					/>
				</Grid>
			</Grid>
		</Fragment>
	);
}
