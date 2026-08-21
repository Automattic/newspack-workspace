/**
 * Prompt display depends on the pageview counter having been written for the
 * current page: `logPageview` is pushed onto `newspackRAS` before
 * `maybeDisplayPrompts`, and the queue drains synchronously in push order, so
 * on an ordinary load the write always lands first.
 *
 * Deferring the write for a prerendered page has to defer the evaluation with
 * it, or prompt display reads a counter that is not there yet (NPPM-3134).
 */
import { handleSegmentation } from './segmentation';
import { logPageview } from './utils';

/**
 * A store faithful to reader-activation's: an unwritten key reads back as null,
 * not undefined and not an empty object.
 *
 * @return {Object} A `ras` stand-in.
 */
function createRAS() {
	const data = {};
	return {
		store: {
			get: key => ( key in data ? data[ key ] : null ),
			set: ( key, value ) => {
				data[ key ] = value;
			},
		},
		getActivities: () => [],
		segments: { register: () => {}, setMatch: () => {} },
	};
}

/**
 * Drain the queue the way reader-activation's handlePush does: synchronously,
 * in push order, invoking each callback with the `ras` object.
 *
 * @param {Object} ras The `ras` stand-in.
 */
function drainRAS( ras ) {
	const queued = window.newspackRAS;
	window.newspackRAS = [];
	queued.forEach( arg => arg( ras ) );
}

function addPrompt() {
	document.body.innerHTML = '<div id="id_123" class="newspack-popup hidden" data-frequency="0,0,0,month"></div>';
	return [ ...document.querySelectorAll( '.newspack-popup' ) ];
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

describe( 'prompt display and the pageview counter', () => {
	beforeEach( () => {
		global.newspack_popups_view = { segments: {}, donor_landing_page: 0 };
		window.newspackRAS = [];
	} );

	afterEach( () => {
		delete global.newspack_popups_view;
		delete document.prerendering;
		delete window.newspackRAS;
		document.body.innerHTML = '';
	} );

	it( 'should display the prompt on an ordinary page load', () => {
		const ras = createRAS();
		const prompts = addPrompt();
		window.newspackRAS.push( logPageview );
		handleSegmentation( prompts );
		drainRAS( ras );
		expect( prompts[ 0 ].classList.contains( 'hidden' ) ).toBe( false );
	} );

	it( 'should not evaluate prompts while the document is prerendering', () => {
		setPrerendering( true );
		const ras = createRAS();
		const prompts = addPrompt();
		window.newspackRAS.push( logPageview );
		handleSegmentation( prompts );
		expect( () => drainRAS( ras ) ).not.toThrow();
		expect( prompts[ 0 ].classList.contains( 'hidden' ) ).toBe( true );
	} );

	it( 'should display the prompt once activated, counting the current page', () => {
		setPrerendering( true );
		const ras = createRAS();
		const prompts = addPrompt();
		window.newspackRAS.push( logPageview );
		handleSegmentation( prompts );
		drainRAS( ras );
		activate();
		expect( ras.store.get( 'pageviews' ).month.count ).toBe( 1 );
		expect( prompts[ 0 ].classList.contains( 'hidden' ) ).toBe( false );
	} );
} );
