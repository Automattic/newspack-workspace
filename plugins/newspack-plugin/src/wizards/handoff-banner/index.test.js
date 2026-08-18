/**
 * External dependencies.
 */
import { render, fireEvent, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import { HandoffBanner, remeasureBannerOffset } from './index';

const VISIBLE_CLASS = 'newspack-handoff-banner-visible';
const HEIGHT_PROPERTY = '--newspack-handoff-banner-height';

/**
 * Place every element at a known spot in the viewport, the way a browser would.
 *
 * @param {Object} rect        Geometry to report.
 * @param {number} rect.top    Distance from the top of the viewport.
 * @param {number} rect.height Height in pixels.
 * @return {Function} Restores the original method.
 */
const mockRect = ( { top, height } ) => {
	const original = window.Element.prototype.getBoundingClientRect;
	window.Element.prototype.getBoundingClientRect = function () {
		return { top, bottom: top + height, height, left: 0, right: 0, width: 0, x: 0, y: top };
	};
	return () => {
		window.Element.prototype.getBoundingClientRect = original;
	};
};

/**
 * Scroll the document, which jsdom otherwise pins at 0.
 *
 * @param {number} value Pixels scrolled from the top of the document.
 * @return {Function} Restores the original descriptor.
 */
const mockScrollY = value => {
	const original = Object.getOwnPropertyDescriptor( window, 'scrollY' );
	Object.defineProperty( window, 'scrollY', { configurable: true, value } );
	return () => {
		if ( original ) {
			Object.defineProperty( window, 'scrollY', original );
		} else {
			delete window.scrollY;
		}
	};
};

describe( 'HandoffBanner', () => {
	let restoreRect;
	let restoreScrollY;

	afterEach( () => {
		if ( restoreRect ) {
			restoreRect();
			restoreRect = null;
		}
		if ( restoreScrollY ) {
			restoreScrollY();
			restoreScrollY = null;
		}
		document.documentElement.classList.remove( VISIBLE_CLASS );
		document.documentElement.style.removeProperty( HEIGHT_PROPERTY );
	} );

	it( "publishes the banner's bottom edge on the document element", () => {
		restoreRect = mockRect( { top: 0, height: 56 } );

		render( <HandoffBanner /> );

		expect( document.documentElement.classList.contains( VISIBLE_CLASS ) ).toBe( true );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '56px' );
	} );

	it( 'covers whatever sits above the banner, e.g. the admin bar padding', () => {
		restoreRect = mockRect( { top: 32, height: 56 } );

		render( <HandoffBanner /> );

		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '88px' );
	} );

	it( 'stays at rest when the document behind a fixed editor is scrolled', () => {
		// Scrolled 294px, the banner's viewport bottom has gone negative; the
		// offset a fixed editor needs is still the same 88px it is at the top.
		restoreScrollY = mockScrollY( 294 );
		restoreRect = mockRect( { top: -262, height: 56 } );

		render( <HandoffBanner /> );

		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '88px' );
	} );

	it( 'rounds a fractional bottom up so no sliver is left uncovered', () => {
		restoreRect = mockRect( { top: 32, height: 56.5 } );

		render( <HandoffBanner /> );

		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '89px' );
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
		restoreRect = mockRect( { top: 32, height: 56 } );

		render( <HandoffBanner /> );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '88px' );

		restoreRect();
		restoreRect = mockRect( { top: 32, height: 92 } );
		resizeCallback();

		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '124px' );

		window.ResizeObserver = originalResizeObserver;
	} );

	it( 're-measures when the banner is moved after mount, e.g. the WooCommerce header offset', () => {
		restoreRect = mockRect( { top: 32, height: 56 } );

		render( <HandoffBanner /> );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '88px' );

		// The bootstrap's WooCommerce offset can land up to five seconds after
		// mount: the margin moves the banner without resizing it, so nothing but
		// this call refreshes the published value.
		restoreRect();
		restoreRect = mockRect( { top: 92, height: 56 } );
		remeasureBannerOffset();

		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '148px' );
	} );

	it( 'ignores a re-measure once the banner is gone', () => {
		restoreRect = mockRect( { top: 32, height: 56 } );

		const { unmount } = render( <HandoffBanner /> );
		unmount();
		remeasureBannerOffset();

		expect( document.documentElement.classList.contains( VISIBLE_CLASS ) ).toBe( false );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '' );
	} );

	it( 'still publishes a value without ResizeObserver support', () => {
		const originalResizeObserver = window.ResizeObserver;
		delete window.ResizeObserver;
		restoreRect = mockRect( { top: 32, height: 56 } );

		render( <HandoffBanner /> );

		expect( document.documentElement.classList.contains( VISIBLE_CLASS ) ).toBe( true );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '88px' );

		window.ResizeObserver = originalResizeObserver;
	} );

	it( 'clears the class and the value when dismissed', () => {
		restoreRect = mockRect( { top: 32, height: 56 } );

		render( <HandoffBanner /> );
		fireEvent.click( screen.getByText( 'Dismiss' ) );

		expect( screen.queryByText( 'Back to Newspack' ) ).toBeNull();
		expect( document.documentElement.classList.contains( VISIBLE_CLASS ) ).toBe( false );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '' );
	} );

	it( 'clears the class and the value on unmount', () => {
		restoreRect = mockRect( { top: 32, height: 56 } );

		const { unmount } = render( <HandoffBanner /> );
		unmount();

		expect( document.documentElement.classList.contains( VISIBLE_CLASS ) ).toBe( false );
		expect( document.documentElement.style.getPropertyValue( HEIGHT_PROPERTY ) ).toBe( '' );
	} );
} );
