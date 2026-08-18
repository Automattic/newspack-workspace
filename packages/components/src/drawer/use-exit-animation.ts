/**
 * WordPress dependencies.
 */
import { useReducedMotion } from '@wordpress/compose';
import { useEffect, useState } from '@wordpress/element';

// Covers an animationend that never arrives.
const EXIT_FALLBACK_MS = 400;

function useExitAnimation( isOpen: boolean, node: HTMLElement | null ) {
	const [ isExiting, setIsExiting ] = useState( false );
	const [ wasOpen, setWasOpen ] = useState( isOpen );

	// The stylesheet zeroes the animation here, so animationend never fires and the
	// fallback would hold a visible panel for 400ms.
	const prefersReducedMotion = useReducedMotion();

	// During render: a commit later the panel would leave the DOM and remount.
	if ( wasOpen !== isOpen ) {
		setWasOpen( isOpen );
		setIsExiting( ! isOpen && ! prefersReducedMotion );
	}

	useEffect( () => {
		if ( ! isExiting ) {
			return;
		}
		// Keyed on the name: animationend bubbles from anything else animating.
		const finish = ( event?: AnimationEvent ) => {
			if ( event?.animationName && 'newspack-drawer-slide-out' !== event.animationName ) {
				return;
			}
			setIsExiting( false );
		};
		const timer = setTimeout( finish, EXIT_FALLBACK_MS );
		node?.addEventListener( 'animationend', finish );
		return () => {
			clearTimeout( timer );
			node?.removeEventListener( 'animationend', finish );
		};
	}, [ isExiting, node ] );

	return { isRendered: isOpen || isExiting, isExiting };
}

export default useExitAnimation;
