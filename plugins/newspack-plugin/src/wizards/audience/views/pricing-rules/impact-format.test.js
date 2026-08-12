/**
 * Normalising and captioning the counts the pricing engine sends.
 */

/**
 * Internal dependencies
 */
import { finiteCount, sampleNote } from './impact-format';

describe( 'finiteCount', () => {
	it( 'keeps a number, including zero and negatives', () => {
		expect( finiteCount( 33 ) ).toBe( 33 );
		expect( finiteCount( 0 ) ).toBe( 0 );
		expect( finiteCount( -4 ) ).toBe( -4 );
	} );

	// PHP counts arrive as strings unless the engine casts them.
	it( 'reads a numeric string, whitespace and all', () => {
		expect( finiteCount( '12' ) ).toBe( 12 );
		expect( finiteCount( '0' ) ).toBe( 0 );
		expect( finiteCount( ' 12 ' ) ).toBe( 12 );
	} );

	// Number() would call each of these a confident zero or one.
	it( 'refuses anything that is not a count', () => {
		[ null, undefined, '', '   ', 'abc', '12abc', NaN, Infinity, false, true, [], {} ].forEach( value =>
			expect( finiteCount( value ) ).toBeNull()
		);
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
