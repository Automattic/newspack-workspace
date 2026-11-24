'use client';

import { useEffect, useState } from 'react';
import { getDeckSlides, goToDeckSlide, useDeckScroller } from './deck';
import { currentDeckIndex } from './deck-navigation';

/**
 * Dot navigation fixed to the right of the viewport, one button per `DeckSlide`. Render it inside `Deck`.
 */
export function DeckNav() {
	const scrollerRef = useDeckScroller();
	const [ slides, setSlides ] = useState< { id: string; title: string }[] >( [] );
	const [ current, setCurrent ] = useState( 0 );

	useEffect( () => {
		const scroller = scrollerRef?.current ?? null;
		const elements = getDeckSlides( scroller );
		setSlides( elements.map( element => ( { id: element.id, title: element.getAttribute( 'aria-label' ) ?? element.id } ) ) );
		if ( ! scroller || ! elements.length ) {
			return;
		}
		const update = () => setCurrent( currentDeckIndex( scroller, elements ) );
		update();
		scroller.addEventListener( 'scroll', update, { passive: true } );
		return () => scroller.removeEventListener( 'scroll', update );
	}, [ scrollerRef ] );

	if ( slides.length < 1 ) {
		return null;
	}

	const inverse = getDeckSlides( scrollerRef?.current ?? null )[ current ]?.matches( '.dark, .newspack-brand-inverse' );

	return (
		<nav aria-label="Slides" className={ `newspack-deck-nav${ inverse ? ' newspack-brand-inverse' : '' } bg-transparent fixed top-1/2 right-4 z-40 flex -translate-y-1/2 flex-col` }>
			{ slides.map( ( slide, index ) => (
				<button
					key={ slide.id }
					type="button"
					aria-label={ `Go to slide ${ index + 1 }: ${ slide.title }` }
					aria-current={ index === current ? 'step' : undefined }
					onClick={ () => goToDeckSlide( getDeckSlides( scrollerRef?.current ?? null ), index ) }
					className="group flex size-8 cursor-pointer items-center justify-center rounded-full transition-[background-color] duration-(--newspack-ui-duration-color) ease-(--newspack-ui-ease-color) motion-reduce:pointer-fine:hover:bg-(--newspack-ui-color-neutral-fill) focus-visible:outline-2 focus-visible:outline-transparent focus-visible:[box-shadow:inset_0_0_0_2px_var(--newspack-ui-color-focus),0_0_0_var(--newspack-ui-focus-halo-width)_var(--newspack-ui-color-focus-halo)]"
				>
					<span className="size-2 rounded-full border border-[var(--newspack-ui-color-text)] bg-transparent [transition:background-color_var(--newspack-ui-duration-color)_var(--newspack-ui-ease-color),border-color_var(--newspack-ui-duration-color)_var(--newspack-ui-ease-color),scale_var(--newspack-ui-duration-press)_var(--newspack-ui-ease-out)] motion-safe:pointer-fine:group-hover:scale-150 motion-safe:group-focus-visible:scale-150 group-aria-[current=step]:bg-[var(--newspack-ui-color-text)] forced-colors:group-aria-[current=step]:bg-[Highlight]" />
				</button>
			) ) }
		</nav>
	);
}
