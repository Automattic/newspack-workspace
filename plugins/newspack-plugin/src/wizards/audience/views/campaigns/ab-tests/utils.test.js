import {
	canEndTest,
	canEndTestFromMenu,
	createRequestQueue,
	formatConfidence,
	formatLift,
	formatRate,
	getRecommendation,
	getVerdict,
	getStatusIntent,
	getTestStatus,
	latestOnly,
	sortTests,
	splitSummary,
} from './utils';

// Pinned so the en-US expectations below hold on a host in any locale.
beforeEach( () => {
	document.documentElement.lang = 'en-US';
} );

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
	it( 'shows a sign on a nonzero lift, and a dash when there is nothing to compare', () => {
		expect( formatLift( 41.6 ) ).toBe( '+41.6%' );
		expect( formatLift( -3.25 ) ).toBe( '-3.3%' );
		expect( formatLift( null ) ).toBe( '—' );
	} );

	it( 'leaves a zero lift unsigned, including one rounded down to negative zero', () => {
		expect( formatLift( 0 ) ).toBe( '0.0%' );
		expect( formatLift( -0 ) ).toBe( '0.0%' );
		expect( formatLift( -0.04 ) ).toBe( '0.0%' );
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

describe( 'percentages in the page’s language', () => {
	const setLang = lang => {
		document.documentElement.lang = lang;
	};

	it( 'uses the page’s decimal mark and percent sign placement', () => {
		setLang( 'fr-FR' );
		expect( formatRate( 2.6912 ) ).toMatch( /^2,69\s%$/ );
		expect( formatConfidence( { confidence: 99.12, label: 'reliable' }, { reliable: 'Fiable' } ) ).toMatch( /^99,1\s% Fiable$/ );
		setLang( 'tr-TR' );
		expect( formatRate( 2.6912 ) ).toBe( '%2,69' );
	} );

	it( 'keeps the sign on a lift in any language', () => {
		setLang( 'de-DE' );
		expect( formatLift( 41.6 ) ).toMatch( /^\+41,6\s%$/ );
		expect( formatLift( -3.25 ) ).toMatch( /^-3,3\s%$/ );
	} );

	it( 'falls back to a shorter tag when Intl rejects the page’s locale', () => {
		setLang( 'pt-PT-ao90' );
		expect( formatRate( 2.6912 ) ).toBe( '2,69%' );
	} );

	it( 'shows the decimals even for a whole number', () => {
		setLang( 'en-US' );
		expect( formatRate( 3 ) ).toBe( '3.00%' );
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
	const cached = ( { label = 'reliable', young = false } = {} ) => ( {
		variants: { a: { rate: 1.9, lift: null }, b: { rate: 2.69, lift: 41.6 } },
		comparisons: { b: { confidence: 99.1, label } },
		flags: { young_test: young },
	} );
	const running = ( id, cachedStats = null ) => ( { test_id: id, variants: { a: variant(), b: variant() }, cached_stats: cachedStats } );
	const ids = tests => sortTests( tests ).map( test => test.test_id );

	it( 'puts what needs the publisher first and ended tests last', () => {
		const tests = [
			{ test_id: 'ended', status: 'ended', variants: { a: variant(), b: variant() } },
			running( 'running' ),
			running( 'ready', cached() ),
			{ test_id: 'draft', variants: { a: variant(), b: variant( { status: 'draft' } ) } },
			{ test_id: 'incomplete', variants: { a: variant() } },
			{ test_id: 'attention', variants: { a: variant(), b: variant( { eligible: false } ) } },
		];
		expect( ids( tests ) ).toEqual( [ 'attention', 'incomplete', 'draft', 'ready', 'running', 'ended' ] );
	} );

	it( 'puts a running test ahead only when its card would name a winner', () => {
		const tests = [
			running( 'none' ),
			running( 'early', cached( { label: 'directional' } ) ),
			running( 'young', cached( { young: true } ) ),
			running( 'ready', cached() ),
		];
		expect( ids( tests ) ).toEqual( [ 'ready', 'none', 'early', 'young' ] );
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
	const version = ( status = 'publish', eligible = true ) => ( { status, eligible } );
	it( 'allows ending only a test that is splitting readers, as the server does', () => {
		const tests = [
			{ variants: { a: version(), b: version() } }, // Running.
			{ variants: { a: version(), b: version( 'publish', false ) } }, // Needs attention, still splitting.
			{ variants: { a: version(), b: version( 'draft', false ) } }, // Needs attention, not splitting.
			{ variants: { a: version(), b: version( 'draft' ) } }, // Draft.
			{ variants: { a: version() } }, // Incomplete.
			{ status: 'ended', variants: { a: version(), b: version() } },
		];
		expect( tests.map( canEndTest ) ).toEqual( [ true, true, false, false, false, false ] );
	} );
} );

describe( 'canEndTestFromMenu', () => {
	const version = ( status = 'publish', eligible = true ) => ( { status, eligible } );
	it( 'offers ending every test that has not ended but is not splitting readers', () => {
		const tests = [
			{ variants: { a: version(), b: version() } }, // Running: End Test is in the header.
			{ variants: { a: version(), b: version( 'publish', false ) } }, // Needs attention, still splitting.
			{ variants: { a: version(), b: version( 'draft', false ) } }, // Needs attention, not splitting.
			{ variants: { a: version(), b: version( 'draft' ) } }, // Draft.
			{ variants: { a: version() } }, // Incomplete.
			{ variants: { b: version( 'draft' ) } }, // Incomplete, A gone.
			{ status: 'ended', variants: { a: version(), b: version() } },
		];
		expect( tests.map( canEndTestFromMenu ) ).toEqual( [ false, false, true, true, true, true, false ] );
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

describe( 'getVerdict', () => {
	const published = { status: 'publish' };
	const twoVersions = { variants: { a: published, b: published } };
	const result = ( { label = 'reliable', rates = { a: 1.9, b: 2.69 }, young = false, anonymous = false, days = 20, readers = 8000 } = {} ) => ( {
		source: { state: 'ok' },
		variants: Object.fromEntries(
			Object.entries( rates ).map( ( [ letter, rate ] ) => [
				letter,
				{ readers, rate, lift: letter === 'a' || null === rate ? null : ( ( rate - rates.a ) / rates.a ) * 100 },
			] )
		),
		comparisons: Object.fromEntries(
			Object.keys( rates )
				.filter( letter => letter !== 'a' )
				.map( letter => [ letter, { confidence: 99, label } ] )
		),
		flags: { young_test: young, high_anonymous: anonymous, days_running: days },
	} );

	it( 'names a reliable winner once the test is old enough', () => {
		const verdict = getVerdict( twoVersions, 'running', result() );
		expect( verdict.answer ).toBe( 'B wins. 2.69% of readers who saw B converted, against 1.90% for A.' );
		expect( verdict.detail ).toBeNull();
		expect( verdict.bars.rows ).toEqual( [
			{ letter: 'a', width: 70.6 },
			{ letter: 'b', width: 100 },
		] );
	} );

	it( 'holds back a reliable result while the test is young', () => {
		const verdict = getVerdict( twoVersions, 'running', result( { young: true, days: 6 } ) );
		expect( verdict.answer ).toBe( 'B is ahead, 2.69% against 1.90%.' );
		expect( verdict.detail ).toBe( 'The result looks reliable, but the test is 6 days old. Let it run a full two weeks before ending it.' );
	} );

	it( 'names no winner on an early signal, and adds the logged-out caution only when it applies', () => {
		const early = getVerdict( twoVersions, 'running', result( { label: 'directional', rates: { a: 2.98, b: 3.83 } } ) );
		expect( early.answer ).toBe( 'No winner yet. B is ahead, 3.83% against 2.98%.' );
		expect( early.detail ).toBe( 'More readers are needed.' );
		const anonymous = getVerdict( twoVersions, 'running', result( { label: 'collecting', anonymous: true } ) );
		expect( anonymous.detail ).toBe( 'More readers are needed. Some may have seen both versions on different devices.' );
	} );

	it( 'says when two versions are even', () => {
		const verdict = getVerdict( twoVersions, 'running', result( { label: 'collecting', rates: { a: 2, b: 2 } } ) );
		expect( verdict.answer ).toBe( 'No winner yet. A and B are even, at 2.00%.' );
	} );

	it( 'says it is too early, with faint bars, before any reader is counted', () => {
		const verdict = getVerdict( twoVersions, 'running', result( { label: 'collecting', readers: 0 } ) );
		expect( verdict.answer ).toBe( 'Too early to tell.' );
		expect( verdict.detail ).toBe( 'The test started today, so there are no results yet.' );
		expect( verdict.bars.placeholder ).toBe( true );
	} );

	it( 'compares the leader with A when a test has three versions', () => {
		const three = { variants: { a: published, b: published, c: published } };
		const verdict = getVerdict( three, 'running', result( { label: 'directional', rates: { a: 1.9, b: 2.1, c: 2.5 } } ) );
		expect( verdict.answer ).toBe( 'No winner yet. C is ahead, 2.50% against 1.90%.' );
		expect( verdict.bars.rows.map( row => row.letter ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );

	it( 'compares A with the best challenger when A leads', () => {
		const three = { variants: { a: published, b: published, c: published } };
		const verdict = getVerdict( three, 'running', result( { label: 'directional', rates: { a: 3, b: 2.1, c: 2.5 } } ) );
		expect( verdict.answer ).toBe( 'No winner yet. A is ahead, 3.00% against 2.50%.' );
	} );

	it( 'asks for the unpublished versions on a draft', () => {
		const draft = { variants: { a: published, b: { status: 'draft' } } };
		expect( getVerdict( draft, 'draft', null ) ).toEqual( {
			answer: 'Not started.',
			detail: 'Publish version B to start the test.',
			bars: null,
		} );
	} );

	it( 'says which version an ended test kept, with its numbers when there are any', () => {
		const ended = { ...twoVersions, status: 'ended', kept: 'b', ended_at: '2026-10-29T12:00:00Z' };
		const verdict = getVerdict( ended, 'ended', result() );
		expect( verdict.answer ).toMatch( /^Ended .+\. Kept B\.$/ );
		expect( verdict.detail ).toBe( 'B converted 2.69% of readers, against 1.90% for A.' );
		expect( getVerdict( ended, 'ended', null ).detail ).toBeNull();
	} );

	it( 'names A the winner when every challenger is reliably worse', () => {
		const verdict = getVerdict( twoVersions, 'running', result( { rates: { a: 2.69, b: 1.9 } } ) );
		expect( verdict.answer ).toBe( 'A wins. 2.69% of readers who saw A converted, against 1.90% for B.' );
	} );

	it( 'agrees with the End Test recommendation on whether there is a winner', () => {
		expect( getVerdict( twoVersions, 'running', result() ).answer ).toMatch( /^B wins\./ );
		expect( getRecommendation( twoVersions, result() ) ).toEqual( { letter: 'b', winner: true } );
		const young = result( { young: true, days: 6 } );
		expect( getVerdict( twoVersions, 'running', young ).answer ).not.toMatch( /wins/ );
		expect( getRecommendation( twoVersions, young ) ).toEqual( { letter: 'a', winner: false } );
	} );

	it( 'says nothing on a running card when the stats source is unavailable', () => {
		expect( getVerdict( twoVersions, 'running', { ...result(), source: { state: 'unavailable' } } ) ).toBeNull();
	} );

	it( 'compares a kept A with the best challenger', () => {
		const ended = { ...twoVersions, status: 'ended', kept: 'a', ended_at: '2026-10-29T12:00:00Z' };
		expect( getVerdict( ended, 'ended', result() ).detail ).toBe( 'A converted 1.90% of readers, against 2.69% for B.' );
	} );

	it( 'gives an ended test no numbers or bars it cannot back', () => {
		const ended = { ...twoVersions, status: 'ended', kept: 'b', ended_at: '2026-10-29T12:00:00Z' };
		const uncounted = getVerdict( ended, 'ended', result( { rates: { a: 1.9, b: null } } ) );
		expect( uncounted.detail ).toBeNull();
		expect( uncounted.bars ).toBeNull();
		// Nothing converted: the numbers stand, but faint "no data yet" bars would not.
		const flat = getVerdict( ended, 'ended', result( { rates: { a: 0, b: 0 } } ) );
		expect( flat.detail ).toBe( 'B converted 0.00% of readers, against 0.00% for A.' );
		expect( flat.bars ).toBeNull();
	} );

	it( 'leaves incomplete and needs-attention cards to their notice', () => {
		expect( getVerdict( twoVersions, 'incomplete', result() ) ).toBeNull();
		expect( getVerdict( twoVersions, 'needs-attention', result() ) ).toBeNull();
	} );
} );

describe( 'createRequestQueue', () => {
	it( 'runs one task at a time, in order, past a failure', async () => {
		const enqueue = createRequestQueue();
		const started = [];
		const resolvers = {};
		const task = name => () => {
			started.push( name );
			return new Promise( ( resolve, reject ) => {
				resolvers[ name ] = { resolve, reject };
			} );
		};
		const first = enqueue( task( 'first' ) );
		const second = enqueue( task( 'second' ) );
		const third = enqueue( task( 'third' ) );
		await Promise.resolve();
		expect( started ).toEqual( [ 'first' ] );

		resolvers.first.reject( new Error( 'failed' ) );
		await expect( first ).rejects.toThrow( 'failed' );
		await new Promise( resolve => setTimeout( resolve, 0 ) );
		expect( started ).toEqual( [ 'first', 'second' ] );

		resolvers.second.resolve( 2 );
		await expect( second ).resolves.toBe( 2 );
		await new Promise( resolve => setTimeout( resolve, 0 ) );
		expect( started ).toEqual( [ 'first', 'second', 'third' ] );
		resolvers.third.resolve( 3 );
		await expect( third ).resolves.toBe( 3 );
	} );
} );
