/**
 * Internal dependencies
 */
import { isUnsavedUnusableDuration } from './published-age';

describe( 'isUnsavedUnusableDuration', () => {
	const valid = { duration_value: 30, duration_unit: 'days' };
	const invalid = { duration_value: 0, duration_unit: 'days' };

	it( 'blocks an unusable edit', () => {
		expect( isUnsavedUnusableDuration( invalid, valid ) ).toBe( true );
	} );

	it( 'leaves a stored unusable value, and an edit back to it, unblocked', () => {
		expect( isUnsavedUnusableDuration( invalid, { ...invalid } ) ).toBe( false );
	} );

	it( 'leaves a usable edit unblocked', () => {
		expect( isUnsavedUnusableDuration( valid, invalid ) ).toBe( false );
	} );
} );
