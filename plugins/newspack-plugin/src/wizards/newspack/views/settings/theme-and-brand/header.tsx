/**
 * Newspack > Settings > Theme and Brand > Header.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { alignCenter, alignLeft } from '@wordpress/icons';
import { ToggleControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { ColorPicker, Grid, ImageUpload, SelectControl } from '../../../../../../packages/components/src';
import { LOGO_SIZE_OPTIONS, parseLogoSize } from './utils';

export default function Header( { themeMods, updateHeader }: { themeMods: ThemeMods; updateHeader: ( a: ThemeMods ) => void } ) {
	return (
		<Grid gutter={ 32 }>
			<Grid columns={ 1 } gutter={ 16 }>
				<Grid gutter={ 16 } className="newspack-design__header__style-size">
					<SelectControl
						className="icon-only"
						label={ __( 'Style', 'newspack' ) }
						value={ themeMods.header_center_logo ? 'center' : 'left' }
						onChange={ ( align: string ) =>
							updateHeader( {
								...themeMods,
								header_center_logo: align === 'center',
							} )
						}
						buttonOptions={ [
							{ value: 'left', icon: alignLeft },
							{ value: 'center', icon: alignCenter },
						] }
					/>
					<SelectControl
						className="icon-only"
						label={ __( 'Size', 'newspack' ) }
						value={ themeMods.header_simplified ? 'small' : 'large' }
						onChange={ ( size: string ) =>
							updateHeader( {
								...themeMods,
								header_simplified: size === 'small',
							} )
						}
						buttonOptions={ [
							{ value: 'small', label: 'S' },
							{ value: 'large', label: 'L' },
						] }
					/>
				</Grid>
				<ToggleControl
					checked={ Boolean( themeMods.header_solid_background ) }
					onChange={ header_solid_background =>
						updateHeader( {
							...themeMods,
							header_solid_background,
						} )
					}
					label={ __( 'Apply a background color to the header', 'newspack' ) }
				/>
				{ themeMods.header_solid_background && (
					<ColorPicker
						label={ __( 'Background color' ) }
						color={ themeMods.header_color_hex }
						onChange={ ( header_color_hex: string ) =>
							updateHeader( {
								...themeMods,
								header_color_hex,
							} )
						}
					/>
				) }
			</Grid>
			<Grid columns={ 1 } gutter={ 16 }>
				<ImageUpload
					className="newspack-design__header__logo"
					style={ {
						backgroundColor: themeMods.header_solid_background ? themeMods.header_color_hex : 'transparent',
					} }
					label={ __( 'Logo', 'newspack' ) }
					image={ themeMods.custom_logo }
					onChange={ ( custom_logo: string ) =>
						updateHeader( {
							...themeMods,
							custom_logo,
							header_text: ! custom_logo,
							header_display_tagline: ! custom_logo,
						} )
					}
				/>
				{ themeMods.custom_logo && (
					<SelectControl
						className="icon-only"
						label={ __( 'Logo Size', 'newspack' ) }
						value={ parseLogoSize( themeMods.logo_size ) }
						onChange={ ( logo_size: number ) =>
							updateHeader( {
								...themeMods,
								logo_size,
							} )
						}
						buttonOptions={ LOGO_SIZE_OPTIONS }
					/>
				) }
			</Grid>
		</Grid>
	);
}
