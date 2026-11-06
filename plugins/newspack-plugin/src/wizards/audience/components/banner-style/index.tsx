/**
 * Style controls and preview styling shared by the countdown banner and the
 * content gifting banner.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, SelectControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import ColorPicker from '../../../../../packages/components/src/color-picker';
import { getContrastColor } from '../../../../../packages/components/src/utils/contrast-color';
import colors from '../../../../../packages/colors/colors.module.scss';

export type BannerStyleSettings = {
	style?: string;
	bg_color?: string;
	button_color?: string;
};

type BannerStyleControlProps = {
	settings: BannerStyleSettings;
	onChange: ( value: BannerStyleSettings ) => void;
	disabled?: boolean;
	/** Render the fields as cells of a parent two-column Grid instead of a single column. */
	gridCells?: boolean;
};

type ColorFieldProps = {
	label: string;
	resetLabel: string;
	help: string;
	value?: string;
	defaultValue: string;
	onChange: ( value: string ) => void;
	disabled?: boolean;
};

const supportsThemeStyle = () => !! window.newspackAudience?.content_banner?.supports_theme_style;
const usesSiteTheme = () => !! window.newspackAudience?.content_banner?.uses_site_theme;

/**
 * The style a banner renders with: the site Theme wherever the adapter is active, otherwise the stored
 * style, with Theme falling back to Light where the theme does not offer it.
 *
 * @param style The stored style.
 */
export const getBannerStyle = ( style?: string ) => {
	if ( usesSiteTheme() ) {
		return 'site';
	}
	if ( style === 'dark' || ( style === 'theme' && supportsThemeStyle() ) ) {
		return style;
	}
	return 'light';
};

/**
 * Class names and inline custom properties for a banner preview, matching what the front end prints.
 *
 * @param settings The banner's style settings.
 */
export const getBannerPreviewProps = ( settings: BannerStyleSettings ) => {
	const style = getBannerStyle( settings.style );
	const bannerStyle: Record< string, string > = {};
	if ( style === 'theme' ) {
		const theme = window.newspackAudience?.content_banner;
		if ( theme?.theme_font_heading ) {
			bannerStyle[ '--newspack-theme-font-heading' ] = theme.theme_font_heading;
		}
		if ( theme?.theme_font_body ) {
			bannerStyle[ '--newspack-theme-font-body' ] = theme.theme_font_body;
		}
		if ( settings.bg_color ) {
			bannerStyle[ '--newspack-content-banner-bg' ] = settings.bg_color;
			bannerStyle[ '--newspack-content-banner-ink' ] = getContrastColor( settings.bg_color );
		}
		if ( settings.button_color ) {
			bannerStyle[ '--newspack-content-banner-button-bg' ] = settings.button_color;
			bannerStyle[ '--newspack-content-banner-button-ink' ] = getContrastColor( settings.button_color );
		} else if ( theme?.theme_button_bg ) {
			bannerStyle[ '--newspack-content-banner-button-bg' ] = theme.theme_button_bg;
			bannerStyle[ '--newspack-content-banner-button-ink' ] = theme.theme_button_ink || getContrastColor( theme.theme_button_bg );
		}
	}
	return {
		bannerClassName: `is-style-${ style }`,
		bannerStyle: bannerStyle as React.CSSProperties,
		buttonClassName: style === 'theme' ? '' : `newspack-ui__button--${ style === 'dark' ? 'primary-light' : 'primary' }`,
	};
};

/**
 * Expand a #rgb hex to #rrggbb, so the color picker always shows six digits.
 *
 * @param color A hex color.
 */
export const toLongHex = ( color: string ) => color.replace( /^#([\da-f])([\da-f])([\da-f])$/i, '#$1$1$2$2$3$3' );

const ColorField = ( { label, resetLabel, help, value, defaultValue, onChange, disabled }: ColorFieldProps ) => (
	<ColorPicker
		label={ label }
		help={ help }
		color={ toLongHex( value || defaultValue ) }
		onChange={ onChange }
		disabled={ disabled }
		action={
			<Button
				variant="secondary"
				onClick={ () => onChange( '' ) }
				aria-label={ resetLabel }
				disabled={ disabled || ! value }
				accessibleWhenDisabled
				__next40pxDefaultSize
			>
				{ __( 'Reset', 'newspack-plugin' ) }
			</Button>
		}
	/>
);

export default function BannerStyleControl( { settings, onChange, disabled = false, gridCells = false }: BannerStyleControlProps ) {
	if ( usesSiteTheme() ) {
		return null;
	}
	const hasThemeStyle = supportsThemeStyle();
	const style = getBannerStyle( settings.style );
	const startRow = { gridColumnStart: 1 };
	const select = (
		<SelectControl
			label={ __( 'Style', 'newspack-plugin' ) }
			help={
				hasThemeStyle
					? __(
							"Light and Dark use the standard banner. Theme matches the site's buttons and fonts, and lets you pick the colors.",
							'newspack-plugin'
					  )
					: undefined
			}
			value={ style }
			options={ [
				{ label: __( 'Light', 'newspack-plugin' ), value: 'light' },
				{ label: __( 'Dark', 'newspack-plugin' ), value: 'dark' },
				...( hasThemeStyle ? [ { label: __( 'Theme', 'newspack-plugin' ), value: 'theme' } ] : [] ),
			] }
			onChange={ ( value: string ) => onChange( { style: value } ) }
			disabled={ disabled }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
	const bannerColor = (
		<ColorField
			label={ __( 'Banner color', 'newspack-plugin' ) }
			resetLabel={ __( 'Reset banner color', 'newspack-plugin' ) }
			help={ __( 'Defaults to the Light style background. Text turns black or white to stay readable.', 'newspack-plugin' ) }
			value={ settings.bg_color }
			defaultValue={ colors[ 'neutral-000' ] }
			onChange={ value => onChange( { bg_color: value } ) }
			disabled={ disabled }
		/>
	);
	const buttonColor = (
		<ColorField
			label={ __( 'Button color', 'newspack-plugin' ) }
			resetLabel={ __( 'Reset button color', 'newspack-plugin' ) }
			help={ __( "Defaults to the theme's button color. The label turns black or white to stay readable.", 'newspack-plugin' ) }
			value={ settings.button_color }
			defaultValue={ window.newspackAudience?.content_banner?.theme_button_bg || colors[ 'neutral-000' ] }
			onChange={ value => onChange( { button_color: value } ) }
			disabled={ disabled }
		/>
	);

	if ( gridCells ) {
		return (
			<>
				<div style={ startRow }>{ select }</div>
				{ style === 'theme' && (
					<>
						<div style={ startRow }>{ bannerColor }</div>
						{ buttonColor }
					</>
				) }
			</>
		);
	}

	return (
		<Stack direction="column" gap="xl">
			{ select }
			{ style === 'theme' && (
				<>
					{ bannerColor }
					{ buttonColor }
				</>
			) }
		</Stack>
	);
}
