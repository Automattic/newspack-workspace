/**
 * Newspack > Settings > Theme and Brand > Header.
 */

/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';
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
				label={ __( 'Style', 'newspack-plugin' ) }
				value={ themeMods.header_center_logo ? 'center' : 'left' }
				onChange={ align =>
					updateHeader( {
						...themeMods,
						header_center_logo: align === 'center',
					} )
				}
			>
				<ToggleGroupControlOption value="left" label={ __( 'Left', 'newspack-plugin' ) } />
				<ToggleGroupControlOption value="center" label={ __( 'Center', 'newspack-plugin' ) } />
			</ToggleGroupControl>
			<ToggleGroupControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				isBlock
				label={ __( 'Size', 'newspack-plugin' ) }
				value={ themeMods.header_simplified ? 'small' : 'large' }
				onChange={ size =>
					updateHeader( {
						...themeMods,
						header_simplified: size === 'small',
					} )
				}
			>
				<ToggleGroupControlOption
					value="small"
					label={ _x( 'S', 'abbreviation of Small', 'newspack-plugin' ) }
					aria-label={ __( 'Small', 'newspack-plugin' ) }
				/>
				<ToggleGroupControlOption
					value="large"
					label={ _x( 'L', 'abbreviation of Large', 'newspack-plugin' ) }
					aria-label={ __( 'Large', 'newspack-plugin' ) }
				/>
			</ToggleGroupControl>
			<ToggleGroupControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				isBlock
				label={ __( 'Background', 'newspack-plugin' ) }
				value={ themeMods.header_solid_background ? 'custom' : 'default' }
				onChange={ value =>
					updateHeader( {
						...themeMods,
						header_solid_background: value === 'custom',
					} )
				}
			>
				<ToggleGroupControlOption value="default" label={ __( 'Default', 'newspack-plugin' ) } />
				<ToggleGroupControlOption value="custom" label={ __( 'Custom', 'newspack-plugin' ) } />
			</ToggleGroupControl>
			{ themeMods.header_solid_background && (
				<ColorPicker
					label={ __( 'Background color', 'newspack-plugin' ) }
					color={ themeMods.header_color_hex }
					onChange={ ( header_color_hex: string ) =>
						updateHeader( {
							...themeMods,
							header_color_hex,
						} )
					}
				/>
			) }
		</Stack>
	);
}
