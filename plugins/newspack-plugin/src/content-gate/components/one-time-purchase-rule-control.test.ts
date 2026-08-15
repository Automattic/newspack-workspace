/**
 * Tests for the one-time purchase rule control's product token bijection.
 *
 * FormTokenField addresses tokens by their display string, so the mapping
 * between product IDs and token strings has to be one-to-one: a collision means
 * selecting one product silently saves another product's ID.
 */

/**
 * Internal dependencies.
 */
import { getProductTokens, normalizeOneTimePurchaseValue } from './one-time-purchase-rule-control';

// The control renders WordPress components, none of which these tests exercise.
jest.mock( '@wordpress/components', () => ( {} ) );

describe( 'getProductTokens', () => {
	/**
	 * Every token maps back to exactly the product it was generated for.
	 *
	 * @param options    Product options passed to getProductTokens.
	 * @param productIds Stored product IDs passed to getProductTokens.
	 */
	const expectBijection = ( options: { value: string | number; label: string }[], productIds: Array< string | number > = [] ) => {
		const { tokenByValue, valueByToken } = getProductTokens( options, productIds );
		const allValues = [ ...options.map( option => option.value ), ...productIds ];
		allValues.forEach( value => {
			const token = tokenByValue.get( String( value ) ) as string;
			expect( token ).toBeDefined();
			expect( valueByToken.get( token ) ).toBe( value );
		} );
		expect( valueByToken.size ).toBe( new Set( allValues.map( String ) ).size );
		return { tokenByValue, valueByToken };
	};

	it( 'uses the bare product name when it is unambiguous', () => {
		const { tokenByValue } = expectBijection( [
			{ value: 10, label: 'Annual Pass' },
			{ value: 11, label: 'Day Pass' },
		] );

		expect( tokenByValue.get( '10' ) ).toBe( 'Annual Pass' );
		expect( tokenByValue.get( '11' ) ).toBe( 'Day Pass' );
	} );

	it( 'appends the ID to products sharing a name', () => {
		const { tokenByValue } = expectBijection( [
			{ value: 10, label: 'Annual Pass' },
			{ value: 11, label: 'Annual Pass' },
		] );

		expect( tokenByValue.get( '10' ) ).toBe( 'Annual Pass (#10)' );
		expect( tokenByValue.get( '11' ) ).toBe( 'Annual Pass (#11)' );
	} );

	it( 'does not let a product named like a generated token steal that token', () => {
		const { tokenByValue, valueByToken } = expectBijection( [
			{ value: 60, label: 'Annual Pass' },
			{ value: 61, label: 'Annual Pass' },
			// A real product whose name happens to match the token generated for #60.
			{ value: 62, label: 'Annual Pass (#60)' },
		] );

		expect( valueByToken.get( 'Annual Pass (#60)' ) ).toBe( 60 );
		expect( tokenByValue.get( '62' ) ).not.toBe( 'Annual Pass (#60)' );
	} );

	it( 'keeps a token for a stored ID missing from the options', () => {
		// A variation or deleted product: editing the field must not drop it.
		const { tokenByValue } = expectBijection( [ { value: 10, label: 'Annual Pass' } ], [ 10, 999 ] );

		expect( tokenByValue.get( '999' ) ).toBe( '#999' );
	} );

	it( 'does not let a product named like an ID fallback steal that token', () => {
		const { tokenByValue, valueByToken } = expectBijection( [ { value: 7, label: '#123' } ], [ 123 ] );

		expect( valueByToken.get( '#123' ) ).toBe( 7 );
		expect( tokenByValue.get( '123' ) ).not.toBe( '#123' );
	} );
} );

describe( 'normalizeOneTimePurchaseValue', () => {
	it( 'fails closed on an unrecognized duration unit', () => {
		// Mirrors the server-side sanitizer: '' never grants access, so the UI
		// must not coerce a malformed unit into a granting one.
		expect( normalizeOneTimePurchaseValue( { duration_unit: 'lifetime' } ).duration_unit ).toBe( '' );
		expect( normalizeOneTimePurchaseValue( undefined ) ).toEqual( {
			product_ids: [],
			duration_value: 0,
			duration_unit: '',
		} );
	} );

	it( 'preserves a recognized duration', () => {
		expect( normalizeOneTimePurchaseValue( { product_ids: [ 10 ], duration_value: '30', duration_unit: 'days' } ) ).toEqual( {
			product_ids: [ 10 ],
			duration_value: 30,
			duration_unit: 'days',
		} );
	} );
} );
