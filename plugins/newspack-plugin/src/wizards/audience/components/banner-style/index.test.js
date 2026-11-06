/**
 * The wizard previews have to print what the front end prints: the same style
 * fallback and the same text pick for each color.
 */

/**
 * External dependencies
 */
import { fireEvent, render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import BannerStyleControl, { getBannerPreviewProps, getBannerStyle, toLongHex } from '.';

jest.mock( '@wordpress/components', () => ( {
	Button: () => null,
	SelectControl: () => <div data-testid="style-select" />,
	__experimentalToggleGroupControl: () => <div data-testid="shadow-toggle" />,
	__experimentalToggleGroupControlOption: () => null,
} ) );
jest.mock( '@wordpress/ui', () => ( { Stack: ( { children } ) => <div>{ children }</div> } ) );
jest.mock( '../../../../../packages/colors/colors.module.scss', () => ( { 'neutral-000': '#ffffff', 'neutral-900': '#1e1e1e' } ) );
jest.mock( '../../../../../packages/components/src/color-picker', () => ( { label, color, onChange } ) => (
	<button type="button" data-testid="color-picker" data-label={ label } data-color={ color } onClick={ () => onChange( '#abcdef' ) } />
) );

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
		expect(
			getBannerPreviewProps( {
				style: 'theme',
				bg_color: '#fff59d',
				text_color: '#102030',
				button_color: '#0d47a1',
				button_text_color: '#ffeb3b',
			} ).bannerStyle
		).toMatchObject( {
			'--newspack-content-banner-ink': '#102030',
			'--newspack-content-banner-button-ink': '#ffeb3b',
		} );
		expect( getBannerPreviewProps( { style: 'theme', button_text_color: '#ffeb3b' } ).bannerStyle ).toMatchObject( {
			'--newspack-content-banner-button-bg': '#666666',
			'--newspack-content-banner-button-ink': '#ffeb3b',
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

describe( 'on a site that uses the site Theme', () => {
	afterEach( () => {
		delete window.newspackAudience;
	} );

	it( 'getBannerStyle returns site on a site Theme for any stored style', () => {
		setThemeData( { supports_theme_style: false, uses_site_theme: true } );
		[ 'light', 'dark', 'theme', undefined ].forEach( style => expect( getBannerStyle( style ) ).toBe( 'site' ) );
	} );

	it( 'previews with the site class and the primary button, and no inline colors until some are picked', () => {
		setThemeData( { supports_theme_style: true, uses_site_theme: true, theme_button_bg: '#666666' } );
		expect( getBannerPreviewProps( { style: 'dark' } ) ).toEqual( {
			bannerClassName: 'is-style-site',
			bannerStyle: {},
			buttonClassName: 'newspack-ui__button--primary',
		} );
	} );

	it( 'previews the picked colors on the site style, with black or white text only where none is picked', () => {
		setThemeData( { supports_theme_style: true, uses_site_theme: true, theme_button_bg: '#666666', theme_button_ink: 'white' } );
		expect( getBannerPreviewProps( { style: 'theme', bg_color: '#fff59d', button_color: '#0d47a1' } ).bannerStyle ).toEqual( {
			'--newspack-content-banner-bg': '#fff59d',
			'--newspack-content-banner-ink': 'black',
			'--newspack-content-banner-button-bg': '#0d47a1',
			'--newspack-content-banner-button-ink': 'white',
		} );
		expect(
			getBannerPreviewProps( {
				style: 'theme',
				bg_color: '#fff59d',
				text_color: '#102030',
				button_color: '#0d47a1',
				button_text_color: '#ffeb3b',
			} ).bannerStyle
		).toEqual( {
			'--newspack-content-banner-bg': '#fff59d',
			'--newspack-content-banner-ink': '#102030',
			'--newspack-content-banner-button-bg': '#0d47a1',
			'--newspack-content-banner-button-ink': '#ffeb3b',
		} );
		expect( getBannerPreviewProps( { style: 'theme', text_color: '#102030', button_text_color: '#ffeb3b' } ).bannerStyle ).toEqual( {
			'--newspack-content-banner-ink': '#102030',
			'--newspack-content-banner-button-ink': '#ffeb3b',
		} );
	} );

	it( 'BannerStyleControl renders no style option on a site Theme, but the four colors and the shadow', () => {
		setThemeData( { supports_theme_style: true, uses_site_theme: true } );
		const { queryByTestId, getAllByTestId } = render( <BannerStyleControl settings={ { style: 'theme' } } onChange={ () => {} } /> );
		expect( queryByTestId( 'style-select' ) ).toBeNull();
		expect( getAllByTestId( 'color-picker' ) ).toHaveLength( 4 );
		expect( queryByTestId( 'shadow-toggle' ) ).not.toBeNull();
	} );

	it( 'BannerStyleControl still renders the style option without the site Theme', () => {
		setThemeData( { supports_theme_style: true, uses_site_theme: false } );
		const { queryByTestId, getAllByTestId } = render( <BannerStyleControl settings={ { style: 'theme' } } onChange={ () => {} } /> );
		expect( queryByTestId( 'style-select' ) ).not.toBeNull();
		expect( getAllByTestId( 'color-picker' ) ).toHaveLength( 4 );
	} );

	it( 'BannerStyleControl shows the colors and the shadow only under Theme on classic', () => {
		setThemeData( { supports_theme_style: true, uses_site_theme: false } );
		const light = render( <BannerStyleControl settings={ { style: 'light' } } onChange={ () => {} } /> );
		expect( light.queryAllByTestId( 'color-picker' ) ).toHaveLength( 0 );
		expect( light.queryByTestId( 'shadow-toggle' ) ).toBeNull();
		light.unmount();
		const theme = render( <BannerStyleControl settings={ { style: 'theme' } } onChange={ () => {} } /> );
		expect( theme.queryAllByTestId( 'color-picker' ) ).toHaveLength( 4 );
		expect( theme.queryByTestId( 'shadow-toggle' ) ).not.toBeNull();
	} );

	it( 'previews the shadow as off only on the Theme and site styles', () => {
		setThemeData( { supports_theme_style: true, uses_site_theme: false } );
		expect( getBannerPreviewProps( { style: 'theme' } ).bannerClassName ).toBe( 'is-style-theme' );
		expect( getBannerPreviewProps( { style: 'theme', shadow: false } ).bannerClassName ).toBe( 'is-style-theme has-no-shadow' );
		expect( getBannerPreviewProps( { style: 'theme', shadow: 0 } ).bannerClassName ).toBe( 'is-style-theme has-no-shadow' );
		expect( getBannerPreviewProps( { style: 'dark', shadow: false } ).bannerClassName ).toBe( 'is-style-dark' );
		setThemeData( { supports_theme_style: true, uses_site_theme: true } );
		expect( getBannerPreviewProps( { style: 'theme', shadow: false } ).bannerClassName ).toBe( 'is-style-site has-no-shadow' );
		expect( getBannerPreviewProps( { style: 'dark', shadow: false } ).bannerClassName ).toBe( 'is-style-site' );
	} );

	it( 'ignores colors left over from the Light or Dark style on the site style', () => {
		setThemeData( { supports_theme_style: true, uses_site_theme: true } );
		[ 'light', 'dark', undefined ].forEach( style =>
			expect( getBannerPreviewProps( { style, bg_color: '#fff59d', button_color: '#0d47a1', text_color: '#102030' } ).bannerStyle ).toEqual(
				{}
			)
		);
	} );

	it( 'stores the Theme style whenever a color or the shadow is changed on the site style', () => {
		setThemeData( { supports_theme_style: true, uses_site_theme: true } );
		const onChange = jest.fn();
		const { getAllByTestId } = render( <BannerStyleControl settings={ { style: 'dark' } } onChange={ onChange } /> );
		fireEvent.click( getAllByTestId( 'color-picker' )[ 1 ] );
		expect( onChange ).toHaveBeenCalledWith( { text_color: '#abcdef', style: 'theme' } );
	} );

	it( 'defaults the empty pickers to the site Theme colors, then to the picked colors black or white', () => {
		setThemeData( {
			supports_theme_style: false,
			uses_site_theme: true,
			site_bg: '#1e1e1e',
			site_text: '#ffffff',
			site_button_bg: '#ffc857',
			site_button_ink: '#000000',
		} );
		const colorOf = ( utils, label ) => utils.getAllByTestId( 'color-picker' ).find( e => e.dataset.label === label ).dataset.color;
		const site = render( <BannerStyleControl settings={ {} } onChange={ () => {} } /> );
		expect( [ 'Banner Color', 'Text Color', 'Button Color', 'Button Text Color' ].map( l => colorOf( site, l ) ) ).toEqual( [
			'#1e1e1e',
			'#ffffff',
			'#ffc857',
			'#000000',
		] );
		site.unmount();
		const picked = render(
			<BannerStyleControl settings={ { style: 'theme', bg_color: '#fff59d', button_color: '#0d47a1' } } onChange={ () => {} } />
		);
		expect( colorOf( picked, 'Text Color' ) ).toBe( '#000000' );
		expect( colorOf( picked, 'Button Text Color' ) ).toBe( '#ffffff' );
	} );
} );
