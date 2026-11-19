/**
 * Internal dependencies.
 */
import { getSelectedOption, getShownValue } from './selected-option';

const POSITIONS = [ '', 'large', 'small', 'behind', 'beside', 'above', 'hidden' ];

describe( 'getSelectedOption', () => {
	it( 'treats an empty position as following the site default', () => {
		expect( getSelectedOption( '', 'large', 'hidden' ) ).toBe( '' );
		expect( getSelectedOption( undefined, 'large', 'hidden' ) ).toBe( '' );
	} );

	it( 'recognizes a hidden image', () => {
		expect( getSelectedOption( 'hidden', 'large', 'hidden' ) ).toBe( 'hidden' );
	} );

	it( "reads the classic theme's layout positions as shown", () => {
		[ 'large', 'small', 'behind', 'beside', 'above' ].forEach( position => {
			expect( getSelectedOption( position, 'large', 'hidden' ) ).toBe( 'large' );
		} );
	} );

	it( 'uses the page value on pages', () => {
		expect( getSelectedOption( 'beside', 'small', 'hidden' ) ).toBe( 'small' );
	} );
} );

describe( 'getShownValue', () => {
	it( 'keeps a shown position the post already has', () => {
		[ 'large', 'small', 'behind', 'beside', 'above' ].forEach( position => {
			expect( getShownValue( position, 'large', 'hidden', 'large', POSITIONS ) ).toBe( position );
		} );
	} );

	it( 'pins the layout the site default already puts in force', () => {
		expect( getShownValue( '', 'beside', 'hidden', 'large', POSITIONS ) ).toBe( 'beside' );
		expect( getShownValue( 'hidden', 'beside', 'hidden', 'large', POSITIONS ) ).toBe( 'beside' );
	} );

	it( 'falls back only when the site hides images too', () => {
		expect( getShownValue( 'hidden', 'hidden', 'hidden', 'large', POSITIONS ) ).toBe( 'large' );
		expect( getShownValue( '', 'hidden', 'hidden', 'small', POSITIONS ) ).toBe( 'small' );
	} );

	it( 'never returns the hidden value', () => {
		[ '', 'hidden', 'large', undefined ].forEach( position => {
			expect( getShownValue( position, 'hidden', 'hidden', 'large', POSITIONS ) ).not.toBe( 'hidden' );
		} );
	} );
} );

describe( 'getShownValue with a vocabulary', () => {
	const positions = [ '', 'large', 'small', 'behind', 'beside', 'above', 'hidden' ];

	it( 'refuses a stored position the themes do not know', () => {
		expect( getShownValue( 'none', 'beside', 'hidden', 'large', positions ) ).toBe( 'beside' );
		expect( getShownValue( 'show', 'hidden', 'hidden', 'large', positions ) ).toBe( 'large' );
	} );

	it( 'refuses a site default the themes do not know', () => {
		expect( getShownValue( '', 'nonsense', 'hidden', 'small', positions ) ).toBe( 'small' );
	} );

	it( 'only ever returns something in the vocabulary, fallback included', () => {
		[ 'none', 'show', '', 'hidden', undefined, 'beside' ].forEach( stored => {
			[ 'nonsense', 'hidden', 'beside' ].forEach( siteDefault => {
				expect( positions ).toContain( getShownValue( stored, siteDefault, 'hidden', 'large', positions ) );
			} );
		} );
	} );

	it( 'clamps an unknown fallback, and an absent vocabulary clamps everything', () => {
		expect( getShownValue( 'none', 'nonsense', 'hidden', 'junk', positions ) ).toBe( '' );
		expect( getShownValue( 'beside', 'large', 'hidden', 'large', undefined ) ).toBe( '' );
	} );
} );
