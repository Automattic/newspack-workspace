/**
 * Newspack > Settings > Theme and Brand > Footer.
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
import { ColorPicker, TextControl } from '../../../../../../packages/components/src';

export default function Footer( { themeMods, onUpdate }: { themeMods: ThemeMods; onUpdate: ( a: ThemeMods ) => void } ) {
	function updateThemeMods( themeModChanges: Partial< ThemeMods > ) {
		onUpdate( { ...themeMods, ...themeModChanges } );
	}
	return (
		<Stack direction="column" gap="xl">
			<TextControl
				withMargin={ false }
				label={ __( 'Copyright information', 'newspack' ) }
				value={ themeMods.footer_copyright || '' }
				onChange={ ( footer_copyright: string ) => updateThemeMods( { footer_copyright } ) }
			/>
			{ /* <Card noBorder className="newspack-design__footer__copyright">
				</Card> */ }
			<ToggleGroupControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				isBlock
				label={ __( 'Background', 'newspack' ) }
				value={ themeMods.footer_color === 'custom' ? 'custom' : 'default' }
				onChange={ value => updateThemeMods( { footer_color: value === 'custom' ? 'custom' : 'default' } ) }
			>
				<ToggleGroupControlOption value="default" label={ __( 'Default', 'newspack' ) } />
				<ToggleGroupControlOption value="custom" label={ __( 'Custom', 'newspack' ) } />
			</ToggleGroupControl>
			<ColorPicker
				label={ __( 'Background color' ) }
				color={ themeMods.footer_color_hex }
				disabled={ themeMods.footer_color !== 'custom' }
				onChange={ ( footer_color_hex: string ) => updateThemeMods( { footer_color_hex } ) }
			/>
		</Stack>
	);
}
