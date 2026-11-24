'use client';

import { useEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { getDeckSlides, goToDeckSlide, useDeckScroller } from './deck';
import { currentDeckIndex, dotTones } from './deck-navigation';
import { ALWAYS_VISIBLE_QUERY, createNavReveal } from './deck-nav-reveal';

/**
 * Dot navigation fixed to the right of the viewport, one button per `DeckSlide`. Render it inside `Deck`. Each target is 32px, smaller when the column would not fit the viewport height, keeping 24px for up to (viewport height - 32) / 24 slides, 14 on an 844 by 390 landscape phone. With a mouse it shows on load, then hides until the pointer nears the right edge or keyboard focus enters it; a tap shows it for a moment, and without a fine hovering pointer it always shows. A mouse click on a dot moves focus to its slide.
 */
export function DeckNav() {
	const scrollerRef = useDeckScroller();
	const navRef = useRef< HTMLElement >( null );
	const [ slides, setSlides ] = useState< { id: string; title: string }[] >( [] );
	const [ current, setCurrent ] = useState( 0 );
	const [ visible, setVisible ] = useState( true );

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

	useEffect( () => {
		const nav = navRef.current;
		const scroller = scrollerRef?.current ?? null;
		if ( ! nav || ! scroller ) {
			return;
		}
		const update = () => {
			const slideBoxes = getDeckSlides( scroller ).map( slide => {
				const box = slide.getBoundingClientRect();
				return { top: box.top, bottom: box.bottom, inverse: slide.matches( '.dark, .newspack-brand-inverse' ) };
			} );
			const buttons = Array.from( nav.querySelectorAll( 'button' ) );
			const tones = dotTones( buttons.map( button => {
				const box = button.getBoundingClientRect();
				return box.top + box.height / 2;
			} ), slideBoxes );
			// Set on the DOM, not in className, so a dot changes tone in the scroll frame the boundary passes it, without waiting for a render.
			buttons.forEach( ( button, index ) => button.classList.toggle( 'newspack-brand-inverse', tones[ index ] ) );
		};
		nav.setAttribute( 'data-settling', '' );
		update();
		// Flush styles while transitions are off, so the first tone is set rather than faded in.
		nav.getBoundingClientRect();
		nav.removeAttribute( 'data-settling' );
		scroller.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );
		return () => {
			scroller.removeEventListener( 'scroll', update );
			window.removeEventListener( 'resize', update );
		};
	}, [ scrollerRef, slides.length ] );

	useEffect( () => {
		const nav = navRef.current;
		if ( ! nav ) {
			return;
		}
		const query = window.matchMedia( ALWAYS_VISIBLE_QUERY );
		const reveal = createNavReveal( setVisible, {
			setTimeout: ( callback, ms ) => window.setTimeout( callback, ms ),
			clearTimeout: id => window.clearTimeout( id as number ),
		} );
		const onPointerMove = ( event: PointerEvent ) => {
			if ( event.pointerType === 'mouse' ) {
				reveal.pointer( event.clientX, window.innerWidth, event.target instanceof Node && nav.contains( event.target ) );
			}
		};
		const onPointerDown = ( event: PointerEvent ) => {
			if ( event.pointerType !== 'mouse' ) {
				reveal.touch();
			}
		};
		const onPointerOut = ( event: PointerEvent ) => {
			if ( ! event.relatedTarget ) {
				reveal.leave();
			}
		};
		const onFocusIn = ( event: FocusEvent ) => {
			if ( event.target instanceof Element && event.target.matches( ':focus-visible' ) ) {
				nav.setAttribute( 'data-instant', '' );
				reveal.pin();
			}
		};
		const onFocusOut = ( event: FocusEvent ) => {
			if ( ! ( event.relatedTarget instanceof Node && nav.contains( event.relatedTarget ) ) ) {
				nav.removeAttribute( 'data-instant' );
				reveal.unpin();
			}
		};
		const onQueryChange = () => reveal.setAlways( query.matches );
		onQueryChange();
		reveal.start();
		query.addEventListener( 'change', onQueryChange );
		document.addEventListener( 'pointermove', onPointerMove, { passive: true } );
		document.addEventListener( 'pointerdown', onPointerDown, { passive: true } );
		document.addEventListener( 'pointerout', onPointerOut, { passive: true } );
		nav.addEventListener( 'focusin', onFocusIn );
		nav.addEventListener( 'focusout', onFocusOut );
		return () => {
			reveal.destroy();
			query.removeEventListener( 'change', onQueryChange );
			document.removeEventListener( 'pointermove', onPointerMove );
			document.removeEventListener( 'pointerdown', onPointerDown );
			document.removeEventListener( 'pointerout', onPointerOut );
			nav.removeEventListener( 'focusin', onFocusIn );
			nav.removeEventListener( 'focusout', onFocusOut );
		};
	}, [ slides.length ] );

	if ( slides.length < 1 ) {
		return null;
	}

	return (
		<nav
			ref={ navRef }
			aria-label="Slides"
			data-hidden={ visible ? undefined : '' }
			className={ `newspack-deck-nav fixed top-1/2 right-4 z-40 flex -translate-y-1/2 flex-col [transition:opacity_var(--newspack-ui-duration-reveal)_var(--newspack-ui-ease-out),translate_var(--newspack-ui-duration-reveal)_var(--newspack-ui-ease-out)] data-[hidden]:pointer-events-none data-[hidden]:opacity-0 motion-safe:data-[hidden]:translate-x-1 data-[instant]:transition-none data-[settling]:[&_*]:transition-none` }
			style={ { '--deck-dot-count': slides.length } as CSSProperties }
		>
			{ slides.map( ( slide, index ) => (
				<button
					key={ slide.id }
					type="button"
					aria-label={ `Go to slide ${ index + 1 }: ${ slide.title }` }
					aria-current={ index === current ? 'step' : undefined }
					onClick={ event => goToDeckSlide( getDeckSlides( scrollerRef?.current ?? null ), index, event.detail > 0 ) }
					className="group flex size-[min(2rem,calc((100svh-2rem)/var(--deck-dot-count)))] cursor-pointer items-center justify-center rounded-full bg-transparent transition-[background-color] duration-(--newspack-ui-duration-color) ease-(--newspack-ui-ease-color) motion-reduce:pointer-fine:hover:bg-(--newspack-ui-color-neutral-fill) focus-visible:outline-2 focus-visible:outline-transparent focus-visible:[box-shadow:inset_0_0_0_2px_var(--newspack-ui-color-focus),0_0_0_var(--newspack-ui-focus-halo-width)_var(--newspack-ui-color-focus-halo)]"
				>
					<span className="size-2 rounded-full border border-[var(--newspack-ui-color-text)] bg-transparent [transition:background-color_var(--newspack-ui-duration-color)_var(--newspack-ui-ease-color),border-color_var(--newspack-ui-duration-color)_var(--newspack-ui-ease-color),scale_var(--newspack-ui-duration-press)_var(--newspack-ui-ease-out)] motion-safe:pointer-fine:group-hover:scale-150 motion-safe:group-focus-visible:scale-150 group-aria-[current=step]:bg-[var(--newspack-ui-color-text)] forced-colors:group-aria-[current=step]:bg-[Highlight]" />
				</button>
			) ) }
		</nav>
	);
}
