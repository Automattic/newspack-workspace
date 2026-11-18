/**
 * Style controls and preview styling shared by the countdown banner and the
 * content gifting banner.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	Button,
	SelectControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
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
	text_color?: string;
	button_text_color?: string;
	shadow?: boolean | number;
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

export type BannerRenderStyle = 'light' | 'dark' | 'theme' | 'site';

/**
 * The style a banner renders with: the site Theme wherever it is in use, otherwise the stored
 * style, with Theme falling back to Light where the theme does not offer it.
 *
 * @param style The stored style.
 */
export const getBannerStyle = ( style?: string ): BannerRenderStyle => {
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
	const theme = window.newspackAudience?.content_banner;
	const hasOptions = style === 'theme' || ( style === 'site' && settings.style === 'theme' );
	if ( style === 'theme' ) {
		if ( theme?.theme_font_heading ) {
			bannerStyle[ '--newspack-theme-font-heading' ] = theme.theme_font_heading;
		}
		if ( theme?.theme_font_body ) {
			bannerStyle[ '--newspack-theme-font-body' ] = theme.theme_font_body;
		}
	}
	if ( hasOptions ) {
		if ( settings.bg_color ) {
			bannerStyle[ '--newspack-content-banner-bg' ] = settings.bg_color;
		}
		const ink = settings.text_color || ( settings.bg_color ? getContrastColor( settings.bg_color ) : '' );
		if ( ink ) {
			bannerStyle[ '--newspack-content-banner-ink' ] = ink;
		}
		if ( settings.button_color ) {
			bannerStyle[ '--newspack-content-banner-button-bg' ] = settings.button_color;
			bannerStyle[ '--newspack-content-banner-button-ink' ] = settings.button_text_color || getContrastColor( settings.button_color );
		} else if ( style === 'theme' && theme?.theme_button_bg ) {
			bannerStyle[ '--newspack-content-banner-button-bg' ] = theme.theme_button_bg;
			bannerStyle[ '--newspack-content-banner-button-ink' ] =
				settings.button_text_color || theme.theme_button_ink || getContrastColor( theme.theme_button_bg );
		} else if ( settings.button_text_color ) {
			bannerStyle[ '--newspack-content-banner-button-ink' ] = settings.button_text_color;
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

const toHex = ( color?: string ) => {
	if ( color === 'white' ) {
		return '#ffffff';
	}
	if ( color === 'black' ) {
		return '#000000';
	}
	return color || '';
};

export default function BannerStyleControl( { settings, onChange, disabled = false, gridCells = false }: BannerStyleControlProps ) {
	const isSite = usesSiteTheme();
	const hasThemeStyle = supportsThemeStyle();
	const style = getBannerStyle( settings.style );
	const hasOptions = style === 'theme' || style === 'site';
	const banner = window.newspackAudience?.content_banner;
	const hasLeftovers = isSite && settings.style !== 'theme';
	const cleared = { bg_color: '', text_color: '', button_color: '', button_text_color: '', shadow: true };
	const shown: BannerStyleSettings = hasLeftovers ? cleared : settings;
	const change = ( value: BannerStyleSettings ) => onChange( isSite ? { ...( hasLeftovers ? cleared : {} ), ...value, style: 'theme' } : value );
	const startRow = { gridColumnStart: 1 };
	const select = isSite ? null : (
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
			label={ __( 'Banner Color', 'newspack-plugin' ) }
			resetLabel={ __( 'Reset banner color', 'newspack-plugin' ) }
			help={
				isSite
					? __( 'Defaults to the site background.', 'newspack-plugin' )
					: __( 'Defaults to the Light style background.', 'newspack-plugin' )
			}
			value={ shown.bg_color }
			defaultValue={ ( isSite && banner?.site_bg ) || colors[ 'neutral-000' ] }
			onChange={ value => change( { bg_color: value } ) }
			disabled={ disabled }
		/>
	);
	const textColor = (
		<ColorField
			label={ __( 'Text Color', 'newspack-plugin' ) }
			resetLabel={ __( 'Reset text color', 'newspack-plugin' ) }
			help={
				isSite
					? __( 'Defaults to the site text color, or black or white once you pick a banner color.', 'newspack-plugin' )
					: __( 'Defaults to black or white, whichever reads better on the banner color.', 'newspack-plugin' )
			}
			value={ shown.text_color }
			defaultValue={ shown.bg_color ? toHex( getContrastColor( shown.bg_color ) ) : ( isSite && banner?.site_text ) || colors[ 'neutral-900' ] }
			onChange={ value => change( { text_color: value } ) }
			disabled={ disabled }
		/>
	);
	const buttonColor = (
		<ColorField
			label={ __( 'Button Color', 'newspack-plugin' ) }
			resetLabel={ __( 'Reset button color', 'newspack-plugin' ) }
			help={
				isSite
					? __( 'Defaults to the site button color.', 'newspack-plugin' )
					: __( 'Defaults to the theme button color.', 'newspack-plugin' )
			}
			value={ shown.button_color }
			defaultValue={ ( isSite ? banner?.site_button_bg : banner?.theme_button_bg ) || colors[ 'neutral-900' ] }
			onChange={ value => change( { button_color: value } ) }
			disabled={ disabled }
		/>
	);
	const buttonTextColor = (
		<ColorField
			label={ __( 'Button Text Color', 'newspack-plugin' ) }
			resetLabel={ __( 'Reset button text color', 'newspack-plugin' ) }
			help={
				isSite
					? __( 'Defaults to the site button text color, or black or white once you pick a button color.', 'newspack-plugin' )
					: __( 'Defaults to black or white, whichever reads better on the button color.', 'newspack-plugin' )
			}
			value={ shown.button_text_color }
			defaultValue={
				shown.button_color
					? toHex( getContrastColor( shown.button_color ) )
					: toHex( isSite ? banner?.site_button_ink : banner?.theme_button_ink ) || colors[ 'neutral-000' ]
			}
			onChange={ value => change( { button_text_color: value } ) }
			disabled={ disabled }
		/>
	);
	const shadow = (
		<ToggleGroupControl
			label={ __( 'Shadow', 'newspack-plugin' ) }
			value={ shown.shadow === false || shown.shadow === 0 ? 'disabled' : 'enabled' }
			onChange={ value => change( { shadow: value === 'enabled' } ) }
			isBlock
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		>
			<ToggleGroupControlOption label={ __( 'Enabled', 'newspack-plugin' ) } value="enabled" />
			<ToggleGroupControlOption label={ __( 'Disabled', 'newspack-plugin' ) } value="disabled" />
		</ToggleGroupControl>
	);

	if ( gridCells ) {
		return (
			<>
				{ select && <div style={ startRow }>{ select }</div> }
				{ hasOptions && (
					<>
						<div style={ startRow }>{ bannerColor }</div>
						{ textColor }
						<div style={ startRow }>{ buttonColor }</div>
						{ buttonTextColor }
						<div style={ startRow }>{ shadow }</div>
					</>
				) }
			</>
		);
	}

	return (
		<Stack direction="column" gap="xl">
			{ select }
			{ hasOptions && (
				<>
					{ bannerColor }
					{ textColor }
					{ buttonColor }
					{ buttonTextColor }
					{ shadow }
				</>
			) }
		</Stack>
	);
}
