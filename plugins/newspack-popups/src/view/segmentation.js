/* globals newspack_popups_view */

import {
	debug,
	closeOverlay,
	getBestPrioritySegment,
	getIntersectionObserver,
	getRawId,
	getOverride,
	handleSeen,
	shouldPromptBeDisplayed,
	syncMatchedSegments,
} from './utils';

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
		const matchingSegment = getBestPrioritySegment( segments );
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
			const override = getOverride( getRawId( promptId ), isOverlay, overlayDisplayed );

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
					// Verify whether the prompt can still be displayed.
					const updatedMatchingSegment = getBestPrioritySegment( segments );
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
