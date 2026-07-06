/**
 * External dependencies
 */
import { render, waitFor, act } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import EmailPreview from './email-preview';

// Mock @wordpress/api-fetch — jest.mock is hoisted above imports.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

// Store observer instances so tests can trigger intersection.
let observerInstances = [];

// Save the originals before any test overwrites them. In jsdom both
// are `undefined` by default; afterAll restores or deletes per case so
// the globals don't leak into suites Jest runs next in the same worker
// (which would silently hand them my stubs instead of the jsdom default).
const originalIntersectionObserver = global.IntersectionObserver;
const originalResizeObserver = global.ResizeObserver;

// Default IntersectionObserver mock: triggers immediately.
function createObserverMock( triggerImmediately = true ) {
	observerInstances = [];
	global.IntersectionObserver = class {
		constructor( callback ) {
			this.callback = callback;
			observerInstances.push( this );
		}
		observe() {
			if ( triggerImmediately ) {
				this.callback( [ { isIntersecting: true } ] );
			}
		}
		disconnect() {}
	};
}

// ResizeObserver mock: immediately reports a 300px-wide container.
function createResizeObserverMock() {
	global.ResizeObserver = class {
		constructor( callback ) {
			this.callback = callback;
		}
		observe() {
			this.callback( [ { contentRect: { width: 300 } } ] );
		}
		disconnect() {}
	};
}

/**
 * Helper: simulate iframe onLoad and stub contentDocument so
 * handleIframeLoad resolves immediately (no pending assets).
 */
function simulateIframeLoad( iframe ) {
	Object.defineProperty( iframe, 'contentDocument', {
		value: {
			querySelectorAll: () => [],
			body: { scrollHeight: 900 },
		},
		configurable: true,
	} );
	iframe.dispatchEvent( new Event( 'load' ) );
}

