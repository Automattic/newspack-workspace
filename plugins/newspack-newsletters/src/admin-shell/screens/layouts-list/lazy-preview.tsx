/**
 * Defer mounting an expensive child until its placeholder scrolls into
 * view, then keep it mounted (re-instantiating iframes is more expensive
 * than the steady-state memory).
 */

import { useEffect, useRef, useState } from '@wordpress/element';

import type { CSSProperties, ReactElement, ReactNode } from 'react';

interface LazyPreviewProps {
	/** Inline style for the placeholder; reserve enough height to avoid reflow on mount. */
	placeholderStyle?: CSSProperties;
	/** IntersectionObserver `rootMargin` — pre-mounts the next row of cards. */
	rootMargin?: string;
	/** Render-prop returning the expensive subtree. */
	children: () => ReactNode;
}

export default function LazyPreview( { placeholderStyle, rootMargin = '200px', children }: LazyPreviewProps ): ReactElement {
	const ref = useRef< HTMLDivElement >( null );
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
