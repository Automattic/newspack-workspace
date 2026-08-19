/**
 * Internal dependencies
 */
import { calcTypeHelp, valueLabel, valueHelp } from './calc-copy';

describe( 'calcTypeHelp', () => {
	it( 'describes each calculation', () => {
		expect( calcTypeHelp( 'fixed_price', 'x' ) ).toBe( 'Readers pay this instead of the regular price.' );
		expect( calcTypeHelp( 'percent_of_base', 'x' ) ).toBe( 'Readers pay a share of the regular price.' );
		expect( calcTypeHelp( 'discount_fixed', 'x' ) ).toBe( 'A set amount comes off the regular price.' );
	} );

	it( 'falls back to the vocab label for an unknown calculation', () => {
		expect( calcTypeHelp( 'something_new', 'Something New' ) ).toBe( 'Something New' );
	} );
} );

describe( 'valueLabel', () => {
	it( 'carries the unit so the field announces it before anything is typed', () => {
		expect( valueLabel( 'percent_of_base', '$' ) ).toBe( 'Value (%)' );
		expect( valueLabel( 'fixed_price', '$' ) ).toBe( 'Value ($)' );
		expect( valueLabel( 'discount_fixed', '€' ) ).toBe( 'Value (€)' );
	} );

	it( 'claims no unit for a calculation it has no wording for', () => {
		expect( valueLabel( 'discount_percent', '$' ) ).toBe( 'Value' );
	} );
} );

describe( 'valueHelp', () => {
	it( 'spells out that a percentage is what readers pay, not what comes off', () => {
		expect( valueHelp( 'percent_of_base' ) ).toBe( 'A percentage of the regular price. 80 means readers pay 80% of it.' );
	} );

	it( 'describes the other calculations and says nothing for an unknown one', () => {
		expect( valueHelp( 'fixed_price' ) ).toBe( 'The price readers pay.' );
		expect( valueHelp( 'discount_fixed' ) ).toBe( 'Taken off the regular price.' );
		expect( valueHelp( 'something_new' ) ).toBe( '' );
	} );
} );
