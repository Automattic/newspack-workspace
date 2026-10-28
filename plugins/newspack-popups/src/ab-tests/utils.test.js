import {
	formatConfidence,
	formatLetterList,
	getStatusLabel,
	formatLift,
	formatRate,
	getCountLabel,
	getLabelIntent,
	getStatusIntent,
	getTestStatus,
	getVariantLabel,
} from './utils';

describe( 'getTestStatus', () => {
	it( 'is running when control and a challenger are both published', () => {
		expect( getTestStatus( { variants: { a: { status: 'publish' }, b: { status: 'publish' } } } ) ).toBe( 'running' );
	} );
	it( 'is draft when any variant is unpublished', () => {
		expect( getTestStatus( { variants: { a: { status: 'publish' }, b: { status: 'draft' } } } ) ).toBe( 'draft' );
		expect( getTestStatus( { variants: { a: { status: 'future' }, b: { status: 'publish' } } } ) ).toBe( 'draft' );
	} );
	it( 'needs attention when any version has an ineligible placement', () => {
		expect( getTestStatus( { variants: { a: { status: 'publish', eligible: false }, b: { status: 'publish', eligible: true } } } ) ).toBe(
			'needs-attention'
		);
		expect( getTestStatus( { variants: { a: { status: 'draft' }, b: { status: 'publish', eligible: false } } } ) ).toBe( 'needs-attention' );
		expect( getTestStatus( { variants: { a: { status: 'publish', eligible: true }, b: { status: 'publish' } } } ) ).toBe( 'running' );
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

describe( 'formatLetterList', () => {
	it( 'joins letters as a list', () => {
		expect( formatLetterList( [ 'A' ] ) ).toBe( 'A' );
		expect( formatLetterList( [ 'A', 'B' ] ) ).toBe( 'A and B' );
	} );
} );

describe( 'badge intents', () => {
	it( 'maps a test status to a badge intent', () => {
		expect( getStatusIntent( 'running' ) ).toBe( 'stable' );
		expect( getStatusIntent( 'draft' ) ).toBe( 'draft' );
		expect( getStatusIntent( 'incomplete' ) ).toBe( 'high' );
		expect( getStatusIntent( 'needs-attention' ) ).toBe( 'high' );
		expect( getStatusLabel( 'needs-attention' ) ).toBe( 'Needs attention' );
	} );
	it( 'maps a confidence label to a badge intent', () => {
		expect( getLabelIntent( 'reliable' ) ).toBe( 'stable' );
		expect( getLabelIntent( 'directional' ) ).toBe( 'medium' );
		expect( getLabelIntent( 'collecting' ) ).toBe( 'low' );
	} );
} );
