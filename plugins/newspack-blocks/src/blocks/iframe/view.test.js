/**
 * The iframe block's retry (NPPM-3180) must leave a frame that already holds a
 * document alone, and must never reset `src`, which adds a history entry.
 */

const SRC = 'https://embed.example.test/widget';

/**
 * Render one iframe block and stub the frame state jsdom cannot provide.
 *
 * @param {Object|null} contentDocument Reported while the frame is attached, null once a
 *                                      cross-origin document commits. Detached reports null.
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
	Object.defineProperty( iframe, 'contentWindow', { configurable: true, get: () => contentWindow } );
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

	it( 'leaves a frame that already holds a cross-origin document alone', () => {
		// The delayed-script case. The frame loaded before this script ran.
		const { replace, setSrc } = renderIframe( null );
		jest.advanceTimersByTime( 10000 );
		expect( replace ).not.toHaveBeenCalled();
		expect( setSrc ).not.toHaveBeenCalled();
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'leaves a frame that holds a same-origin document alone', () => {
		const { replace, setSrc } = renderIframe( { URL: 'https://example.test/embed' } );
		jest.advanceTimersByTime( 10000 );
		expect( replace ).not.toHaveBeenCalled();
		expect( setSrc ).not.toHaveBeenCalled();
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'retries with location.replace while nothing has committed, then stops', () => {
		const { state, replace, setSrc } = renderIframe( { URL: 'about:blank' } );

		jest.advanceTimersByTime( 2000 );
		expect( replace ).toHaveBeenCalledTimes( 1 );
		expect( replace ).toHaveBeenCalledWith( SRC );

		jest.advanceTimersByTime( 2000 );
		expect( replace ).toHaveBeenCalledTimes( 2 );

		// The retry succeeded and a cross-origin document committed.
		state.contentDocument = null;
		jest.advanceTimersByTime( 10000 );
		expect( replace ).toHaveBeenCalledTimes( 2 );
		expect( jest.getTimerCount() ).toBe( 0 );

		// Resetting src is what adds the history entry.
		expect( setSrc ).not.toHaveBeenCalled();
	} );

	it( 'stops retrying once the frame is removed from the page', () => {
		const { iframe, replace } = renderIframe( { URL: 'about:blank' } );
		jest.advanceTimersByTime( 2000 );
		expect( replace ).toHaveBeenCalledTimes( 1 );

		iframe.remove();
		jest.advanceTimersByTime( 10000 );
		expect( replace ).toHaveBeenCalledTimes( 1 );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );
} );
