export const EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';
export const EASE_IN_OUT = 'cubic-bezier(0.77, 0, 0.175, 1)';
export const EASE_DRAWER = 'cubic-bezier(0.32, 0.72, 0, 1)';

export const EASE_COLOR = 'ease';

export const DURATION_COLOR = 150;
export const DURATION_PRESS = 160;
export const DURATION_INDICATOR = 200;
export const DURATION_REVEAL = 200;
export const DURATION_MODAL = 250;
export const DURATION_SHEET = 400;
export const DURATION_EXIT = 120;

export const PRESS_SCALE = 0.97;
export const ENTER_SCALE = 0.96;
export const REVEAL_SCALE = 0.9;
export const ENTER_OFFSET = 4;

export const TRAVEL_BASE_DISTANCE = 240;
export const TRAVEL_PX_PER_MS = 4;
export const DURATION_TRAVEL_MAX = 450;

export const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

/**
 * Whether the reader's system asks for reduced motion.
 *
 * @return {boolean} True when reduced motion is requested.
 */
export function prefersReducedMotion() {
	return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia( REDUCED_MOTION_QUERY ).matches;
}

/**
 * How long a surface takes to travel a distance: its base duration up to
 * TRAVEL_BASE_DISTANCE, then 1ms more for every TRAVEL_PX_PER_MS pixels,
 * up to DURATION_TRAVEL_MAX. The cap also applies to the base, so a base above
 * DURATION_TRAVEL_MAX is shortened to it.
 *
 * @param {number} distance How far the surface travels, in pixels.
 * @param {number} [base]   Its duration over short travel, in milliseconds. Defaults to DURATION_MODAL.
 * @return {number} The duration, in milliseconds.
 */
export function travelDuration( distance, base = DURATION_MODAL ) {
	if ( ! Number.isFinite( distance ) ) {
		return Math.round( Math.min( DURATION_TRAVEL_MAX, base ) );
	}

	const extra = Math.max( 0, distance - TRAVEL_BASE_DISTANCE ) / TRAVEL_PX_PER_MS;

	return Math.round( Math.min( DURATION_TRAVEL_MAX, base + extra ) );
}
