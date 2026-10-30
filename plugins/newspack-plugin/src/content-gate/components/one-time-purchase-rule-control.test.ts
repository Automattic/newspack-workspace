/**
 * Tests for the one-time purchase rule's stored-value normalization.
 *
 * Product tokens come from the shared access-rule option helpers, which carry
 * their own tests; only the duration handling is particular to this rule.
 */

/**
 * Internal dependencies.
 */
import { normalizeOneTimePurchaseValue } from './one-time-purchase-rule-control';

// The control renders WordPress components, none of which these tests exercise.
jest.mock( '@wordpress/components', () => ( {} ) );

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
