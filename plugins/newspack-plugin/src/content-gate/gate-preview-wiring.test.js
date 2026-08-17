/**
 * The gate script wires preview-param propagation and gate rendering as two
 * separate domReady registrations. This pins the property that comment claims:
 * a throw in propagation must not stop the gate from initialising.
 *
 * gate.js is imported for its side effects, so the mocks below have to be in
 * place before the import is evaluated.
 */

const mockPropagate = jest.fn();
let warnSpy;

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
		window.newspackRAS = [];
		// `interactive` makes gate.js's domReady() invoke synchronously, which is
		// the path a footer/async script actually takes.
		Object.defineProperty( document, 'readyState', { value: 'interactive', configurable: true } );
		document.body.innerHTML = '<div class="newspack-content-gate__gate"></div>';
		// Held in a variable rather than reached for as `console.warn`, which the
		// no-console rule rejects.
		warnSpy = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		warnSpy.mockRestore();
	} );

	it( 'runs propagation on load', () => {
		require( './gate' );

		expect( mockPropagate ).toHaveBeenCalled();
	} );

	it( 'still initialises the gate when propagation throws', () => {
		mockPropagate.mockImplementation( () => {
			throw new Error( 'isolation' );
		} );

		expect( () => require( './gate' ) ).not.toThrow();
		expect( warnSpy ).toHaveBeenCalled();
		// Assert the gate actually initialised rather than inferring it from module
		// evaluation completing. The assertion above already catches the try/catch
		// being deleted outright, since that lets the throw escape require(). What
		// only this line catches is the gate's own registration being folded *inside*
		// the propagation try block: then require() still succeeds, the warning still
		// fires, and nothing but an empty newspackRAS reveals that the gate never
		// initialised.
		expect( window.newspackRAS.length ).toBeGreaterThan( 0 );
	} );
} );
