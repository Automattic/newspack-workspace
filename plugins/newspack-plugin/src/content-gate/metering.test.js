/**
 * The meter reads its allowance from a `type="application/json"` element the server
 * prints, not from a global set by an inline script. A performance optimizer can hold
 * an inline script back while letting the metering file through, and the meter running
 * without its allowance leaves every metered article readable, because metering makes
 * the server send the whole article.
 */

const SETTINGS = {
	count: 2,
	period: 'month',
	gate_id: 84,
	meter_key: 'news',
	post_id: 32,
	excerpt: '<p>Teaser.</p>',
};

/**
 * Render the page the server sends for a metered post: the whole article, the gate
 * hidden, and optionally the settings element.
 *
 * @param {Object|null} settings Settings to print, or null to print none.
 */
function renderMeteredPage( settings ) {
	document.body.innerHTML =
		'<div class="entry-content"><p>Full article.</p></div>' +
		'<div style="display:none"><div class="newspack-content-gate__gate newspack-content-gate__inline-gate">Gate</div></div>' +
		( settings ? '<script type="application/json" id="newspack-metering-settings">' + JSON.stringify( settings ) + '</script>' : '' );
	document.body.className = '';
}

/**
 * Load metering.js fresh and return the callback it queued on window.newspackRAS.
 *
 * @return {Function} The queued meter callback.
 */
function loadMeterCallback() {
	jest.isolateModules( () => {
		require( './metering' );
	} );
	return window.newspackRAS[ window.newspackRAS.length - 1 ];
}

/**
 * A reader-activation double with an in-memory store.
 *
 * @param {Object} stored Initial store contents, keyed by store key.
 *
 * @return {Object} The double, with a `store` and recorded `activities`.
 */
function createRAS( stored = {} ) {
	const activities = [];
	return {
		activities,
		store: {
			get: key => stored[ key ],
			set: ( key, value ) => {
				stored[ key ] = value;
			},
		},
		dispatchActivity: ( action, data ) => activities.push( { action, data } ),
	};
}

describe( 'content gate metering', () => {
	beforeEach( () => {
		delete window.newspack_metering_settings;
		window.newspackRAS = [];
	} );

	it( 'reads the allowance from the DOM, not from a global set at load time', () => {
		renderMeteredPage( SETTINGS );
		const meter = loadMeterCallback();
		// Nothing ever assigns the global; the settings element is the only source.
		expect( window.newspack_metering_settings ).toBeUndefined();

		const ras = createRAS();
		meter( ras );

		expect( ras.store.get( 'metering-news' ).content ).toEqual( [ 32 ] );
		expect( document.querySelector( '.newspack-content-gate__gate' ) ).toBeNull();
	} );

	it( 'locks the article once the allowance is spent', () => {
		renderMeteredPage( SETTINGS );
		const meter = loadMeterCallback();
		// An allowance that has not rolled over yet, so the two recorded views stand.
		const unexpired = Math.floor( Date.now() / 1000 ) + 400 * 86400;

		meter( createRAS( { 'metering-news': { content: [ 1, 2 ], expiration: unexpired } } ) );

		expect( document.body.classList.contains( 'newspack-content-locked' ) ).toBe( true );
		expect( document.querySelector( '.entry-content' ).innerHTML ).toContain( 'Teaser.' );
	} );

	it( 'leaves an unmetered page alone when no settings element is present', () => {
		renderMeteredPage( null );
		const meter = loadMeterCallback();

		meter( createRAS() );

		expect( document.querySelector( '.newspack-content-gate__gate' ) ).not.toBeNull();
		expect( document.querySelector( '.entry-content' ).textContent ).toBe( 'Full article.' );
	} );
} );
