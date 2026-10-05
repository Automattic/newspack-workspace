/**
 * Normalising, pricing and captioning the numbers the engine sends.
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

	// The wizard has no error boundary, so a throw here would cost the whole page.
	it( 'falls back to the em-dash rather than throwing', () => {
		[ null, undefined, '', 'abc', {} ].forEach( amount => expect( formatPrice( amount, currency ) ).toBe( '—' ) );
	} );
} );

const payload = ( over = {} ) => ( {
	preview_limited: true,
	sample_count: 50,
	...over,
} );

describe( 'sampleNote', () => {
	it( 'captions a table the engine capped', () => {
		expect( sampleNote( payload() ) ).toBe( 'Showing a sample of 50 products.' );
	} );

	it( 'captions a short sample too, since the engine only flags a cut walk', () => {
		expect( sampleNote( payload( { sample_count: 9 } ) ) ).toBe( 'Showing a sample of 9 products.' );
	} );

	it( 'stays quiet when the engine did not cap', () => {
		expect( sampleNote( payload( { preview_limited: false } ) ) ).toBeNull();
	} );

	it( 'reads a count the engine sent as a string', () => {
		expect( sampleNote( payload( { sample_count: '1' } ) ) ).toBe( 'Showing a sample of 1 product.' );
		expect( sampleNote( payload( { sample_count: '50' } ) ) ).toBe( 'Showing a sample of 50 products.' );
	} );

	it( 'stays quiet when a count is missing', () => {
		expect( sampleNote( payload( { sample_count: null } ) ) ).toBeNull();
	} );
} );
