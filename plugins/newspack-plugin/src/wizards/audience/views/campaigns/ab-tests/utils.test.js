import { formatConfidence, formatLift, getStatusIntent, getTestStatus, sortTests, splitSummary } from './utils';

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

describe( 'formatLift', () => {
	it( 'always shows a sign, and a dash when there is nothing to compare', () => {
		expect( formatLift( 41.6 ) ).toBe( '+41.6%' );
		expect( formatLift( -3.25 ) ).toBe( '-3.3%' );
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

describe( 'splitSummary', () => {
	it( 'gives B the rest of an A/B test', () => {
		expect( splitSummary( 60, [ 'a', 'b' ] ) ).toBe( '60% see A, 40% see B.' );
	} );
	it( 'divides the rest evenly when there are more versions', () => {
		const summary = splitSummary( 60, [ 'c', 'a', 'b' ] );
		expect( summary ).toContain( '60% see A' );
		expect( summary ).toContain( '20% see B' );
		expect( summary ).toContain( '20% see C' );
		expect( summary ).not.toContain( '40%' );
	} );
	it( 'names the challenger when it is not B', () => {
		expect( splitSummary( 60, [ 'a', 'c' ] ) ).toBe( '60% see A, 40% see C.' );
	} );
	it( 'keeps the shares at 100 when they do not divide evenly', () => {
		const summary = splitSummary( 50, [ 'a', 'b', 'c', 'd' ] );
		expect( summary ).toContain( '17% see B' );
		expect( summary ).toContain( '17% see C' );
		expect( summary ).toContain( '16% see D' );
	} );
} );

describe( 'sortTests', () => {
	it( 'lists running tests before incomplete ones', () => {
		const tests = [
			{ test_id: 'i', variants: { a: variant() } },
			{ test_id: 'r', variants: { a: variant(), b: variant() } },
		];
		expect( sortTests( tests ).map( test => test.test_id ) ).toEqual( [ 'r', 'i' ] );
	} );
} );
