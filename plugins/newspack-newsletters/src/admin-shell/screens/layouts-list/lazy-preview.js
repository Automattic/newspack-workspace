/**
 * Defer mounting an expensive child until its placeholder scrolls into
 * view, then keep it mounted (re-instantiating iframes is more expensive
 * than the steady-state memory).
 */

import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * @param {Object}   props
 * @param {Object}   [props.placeholderStyle] Inline style for the placeholder; reserve enough height to avoid reflow on mount.
 * @param {string}   [props.rootMargin]       IntersectionObserver `rootMargin` — pre-mounts the next row of cards.
 * @param {Function} props.children           Render-prop returning the expensive subtree.
 * @return {Object} React element.
 */
export default function LazyPreview( { placeholderStyle, rootMargin = '200px', children } ) {
	const ref = useRef( null );
	const [ isVisible, setIsVisible ] = useState( false );

	useEffect( () => {
		if ( isVisible ) {
			return undefined;
		}
		// SSR / no-IO fallback: mount immediately. Better than rendering
		// nothing in the rare environment without IntersectionObserver.
		if ( typeof window === 'undefined' || typeof window.IntersectionObserver === 'undefined' ) {
			setIsVisible( true );
			return undefined;
		}
		const node = ref.current;
		if ( ! node ) {
			return undefined;
		}
		const observer = new window.IntersectionObserver(
			entries => {
				const entry = entries[ 0 ];
				if ( entry?.isIntersecting ) {
					setIsVisible( true );
					observer.disconnect();
				}
			},
			{ rootMargin }
		);
		observer.observe( node );
		return () => observer.disconnect();
	}, [ isVisible, rootMargin ] );

	return (
		<div ref={ ref } style={ ! isVisible ? placeholderStyle : undefined }>
			{ isVisible ? children() : null }
		</div>
	);
}
