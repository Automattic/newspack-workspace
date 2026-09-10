/**
 * The iframe block's retry (NPPM-3180) must leave a frame that already holds a
 * document alone, and must never reset `src`, which adds a history entry.
 */

const SRC = 'https://embed.example.test/widget';

/**
 * Render one iframe block and stub the frame state jsdom cannot provide.
 *
 * @param {Object|null} contentDocument What the attached frame reports: the initial
 *                                      about:blank document while a navigation is in
 *                                      flight or was abandoned (cross-origin included),
 *                                      null once a cross-origin document has loaded.
 *                                      A detached frame reports null, as does its
 *                                      contentWindow.
 * @return {Object} The iframe, its state holder, and the `replace` and `setSrc` spies.
 */
function renderIframe( contentDocument ) {
	document.body.innerHTML = `<figure class="wp-block-newspack-blocks-iframe"><div class="wp-block-embed__wrapper"><iframe src="${ SRC }"></iframe></div></figure>`;
	const iframe = document.querySelector( 'iframe' );
	const state = { contentDocument };
	const replace = jest.fn();
	const setSrc = jest.fn();
	const contentWindow = { location: { replace } };
	Object.defineProperty( iframe, 'contentDocument', { configurable: true, get: () => ( iframe.isConnected ? state.contentDocument : null ) } );
	Object.defineProperty( iframe, 'contentWindow', { configurable: true, get: () => ( iframe.isConnected ? contentWindow : null ) } );
	Object.defineProperty( iframe, 'src', { configurable: true, get: () => SRC, set: setSrc } );
	jest.isolateModules( () => require( './view' ) );
	return { iframe, state, replace, setSrc };
}

describe( 'iframe block view script', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.clearAllTimers();
		jest.useRealTimers();
		document.body.innerHTML = '';
	} );

	it.each( [
		// The delayed-script case: the frame loaded before this script ran.
		[ 'cross-origin', null ],
		[ 'same-origin', { URL: 'https://example.test/embed' } ],
	] )( 'leaves a frame that already holds a %s document alone', ( _, contentDocument ) => {
		const { replace, setSrc } = renderIframe( contentDocument );
		jest.advanceTimersByTime( 10000 );
		expect( replace ).not.toHaveBeenCalled();
		expect( setSrc ).not.toHaveBeenCalled();
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'retries with location.replace while the frame is still blank, then stops', () => {
		const { state, replace, setSrc } = renderIframe( { URL: 'about:blank' } );

		jest.advanceTimersByTime( 2000 );
		expect( replace ).toHaveBeenCalledTimes( 1 );
		expect( replace ).toHaveBeenCalledWith( SRC );

		jest.advanceTimersByTime( 2000 );
		expect( replace ).toHaveBeenCalledTimes( 2 );

		// The retry succeeded and a cross-origin document loaded.
		state.contentDocument = null;
		jest.advanceTimersByTime( 10000 );
		expect( replace ).toHaveBeenCalledTimes( 2 );
		expect( jest.getTimerCount() ).toBe( 0 );

		// Resetting src is what adds the history entry.
		expect( setSrc ).not.toHaveBeenCalled();
	} );

	it( 'gives up after 10 attempts on a frame that never loads', () => {
		const { replace } = renderIframe( { URL: 'about:blank' } );
		jest.advanceTimersByTime( 20000 );
		expect( replace ).toHaveBeenCalledTimes( 10 );

		jest.advanceTimersByTime( 20000 );
		expect( replace ).toHaveBeenCalledTimes( 10 );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'checks the frame before touching contentWindow, so a removed frame does not throw', () => {
		const { iframe, replace } = renderIframe( { URL: 'about:blank' } );
		jest.advanceTimersByTime( 2000 );
		expect( replace ).toHaveBeenCalledTimes( 1 );

		// A detached frame has no contentWindow; dereferencing it would throw inside the tick.
		iframe.remove();
		expect( () => jest.advanceTimersByTime( 10000 ) ).not.toThrow();
		expect( replace ).toHaveBeenCalledTimes( 1 );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );
} );
