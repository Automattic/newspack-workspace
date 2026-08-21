/**
 * Tests for pageview logging.
 *
 * A browser can prerender a link the reader never clicks. Scripts run in that
 * hidden document, so without a gate the visit is counted and the reader crosses
 * "has read N articles" segment thresholds for pages they never opened
 * (NPPM-3134).
 */
import { logPageview } from './index';

/**
 * Build a minimal stand-in for the Reader Data Library object.
 *
 * @return {Object} A `ras` with an in-memory store.
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
	};
}

function setPrerendering( prerendering ) {
	Object.defineProperty( document, 'prerendering', {
		value: prerendering,
		configurable: true,
		writable: true,
	} );
}

function activate() {
	document.prerendering = false;
	document.dispatchEvent( new Event( 'prerenderingchange' ) );
}

describe( 'logPageview', () => {
	beforeEach( () => {
		global.newspack_popups_view = { donor_landing_page: 0 };
	} );

	afterEach( () => {
		delete global.newspack_popups_view;
		delete document.prerendering;
		document.body.className = '';
	} );

	it( 'should increment the pageview counters on a normal page view', () => {
		const ras = createRAS();
		logPageview( ras );
		expect( ras.store.get( 'pageviews' ).day.count ).toBe( 1 );
	} );

	it( 'should not increment the pageview counters while prerendering', () => {
		setPrerendering( true );
		const ras = createRAS();
		logPageview( ras );
		expect( ras.store.get( 'pageviews' ) ).toBeUndefined();
	} );

	it( 'should increment the pageview counters once the prerender is activated', () => {
		setPrerendering( true );
		const ras = createRAS();
		logPageview( ras );
		activate();
		expect( ras.store.get( 'pageviews' ).day.count ).toBe( 1 );
	} );

	/**
	 * Reaching the donor landing page marks the reader as a donor, which changes
	 * which prompts they see. Prerendering that page is not reaching it.
	 */
	it( 'should not mark the reader as a donor while prerendering the donor landing page', () => {
		setPrerendering( true );
		global.newspack_popups_view = { donor_landing_page: 7 };
		document.body.className = 'page-id-7';
		const ras = createRAS();
		logPageview( ras );
		expect( ras.store.get( 'is_donor' ) ).toBeUndefined();
	} );
} );
