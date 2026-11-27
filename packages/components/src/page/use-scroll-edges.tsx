/**
 * WordPress dependencies.
 */
import { useEffect, useLayoutEffect, useRef, useState } from '@wordpress/element';

const SCROLL_EPSILON = 1;

const isRtl = ( element: HTMLElement ): boolean => 'rtl' === window.getComputedStyle( element ).direction;

const scrollToEnd = ( element: HTMLElement ): void => {
	element.scrollLeft = isRtl( element ) ? -element.scrollWidth : element.scrollWidth;
};

interface ScrollEdges {
	start: boolean;
	end: boolean;
	overflowing: boolean;
}

/**
 * Tracks which ends of a horizontally scrolling element have content hidden,
 * and keeps it scrolled to its end until the user scrolls it, starting again
 * whenever `resetKey` changes.
 *
 * @param element  Scroll container.
 * @param resetKey Changes when the content should be scrolled back to its end.
 * @return Overflow state.
 */
const useScrollEdges = ( element: HTMLElement | null, resetKey: string ): ScrollEdges => {
	const [ edges, setEdges ] = useState< ScrollEdges >( { start: false, end: false, overflowing: false } );
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

		const measure = ( { fromScroll = false }: { fromScroll?: boolean } = {} ): void => {
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

		let frame: number | null = null;
		const onScroll = (): void => {
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
