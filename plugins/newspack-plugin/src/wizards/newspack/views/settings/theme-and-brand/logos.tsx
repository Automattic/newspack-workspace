/**
 * Newspack > Settings > Theme and Brand > Logos.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
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

// Mirrors the footer logo boxes in newspack-theme's `_site-footer.scss`.
const FOOTER_LOGO_BOXES: Record< string, { maxWidth: number; maxHeight: number } > = {
	small: { maxWidth: 160, maxHeight: 48 },
	medium: { maxWidth: 200, maxHeight: 76 },
	large: { maxWidth: 304, maxHeight: 120 },
	xlarge: { maxWidth: 368, maxHeight: 160 },
};

const PREVIEW_PADDING = 'var(--wpds-dimension-padding-2xl, 24px)';

type Size = { width: number; height: number };

const imageUrl = ( image: unknown ) => ( image as { url?: string } | null )?.url;

function useNaturalSize( url?: string ) {
	const [ size, setSize ] = useState< Size | null >( null );
	useEffect( () => {
		setSize( null );
		if ( ! url ) {
			return;
		}
		const img = new window.Image();
		img.onload = () => setSize( { width: img.naturalWidth, height: img.naturalHeight } );
		img.src = url;
		return () => {
			img.onload = null;
		};
	}, [ url ] );
	return size;
}

/**
 * The size newspack-theme renders the header logo at for a given size percentage,
 * following `newspack_customize_logo_resize()`.
 */
function headerLogoSize( { width, height }: Size, percent: number ): Size {
	const maxWidth = Math.min( width, 600 );
	const isLandscape = width >= height;
	const ratio = isLandscape ? width / height : height / width;
	const maxShort = isLandscape ? Math.floor( maxWidth / ratio ) : maxWidth;
	const short = Math.round( 48 + ( percent * ( maxShort - 48 ) ) / 100 );
	const long = Math.round( short * ratio );
	const renderedWidth = Math.min( isLandscape ? long : short, maxWidth );
	return { width: renderedWidth, height: Math.round( ( renderedWidth * height ) / width ) };
}

const previewHeight = ( logoHeight: number ) => `calc(${ logoHeight }px + 2 * ${ PREVIEW_PADDING })`;

export default function Logos( { themeMods, onUpdate }: { themeMods: ThemeMods; onUpdate: ( a: ThemeMods ) => void } ) {
	function updateThemeMods( themeModChanges: Partial< ThemeMods > ) {
		onUpdate( { ...themeMods, ...themeModChanges } );
	}

	const headerNaturalSize = useNaturalSize( imageUrl( themeMods.custom_logo ) );
	const logoSizePercent = themeMods.logo_size as unknown;
	const hasLogoSize = logoSizePercent !== '' && logoSizePercent !== null && Number.isFinite( Number( logoSizePercent ) );
	const largestPercent = Math.max( LOGO_SIZE_OPTIONS[ LOGO_SIZE_OPTIONS.length - 1 ].value, hasLogoSize ? Number( logoSizePercent ) : 0 );
	const headerImageStyle =
		headerNaturalSize && hasLogoSize
			? { width: headerLogoSize( headerNaturalSize, Number( logoSizePercent ) ).width, height: 'auto' }
			: undefined;
	const headerPreviewHeight = headerNaturalSize ? previewHeight( headerLogoSize( headerNaturalSize, largestPercent ).height ) : undefined;
	const footerImageStyle = {
		...( FOOTER_LOGO_BOXES[ themeMods.footer_logo_size ] ?? FOOTER_LOGO_BOXES.medium ),
		width: 'auto',
		height: 'auto',
	};
	const footerPreviewHeight = previewHeight( FOOTER_LOGO_BOXES.xlarge.maxHeight );
	return (
		<Stack direction="column" gap="xl">
			<ImageUpload
				withMargin={ false }
				className="newspack-design__header__logo"
				style={ {
					backgroundColor: themeMods.header_solid_background ? themeMods.header_color_hex : 'transparent',
					...( themeMods.custom_logo && { padding: PREVIEW_PADDING, height: headerPreviewHeight } ),
				} }
				imageStyle={ headerImageStyle }
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
							...( themeMods.newspack_footer_logo && { padding: PREVIEW_PADDING, height: footerPreviewHeight } ),
						} }
						imageStyle={ footerImageStyle }
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
