/**
 * Newspack > Settings > Advanced Settings > Featured Image Posts New
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Grid, SelectControl } from '../../../../../../packages/components/src';

export default function FeaturedImagePostsNew( { data, update }: ThemeModComponentProps< AdvancedSettings > ) {
	return (
		<Grid gutter={ 32 }>
			<SelectControl
				label={ __( 'Default featured image position for new posts', 'newspack-plugin' ) }
				help={ __( 'Set a default featured image position for new posts.', 'newspack-plugin' ) }
				value={ data.featured_image_default }
				options={ [
					{ label: __( 'Large', 'newspack-plugin' ), value: 'large' },
					{ label: __( 'Small', 'newspack-plugin' ), value: 'small' },
					{
						label: __( 'Behind article title', 'newspack-plugin' ),
						value: 'behind',
					},
					{
						label: __( 'Beside article title', 'newspack-plugin' ),
						value: 'beside',
					},
					{
						label: __( 'Hidden', 'newspack-plugin' ),
						value: 'hidden',
					},
				] }
				onChange={ ( featured_image_default: string ) => update( { featured_image_default } ) }
			/>
			<SelectControl
				label={ __( 'Default template for new posts', 'newspack-plugin' ) }
				help={ __( 'Set a default template for new posts.', 'newspack-plugin' ) }
				value={ data.post_template_default }
				options={ [
					{
						label: __( 'With sidebar', 'newspack-plugin' ),
						value: 'default',
					},
					{
						label: __( 'One Column', 'newspack-plugin' ),
						value: 'single-feature.php',
					},
					{
						label: __( 'One Column Wide', 'newspack-plugin' ),
						value: 'single-wide.php',
					},
				] }
				onChange={ ( post_template_default: string ) => update( { post_template_default } ) }
			/>
		</Grid>
	);
}
