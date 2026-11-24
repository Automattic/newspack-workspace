'use client';

import { createContext, useContext, useEffect, useRef } from 'react';
import type { ReactNode, RefObject } from 'react';
import { decodeDeckHash, encodeDeckHash, ACTIVATABLE_TARGETS, isIgnoredTarget, planDeckStep } from './deck-navigation';

const DeckContext = createContext< RefObject< HTMLDivElement | null > | null >( null );

export function useDeckScroller() {
	return useContext( DeckContext );
}

export function getDeckSlides( scroller: HTMLElement | null ) {
	return scroller ? Array.from( scroller.querySelectorAll< HTMLElement >( '[data-deck-slide]' ) ) : [];
}

function syncHash( slide: HTMLElement ) {
	window.history.replaceState( window.history.state, '', encodeDeckHash( slide.id ) );
}

export function goToDeckSlide( slides: HTMLElement[], index: number, focus = false ) {
	const slide = slides[ Math.max( 0, Math.min( slides.length - 1, index ) ) ];
	if ( ! slide ) {
		return;
	}
	slide.scrollIntoView( { block: 'start' } );
	syncHash( slide );
	if ( focus ) {
		slide.focus( { preventScroll: true } );
	}
}

/**
 * Full-viewport scroll container that snaps between `DeckSlide`s and owns the keyboard navigation.
 */
export function Deck( { children, className }: { children: ReactNode; className?: string } ) {
	const ref = useRef< HTMLDivElement >( null );

	useEffect( () => {
		const scroller = ref.current;
		if ( ! scroller ) {
			return;
		}
		const hash = decodeDeckHash( window.location.hash );
		const target = hash && getDeckSlides( scroller ).find( slide => slide.id === hash );
		if ( target ) {
			target.scrollIntoView( { block: 'start', behavior: 'instant' } );
		}

		let pending: number | null = null;
		let settle: ReturnType< typeof setTimeout > | undefined;
		const clearPending = () => {
			pending = null;
			clearTimeout( settle );
		};
		const holdPending = ( index: number ) => {
			pending = index;
			clearTimeout( settle );
			settle = setTimeout( clearPending, 1000 );
		};
		scroller.addEventListener( 'scrollend', clearPending );

		const onKeyDown = ( event: KeyboardEvent ) => {
			if ( event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey ) {
				return;
			}
			const eventTarget = event.target instanceof Element ? event.target : null;
			if ( isIgnoredTarget( eventTarget ) ) {
				return;
			}
			const space = event.key === ' ';
			if ( space && eventTarget?.closest( ACTIVATABLE_TARGETS ) ) {
				return;
			}
			const slides = getDeckSlides( scroller );
			if ( ! slides.length ) {
				return;
			}
			let step: 1 | -1 | null = null;
			let jump: number | null = null;
			if ( space ) {
				step = event.shiftKey ? -1 : 1;
			} else if ( event.shiftKey ) {
				return;
			} else if ( [ 'ArrowDown', 'ArrowRight', 'PageDown' ].includes( event.key ) ) {
				step = 1;
			} else if ( [ 'ArrowUp', 'ArrowLeft', 'PageUp' ].includes( event.key ) ) {
				step = -1;
			} else if ( event.key === 'Home' ) {
				jump = 0;
			} else if ( event.key === 'End' ) {
				jump = slides.length - 1;
			}
			if ( step === null && jump === null ) {
				return;
			}
			event.preventDefault();
			if ( step !== null ) {
				const plan = planDeckStep( scroller, slides, step, pending );
				if ( plan?.type === 'scroll' ) {
					scroller.scrollBy( { top: plan.top } );
				} else if ( plan ) {
					holdPending( plan.index );
					goToDeckSlide( slides, plan.index, true );
				}
			} else if ( jump !== null ) {
				holdPending( jump );
				goToDeckSlide( slides, jump, true );
			}
		};

		document.addEventListener( 'keydown', onKeyDown );
		return () => {
			document.removeEventListener( 'keydown', onKeyDown );
			scroller.removeEventListener( 'scrollend', clearPending );
			clearTimeout( settle );
		};
	}, [] );

	return (
		<DeckContext.Provider value={ ref }>
			<div ref={ ref } className={ [ 'newspack-deck h-svh snap-y snap-mandatory overflow-y-auto', className ].filter( Boolean ).join( ' ' ) }>
				{ children }
			</div>
		</DeckContext.Provider>
	);
}

/**
 * One slide of a `Deck`. `tone="brand"` paints it with the Cobalt inverse surface, which needs the newspack-brand theme; without it the slide is unpainted.
 */
export function DeckSlide( {
	id,
	title,
	tone = 'default',
	children,
	className,
}: {
	id: string;
	title: string;
	tone?: 'default' | 'brand';
	children: ReactNode;
	className?: string;
} ) {
	return (
		<section
			id={ id }
			data-deck-slide=""
			aria-label={ title }
			tabIndex={ -1 }
			className={ [ 'min-h-svh snap-start outline-none', tone === 'brand' ? 'newspack-brand-inverse' : '', className ].filter( Boolean ).join( ' ' ) }
		>
			{ children }
		</section>
	);
}
