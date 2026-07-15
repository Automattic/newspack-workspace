/**
 * Internal dependencies
 */
import { operatorOptionsForField, toggleField } from './configure-view';

describe( 'incoming-field operators', () => {
	it( 'offers text/number for plain fields and single/multi for enumerated', () => {
		expect( operatorOptionsForField( { has_options: false } ).map( o => o.value ) ).toEqual( [ 'default', 'range' ] );
		expect( operatorOptionsForField( { has_options: true } ).map( o => o.value ) ).toEqual( [ 'default', 'list__in' ] );
	} );

	it( 'toggles a field in/out of the operator map using the field default', () => {
		const option = { value: 'AMOUNT', has_options: false, matching_function: 'default' };
		expect( toggleField( {}, option, true ) ).toEqual( { AMOUNT: 'default' } );
		expect( toggleField( { AMOUNT: 'range' }, option, false ) ).toEqual( {} );
	} );
} );
