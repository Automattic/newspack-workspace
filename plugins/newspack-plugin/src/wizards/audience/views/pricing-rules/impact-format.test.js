/**
 * Normalising and captioning the counts the pricing engine sends.
 */

/**
 * Internal dependencies
 */
import { finiteNumber, formatPrice, sampleNote } from './impact-format';

describe( 'finiteNumber', () => {
	it( 'keeps a number, including zero and negatives', () => {
		expect( finiteNumber( 33 ) ).toBe( 33 );
		expect( finiteNumber( 0 ) ).toBe( 0 );
		expect( finiteNumber( -4 ) ).toBe( -4 );
	} );

	// PHP counts arrive as strings unless the engine casts them.
	it( 'reads a numeric string, whitespace and all', () => {
		expect( finiteNumber( '12' ) ).toBe( 12 );
		expect( finiteNumber( '0' ) ).toBe( 0 );
		expect( finiteNumber( ' 12 ' ) ).toBe( 12 );
	} );

	// Number() would call each of these a confident zero or one.
	it( 'refuses anything that is not a count', () => {
		[ null, undefined, '', '   ', 'abc', '12abc', NaN, Infinity, false, true, [], {} ].forEach( value =>
			expect( finiteNumber( value ) ).toBeNull()
		);
	} );
} );

describe( 'formatPrice', () => {
	const currency = { code: 'USD', symbol: '$', decimals: 2 };

	it( 'formats a number and a numeric string alike', () => {
		expect( formatPrice( 12, currency ) ).toBe( '$12.00' );
		expect( formatPrice( '12.5', currency ) ).toBe( '$12.50' );
		expect( formatPrice( 0, currency ) ).toBe( '$0.00' );
	} );

	// toFixed throws on a string, and the wizard has no error boundary, so an
	// unexpected shape has to cost one cell rather than the whole page.
	it( 'falls back to the em-dash rather than throwing', () => {
		[ null, undefined, '', 'abc', {} ].forEach( amount => expect( formatPrice( amount, currency ) ).toBe( '—' ) );
	} );
} );

const payload = ( over = {} ) => ( {
	preview_limited: true,
	sample_count: 50,
	sample_limit: 50,
	...over,
} );

describe( 'sampleNote', () => {
	it( 'captions a table the engine capped', () => {
		expect( sampleNote( payload() ) ).toBe( 'Showing a sample of 50 products.' );
	} );

	it( 'stays quiet when the sample fell short of the cap', () => {
		expect( sampleNote( payload( { sample_count: 9 } ) ) ).toBeNull();
	} );

	it( 'stays quiet when the engine did not cap', () => {
		expect( sampleNote( payload( { preview_limited: false } ) ) ).toBeNull();
	} );

	// As strings, '9' < '50' compares lexicographically and inverts the test.
	it( 'compares counts numerically when the engine sends them as strings', () => {
		expect( sampleNote( payload( { sample_count: '9', sample_limit: '50' } ) ) ).toBeNull();
		expect( sampleNote( payload( { sample_count: '50', sample_limit: '50' } ) ) ).toBe( 'Showing a sample of 50 products.' );
	} );

	it( 'stays quiet when a count is missing', () => {
		expect( sampleNote( payload( { sample_count: null } ) ) ).toBeNull();
	} );
} );
