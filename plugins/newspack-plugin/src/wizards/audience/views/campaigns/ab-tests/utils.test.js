import {
	canEndTest,
	formatConfidence,
	formatLift,
	getRecommendation,
	getStatusIntent,
	getTestStatus,
	latestOnly,
	sortTests,
	splitSummary,
} from './utils';

const variant = ( extra = {} ) => ( { status: 'publish', eligible: true, ...extra } );

describe( 'getTestStatus', () => {
	it( 'reads each state from the versions', () => {
		expect( getTestStatus( { variants: { a: variant() } } ) ).toBe( 'incomplete' );
		expect( getTestStatus( { variants: { a: variant(), b: variant( { status: 'draft' } ) } } ) ).toBe( 'draft' );
		expect( getTestStatus( { variants: { a: variant(), b: variant() } } ) ).toBe( 'running' );
		expect( getTestStatus( { variants: { a: variant(), b: variant( { eligible: false } ) } } ) ).toBe( 'needs-attention' );
	} );

	it( 'takes an ended test’s state from the server, whatever its versions say', () => {
		expect( getTestStatus( { status: 'ended', variants: { a: variant( { status: 'draft' } ), b: variant() } } ) ).toBe( 'ended' );
	} );
} );

describe( 'getStatusIntent', () => {
	it( 'gives each status its badge intent', () => {
		expect( [ 'running', 'needs-attention', 'draft', 'incomplete', 'ended' ].map( getStatusIntent ) ).toEqual( [
			'stable',
			'high',
			'draft',
			'low',
			'informational',
		] );
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

describe( 'latestOnly', () => {
	const deferred = () => {
		let resolve, reject;
		const promise = new Promise( ( res, rej ) => {
			resolve = res;
			reject = rej;
		} );
		return { promise, resolve, reject };
	};

	it( 'drops an earlier response that resolves after a later one', async () => {
		const first = deferred();
		const second = deferred();
		const fetcher = jest.fn().mockReturnValueOnce( first.promise ).mockReturnValueOnce( second.promise );
		const onSettle = jest.fn();
		const load = latestOnly( fetcher, onSettle );

		const a = load();
		const b = load();
		second.resolve( 'new' );
		await b;
		first.resolve( 'old' );
		await a;

		expect( onSettle ).toHaveBeenCalledTimes( 1 );
		expect( onSettle ).toHaveBeenCalledWith( 'new' );
	} );

	it( 'settles the latest failure with null, but not an earlier one', async () => {
		const first = deferred();
		const second = deferred();
		const fetcher = jest.fn().mockReturnValueOnce( first.promise ).mockReturnValueOnce( second.promise );
		const onSettle = jest.fn();
		const load = latestOnly( fetcher, onSettle );

		const a = load();
		const b = load();
		first.reject( new Error( 'stale' ) );
		await a;
		expect( onSettle ).not.toHaveBeenCalled();

		second.reject( new Error( 'down' ) );
		await b;
		expect( onSettle ).toHaveBeenCalledWith( null );
	} );
} );

describe( 'canEndTest', () => {
	it( 'allows ending only a test that is splitting readers', () => {
		expect( [ 'running', 'needs-attention', 'draft', 'incomplete', 'ended' ].map( canEndTest ) ).toEqual( [ true, true, false, false, false ] );
	} );
} );

describe( 'getRecommendation', () => {
	const twoVersions = { variants: { a: variant(), b: variant() } };
	const result = ( { label = 'reliable', lift = 12.3, young = false, state = 'ok' } = {} ) => ( {
		source: { state },
		variants: { a: { rate: 1.9, lift: null }, b: { rate: lift > 0 ? 2.69 : 1.5, lift } },
		comparisons: { b: { confidence: 99.1, label } },
		flags: { young_test: young },
	} );

	it( 'recommends a reliable winner once the test is old enough', () => {
		expect( getRecommendation( twoVersions, result() ) ).toEqual( { letter: 'b', winner: true } );
	} );

	it( 'recommends A while the test is young, however reliable the result', () => {
		expect( getRecommendation( twoVersions, result( { young: true } ) ) ).toEqual( { letter: 'a', winner: false } );
	} );

	it( 'recommends A without a reliable result, or without results', () => {
		expect( getRecommendation( twoVersions, result( { label: 'directional' } ) ) ).toEqual( { letter: 'a', winner: false } );
		expect( getRecommendation( twoVersions, result( { state: 'unavailable' } ) ) ).toEqual( { letter: 'a', winner: false } );
		expect( getRecommendation( twoVersions, null ) ).toEqual( { letter: 'a', winner: false } );
	} );

	it( 'names A the winner when the challenger is reliably worse', () => {
		expect( getRecommendation( twoVersions, result( { lift: -21 } ) ) ).toEqual( { letter: 'a', winner: true } );
	} );
} );
