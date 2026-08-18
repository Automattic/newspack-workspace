/* globals newspack_popups_view */

import {
	debug,
	closeOverlay,
	getAbOverride,
	getBestPrioritySegment,
	getIntersectionObserver,
	getRawId,
	getOverride,
	handleSeen,
	shouldPromptBeDisplayed,
	syncMatchedSegments,
} from './utils';
import { getCarriedSegmentIds } from './utils/carried-segments';

/**
 * Match reader to segments.
 */
export const handleSegmentation = prompts => {
	const maybeDisplayPrompts = ( ras = null ) => {
		// Don't display prompts if the content is locked.
		if ( document.body.classList.contains( 'newspack-content-locked' ) ) {
			return;
		}
		const segments = newspack_popups_view?.segments || {};
		// Always consume the handoff, so the cookie is cleared even for a reader
		// whose carried IDs are then discarded. A signed-in reader's local
		// matching is live, so a snapshot from their last visit can only make it
		// staler — and their own matched_segments write must stay criteria-only.
		const carriedIds = getCarriedSegmentIds( Object.keys( segments ) );
		// Re-read the authenticated flag fresh each time this is called, rather
		// than freezing it in a variable: RAS's setAuthenticated() can flip a
		// reader to authenticated mid-page with no reload, and a delayed or
		// scroll-triggered prompt's unhide() re-check (below) must see that
		// change even though it runs long after this function returns. Reuses
		// carriedIds itself rather than calling getCarriedSegmentIds() again —
		// it already consumed the cookie, so a second call would find nothing.
		const getCarried = () => ( ras?.store?.get( 'reader' )?.authenticated ? [] : carriedIds );
		const matchingSegment = getBestPrioritySegment( segments, null, getCarried() );
		debug( 'matchingSegment', matchingSegment );

		// Register segments and set match via RAS if available.
		if ( ras?.segments ) {
			ras.segments.register( segments );
			ras.segments.setMatch( matchingSegment );
		}

		// Persist the full matching set server-side for logged-in readers, so
		// server-side consumers (dynamic pricing, available-deals) can read it.
		syncMatchedSegments( ras, segments );

		let overlayDisplayed;

		prompts.forEach( prompt => {
			const promptId = prompt.getAttribute( 'id' );
			const isOverlay = prompt.classList.contains( 'newspack-lightbox' );
			// A/B variant selection composes with the standard override: a test
			// variant the reader is not assigned to is suppressed before it can
			// claim the single-overlay slot; the assigned variant goes through
			// the normal frequency/segmentation checks.
			const override = getOverride( getRawId( promptId ), isOverlay, overlayDisplayed ) ?? getAbOverride( prompt );

			// Attach event listeners to overlay close buttons.
			const closeButtons = [ ...prompt.querySelectorAll( '.newspack-lightbox__close, button.newspack-lightbox-overlay' ) ];
			closeButtons.forEach( closeButton => {
				closeButton.addEventListener( 'click', closeOverlay );
			} );
			// Check segmentation.
			const shouldDisplay = shouldPromptBeDisplayed( prompt, matchingSegment, ras, override );

			// Only show one overlay at a time.
			if ( ! overlayDisplayed && isOverlay && shouldDisplay ) {
				overlayDisplayed = true;
			}

			// Unhide the prompt.
			if ( shouldDisplay ) {
				const delayPrompt = () => {
					// By delay.
					const delay = prompt.getAttribute( 'data-delay' ) || 0;
					setTimeout( unhide, delay );
				};
				const unhide = () => {
					// Conditions may have changed since the prompt was delayed.
					// Verify whether the prompt can still be displayed. Re-derive the
					// carried set here (via getCarried()) rather than closing over the
					// value computed above: a reader who authenticates mid-delay must
					// have their live matching win over a stale carried snapshot even
					// for a prompt that was already pending when that happened.
					const updatedMatchingSegment = getBestPrioritySegment( segments, null, getCarried() );
					if ( ras?.segments ) {
						ras.segments.setMatch( updatedMatchingSegment );
					}
					if ( ! shouldPromptBeDisplayed( prompt, updatedMatchingSegment, ras, override ) ) {
						return;
					}
					// Prioritize RAS overlays. If there are any reinitiate the delay and return early.
					if ( ras?.overlays && ras.overlays.get().length ) {
						delayPrompt();
						return;
					}
					prompt.classList.remove( 'hidden' );

					// Log a "prompt_seen" activity when the prompt becomes visible.
					if ( ras ) {
						handleSeen( prompt, ras );
					}

					// Register the overlay in RAS.
					if ( isOverlay && ras?.overlays ) {
						if ( ! document.body.classList.contains( 'newspack-content-locked' ) ) {
							prompt.overlayId = ras.overlays.add( `prompt_${ promptId }` );
						}
					}
				};
				if ( isOverlay ) {
					const scroll = prompt.getAttribute( 'data-scroll' );
					if ( scroll ) {
						// By scroll trigger.
						const marker = document.getElementById( `page-position-marker_${ promptId }` );
						if ( marker ) {
							getIntersectionObserver( unhide ).observe( marker );
						}
					} else {
						delayPrompt();
					}
				} else {
					unhide();
				}
			}
		} );
	};

	// If no segments to handle.
	if ( ! newspack_popups_view.segments ) {
		maybeDisplayPrompts();
	} else {
		window.newspackRAS = window.newspackRAS || [];
		window.newspackRAS.push( maybeDisplayPrompts );
	}
};
