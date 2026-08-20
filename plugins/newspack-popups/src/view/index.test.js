/**
 * The view entry runs preview-link propagation and every reader-facing handler in
 * one domReady callback, so a throw in the preview code would take prompt display
 * and prompt analytics with it. That is what the try/catch there prevents, and
 * this pins it: without the guard, reverting it leaves the suite green.
 *
 * index.js is imported for its side effects, so the mocks have to be in place
 * before the import is evaluated.
 */

const mockPropagate = jest.fn();
const mockHandleSegmentation = jest.fn();
const mockHandleAnalytics = jest.fn();
const mockHandleContextual = jest.fn();
const mockGetPrompts = jest.fn( () => [] );
const mockLogPageview = jest.fn();

jest.mock( './preview-links', () => ( {
	propagatePreviewParams: ( ...args ) => mockPropagate( ...args ),
} ) );
jest.mock( './segmentation', () => ( {
	handleSegmentation: ( ...args ) => mockHandleSegmentation( ...args ),
} ) );
jest.mock( './analytics/ga4', () => ( {
	handleAnalytics: ( ...args ) => mockHandleAnalytics( ...args ),
} ) );
jest.mock( './analytics/contextual-prompt', () => ( {
	handleContextualPromptAnalytics: ( ...args ) => mockHandleContextual( ...args ),
} ) );
jest.mock( './utils', () => ( {
	// domReady runs the callback immediately, which is the path a footer script takes
	// once the document is already parsed.
	domReady: cb => cb(),
	getPrompts: ( ...args ) => mockGetPrompts( ...args ),
	logPageview: mockLogPageview,
} ) );
jest.mock( './style.scss', () => ( {} ), { virtual: true } );
jest.mock( './patterns.scss', () => ( {} ), { virtual: true } );
jest.mock( './merge-tags', () => ( {} ), { virtual: true } );

let warnSpy;

describe( 'view entry: preview propagation cannot break reader-facing prompt logic', () => {
	beforeEach( () => {
		jest.resetModules();
		[ mockPropagate, mockHandleSegmentation, mockHandleAnalytics, mockHandleContextual ].forEach( m => m.mockReset() );
		global.newspack_popups_view = { has_disabled_prompts: false };
		window.newspackRAS = [];
		// Held in a variable rather than reached for as `console.warn`, which the
		// no-console rule rejects.
		warnSpy = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		warnSpy.mockRestore();
	} );

	it( 'runs propagation and the prompt handlers on load', () => {
		require( './index' );

		expect( mockPropagate ).toHaveBeenCalled();
		expect( mockHandleSegmentation ).toHaveBeenCalled();
		expect( mockHandleAnalytics ).toHaveBeenCalled();
	} );

	it( 'still runs the prompt handlers when propagation throws', () => {
		mockPropagate.mockImplementation( () => {
			throw new Error( 'isolation' );
		} );

		expect( () => require( './index' ) ).not.toThrow();
		expect( warnSpy ).toHaveBeenCalled();
		expect( mockHandleSegmentation ).toHaveBeenCalled();
		expect( mockHandleAnalytics ).toHaveBeenCalled();
		expect( mockHandleContextual ).toHaveBeenCalled();
	} );

	it( 'pushes the pageview before propagation runs, so a reader never loses it', () => {
		mockPropagate.mockImplementation( () => {
			throw new Error( 'isolation' );
		} );

		require( './index' );

		expect( window.newspackRAS ).toContain( mockLogPageview );
	} );
} );
