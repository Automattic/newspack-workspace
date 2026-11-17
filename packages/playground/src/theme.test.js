import { themeConfig } from './theme';

const theme = {
	settings: {
		color: { palette: [ { slug: 'base', color: '#fff' } ], defaultPalette: false },
		blocks: { 'core/button': { color: { palette: [ { slug: 'accent', color: '#00f' } ] } } },
	},
	styles: { color: { background: 'var(--wp--preset--color--base)' } },
};

describe( 'themeConfig', () => {
	it( 'keys the presets by the theme origin, at the root and per block', () => {
		const config = themeConfig( theme );
		expect( config.settings.color.palette.theme ).toEqual( [ { slug: 'base', color: '#fff' } ] );
		expect( config.settings.blocks[ 'core/button' ].color.palette ).toEqual( { theme: [ { slug: 'accent', color: '#00f' } ] } );
		expect( config.settings.color.defaultPalette ).toBe( false );
	} );

	it( 'layers the theme over core\'s defaults, keeping core presets under the default origin', () => {
		const config = themeConfig( theme );
		expect( config.settings.color.text ).toBe( true );
		expect( config.settings.color.palette.default.length ).toBeGreaterThan( 0 );
		expect( config.styles.color.background ).toBe( 'var(--wp--preset--color--base)' );
	} );

	it( 'lets a style variation replace a preset list rather than append to it', () => {
		const dark = { settings: { color: { palette: [ { slug: 'base', color: '#000' } ] } } };
		expect( themeConfig( theme, dark ).settings.color.palette.theme ).toEqual( [ { slug: 'base', color: '#000' } ] );
	} );

	it( 'keeps what a variation leaves unset', () => {
		const dark = { settings: { color: { palette: [ { slug: 'base', color: '#000' } ] } } };
		expect( themeConfig( theme, dark ).styles.color ).toEqual( theme.styles.color );
	} );
} );
