'use client';

import { createContext, useContext, useEffect, useRef } from 'react';
import type { ReactNode, RefObject } from 'react';
import { atDestination, decodeDeckHash, deckKeyAction, encodeDeckHash, planDeckStep, slideScrollTop } from './deck-navigation';
import { browserMotionEnv, createDeckMotion } from './deck-motion';
import type { DeckMotion } from './deck-motion';

const DeckContext = createContext< RefObject< HTMLDivElement | null > | null >( null );

export function useDeckScroller() {
	return useContext( DeckContext );
}

export function getDeckSlides( scroller: HTMLElement | null ) {
	return scroller ? Array.from( scroller.querySelectorAll< HTMLElement >( '[data-deck-slide]' ) ) : [];
}

const motions = new WeakMap< HTMLElement, DeckMotion >();

export function getDeckMotion( scroller: HTMLElement ) {
	let motion = motions.get( scroller );
	if ( ! motion ) {
		motion = createDeckMotion( scroller, browserMotionEnv( scroller ) );
		motions.set( scroller, motion );
	}
	return motion;
}

function syncHash( slide: HTMLElement ) {
	window.history.replaceState( window.history.state, '', encodeDeckHash( slide.id ) );
}

export function goToDeckSlide( slides: HTMLElement[], index: number, focus = false ) {
	const target = Math.max( 0, Math.min( slides.length - 1, index ) );
	const slide = slides[ target ];
	if ( ! slide ) {
		return;
	}
	const scroller = slide.closest< HTMLElement >( '.newspack-deck' );
	if ( scroller ) {
		getDeckMotion( scroller ).scrollTo( slideScrollTop( scroller, slide ), target );
	} else {
		slide.scrollIntoView( { block: 'start' } );
	}
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
		const motion = getDeckMotion( scroller );
		const hash = decodeDeckHash( window.location.hash );
		const target = hash && getDeckSlides( scroller ).find( slide => slide.id === hash );
		if ( target ) {
			target.scrollIntoView( { block: 'start', behavior: 'instant' } );
		}

		const cancel = () => motion.cancel();
		const onPointerDown = ( event: PointerEvent ) => {
			if ( event.target === scroller ) {
				motion.cancel();
			}
		};

		const onKeyDown = ( event: KeyboardEvent ) => {
			const action = deckKeyAction( event, event.target instanceof Element ? event.target : null );
			if ( ! action ) {
				return;
			}
			if ( action.type === 'cancel' ) {
				motion.cancel();
				return;
			}
			const slides = getDeckSlides( scroller );
			if ( ! slides.length ) {
				return;
			}
			event.preventDefault();
			if ( action.type === 'jump' ) {
				goToDeckSlide( slides, action.to === 'first' ? 0 : slides.length - 1, true );
				return;
			}
			const offset = motion.destination() - scroller.scrollTop;
			const plan = planDeckStep(
				scroller,
				slides.map( slide => atDestination( slide, offset ) ),
				action.direction,
				motion.pending
			);
			if ( plan?.type === 'scroll' ) {
				motion.scrollTo( motion.destination() + plan.top );
			} else if ( plan ) {
				goToDeckSlide( slides, plan.index, true );
			}
		};

		const onClick = ( event: MouseEvent ) => {
			if ( event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) {
				return;
			}
			const link = event.target instanceof Element ? event.target.closest< HTMLAnchorElement >( 'a[href^="#"]' ) : null;
			const slides = getDeckSlides( scroller );
			const index = link ? slides.findIndex( slide => slide.id === decodeDeckHash( link.getAttribute( 'href' ) ?? '' ) ) : -1;
			if ( index < 0 ) {
				return;
			}
			event.preventDefault();
			goToDeckSlide( slides, index, true );
		};

		scroller.addEventListener( 'wheel', cancel, { passive: true } );
		scroller.addEventListener( 'touchstart', cancel, { passive: true } );
		scroller.addEventListener( 'pointerdown', onPointerDown );
		scroller.addEventListener( 'click', onClick );
		document.addEventListener( 'keydown', onKeyDown );
		return () => {
			document.removeEventListener( 'keydown', onKeyDown );
			scroller.removeEventListener( 'wheel', cancel );
			scroller.removeEventListener( 'touchstart', cancel );
			scroller.removeEventListener( 'pointerdown', onPointerDown );
			scroller.removeEventListener( 'click', onClick );
			motion.cancel();
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
