import { whenActivated } from './prerender';

/**
 * jsdom implements neither `document.prerendering` nor the `prerenderingchange`
 * event, so both are simulated here. `prerendering` is defined as a configurable
 * own property so each test can put the document into the state it needs and
 * `activate()` can flip it the way a real activation does.
 *
 * @param {boolean|undefined} prerendering Initial value, or undefined to simulate
 *                                         a browser without the Speculation Rules API.
 * @return {Function} Activates the document, firing `prerenderingchange`.
 */
function mockPrerendering( prerendering ) {
	if ( undefined === prerendering ) {
		delete document.prerendering;
		return () => {};
	}
	Object.defineProperty( document, 'prerendering', {
		value: prerendering,
		configurable: true,
		writable: true,
	} );
	return () => {
		document.prerendering = false;
		document.dispatchEvent( new Event( 'prerenderingchange' ) );
	};
}

describe( 'whenActivated', () => {
	afterEach( () => {
		delete document.prerendering;
	} );

	it( 'should run the callback immediately when the document is not prerendering', () => {
		mockPrerendering( false );
		const callback = jest.fn();
		whenActivated( callback );
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'should run the callback immediately when the browser has no prerendering support', () => {
		mockPrerendering( undefined );
		const callback = jest.fn();
		whenActivated( callback );
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'should not run the callback while the document is prerendering', () => {
		mockPrerendering( true );
		const callback = jest.fn();
		whenActivated( callback );
		expect( callback ).not.toHaveBeenCalled();
	} );

	it( 'should run the callback once the prerendered document is activated', () => {
		const activate = mockPrerendering( true );
		const callback = jest.fn();
		whenActivated( callback );
		activate();
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'should run each deferred callback once, in the order it was deferred', () => {
		const activate = mockPrerendering( true );
		const calls = [];
		whenActivated( () => calls.push( 'first' ) );
		whenActivated( () => calls.push( 'second' ) );
		activate();
		document.dispatchEvent( new Event( 'prerenderingchange' ) );
		expect( calls ).toEqual( [ 'first', 'second' ] );
	} );
} );
