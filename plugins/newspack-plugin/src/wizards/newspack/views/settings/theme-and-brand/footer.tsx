/**
 * Newspack > Settings > Theme and Brand > Footer.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { ToggleControl } from '@wordpress/components';

const settingsTabs = window.newspackSettings;
const isMultibrandedEnabled = settingsTabs && 'additional-brands' in settingsTabs;
/**
 * Internal dependencies
 */
import { ColorPicker, Grid, ImageUpload, SelectControl, TextControl } from '../../../../../../packages/components/src';

export default function Footer( { themeMods, onUpdate }: { themeMods: ThemeMods; onUpdate: ( a: ThemeMods ) => void } ) {
	function updateThemeMods( themeModChanges: Partial< ThemeMods > ) {
		onUpdate( { ...themeMods, ...themeModChanges } );
	}
	return (
		<Grid gutter={ 32 }>
			<Grid columns={ 1 } gutter={ 16 }>
				<TextControl
					label={ __( 'Copyright information', 'newspack' ) }
					value={ themeMods.footer_copyright || '' }
					onChange={ ( footer_copyright: string ) => updateThemeMods( { footer_copyright } ) }
				/>
				{ /* <Card noBorder className="newspack-design__footer__copyright">
				</Card> */ }
				<ToggleControl
					checked={ themeMods.footer_color !== 'default' }
					onChange={ checked =>
						updateThemeMods( {
							footer_color: checked ? 'custom' : 'default',
						} )
					}
					label={ __( 'Apply a background color to the footer', 'newspack' ) }
				/>
				{ themeMods.footer_color === 'custom' && (
					<ColorPicker
						label={ __( 'Background color' ) }
						color={ themeMods.footer_color_hex }
						onChange={ ( footer_color_hex: string ) => updateThemeMods( { footer_color_hex } ) }
					/>
				) }
			</Grid>
			{ ! isMultibrandedEnabled && (
				<Grid columns={ 1 } gutter={ 16 }>
					<ImageUpload
						className="newspack-design__footer__logo"
						label={ __( 'Alternative Logo', 'newspack' ) }
						help={ __( 'Optional alternative logo to be displayed in the footer.', 'newspack' ) }
						style={ {
							backgroundColor:
								themeMods.footer_color === 'custom' && themeMods.footer_color_hex ? themeMods.footer_color_hex : 'transparent',
						} }
						image={ themeMods.newspack_footer_logo }
						onChange={ ( newspack_footer_logo: string ) => updateThemeMods( { newspack_footer_logo } ) }
					/>
					{ themeMods.newspack_footer_logo && (
						<SelectControl
							className="icon-only"
							label={ __( 'Alternative logo - Size', 'newspack' ) }
							value={ themeMods.footer_logo_size }
							onChange={ ( footer_logo_size: string ) => updateThemeMods( { footer_logo_size } ) }
							buttonOptions={ [
								{ value: 'small', label: 'S' },
								{ value: 'medium', label: 'M' },
								{ value: 'large', label: 'L' },
								{ value: 'xlarge', label: 'XL' },
							] }
						/>
					) }
				</Grid>
			) }
		</Grid>
	);
}
