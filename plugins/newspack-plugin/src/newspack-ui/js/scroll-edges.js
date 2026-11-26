/**
 * Edge fades for scrolling elements: horizontally scrolling rows, such as
 * `newspack-ui__tabs--scroll`, and vertically scrolling modals.
 * A vanilla port of the newspack-components `useScrollEdges` hook.
 */

import { domReady } from './utils';

const SCROLL_EPSILON = 1;

/**
 * Whether an element lays out right to left.
 *
 * @param {HTMLElement} element The element.
 * @return {boolean} Whether it's RTL.
 */
export const isRtl = element => 'rtl' === window.getComputedStyle( element ).direction;

/**
 * Which ends of a scrolling element have content hidden.
 *
 * @param {HTMLElement} element Scroll container.
 * @param {'x'|'y'}     axis    The scrolling axis.
 * @return {{start: boolean, end: boolean, overflowing: boolean}} Overflow state.
 */
export const getScrollEdges = ( element, axis = 'x' ) => {
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
 * @param {HTMLElement} element Scroll container.
 * @param {'x'|'y'}     axis    The scrolling axis.
 * @return {{start: boolean, end: boolean, overflowing: boolean}} Overflow state.
 */
export const updateScrollEdges = ( element, axis = 'x' ) => {
	const edges = getScrollEdges( element, axis );
	element.classList.toggle( 'is-overflowing-start', edges.start );
	element.classList.toggle( 'is-overflowing-end', edges.end );
	return edges;
};

/**
 * Scroll an item to the center of its row, clamped to the row's ends. The first
 * item keeps the row at its start.
 *
 * @param {HTMLElement} element Scroll container.
 * @param {HTMLElement} item    The item to bring into view.
 */
export const centerItem = ( element, item ) => {
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
 * @param {HTMLElement} element Scroll container.
 * @param {'x'|'y'}     axis    The scrolling axis.
 * @return {Function} Stops observing.
 */
export const observeScrollEdges = ( element, axis = 'x' ) => {
	let frame = null;
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
 * @param {Document|HTMLElement} root Where to look for rows.
 */
export const initScrollingTabs = ( root = document ) => {
	root.querySelectorAll( '.newspack-ui__tabs--scroll .newspack-ui__tabs__list' ).forEach( list => {
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
