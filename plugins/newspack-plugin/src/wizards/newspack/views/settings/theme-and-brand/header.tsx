/**
 * Newspack > Settings > Theme and Brand > Header.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { ColorPicker } from '../../../../../../packages/components/src';

export default function Header( { themeMods, updateHeader }: { themeMods: ThemeMods; updateHeader: ( a: ThemeMods ) => void } ) {
	return (
		<Stack direction="column" gap="xl">
			<ToggleGroupControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				isBlock
				label={ __( 'Style', 'newspack' ) }
				value={ themeMods.header_center_logo ? 'center' : 'left' }
				onChange={ align =>
					updateHeader( {
						...themeMods,
						header_center_logo: align === 'center',
					} )
				}
			>
				<ToggleGroupControlOption value="left" label={ __( 'Left', 'newspack' ) } />
				<ToggleGroupControlOption value="center" label={ __( 'Center', 'newspack' ) } />
			</ToggleGroupControl>
			<ToggleGroupControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				isBlock
				label={ __( 'Size', 'newspack' ) }
				value={ themeMods.header_simplified ? 'small' : 'large' }
				onChange={ size =>
					updateHeader( {
						...themeMods,
						header_simplified: size === 'small',
					} )
				}
			>
				<ToggleGroupControlOption value="small" label="S" aria-label={ __( 'Small', 'newspack' ) } />
				<ToggleGroupControlOption value="large" label="L" aria-label={ __( 'Large', 'newspack' ) } />
			</ToggleGroupControl>
			<ToggleGroupControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				isBlock
				label={ __( 'Background', 'newspack' ) }
				value={ themeMods.header_solid_background ? 'custom' : 'default' }
				onChange={ value =>
					updateHeader( {
						...themeMods,
						header_solid_background: value === 'custom',
					} )
				}
			>
				<ToggleGroupControlOption value="default" label={ __( 'Default', 'newspack' ) } />
				<ToggleGroupControlOption value="custom" label={ __( 'Custom', 'newspack' ) } />
			</ToggleGroupControl>
			<ColorPicker
				label={ __( 'Background color' ) }
				color={ themeMods.header_color_hex }
				disabled={ ! themeMods.header_solid_background }
				onChange={ ( header_color_hex: string ) =>
					updateHeader( {
						...themeMods,
						header_color_hex,
					} )
				}
			/>
		</Stack>
	);
}
