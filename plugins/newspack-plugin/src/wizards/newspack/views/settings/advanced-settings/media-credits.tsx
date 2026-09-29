/**
 * Newspack > Settings > Advanced Settings > Media Credits
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { ImageUpload, TextControl } from '../../../../../../packages/components/src';
import ChoiceToggle from './choice-toggle';

export default function MediaCredits( { data, isFetching, update }: ThemeModComponentProps< AdvancedSettings > ) {
	const [ imageThumbnail, setImageThumbnail ] = useState< null | string >( null );
	useEffect( () => {
		if ( data.newspack_image_credits_placeholder_url ) {
			setImageThumbnail( data.newspack_image_credits_placeholder_url );
		}
	}, [ data.newspack_image_credits_placeholder_url ] );
	return (
		<>
			<TextControl
				withMargin={ false }
				label={ __( 'Credit class name', 'newspack-plugin' ) }
				help={ __( 'A CSS class name to be applied to all image credit elements. Leave blank to display no class name.', 'newspack-plugin' ) }
				value={ data.newspack_image_credits_class_name }
				onChange={ ( newspack_image_credits_class_name: string ) => update( { newspack_image_credits_class_name } ) }
			/>
			<TextControl
				withMargin={ false }
				label={ __( 'Credit label', 'newspack-plugin' ) }
				help={ __( 'A label to prefix all media credits. Leave blank to display no prefix.', 'newspack-plugin' ) }
				value={ data.newspack_image_credits_prefix_label }
				onChange={ ( newspack_image_credits_prefix_label: string ) => update( { newspack_image_credits_prefix_label } ) }
			/>
			<ImageUpload
				withMargin={ false }
				image={ imageThumbnail && data.newspack_image_credits_placeholder ? { url: imageThumbnail } : null }
				label={ __( 'Image for uncredited media', 'newspack-plugin' ) }
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
			<ChoiceToggle
				label={ __( 'Image credits on upload', 'newspack-plugin' ) }
				onLabel={ __( 'Auto-populate', 'newspack-plugin' ) }
				offLabel={ __( 'Manual', 'newspack-plugin' ) }
				help={ __( 'Automatically populate image credits from EXIF or IPTC metadata when uploading new images.', 'newspack-plugin' ) }
				checked={ data.newspack_image_credits_auto_populate }
				disabled={ isFetching }
				onChange={ newspack_image_credits_auto_populate => update( { newspack_image_credits_auto_populate } ) }
			/>
		</>
	);
}
