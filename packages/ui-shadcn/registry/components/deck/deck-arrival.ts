export const CLOSING_LOGO_PAUSE_TOKEN = '--newspack-deck-closing-logo-pause';

type Box = { top: number; bottom: number };

export type SlidePlace = 'current' | 'visible' | 'away';

/**
 * Where a slide sits in the deck's viewport: `current` when its top has reached the top and it still
 * fills it, `visible` when part of it shows, `away` when none of it does.
 */
export function slidePlace( slide: Box, viewport: Box ): SlidePlace {
	if ( slide.bottom <= viewport.top || slide.top >= viewport.bottom ) {
		return 'away';
	}
	return slide.top <= viewport.top + 1 && slide.bottom > viewport.top + 1 ? 'current' : 'visible';
}

/**
 * How long the closing logo waits before it fades in: the slide's travel for this arrival plus the
 * deck's pause, or nothing under reduced motion, where the slide jumps and has no entrance to follow.
 */
export function closingLogoDelay( travel: number, pause: number, reduced: boolean ) {
	return reduced ? 0 : Math.max( 0, travel ) + Math.max( 0, pause );
}

export type ClosingLogoView = {
	opacity(): number;
	show( delay: number ): void;
	hide( animate: boolean ): void;
};

export type ClosingLogoEnv = {
	reducedMotion(): boolean;
	pause(): number;
};

/**
 * The closing slide's logo fade. The logo starts visible, so it never depends on script to appear,
 * and is hidden at once only when the slide is out of sight. `arrive( travel )` is a scripted
 * navigation to the slide, `settle()` the scroll coming to rest on it any other way. `leave()` cancels
 * a fade that has not started, reverses one under way and leaves a finished logo alone; `reset()`
 * hides it at once when the slide is out of sight, so the next arrival fades again. A logo caught
 * fading out fades straight back in, rather than holding part-faded through the wait.
 */
export function createClosingLogoFade( view: ClosingLogoView, env: ClosingLogoEnv, visible: boolean ) {
	let shown = visible;
	if ( ! shown ) {
		view.hide( false );
	}
	const show = ( travel: number ) => {
		const delay = view.opacity() > 0 ? 0 : closingLogoDelay( travel, env.pause(), env.reducedMotion() );
		shown = true;
		view.show( delay );
	};

	return {
		get shown() {
			return shown;
		},
		arrive( travel: number ) {
			if ( shown ) {
				if ( view.opacity() > 0 ) {
					return;
				}
				view.hide( false );
			}
			show( travel );
		},
		settle() {
			if ( ! shown ) {
				show( 0 );
			}
		},
		leave() {
			if ( ! shown ) {
				return;
			}
			const opacity = view.opacity();
			if ( opacity >= 1 ) {
				return;
			}
			shown = false;
			view.hide( opacity > 0 );
		},
		reset() {
			if ( shown ) {
				shown = false;
				view.hide( false );
			}
		},
	};
}

export type ClosingLogoFade = ReturnType< typeof createClosingLogoFade >;

/**
 * Whether the logo starts visible: when the deck opens on the slide, or any of it is already in
 * view, since a first placement is never animated and nothing the reader can see is hidden.
 */
export function opensVisible( hash: string | null, id: string, place: SlidePlace ) {
	return hash === id || place !== 'away';
}

export type ArrivalEvent = { type: 'start'; to: number; duration: number } | { type: 'end' } | { type: 'cancel' };

export type ArrivalDeck = {
	place( offset?: number ): SlidePlace;
	scrollTop(): number;
	animating(): boolean;
	settles: boolean;
};

/**
 * Turns the deck's motion events and scrolls into the fade's calls. A scripted navigation that will
 * leave the slide current is an arrival with that navigation's travel, and one from out of sight
 * resets the logo first; any other navigation is a leave. While a scripted scroll runs its own
 * events decide, and `scroll` and `scrollend` are ignored. When a scroll ends or stops, where the
 * slide is decides: current settles, partly in view leaves, out of sight resets.
 */
export function routeClosingLogo( fade: ClosingLogoFade, deck: ArrivalDeck ) {
	const check = () => {
		const where = deck.place();
		if ( where === 'away' ) {
			fade.reset();
		} else if ( where === 'current' ) {
			fade.settle();
		} else {
			fade.leave();
		}
	};
	return {
		motion( event: ArrivalEvent ) {
			if ( event.type !== 'start' ) {
				check();
				return;
			}
			const now = deck.place();
			if ( deck.place( event.to - deck.scrollTop() ) !== 'current' ) {
				fade.leave();
			} else if ( now !== 'current' ) {
				if ( now === 'away' ) {
					fade.reset();
				}
				fade.arrive( event.duration );
			}
		},
		scroll() {
			if ( deck.animating() ) {
				return;
			}
			const where = deck.place();
			if ( where === 'away' ) {
				fade.reset();
			} else if ( where === 'visible' ) {
				fade.leave();
			} else if ( ! deck.settles ) {
				fade.settle();
			}
		},
		scrollEnd() {
			if ( ! deck.animating() ) {
				check();
			}
		},
	};
}
