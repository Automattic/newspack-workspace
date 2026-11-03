export declare const EASE_OUT: string;
export declare const EASE_IN_OUT: string;
export declare const EASE_DRAWER: string;

export declare const EASE_COLOR: string;

export declare const DURATION_COLOR: number;
export declare const DURATION_PRESS: number;
export declare const DURATION_INDICATOR: number;
export declare const DURATION_REVEAL: number;
export declare const DURATION_MODAL: number;
export declare const DURATION_SHEET: number;
export declare const DURATION_EXIT: number;

export declare const PRESS_SCALE: number;
export declare const ENTER_SCALE: number;
export declare const REVEAL_SCALE: number;
export declare const ENTER_OFFSET: number;

export declare const TRAVEL_BASE_DISTANCE: number;
export declare const TRAVEL_PX_PER_MS: number;
export declare const DURATION_TRAVEL_MAX: number;

export declare const REDUCED_MOTION_QUERY: string;

/**
 * Whether the reader's system asks for reduced motion.
 *
 * @return True when reduced motion is requested.
 */
export declare function prefersReducedMotion(): boolean;

/**
 * How long a surface takes to travel a distance: its base duration up to
 * TRAVEL_BASE_DISTANCE, then 1ms more for every TRAVEL_PX_PER_MS pixels,
 * up to DURATION_TRAVEL_MAX. The cap also applies to the base, so a base above
 * DURATION_TRAVEL_MAX is shortened to it.
 *
 * @param distance How far the surface travels, in pixels.
 * @param base     Its duration over short travel, in milliseconds. Defaults to DURATION_MODAL.
 * @return The duration, in milliseconds.
 */
export declare function travelDuration( distance: number, base?: number ): number;
