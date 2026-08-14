/**
 * The gate script wires preview-param propagation and gate rendering as two
 * separate domReady registrations. This pins the property that comment claims:
 * a throw in propagation must not stop the gate from initialising.
 *
 * gate.js is imported for its side effects, so the mocks below have to be in
 * place before the import is evaluated.
 */

const mockPropagate = jest.fn();

jest.mock( './preview-links', () => ( {
	propagateGatePreviewParams: ( ...args ) => mockPropagate( ...args ),
} ) );

jest.mock( '../reader-activation/analytics', () => ( { getEventPayload: jest.fn(), sendEvent: jest.fn() } ) );
jest.mock( '../reader-activation/utils', () => ( { debugLog: jest.fn() } ) );
jest.mock( '../shared/js/cta-attribution', () => ( { persistCtaAttribution: jest.fn() } ) );
jest.mock( './gate.scss', () => ( {} ), { virtual: true } );

describe( 'gate.js preview-param wiring', () => {
	beforeEach( () => {
		jest.resetModules();
		mockPropagate.mockReset();
		global.newspack_content_gate = { metadata: {} };
		// `interactive` makes gate.js's domReady() invoke synchronously, which is
		// the path a footer/async script actually takes.
		Object.defineProperty( document, 'readyState', { value: 'interactive', configurable: true } );
		document.body.innerHTML = '<div class="newspack-content-gate__gate"></div>';
		jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		console.warn.mockRestore();
	} );

	it( 'runs propagation on load', () => {
		require( './gate' );

		expect( mockPropagate ).toHaveBeenCalled();
	} );

	it( 'still initialises the gate when propagation throws', () => {
		mockPropagate.mockImplementation( () => {
			throw new Error( 'isolation' );
		} );

		// The throw is swallowed rather than aborting module evaluation, so the
		// gate's own registration below it still runs.
		expect( () => require( './gate' ) ).not.toThrow();
		expect( console.warn ).toHaveBeenCalled();
	} );
} );
