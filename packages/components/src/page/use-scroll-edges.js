/**
 * WordPress dependencies.
 */
import { useEffect, useLayoutEffect, useRef, useState } from '@wordpress/element';

const SCROLL_EPSILON = 1;

const isRtl = element => 'rtl' === window.getComputedStyle( element ).direction;

const scrollToEnd = element => {
	element.scrollLeft = isRtl( element ) ? -element.scrollWidth : element.scrollWidth;
};

/**
 * Tracks which ends of a horizontally scrolling element have content hidden,
 * and keeps it scrolled to its end until the user scrolls it, starting again
 * whenever `resetKey` changes.
 *
 * @param {HTMLElement|null} element  Scroll container.
 * @param {string}           resetKey Changes when the content should be scrolled back to its end.
 * @return {{start: boolean, end: boolean, overflowing: boolean}} Overflow state.
 */
const useScrollEdges = ( element, resetKey ) => {
	const [ edges, setEdges ] = useState( { start: false, end: false, overflowing: false } );
	const isPinnedToEnd = useRef( true );

	useLayoutEffect( () => {
		if ( ! element ) {
			return;
		}
		isPinnedToEnd.current = true;
		scrollToEnd( element );
	}, [ element, resetKey ] );

	useEffect( () => {
		if ( ! element ) {
			return undefined;
		}

		const measure = ( { fromScroll = false } = {} ) => {
			// Siblings such as header actions can mount after the trail and narrow it.
			if ( ! fromScroll && isPinnedToEnd.current ) {
				scrollToEnd( element );
			}
			const { scrollWidth, clientWidth, scrollLeft } = element;
			const maxScroll = Math.max( scrollWidth - clientWidth, 0 );
			// RTL scrollLeft runs from 0 at the start towards negative values.
			const fromStart = isRtl( element ) ? -scrollLeft : scrollLeft;
			const next = {
				start: fromStart > SCROLL_EPSILON,
				end: fromStart < maxScroll - SCROLL_EPSILON,
				overflowing: scrollWidth > clientWidth + SCROLL_EPSILON,
			};
			if ( fromScroll ) {
				isPinnedToEnd.current = ! next.end;
			}
			setEdges( prev => ( prev.start === next.start && prev.end === next.end && prev.overflowing === next.overflowing ? prev : next ) );
		};

		let frame = null;
		const onScroll = () => {
			if ( null === frame ) {
				frame = window.requestAnimationFrame( () => {
					frame = null;
					measure( { fromScroll: true } );
				} );
			}
		};

		const resizeObserver = window.ResizeObserver ? new window.ResizeObserver( () => measure() ) : null;
		resizeObserver?.observe( element );
		if ( element.firstElementChild ) {
			resizeObserver?.observe( element.firstElementChild );
		}
		element.addEventListener( 'scroll', onScroll, { passive: true } );
		measure();

		return () => {
			element.removeEventListener( 'scroll', onScroll );
			resizeObserver?.disconnect();
			if ( null !== frame ) {
				window.cancelAnimationFrame( frame );
			}
		};
	}, [ element, resetKey ] );

	return edges;
};

export default useScrollEdges;
