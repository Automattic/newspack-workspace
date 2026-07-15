/**
 * Internal dependencies
 */
import { operatorOptionsForField, toggleField } from './configure-view';

describe( 'incoming-field operators', () => {
	it( 'offers text/number for plain fields and single/multi for enumerated', () => {
		expect( operatorOptionsForField( { has_options: false } ).map( o => o.value ) ).toEqual( [ 'default', 'range' ] );
		expect( operatorOptionsForField( { has_options: true } ).map( o => o.value ) ).toEqual( [ 'default', 'list__in' ] );
	} );

	it( 'constrains operator options by value_type', () => {
		expect( operatorOptionsForField( { value_type: 'number' } ).map( o => o.value ) ).toEqual( [ 'range' ] );
		expect( operatorOptionsForField( { value_type: 'date' } ).map( o => o.value ) ).toEqual( [ 'default' ] );
		expect( operatorOptionsForField( { value_type: 'multiselect' } ).map( o => o.value ) ).toEqual( [ 'list__in' ] );
		expect( operatorOptionsForField( { value_type: 'select' } ).map( o => o.value ) ).toEqual( [ 'default', 'list__in' ] );
		expect( operatorOptionsForField( { value_type: 'string', has_options: false } ).map( o => o.value ) ).toEqual( [ 'default', 'range' ] );
		expect( operatorOptionsForField( { value_type: 'string', has_options: true } ).map( o => o.value ) ).toEqual( [ 'default', 'list__in' ] );
	} );

	it( 'toggles a field in/out of the operator map using the field default', () => {
		const option = { value: 'AMOUNT', has_options: false, matching_function: 'default' };
		expect( toggleField( {}, option, true ) ).toEqual( { AMOUNT: 'default' } );
		expect( toggleField( { AMOUNT: 'range' }, option, false ) ).toEqual( {} );
	} );

	it( 'preserves sibling fields on toggle-off and propagates the field default operator', () => {
		expect(
			toggleField(
				{
					AMOUNT: 'range',
					NAME: 'default',
				},
				{ value: 'AMOUNT', has_options: false, matching_function: 'range' },
				false
			)
		).toEqual( { NAME: 'default' } );
		expect( toggleField( {}, { value: 'FAVS', has_options: true, matching_function: 'list__in' }, true ) ).toEqual( { FAVS: 'list__in' } );
	} );
} );