describe( 'EmailPreview', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		createObserverMock( true );
		createResizeObserverMock();
	} );

	afterAll( () => {
		// Restore (or delete) the observer globals so they don't leak
		// into whatever suite Jest runs next in the same worker.
		if ( undefined === originalIntersectionObserver ) {
			delete global.IntersectionObserver;
		} else {
			global.IntersectionObserver = originalIntersectionObserver;
		}
		if ( undefined === originalResizeObserver ) {
			delete global.ResizeObserver;
		} else {
			global.ResizeObserver = originalResizeObserver;
		}
	} );

	it( 'renders loading state while fetching', async () => {
		// Keep the promise pending so we can observe the loading state.
		apiFetch.mockReturnValue( new Promise( () => {} ) );

		render( <EmailPreview postId={ 123 } /> );

		expect( document.querySelector( '.newspack-email-preview__placeholder' ) ).toBeInTheDocument();
	} );

	it( 'renders iframe on successful fetch and gains is-ready after load', async () => {
		apiFetch.mockResolvedValue( {
			html: '<html><body><p>Hello Sample Reader</p></body></html>',
			id: 123,
		} );

		render( <EmailPreview postId={ 123 } /> );

		await waitFor( () => {
			const iframe = document.querySelector( '.newspack-email-preview__iframe' );
			expect( iframe ).toBeTruthy();
			expect( iframe.getAttribute( 'srcdoc' ) ).toContain( 'Sample Reader' );
		} );

		// Simulate iframe load (also fires automatically in jsdom, but explicit
		// call ensures the contentDocument stub is in place for assertion).
		const iframe = document.querySelector( '.newspack-email-preview__iframe' );
		simulateIframeLoad( iframe );

		const container = document.querySelector( '.newspack-email-preview' );
		await waitFor( () => {
			expect( container.classList.contains( 'is-ready' ) ).toBe( true );
		} );
	} );

	it( 'renders fallback placeholder on fetch error', async () => {
		apiFetch.mockRejectedValue( new Error( 'Server error' ) );

		render( <EmailPreview postId={ 456 } /> );

		await waitFor( () => {
			const placeholder = document.querySelector( '.newspack-email-preview__placeholder' );
			expect( placeholder ).toBeTruthy();
			// No iframe should be present.
			expect( document.querySelector( '.newspack-email-preview__iframe' ) ).toBeNull();
		} );
	} );

	it( 'does not fetch until element is visible', () => {
		// Observer that does NOT trigger intersection.
		createObserverMock( false );

		render( <EmailPreview postId={ 789 } /> );

		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'fetches the correct endpoint path', async () => {
		apiFetch.mockResolvedValue( { html: '<p>Test</p>', id: 42 } );

		render( <EmailPreview postId={ 42 } /> );

		await waitFor( () => {
			expect( apiFetch ).toHaveBeenCalledWith( {
				path: '/newspack/v1/wizard/newspack-settings/emails/42/preview',
			} );
		} );
	} );

	it( 'resets state when postId changes', async () => {
		apiFetch.mockResolvedValue( {
			html: '<html><body><p>First email</p></body></html>',
			id: 1,
		} );

		const { rerender } = render( <EmailPreview postId={ 1 } /> );

		// Wait for first render to complete.
		await waitFor( () => {
			const iframe = document.querySelector( '.newspack-email-preview__iframe' );
			expect( iframe ).toBeTruthy();
		} );

		// Simulate onLoad for first email.
		simulateIframeLoad( document.querySelector( '.newspack-email-preview__iframe' ) );
		await waitFor( () => {
			expect( document.querySelector( '.newspack-email-preview' ).classList.contains( 'is-ready' ) ).toBe( true );
		} );

		// Change postId — should reset and re-fetch.
		apiFetch.mockResolvedValue( {
			html: '<html><body><p>Second email</p></body></html>',
			id: 2,
		} );

		rerender( <EmailPreview postId={ 2 } /> );

		// New iframe should appear with updated content.
		await waitFor( () => {
			const iframe = document.querySelector( '.newspack-email-preview__iframe' );
			expect( iframe ).toBeTruthy();
			expect( iframe.getAttribute( 'srcdoc' ) ).toContain( 'Second email' );
		} );
	} );

	it( 'cancelled fetch does not update state when postId changes mid-flight', async () => {
		// First fetch: controlled promise that resolves AFTER the second.
		let resolveFirst;
		const firstPromise = new Promise( resolve => {
			resolveFirst = resolve;
		} );
		apiFetch.mockReturnValueOnce( firstPromise );

		const { rerender } = render( <EmailPreview postId={ 1 } /> );

		// Change postId before first fetch resolves.
		apiFetch.mockResolvedValueOnce( {
			html: '<html><body><p>Second email</p></body></html>',
			id: 2,
		} );

		rerender( <EmailPreview postId={ 2 } /> );

		// Wait for second fetch to render.
		await waitFor( () => {
			const iframe = document.querySelector( '.newspack-email-preview__iframe' );
			expect( iframe ).toBeTruthy();
			expect( iframe.getAttribute( 'srcdoc' ) ).toContain( 'Second email' );
		} );

		// Now resolve the first (stale) fetch — it should NOT overwrite the iframe.
		await act( async () => {
			resolveFirst( {
				html: '<html><body><p>First email (stale)</p></body></html>',
				id: 1,
			} );
		} );

		// The iframe should still show the second email, not the stale first.
		const iframe = document.querySelector( '.newspack-email-preview__iframe' );
		expect( iframe.getAttribute( 'srcdoc' ) ).toContain( 'Second email' );
		expect( iframe.getAttribute( 'srcdoc' ) ).not.toContain( 'First email' );
	} );

	// Slice 2b.2 widened the prop type from `number` to `number | string`
	// to accept `wc:{id}` identifiers (WC classic-template emails). The
	// component is API-shape-agnostic — these tests verify that string
	// postIds flow through the path interpolation and into the iframe
	// just like numeric ones.

	it( 'fetches the correct endpoint path for a wc: string postId', async () => {
		apiFetch.mockResolvedValue( {
			html: '<p>WC Preview</p>',
			id: 'wc:customer_payment_retry',
		} );

		render( <EmailPreview postId="wc:customer_payment_retry" /> );

		await waitFor( () => {
			expect( apiFetch ).toHaveBeenCalledWith( {
				path: '/newspack/v1/wizard/newspack-settings/emails/wc:customer_payment_retry/preview',
			} );
		} );
	} );

	it( 'renders iframe for a wc: string postId', async () => {
		apiFetch.mockResolvedValue( {
			html: '<html><body><p>Classic WC email</p></body></html>',
			id: 'wc:expired_subscription',
		} );

		render( <EmailPreview postId="wc:expired_subscription" /> );

		await waitFor( () => {
			const iframe = document.querySelector( '.newspack-email-preview__iframe' );
			expect( iframe ).toBeTruthy();
			expect( iframe.getAttribute( 'srcdoc' ) ).toContain( 'Classic WC email' );
		} );
	} );

	// Note: The safety timeout (8s fallback for slow assets) and the iframe
	// onError handler are not tested here because jsdom automatically fires
	// the iframe load event when srcDoc is set, which prevents us from
	// simulating pending-asset scenarios. These defensive measures work in
	// real browsers but require an integration/e2e test environment.
} );
