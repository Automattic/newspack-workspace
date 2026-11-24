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
 * hides it at once once the slide is out of sight, so the next arrival fades again.
 */
export function createClosingLogoFade( view: ClosingLogoView, env: ClosingLogoEnv, visible: boolean ) {
	let shown = visible;
	if ( ! shown ) {
		view.hide( false );
	}
	const show = ( travel: number ) => {
		shown = true;
		view.show( closingLogoDelay( travel, env.pause(), env.reducedMotion() ) );
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
