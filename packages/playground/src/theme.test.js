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

	it( 'keeps what a variation leaves unset, a level down', () => {
		const dark = { settings: { color: { palette: [ { slug: 'base', color: '#000' } ] } }, styles: { color: { text: '#fff' } } };
		const config = themeConfig( theme, dark );
		expect( config.styles.color ).toEqual( { background: 'var(--wp--preset--color--base)', text: '#fff' } );
		expect( config.settings.color.defaultPalette ).toBe( false );
	} );

	it( 'keys core\'s aspect ratios by origin, as the aspect ratio control reads them', () => {
		expect( themeConfig( theme ).settings.dimensions.aspectRatios.default.length ).toBeGreaterThan( 0 );
	} );

	it( 'turns on what appearanceTools opts into, without overriding what the theme sets', () => {
		const tools = { settings: { appearanceTools: true, spacing: { margin: false }, blocks: { 'core/group': { appearanceTools: true } } } };
		const { settings } = themeConfig( tools );
		expect( settings.spacing.padding ).toBe( true );
		expect( settings.spacing.blockGap ).toBe( true );
		expect( settings.border.radius ).toBe( true );
		expect( settings.spacing.margin ).toBe( false );
		expect( settings.blocks[ 'core/group' ].color.link ).toBe( true );
		expect( settings.appearanceTools ).toBe( false );
	} );

	it( 'migrates a v2 theme that sets its own sizes, turning core\'s off', () => {
		const v2 = { version: 2, settings: { typography: { fontSizes: [ { slug: 'small', size: '12px' } ] }, spacing: { spacingSizes: [ { slug: '20', size: '4px' } ] } } };
		const { settings } = themeConfig( v2 );
		expect( settings.typography.defaultFontSizes ).toBe( false );
		expect( settings.spacing.defaultSpacingSizes ).toBe( false );
		expect( themeConfig( { ...v2, version: 3 } ).settings.typography.defaultFontSizes ).toBe( true );
	} );
} );
