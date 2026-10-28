import { formatConfidence, formatLift, formatRate, getCountLabel, getTestStatus, getVariantLabel } from './utils';

describe( 'getTestStatus', () => {
	it( 'is running when control and a challenger are both published', () => {
		expect( getTestStatus( { variants: { a: { status: 'publish' }, b: { status: 'publish' } } } ) ).toBe( 'running' );
	} );
	it( 'is draft when any variant is unpublished', () => {
		expect( getTestStatus( { variants: { a: { status: 'publish' }, b: { status: 'draft' } } } ) ).toBe( 'draft' );
		expect( getTestStatus( { variants: { a: { status: 'future' }, b: { status: 'publish' } } } ) ).toBe( 'draft' );
	} );
	it( 'is incomplete without a control or without a challenger', () => {
		expect( getTestStatus( { variants: { b: { status: 'publish' } } } ) ).toBe( 'incomplete' );
		expect( getTestStatus( { variants: { a: { status: 'publish' } } } ) ).toBe( 'incomplete' );
		expect( getTestStatus( { variants: {} } ) ).toBe( 'incomplete' );
	} );
} );

describe( 'formatting', () => {
	it( 'shows a dash for missing numbers', () => {
		expect( formatRate( null ) ).toBe( '—' );
		expect( formatLift( null ) ).toBe( '—' );
		expect( formatConfidence( null ) ).toBe( '—' );
	} );
	it( 'formats rates and confidence as percentages', () => {
		expect( formatRate( 4.07 ) ).toBe( '4.07%' );
		expect( formatRate( 3 ) ).toBe( '3.00%' );
		expect( formatConfidence( 93.4 ) ).toBe( '93.4%' );
		expect( formatConfidence( 50 ) ).toBe( '50.0%' );
	} );
	it( 'signs lift', () => {
		expect( formatLift( 35.7 ) ).toBe( '+35.7%' );
		expect( formatLift( -12 ) ).toBe( '-12.0%' );
		expect( formatLift( 0 ) ).toBe( '+0.0%' );
	} );
} );

describe( 'labels', () => {
	it( 'names the counting unit', () => {
		expect( getCountLabel( 'readers' ) ).toBe( 'Readers' );
		expect( getCountLabel( 'events' ) ).toBe( 'Views' );
		expect( getCountLabel( undefined ) ).toBe( 'Readers' );
	} );
	it( 'names variants by role', () => {
		expect( getVariantLabel( 'a' ) ).toBe( 'A (control)' );
		expect( getVariantLabel( 'b' ) ).toBe( 'B (challenger)' );
		expect( getVariantLabel( 'd' ) ).toBe( 'D (challenger)' );
	} );
} );
