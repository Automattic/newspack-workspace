/**
 * Newspack > Settings > Theme and Brand > Logos.
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
import { ImageUpload } from '../../../../../../packages/components/src';
import { LOGO_SIZE_OPTIONS, parseLogoSize } from './utils';

const settingsTabs = window.newspackSettings;
const isMultibrandedEnabled = settingsTabs && 'additional-brands' in settingsTabs;

export default function Logos( { themeMods, onUpdate }: { themeMods: ThemeMods; onUpdate: ( a: ThemeMods ) => void } ) {
	function updateThemeMods( themeModChanges: Partial< ThemeMods > ) {
		onUpdate( { ...themeMods, ...themeModChanges } );
	}
	return (
		<Stack direction="column" gap="xl">
			<ImageUpload
				withMargin={ false }
				className="newspack-design__header__logo"
				style={ {
					backgroundColor: themeMods.header_solid_background ? themeMods.header_color_hex : 'transparent',
				} }
				label={ __( 'Header Logo', 'newspack' ) }
				image={ themeMods.custom_logo }
				onChange={ ( custom_logo: string ) =>
					updateThemeMods( {
						custom_logo,
						header_text: ! custom_logo,
						header_display_tagline: ! custom_logo,
					} )
				}
			/>
			{ themeMods.custom_logo && (
				<ToggleGroupControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					isBlock
					label={ __( 'Header Logo Size', 'newspack' ) }
					value={ parseLogoSize( themeMods.logo_size ) }
					onChange={ logo_size => updateThemeMods( { logo_size: Number( logo_size ) } ) }
				>
					{ LOGO_SIZE_OPTIONS.map( option => (
						<ToggleGroupControlOption key={ option.value } value={ option.value } label={ option.label } />
					) ) }
				</ToggleGroupControl>
			) }
			{ ! isMultibrandedEnabled && (
				<>
					<ImageUpload
						withMargin={ false }
						className="newspack-design__footer__logo"
						label={ __( 'Footer Logo', 'newspack' ) }
						help={ __( 'Optional. Without one, the footer shows the header logo.', 'newspack' ) }
						style={ {
							backgroundColor:
								themeMods.footer_color === 'custom' && themeMods.footer_color_hex ? themeMods.footer_color_hex : 'transparent',
						} }
						image={ themeMods.newspack_footer_logo }
						onChange={ ( newspack_footer_logo: string ) => updateThemeMods( { newspack_footer_logo } ) }
					/>
					{ themeMods.newspack_footer_logo && (
						<ToggleGroupControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							isBlock
							label={ __( 'Footer Logo Size', 'newspack' ) }
							value={ themeMods.footer_logo_size }
							onChange={ footer_logo_size => updateThemeMods( { footer_logo_size: String( footer_logo_size ) } ) }
						>
							<ToggleGroupControlOption value="small" label="S" aria-label={ __( 'Small', 'newspack' ) } />
							<ToggleGroupControlOption value="medium" label="M" aria-label={ __( 'Medium', 'newspack' ) } />
							<ToggleGroupControlOption value="large" label="L" aria-label={ __( 'Large', 'newspack' ) } />
							<ToggleGroupControlOption value="xlarge" label="XL" aria-label={ __( 'Extra large', 'newspack' ) } />
						</ToggleGroupControl>
					) }
				</>
			) }
		</Stack>
	);
}
