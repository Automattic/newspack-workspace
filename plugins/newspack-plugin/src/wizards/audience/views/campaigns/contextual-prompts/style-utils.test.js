/**
 * Internal dependencies
 */
import { contrastRatio, presetRefForColor, relativeLuminance, resolveColor } from './style-utils';

const PALETTE = [
	{ name: 'Accent', slug: 'accent', color: '#178f15' },
	{ name: 'Base', slug: 'base', color: '#ffffff' },
];

describe( 'contrastRatio', () => {
	it( 'computes the WCAG ratio for black on white', () => {
		expect( contrastRatio( '#000000', '#ffffff' ) ).toBeCloseTo( 21, 0 );
	} );
	it( 'is symmetric', () => {
		expect( contrastRatio( '#ffffff', '#000000' ) ).toBeCloseTo( 21, 0 );
	} );
	it( 'flags a low-contrast pair below 4.5', () => {
		expect( contrastRatio( '#777777', '#888888' ) ).toBeLessThan( 4.5 );
	} );
	it( 'returns null for unparseable input', () => {
		expect( contrastRatio( 'nope', '#ffffff' ) ).toBeNull();
	} );
} );

describe( 'relativeLuminance', () => {
	it( 'runs from black to white', () => {
		expect( relativeLuminance( '#000000' ) ).toBe( 0 );
		expect( relativeLuminance( '#ffffff' ) ).toBeCloseTo( 1, 5 );
	} );
	it( 'orders a pair, which is what picks the contrast suggestion', () => {
		expect( relativeLuminance( '#777777' ) ).toBeLessThan( relativeLuminance( '#888888' ) );
	} );
	it( 'returns null for unparseable input', () => {
		expect( relativeLuminance( 'var:preset|color|accent' ) ).toBeNull();
	} );
} );

describe( 'resolveColor', () => {
	it( 'resolves a preset reference against the palette', () => {
		expect( resolveColor( 'var:preset|color|accent', PALETTE ) ).toBe( '#178f15' );
	} );
	it( 'passes hex through', () => {
		expect( resolveColor( '#123456', PALETTE ) ).toBe( '#123456' );
	} );
	it( 'returns null for an unknown slug', () => {
		expect( resolveColor( 'var:preset|color|missing', PALETTE ) ).toBeNull();
	} );
} );

describe( 'presetRefForColor', () => {
	it( 'returns the preset ref for a palette color', () => {
		expect( presetRefForColor( '#178F15', PALETTE ) ).toBe( 'var:preset|color|accent' );
	} );
	it( 'returns the raw hex for a custom color', () => {
		expect( presetRefForColor( '#123456', PALETTE ) ).toBe( '#123456' );
	} );
} );
