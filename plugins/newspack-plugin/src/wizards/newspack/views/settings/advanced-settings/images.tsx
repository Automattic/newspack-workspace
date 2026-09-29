/**
 * Newspack > Settings > Advanced Settings > Images
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { ImageUpload } from '../../../../../../packages/components/src';
import OverwriteWarning from './overwrite-warning';

const POSITION_OPTIONS = [
	{ label: __( 'Large', 'newspack-plugin' ), value: 'large' },
	{ label: __( 'Small', 'newspack-plugin' ), value: 'small' },
	{ label: __( 'Behind article title', 'newspack-plugin' ), value: 'behind' },
	{ label: __( 'Beside article title', 'newspack-plugin' ), value: 'beside' },
	{ label: __( 'Hidden', 'newspack-plugin' ), value: 'hidden' },
];

export default function Images( { data, update, isFetching, postCount }: ThemeModComponentProps< AdvancedSettings > & { postCount: string } ) {
	return (
		<>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Featured image position for new posts', 'newspack-plugin' ) }
				value={ data.featured_image_default }
				options={ POSITION_OPTIONS }
				disabled={ isFetching }
				onChange={ ( featured_image_default: string ) => update( { featured_image_default } ) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Featured image position for all existing posts', 'newspack-plugin' ) }
				value={ data.featured_image_all_posts }
				options={ [ { label: __( 'Select to change all posts', 'newspack-plugin' ), value: 'none' }, ...POSITION_OPTIONS ] }
				disabled={ isFetching }
				onChange={ ( featured_image_all_posts: string ) => update( { featured_image_all_posts } ) }
			/>
			{ data.featured_image_all_posts !== 'none' && <OverwriteWarning postCount={ postCount } /> }
			<ImageUpload
				withMargin={ false }
				label={ __( 'Fallback image', 'newspack-plugin' ) }
				help={ __(
					'Shown in place of any image on your site that cannot be found, including images inside post content.',
					'newspack-plugin'
				) }
				image={ data.post_content_fallback_image ? { url: data.post_content_fallback_image } : null }
				buttonLabel={ __( 'Select', 'newspack-plugin' ) }
				onChange={ ( image: null | PlaceholderImage ) => update( { post_content_fallback_image: image?.url || null } ) }
			/>
		</>
	);
}
