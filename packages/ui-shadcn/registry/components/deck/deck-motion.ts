export const EASE_DRAWER = 'cubic-bezier(0.32, 0.72, 0, 1)';
export const DURATION_MODAL = 250;
export const TRAVEL_BASE_DISTANCE = 240;
export const TRAVEL_PX_PER_MS = 4;
export const DURATION_TRAVEL_MAX = 450;
export const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

export const DURATION_TOKEN = '--newspack-ui-duration-modal';
export const EASING_TOKEN = '--newspack-ui-ease-drawer';

export type Easing = ( progress: number ) => number;

/**
 * The newspack-motion travel rule: `base` up to TRAVEL_BASE_DISTANCE, then 1ms more for every
 * TRAVEL_PX_PER_MS pixels, up to DURATION_TRAVEL_MAX. Same as `travelDuration()` in newspack-motion.
 */
export function travelDuration( distance: number, base = DURATION_MODAL ) {
	if ( ! Number.isFinite( distance ) ) {
		return Math.round( Math.min( DURATION_TRAVEL_MAX, base ) );
	}
	const extra = Math.max( 0, distance - TRAVEL_BASE_DISTANCE ) / TRAVEL_PX_PER_MS;
	return Math.round( Math.min( DURATION_TRAVEL_MAX, base + extra ) );
}

/**
 * The CSS `cubic-bezier( x1, y1, x2, y2 )` timing function, solved for x like browsers do.
 */
export function cubicBezier( x1: number, y1: number, x2: number, y2: number ): Easing {
	const cx = 3 * x1;
	const bx = 3 * ( x2 - x1 ) - cx;
	const ax = 1 - cx - bx;
	const cy = 3 * y1;
	const by = 3 * ( y2 - y1 ) - cy;
	const ay = 1 - cy - by;
	const sampleX = ( t: number ) => ( ( ax * t + bx ) * t + cx ) * t;
	const sampleY = ( t: number ) => ( ( ay * t + by ) * t + cy ) * t;
	const slopeX = ( t: number ) => ( 3 * ax * t + 2 * bx ) * t + cx;
	const solveX = ( x: number ) => {
		let t = x;
		for ( let i = 0; i < 8; i++ ) {
			const error = sampleX( t ) - x;
			if ( Math.abs( error ) < 1e-7 ) {
				return t;
			}
			const slope = slopeX( t );
			if ( Math.abs( slope ) < 1e-6 ) {
				break;
			}
			t -= error / slope;
		}
		let low = 0;
		let high = 1;
		t = x;
		for ( let i = 0; i < 64 && high - low > 1e-9; i++ ) {
			if ( sampleX( t ) < x ) {
				low = t;
			} else {
				high = t;
			}
			t = ( low + high ) / 2;
		}
		return t;
	};
	return progress => ( progress <= 0 ? 0 : progress >= 1 ? 1 : sampleY( solveX( progress ) ) );
}

const KEYWORDS: Record< string, [ number, number, number, number ] > = {
	linear: [ 0, 0, 1, 1 ],
	ease: [ 0.25, 0.1, 0.25, 1 ],
	'ease-in': [ 0.42, 0, 1, 1 ],
	'ease-out': [ 0, 0, 0.58, 1 ],
	'ease-in-out': [ 0.42, 0, 0.58, 1 ],
};

/**
 * Parses a `cubic-bezier()` or keyword easing. Returns null for anything else, such as `steps()`.
 */
export function parseEasing( value: string ): Easing | null {
	const text = value.trim().toLowerCase();
	if ( KEYWORDS[ text ] ) {
		return cubicBezier( ...KEYWORDS[ text ] );
	}
	const match = /^cubic-bezier\(\s*([^,]+),([^,]+),([^,]+),([^,)]+)\)$/.exec( text );
	if ( ! match ) {
		return null;
	}
	const [ x1, y1, x2, y2 ] = match.slice( 1 ).map( Number );
	if ( [ x1, y1, x2, y2 ].some( n => ! Number.isFinite( n ) ) || x1 < 0 || x1 > 1 || x2 < 0 || x2 > 1 ) {
		return null;
	}
	return cubicBezier( x1, y1, x2, y2 );
}

