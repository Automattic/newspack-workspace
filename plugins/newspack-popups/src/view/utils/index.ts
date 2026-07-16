/* globals newspack_popups_view */

export * from './analytics';
export * from './prompts';
export * from './segments';

import { getRawId } from './prompts';
import { periods, type Pageviews } from './segments';

// The minimum continuous amount of time the prompt must be in the viewport before being considered visible.
const MINIMUM_VISIBLE_TIME = 250;

// The minimum percentage of the prompt that must be in the viewport before being considered visible.
const MINIMUM_VISIBLE_PERCENTAGE = 0.5;

/**
 * Execute a callback function when an element becomes visible.
 *
 * @param {Function} handleEvent Callback function to execute when the prompt becomes eligible for display.
 * @return {IntersectionObserver} Observer instance.
 */
export const getIntersectionObserver = ( handleEvent: () => void ): IntersectionObserver => {
	let timer: ReturnType< typeof setTimeout > | false | undefined;
	const observer = new IntersectionObserver(
		entries => {
			entries.forEach( observerEntry => {
				if ( observerEntry.isIntersecting ) {
					if ( ! timer ) {
						timer = setTimeout( () => {
							handleEvent();
							observer.unobserve( observerEntry.target );
						}, MINIMUM_VISIBLE_TIME || 0 );
					}
				} else if ( timer ) {
					clearTimeout( timer );
					timer = false;
				}
			} );
		},
		{
			threshold: MINIMUM_VISIBLE_PERCENTAGE,
		}
	);

	return observer;
};

/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param {Function} callback A function to execute after the DOM is ready.
 * @return {void}
 */
export function domReady( callback: () => void ): void {
	if ( typeof document === 'undefined' ) {
		return;
	}
	if (
		document.readyState === 'complete' || // DOMContentLoaded + Images/Styles/etc loaded, so we call directly.
		document.readyState === 'interactive' // DOMContentLoaded fires at this point, so we call directly.
	) {
		return void callback();
	}
	// DOMContentLoaded has not fired yet, delay callback until then.
	document.addEventListener( 'DOMContentLoaded', callback );
}

/**
 * Log a "prompt_seen" activity when the prompt becomes visible.
 *
 * @param {HTMLElement} prompt HTML element for prompt.
 * @param {Object}      ras    Reader Data Library object.
 */
export const handleSeen = ( prompt: PromptElement, ras: NewspackReaderActivation ): void => {
	const handleEvent = () => ras.dispatchActivity( 'prompt_seen', { prompt_id: getRawId( prompt.getAttribute( 'id' )! ) } );
	// NOTE: pre-existing bug, not introduced by this migration: `IntersectionObserver.observe()`
	// takes only a target element; the `{ attributes: true }` second argument (a MutationObserver
	// option) is silently ignored by the browser. Left as-is; cast to preserve the call shape.
	( getIntersectionObserver( handleEvent ).observe as ( target: Element, options?: unknown ) => void )( prompt, { attributes: true } );
};

/**
 * Increment pageview counters.
 *
 * @param {Object} ras Reader Data Library object.
 *
 * @return {Object} Total pageviews.
 */
export const logPageview = ( ras: NewspackReaderActivation ): Pageviews => {
	const now = Date.now();
	const pageviewTemplate: Pageviews = {
		day: {
			count: 0,
			start: now,
		},
		week: {
			count: 0,
			start: now,
		},
		month: {
			count: 0,
			start: now,
		},
	};

	// `ras.store.get()` returns `unknown` by design; narrow at this boundary.
	const priorPageviews = ( ras.store.get( 'pageviews' ) as Pageviews ) || {};
	const pageviews: Pageviews = { ...pageviewTemplate, ...priorPageviews };

	// If the current page is the donor landing page, mark the reader as a donor.
	let pageId: number | undefined;
	document.body.classList.forEach( className => {
		if ( 0 === className.indexOf( 'page-id-' ) ) {
			pageId = parseInt( className.replace( 'page-id-', '' ) );
		}
	} );
	// `String( ... )` mirrors the implicit-to-string coercion `parseInt()` already
	// performs at runtime when given a non-string (including `undefined`) value.
	if ( pageId && parseInt( String( newspack_popups_view?.donor_landing_page ) ) === pageId ) {
		ras.store.set( 'is_donor', true );
	}

	for ( const period in pageviews ) {
		// If the period has elapsed, reset the count.
		if ( periods[ period ] < now - pageviews[ period ].start ) {
			pageviews[ period ].count = 0;
			pageviews[ period ].start = now;
		}

		// Increment the count.
		pageviews[ period ].count++;
	}

	// Persist to the Reader Data Library store.
	ras.store.set( 'pageviews', pageviews );
	return pageviews;
};
