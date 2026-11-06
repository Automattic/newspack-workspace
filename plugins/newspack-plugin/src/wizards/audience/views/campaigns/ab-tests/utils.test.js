import { computeLift, formatConfidence, formatLift, getStatusIntent, getTestStatus, sortTests } from './utils';

const variant = ( extra = {} ) => ( { status: 'publish', eligible: true, ...extra } );

describe( 'getTestStatus', () => {
	it( 'reads each state from the versions', () => {
		expect( getTestStatus( { variants: { a: variant() } } ) ).toBe( 'incomplete' );
		expect( getTestStatus( { variants: { a: variant(), b: variant( { status: 'draft' } ) } } ) ).toBe( 'draft' );
		expect( getTestStatus( { variants: { a: variant(), b: variant() } } ) ).toBe( 'running' );
		expect( getTestStatus( { variants: { a: variant(), b: variant( { eligible: false } ) } } ) ).toBe( 'needs-attention' );
	} );
} );

describe( 'getStatusIntent', () => {
	it( 'gives each status its badge intent', () => {
		expect( [ 'running', 'needs-attention', 'draft', 'incomplete' ].map( getStatusIntent ) ).toEqual( [ 'stable', 'high', 'draft', 'low' ] );
	} );
} );

describe( 'computeLift', () => {
	it( 'uses the rates as the table shows them', () => {
		// The raw rates give +41.4%; the 1.90% and 2.69% the table shows give +41.6%.
		expect( formatLift( computeLift( 1.8992, 2.6862 ) ) ).toBe( '+41.6%' );
		expect( formatLift( computeLift( 1.9, 2.69 ) ) ).toBe( '+41.6%' );
	} );

	it( 'has nothing to compare against a zero or missing rate', () => {
		expect( computeLift( 0, 2 ) ).toBeNull();
		expect( computeLift( null, 2 ) ).toBeNull();
		expect( formatLift( null ) ).toBe( '—' );
	} );
} );

describe( 'formatConfidence', () => {
	const labels = { reliable: 'Reliable', collecting: 'Too early' };
	it( 'puts the percentage before the label, or shows the label alone', () => {
		expect( formatConfidence( { confidence: 99.12, label: 'reliable' }, labels ) ).toBe( '99.1% Reliable' );
		expect( formatConfidence( { confidence: null, label: 'collecting' }, labels ) ).toBe( 'Too early' );
		expect( formatConfidence( null, labels ) ).toBe( '—' );
	} );
} );

describe( 'sortTests', () => {
	it( 'keeps the retired page’s order', () => {
		const tests = [
			{ test_id: 'i', variants: { a: variant() } },
			{ test_id: 'r', variants: { a: variant(), b: variant() } },
		];
		expect( sortTests( tests ).map( test => test.test_id ) ).toEqual( [ 'r', 'i' ] );
	} );
} );
