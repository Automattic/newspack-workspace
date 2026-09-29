/**
 * The meter reads its allowance from a `type="application/json"` element the server
 * prints, and reads it when it runs rather than when the file loads. A performance
 * optimizer can hold an inline script back while letting the metering file through, and
 * a meter running without its allowance leaves every metered article readable, because
 * metering makes the server send the whole article.
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
 * hidden, and optionally the allowance element.
 *
 * @param {Object|null} settings Allowance to print, or null to print none.
 */
function renderMeteredPage( settings ) {
	document.body.innerHTML =
		'<div class="entry-content"><p>Full article.</p></div>' +
		'<div style="display:none"><div class="newspack-content-gate__gate newspack-content-gate__inline-gate">Gate</div></div>';
	document.body.className = '';
	if ( settings ) {
		printSettings( settings );
	}
}

/**
 * Append the allowance element to the page.
 *
 * @param {Object} settings Allowance to print.
 */
function printSettings( settings ) {
	const element = document.createElement( 'script' );
	element.type = 'application/json';
	element.id = 'newspack-content-gate-metering-settings';
	element.textContent = JSON.stringify( settings );
	document.body.appendChild( element );
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
		window.newspackRAS = [];
	} );

	it( 'reads the allowance that only reaches the page after the module has loaded', () => {
		// The page the optimizer serves: the article and the gate, no allowance yet.
		renderMeteredPage( null );
		const meter = loadMeterCallback();

		printSettings( SETTINGS );
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

	it( 'leaves an unmetered page alone when no allowance element is present', () => {
		renderMeteredPage( null );
		const meter = loadMeterCallback();

		meter( createRAS() );

		expect( document.querySelector( '.newspack-content-gate__gate' ) ).not.toBeNull();
		expect( document.querySelector( '.entry-content' ).textContent ).toBe( 'Full article.' );
	} );
} );