/**
 * Parses a CSS `<time>` such as `250ms` or `0.25s` into milliseconds. Returns null otherwise.
 */
export function parseDuration( value: string ) {
	const match = /^(\d*\.?\d+)(ms|s)$/i.exec( value.trim() );
	if ( ! match ) {
		return null;
	}
	return Number( match[ 1 ] ) * ( match[ 2 ].toLowerCase() === 's' ? 1000 : 1 );
}

export type DeckMotionTokens = { base: number; easing: Easing };

/**
 * Reads the travel base duration and curve from the newspack-ui tokens in a computed style, so a
 * theme override reaches the deck, falling back to the newspack-motion values without the theme.
 */
export function readDeckMotionTokens( style: { getPropertyValue( name: string ): string } ): DeckMotionTokens {
	return {
		base: parseDuration( style.getPropertyValue( DURATION_TOKEN ) ) ?? DURATION_MODAL,
		easing: parseEasing( style.getPropertyValue( EASING_TOKEN ) ) ?? ( parseEasing( EASE_DRAWER ) as Easing ),
	};
}

export type DeckMotionScroller = {
	scrollTop: number;
	scrollHeight: number;
	clientHeight: number;
	scrollTo( options: { top: number; behavior: ScrollBehavior } ): void;
	style: { scrollSnapType: string };
};

export type DeckMotionEnv = {
	now(): number;
	requestFrame( callback: () => void ): number;
	cancelFrame( id: number ): void;
	reducedMotion(): boolean;
	tokens(): DeckMotionTokens;
};

export type DeckMotion = {
	scrollTo( top: number, index?: number | null ): void;
	cancel(): void;
	destination(): number;
	readonly pending: number | null;
	readonly animating: boolean;
};

export function browserMotionEnv( element: Element ): DeckMotionEnv {
	return {
		now: () => performance.now(),
		requestFrame: callback => requestAnimationFrame( callback ),
		cancelFrame: id => cancelAnimationFrame( id ),
		reducedMotion: () => window.matchMedia( REDUCED_MOTION_QUERY ).matches,
		tokens: () => readDeckMotionTokens( getComputedStyle( element ) ),
	};
}

/**
 * Scripted scrolling for a deck. A new `scrollTo` mid-flight starts again from where the scroll is,
 * with a duration for the distance left; `cancel` stops it where it is. Snapping is off while it runs,
 * because a snap container re-snaps every programmatic scroll.
 */
export function createDeckMotion( scroller: DeckMotionScroller, env: DeckMotionEnv ): DeckMotion {
	let frame: number | null = null;
	let from = 0;
	let to = 0;
	let start = 0;
	let duration = 0;
	let easing: Easing = progress => progress;
	let pending: number | null = null;

	const jump = ( top: number ) => scroller.scrollTo( { top, behavior: 'instant' } );
	const stop = () => {
		if ( frame === null ) {
			return;
		}
		env.cancelFrame( frame );
		frame = null;
		pending = null;
		scroller.style.scrollSnapType = '';
	};
	const tick = () => {
		const progress = duration > 0 ? Math.min( 1, Math.max( 0, ( env.now() - start ) / duration ) ) : 1;
		jump( from + ( to - from ) * easing( progress ) );
		frame = progress < 1 ? env.requestFrame( tick ) : null;
		if ( frame === null ) {
			pending = null;
			scroller.style.scrollSnapType = '';
		}
	};

	return {
		get pending() {
			return pending;
		},
		get animating() {
			return frame !== null;
		},
		destination: () => ( frame !== null ? to : scroller.scrollTop ),
		cancel: stop,
		scrollTo( top, index = null ) {
			const target = Math.max( 0, Math.min( scroller.scrollHeight - scroller.clientHeight, top ) );
			const distance = Math.abs( target - scroller.scrollTop );
			if ( env.reducedMotion() || distance < 1 ) {
				stop();
				jump( target );
				return;
			}
			const tokens = env.tokens();
			from = scroller.scrollTop;
			to = target;
			duration = travelDuration( distance, tokens.base );
			easing = tokens.easing;
			start = env.now();
			pending = index;
			scroller.style.scrollSnapType = 'none';
			if ( frame === null ) {
				frame = env.requestFrame( tick );
			}
		},
	};
}
