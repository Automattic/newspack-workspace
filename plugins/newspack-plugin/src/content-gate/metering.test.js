/**
 * Tests for the client-side metering gate.
 *
 * metering.js reads `newspack_metering_settings` at import time and pushes
 * itself onto `window.newspackRAS`, so each test imports it fresh inside
 * `jest.isolateModules()` with the globals already in place.
 */

/**
 * Build a minimal stand-in for the Reader Data Library object metering uses.
 *
 * @return {Object} A `ras` with an in-memory store and a spied dispatchActivity.
 */
function createRAS() {
	const data = {};
	return {
		store: {
			get: key => data[ key ],
			set: ( key, value ) => {
				data[ key ] = value;
			},
		},
		dispatchActivity: jest.fn(),
		overlays: { get: () => [] },
	};
}

/**
 * The `month` expiration metering computes for right now. Stored data carrying a
 * different expiration is treated as expired and its content list is cleared, so
 * a test that primes the list has to match it.
 *
 * @return {number} Unix timestamp in seconds.
 */
function currentMonthExpiration() {
	const date = new Date();
	date.setHours( 0, 0, 0, 0 );
	date.setMonth( date.getMonth() + 1 );
	date.setDate( 1 );
	return parseInt( date.getTime() / 1000, 10 );
}

function loadMeter( settings ) {
	global.newspack_metering_settings = {
		gate_id: 1,
		post_id: 42,
		count: 3,
		period: 'month',
		excerpt: '',
		article_view: { action: 'article_view', data: { post_id: 42 } },
		...settings,
	};
	let meter;
	jest.isolateModules( () => {
		( { meter } = require( './metering' ) );
	} );
	return meter;
}

describe( 'meter', () => {
	beforeEach( () => {
		window.newspackRAS = [];
	} );

	afterEach( () => {
		delete global.newspack_metering_settings;
		delete document.prerendering;
		delete window.newspackRAS;
	} );

	it( 'should add the post to the metered content list on a normal page view', () => {
		const ras = createRAS();
		loadMeter()( ras );
		expect( ras.store.get( 'metering-1' ).content ).toEqual( [ 42 ] );
	} );

	/**
	 * A prerendered article the reader never opens must not spend one of their
	 * free reads (NPPM-3134).
	 */
	it( 'should not add the post to the metered content list while prerendering', () => {
		Object.defineProperty( document, 'prerendering', { value: true, configurable: true, writable: true } );
		const ras = createRAS();
		loadMeter()( ras );
		expect( ras.store.get( 'metering-1' ).content ).toEqual( [] );
	} );

	it( 'should add the post to the metered content list once the prerender is activated', () => {
		Object.defineProperty( document, 'prerendering', { value: true, configurable: true, writable: true } );
		const ras = createRAS();
		loadMeter()( ras );
		document.prerendering = false;
		document.dispatchEvent( new Event( 'prerenderingchange' ) );
		expect( ras.store.get( 'metering-1' ).content ).toEqual( [ 42 ] );
	} );

	/**
	 * The gating decision must NOT be deferred: a prerendered page that renders
	 * unlocked and then locks itself on activation is worse than the bug.
	 */
	it( 'should lock content during prerender when the reader is over the limit', () => {
		Object.defineProperty( document, 'prerendering', { value: true, configurable: true, writable: true } );
		document.body.innerHTML = '<div class="entry-content">full article</div>';
		const ras = createRAS();
		ras.store.set( 'metering-1', { content: [ 1, 2, 3 ], expiration: currentMonthExpiration() } );
		loadMeter()( ras );
		expect( document.body.classList.contains( 'newspack-content-locked' ) ).toBe( true );
		document.body.className = '';
		document.body.innerHTML = '';
	} );
} );
