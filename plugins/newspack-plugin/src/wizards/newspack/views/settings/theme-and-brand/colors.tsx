/**
 * Newspack > Settings > Theme and Brand > Colors. Component for setting colors to use in your theme.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ColorPicker, Grid } from '../../../../../../packages/components/src';

export default function Colors( { themeMods, updateColors }: { themeMods: ThemeMods; updateColors: ( a: ThemeMods ) => void } ) {
	return (
		<Grid gutter={ 32 }>
			{ /* This UI does not enable setting 'theme_colors' to 'default'. As soon as a color is picked, 'theme_colors' will be 'custom'. */ }
			<ColorPicker
				label={ __( 'Primary', 'newspack-plugin' ) }
				color={ themeMods.primary_color_hex }
				onChange={ ( primary_color_hex: string ) =>
					updateColors( {
						...themeMods,
						primary_color_hex,
						theme_colors: 'custom',
					} )
				}
			/>
			<ColorPicker
				label={ __( 'Secondary', 'newspack-plugin' ) }
				color={ themeMods.secondary_color_hex }
				onChange={ ( secondary_color_hex: string ) =>
					updateColors( {
						...themeMods,
						secondary_color_hex,
						theme_colors: 'custom',
					} )
				}
			/>
		</Grid>
	);
}
