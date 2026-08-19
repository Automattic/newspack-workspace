/**
 * Internal dependencies
 */
import { cycleRange, priceSummary } from './schedule-format';

const USD = { code: 'USD', symbol: '$', decimals: 2 };

describe( 'cycleRange', () => {
	it( 'runs the last price to the end of the subscription', () => {
		expect( cycleRange( 7, null ) ).toEqual( { display: '7 onward', label: '7 onward' } );
		expect( cycleRange( 1, null ) ).toEqual( { display: '1 onward', label: '1 onward' } );
	} );

	it( 'shows a bare number when the next price starts immediately after', () => {
		expect( cycleRange( 1, 2 ) ).toEqual( { display: '1', label: '1' } );
	} );

	it( 'closes the range one cycle before the next price starts', () => {
		expect( cycleRange( 2, 7 ) ).toEqual( { display: '2 → 6', label: '2 to 6' } );
	} );

	it( 'shows a bare number when another price starts on the same cycle', () => {
		expect( cycleRange( 2, 2 ) ).toEqual( { display: '2', label: '2' } );
	} );

	it( 'shows a bare number when the next price starts before this one', () => {
		expect( cycleRange( 5, 3 ) ).toEqual( { display: '5', label: '5' } );
	} );
} );

describe( 'priceSummary', () => {
	it( 'shows a fixed price as money', () => {
		expect( priceSummary( { at: '1', calc_type: 'fixed_price', value: '8', label: '' }, USD ) ).toBe( '$8.00' );
	} );

	it( 'shows a percentage as what readers pay, not what comes off', () => {
		expect( priceSummary( { at: '2', calc_type: 'percent_of_base', value: '80', label: '' }, USD ) ).toBe( 'Pay 80%' );
	} );

	it( 'marks a fixed discount as coming off the regular price', () => {
		expect( priceSummary( { at: '7', calc_type: 'discount_fixed', value: '2', label: '' }, USD ) ).toBe( '$2.00 off' );
	} );

	it( 'reads a zero as a deliberate free price', () => {
		expect( priceSummary( { at: '1', calc_type: 'fixed_price', value: '0', label: '' }, USD ) ).toBe( '$0.00' );
	} );
} );
