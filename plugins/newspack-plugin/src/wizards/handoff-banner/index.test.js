/**
 * External dependencies.
 */
import { render, fireEvent, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import { HandoffBanner } from './index';

const VISIBLE_CLASS = 'newspack-handoff-banner-visible';
const HEIGHT_PROPERTY = '--newspack-handoff-banner-height';

/**
 * Give every element a stable measurable height, the way a browser would.
 *
 * @param {number} height Height in pixels reported by `offsetHeight`.
 * @return {Function} Restores the original descriptor.
 */
const mockOffsetHeight = height => {
	const original = Object.getOwnPropertyDescriptor( window.HTMLElement.prototype, 'offsetHeight' );
	Object.defineProperty( window.HTMLElement.prototype, 'offsetHeight', {
		configurable: true,
		get: () => height,
	} );
	return () => {
		if ( original ) {
			Object.defineProperty( window.HTMLElement.prototype, 'offsetHeight', original );
		} else {
			delete window.HTMLElement.prototype.offsetHeight;
		}
	};
};

describe( 'HandoffBanner', () => {
	let restoreOffsetHeight;

	afterEach( () => {
		if ( restoreOffsetHeight ) {
			restoreOffsetHeight();
			restoreOffsetHeight = null;
		}
		document.documentElement.classList.remove( VISIBLE_CLASS );
		document.documentElement.style.removeProperty( HEIGHT_PROPERTY );
	} );

	it( 'publishes the measured banner height on the document element', () => {
		restoreOffsetHeight = mockOffsetHeight( 56 );

		render( <HandoffBanner /> );

		expect( document.documentElement.classList.contains( VISIBLE_CLASS ) ).toBe( true );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '56px' );
	} );

	it( 're-measures when the banner is resized, e.g. when the text wraps', () => {
		let resizeCallback;
		const originalResizeObserver = window.ResizeObserver;
		window.ResizeObserver = class {
			constructor( callback ) {
				resizeCallback = callback;
			}
			observe() {}
			unobserve() {}
			disconnect() {}
		};
		restoreOffsetHeight = mockOffsetHeight( 56 );

		render( <HandoffBanner /> );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '56px' );

		restoreOffsetHeight();
		restoreOffsetHeight = mockOffsetHeight( 92 );
		resizeCallback();

		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '92px' );

		window.ResizeObserver = originalResizeObserver;
	} );

	it( 'still publishes a height without ResizeObserver support', () => {
		const originalResizeObserver = window.ResizeObserver;
		delete window.ResizeObserver;
		restoreOffsetHeight = mockOffsetHeight( 56 );

		render( <HandoffBanner /> );

		expect( document.documentElement.classList.contains( VISIBLE_CLASS ) ).toBe( true );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '56px' );

		window.ResizeObserver = originalResizeObserver;
	} );

	it( 'clears the class and the height when dismissed', () => {
		restoreOffsetHeight = mockOffsetHeight( 56 );

		render( <HandoffBanner /> );
		fireEvent.click( screen.getByText( 'Dismiss' ) );

		expect( screen.queryByText( 'Back to Newspack' ) ).toBeNull();
		expect( document.documentElement.classList.contains( VISIBLE_CLASS ) ).toBe( false );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '' );
	} );

	it( 'clears the class and the height on unmount', () => {
		restoreOffsetHeight = mockOffsetHeight( 56 );

		const { unmount } = render( <HandoffBanner /> );
		unmount();

		expect( document.documentElement.classList.contains( VISIBLE_CLASS ) ).toBe( false );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '' );
	} );
} );
