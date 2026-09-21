/**
 * The meter reads its settings from a `wp_localize_script` tag printed immediately
 * before the script itself. An optimizer that delays scripts can replay that inline
 * tag after this module has run, so the settings must be read when the meter runs,
 * not when the module is evaluated. Reading them too early threw and skipped the
 * meter entirely, which served every metered article in full.
 */

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

const SETTINGS = {
	count: 2,
	period: 'month',
	gate_id: 84,
	meter_key: 'news',
	post_id: 32,
	excerpt: '<p>Teaser.</p>',
};

describe( 'content gate metering', () => {
	beforeEach( () => {
		document.body.innerHTML =
			'<div class="entry-content"><p>Full article.</p></div>' +
			'<div class="newspack-content-gate__gate newspack-content-gate__inline-gate">Gate</div>';
		document.body.className = '';
		delete window.newspack_metering_settings;
		window.newspackRAS = [];
		jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		// eslint-disable-next-line no-console
		console.warn.mockRestore();
	} );

	it( 'reads settings that arrive after the module is evaluated', () => {
		const meter = loadMeterCallback();

		// The settings tag is replayed only now, after this module already ran.
		window.newspack_metering_settings = SETTINGS;
		const ras = createRAS();
		meter( ras );

		expect( ras.store.get( 'metering-news' ).content ).toEqual( [ 32 ] );
		expect( document.querySelector( '.newspack-content-gate__gate' ) ).toBeNull();
	} );

	it( 'leaves the gate in place and warns once when the settings never arrive', () => {
		const meter = loadMeterCallback();

		meter( createRAS() );
		meter( createRAS() );

		expect( document.querySelector( '.newspack-content-gate__gate' ) ).not.toBeNull();
		expect( document.querySelector( '.entry-content' ).textContent ).toBe( 'Full article.' );
		// eslint-disable-next-line no-console
		expect( console.warn ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'locks the article once the allowance is spent', () => {
		const meter = loadMeterCallback();
		window.newspack_metering_settings = SETTINGS;

		// An allowance that has not rolled over yet, so the two recorded views stand.
		const unexpired = Math.floor( Date.now() / 1000 ) + 400 * 86400;
		meter( createRAS( { 'metering-news': { content: [ 1, 2 ], expiration: unexpired } } ) );

		expect( document.body.classList.contains( 'newspack-content-locked' ) ).toBe( true );
		expect( document.querySelector( '.entry-content' ).innerHTML ).toContain( 'Teaser.' );
	} );
} );
