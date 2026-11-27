/**
 * Edge fades for scrolling elements: horizontally scrolling rows, such as
 * `newspack-ui__tabs--scroll`, and vertically scrolling modals.
 * A vanilla port of the newspack-components `useScrollEdges` hook.
 */

import { domReady } from './utils';

const SCROLL_EPSILON = 1;

/** A scrolling tab list, with its cleanup function stashed on it as an expando. */
interface ScrollEdgesList extends HTMLElement {
	_newspackScrollEdges?: () => void;
}

/**
 * Whether an element lays out right to left.
 *
 * @param element The element.
 * @return Whether it's RTL.
 */
export const isRtl = ( element: HTMLElement ) => 'rtl' === window.getComputedStyle( element ).direction;

/**
 * Which ends of a scrolling element have content hidden.
 *
 * @param element Scroll container.
 * @param axis    The scrolling axis.
 * @return Overflow state.
 */
export const getScrollEdges = ( element: HTMLElement, axis: 'x' | 'y' = 'x' ) => {
	const size = 'y' === axis ? element.scrollHeight : element.scrollWidth;
	const visible = 'y' === axis ? element.clientHeight : element.clientWidth;
	const maxScroll = Math.max( size - visible, 0 );
	let fromStart = element.scrollTop;
	if ( 'x' === axis ) {
		// RTL scrollLeft runs from 0 at the start towards negative values.
		fromStart = isRtl( element ) ? -element.scrollLeft : element.scrollLeft;
	}
	return {
		start: fromStart > SCROLL_EPSILON,
		end: fromStart < maxScroll - SCROLL_EPSILON,
		overflowing: size > visible + SCROLL_EPSILON,
	};
};

/**
 * Reflect the overflow state in the `is-overflowing-start` and `is-overflowing-end` classes.
 *
 * @param element Scroll container.
 * @param axis    The scrolling axis.
 * @return Overflow state.
 */
export const updateScrollEdges = ( element: HTMLElement, axis: 'x' | 'y' = 'x' ) => {
	const edges = getScrollEdges( element, axis );
	element.classList.toggle( 'is-overflowing-start', edges.start );
	element.classList.toggle( 'is-overflowing-end', edges.end );
	return edges;
};

/**
 * Scroll an item to the center of its row, clamped to the row's ends. The first
 * item keeps the row at its start.
 *
 * @param element Scroll container.
 * @param item    The item to bring into view.
 */
export const centerItem = ( element: HTMLElement, item: Element | null ) => {
	if ( ! item || ! element.contains( item ) ) {
		return;
	}
	const maxScroll = Math.max( element.scrollWidth - element.clientWidth, 0 );
	const rtl = isRtl( element );
	let fromStart = 0;
	if ( item !== element.firstElementChild ) {
		const rowRect = element.getBoundingClientRect();
		const itemRect = item.getBoundingClientRect();
		const target = element.scrollLeft + itemRect.left + itemRect.width / 2 - ( rowRect.left + element.clientWidth / 2 );
		fromStart = Math.min( maxScroll, Math.max( 0, rtl ? -target : target ) );
	}
	element.scrollLeft = rtl ? -fromStart : fromStart;
};

/**
 * Keep an element's edge classes in sync with its scroll position and size.
 *
 * @param element Scroll container.
 * @param axis    The scrolling axis.
 * @return Stops observing.
 */
export const observeScrollEdges = ( element: HTMLElement, axis: 'x' | 'y' = 'x' ): ( () => void ) => {
	let frame: number | null = null;
	const onScroll = () => {
		if ( null === frame ) {
			frame = window.requestAnimationFrame( () => {
				frame = null;
				updateScrollEdges( element, axis );
			} );
		}
	};
	const resizeObserver = window.ResizeObserver ? new window.ResizeObserver( () => updateScrollEdges( element, axis ) ) : null;
	resizeObserver?.observe( element );
	// Items change size without resizing the scroll container, such as when a web font loads.
	[ ...element.children ].forEach( child => resizeObserver?.observe( child ) );
	element.addEventListener( 'scroll', onScroll, { passive: true } );
	updateScrollEdges( element, axis );

	return () => {
		element.removeEventListener( 'scroll', onScroll );
		resizeObserver?.disconnect();
		if ( null !== frame ) {
			window.cancelAnimationFrame( frame );
		}
	};
};

/**
 * Set up every scrolling tab row on the page: center its selected item, then track its edges.
 *
 * @param root Where to look for rows.
 */
export const initScrollingTabs = ( root: Document | HTMLElement = document ) => {
	root.querySelectorAll< ScrollEdgesList >( '.newspack-ui__tabs--scroll .newspack-ui__tabs__list' ).forEach( list => {
		if ( list._newspackScrollEdges ) {
			return;
		}
		const selected = list.querySelector( ':scope > [aria-current="page"], :scope > [aria-selected="true"], :scope > .selected' );
		centerItem( list, selected );
		const initialScroll = list.scrollLeft;
		list._newspackScrollEdges = observeScrollEdges( list );
		// A web font that loads late changes the item widths; center again unless the reader has scrolled.
		document.fonts?.ready.then( () => {
			if ( list.scrollLeft === initialScroll ) {
				centerItem( list, selected );
				updateScrollEdges( list );
			}
		} );
	} );
};

domReady( () => initScrollingTabs() );
