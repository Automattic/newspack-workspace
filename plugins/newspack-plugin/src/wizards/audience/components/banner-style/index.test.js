/**
 * The wizard previews have to print what the front end prints: the same style
 * fallback and the same text pick for each color.
 */

/**
 * Internal dependencies
 */
import { getBannerPreviewProps, toLongHex } from '.';

jest.mock( '@wordpress/components', () => ( {} ) );
jest.mock( '@wordpress/ui', () => ( {} ) );
jest.mock( '../../../../../packages/components/src/color-picker', () => () => null );

const setThemeData = contentBanner => {
	window.newspackAudience = { content_banner: contentBanner };
};

describe( 'getBannerPreviewProps', () => {
	afterEach( () => {
		delete window.newspackAudience;
	} );

	it( 'previews a Theme banner as Light when the theme does not offer Theme', () => {
		setThemeData( { supports_theme_style: false, theme_button_bg: '', theme_button_ink: '' } );
		const preview = getBannerPreviewProps( { style: 'theme', bg_color: '#1a237e', button_color: '#ffd54f' } );
		expect( preview.bannerClassName ).toBe( 'is-style-light' );
		expect( preview.bannerStyle ).toEqual( {} );
	} );

	it( 'picks readable text for each picked color, and uses the theme button and fonts by default', () => {
		setThemeData( {
			supports_theme_style: true,
			theme_button_bg: '#666666',
			theme_button_ink: 'white',
			theme_font_heading: '"Playfair Display",serif',
			theme_font_body: '"Space Mono",monospace',
		} );
		expect( getBannerPreviewProps( { style: 'theme', bg_color: '#fff59d', button_color: '#0d47a1' } ).bannerStyle ).toMatchObject( {
			'--newspack-content-banner-bg': '#fff59d',
			'--newspack-content-banner-ink': 'black',
			'--newspack-content-banner-button-bg': '#0d47a1',
			'--newspack-content-banner-button-ink': 'white',
		} );
		expect( getBannerPreviewProps( { style: 'theme', bg_color: '', button_color: '' } ).bannerStyle ).toEqual( {
			'--newspack-theme-font-heading': '"Playfair Display",serif',
			'--newspack-theme-font-body': '"Space Mono",monospace',
			'--newspack-content-banner-button-bg': '#666666',
			'--newspack-content-banner-button-ink': 'white',
		} );
	} );
} );

it( 'expands three-digit hex colors before they reach the color picker', () => expect( toLongHex( '#fff' ) ).toBe( '#ffffff' ) );
