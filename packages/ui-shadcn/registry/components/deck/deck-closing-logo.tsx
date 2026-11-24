'use client';

import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { getDeckMotion, useDeckScroller } from './deck';
import { decodeDeckHash, atDestination } from './deck-navigation';
import { REDUCED_MOTION_QUERY, parseDuration } from './deck-motion';
import { CLOSING_LOGO_PAUSE_TOKEN, createClosingLogoFade, slidePlace } from './deck-arrival';

/**
 * The closing slide's logo, which fades in each time the slide arrives, once it has settled and after
 * the deck's `--newspack-deck-closing-logo-pause`. It renders visible, so without script or before
 * hydration the logo shows, and a deck opened on this slide shows it at once.
 */
export function DeckClosingLogo( { children, className }: { children: ReactNode; className?: string } ) {
	const scrollerRef = useDeckScroller();
	const ref = useRef< HTMLDivElement >( null );

	useEffect( () => {
		const logo = ref.current;
		const scroller = scrollerRef?.current ?? null;
		const slide = logo?.closest< HTMLElement >( '[data-deck-slide]' ) ?? null;
		if ( ! logo || ! scroller || ! slide ) {
			return;
		}
		const motion = getDeckMotion( scroller );
		const place = ( offset = 0 ) => slidePlace( atDestination( slide, offset ).getBoundingClientRect(), scroller.getBoundingClientRect() );
		const opening = decodeDeckHash( window.location.hash ) === slide.id || place() !== 'away';
		const fade = createClosingLogoFade(
			{
				opacity: () => Number( getComputedStyle( logo ).opacity ),
				show: delay => {
					logo.style.transitionDelay = `${ delay }ms`;
					logo.removeAttribute( 'data-hidden' );
				},
				hide: animate => {
					logo.style.transitionDelay = '';
					if ( ! animate ) {
						logo.setAttribute( 'data-instant', '' );
					}
					logo.setAttribute( 'data-hidden', '' );
					if ( ! animate ) {
						logo.getBoundingClientRect();
						logo.removeAttribute( 'data-instant' );
					}
				},
			},
			{
				reducedMotion: () => window.matchMedia( REDUCED_MOTION_QUERY ).matches,
				pause: () => parseDuration( getComputedStyle( logo ).getPropertyValue( CLOSING_LOGO_PAUSE_TOKEN ) ) ?? 0,
			},
			opening
		);

		const check = () => {
			const where = place();
			if ( where === 'away' ) {
				fade.reset();
			} else if ( where === 'current' ) {
				fade.settle();
			} else {
				fade.leave();
			}
		};
		const settles = 'onscrollend' in scroller;
		const onScroll = () => {
			if ( motion.animating ) {
				return;
			}
			const where = place();
			if ( where === 'away' ) {
				fade.reset();
			} else if ( where === 'visible' ) {
				fade.leave();
			} else if ( ! settles ) {
				fade.settle();
			}
		};
		const onScrollEnd = () => {
			if ( ! motion.animating ) {
				check();
			}
		};
		const unsubscribe = motion.subscribe( event => {
			if ( event.type === 'start' ) {
				const arriving = place( event.to - scroller.scrollTop ) === 'current';
				if ( arriving && place() !== 'current' ) {
					fade.arrive( event.duration );
				} else if ( ! arriving ) {
					fade.leave();
				}
			} else if ( event.type === 'end' ) {
				check();
			} else {
				fade.leave();
			}
		} );
		scroller.addEventListener( 'scroll', onScroll, { passive: true } );
		scroller.addEventListener( 'scrollend', onScrollEnd );
		return () => {
			unsubscribe();
			scroller.removeEventListener( 'scroll', onScroll );
			scroller.removeEventListener( 'scrollend', onScrollEnd );
			logo.removeAttribute( 'data-hidden' );
			logo.style.transitionDelay = '';
		};
	}, [ scrollerRef ] );

	return (
		<div
			ref={ ref }
			className={ cn(
				'[transition:opacity_var(--newspack-ui-duration-reveal)_var(--newspack-ui-ease-out)] data-[hidden]:opacity-0 data-[instant]:transition-none print:transition-none print:data-[hidden]:opacity-100',
				className
			) }
		>
			{ children }
		</div>
	);
}
