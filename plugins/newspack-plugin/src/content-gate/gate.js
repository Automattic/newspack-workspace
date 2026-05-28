/* globals newspack_content_gate */
/**
 * Internal dependencies
 */
import './gate.scss';
import { getEventPayload, sendEvent } from '../reader-activation/analytics';
import { debugLog } from '../reader-activation/utils';

const EVENT_NAME = 'np_gate_interaction';

/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param {Function} callback A function to execute after the DOM is ready.
 * @return {void}
 */
function domReady( callback ) {
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

// Gate info to send with each event.
// This is mutable so that its properties can be carried from event to event in gate interaction flows.
const gateInfo = {
	...newspack_content_gate.metadata,
	referrer: window.location.pathname,
};

/**
 * Reload the page when a newly registered reader is detected.
 */
function initReloadHandler() {
	debugLog( 'log', '[Gate] initReloadHandler called' );
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( function ( ras ) {
		debugLog( 'log', '[Gate] RAS initialized' );
		let reload = false;

		const refreshPage = function ( ev ) {
			debugLog( 'log', '[Gate] refreshPage called with event:', ev );
			debugLog( 'log', '[Gate] Event detail:', ev?.detail );
			debugLog( 'log', '[Gate] Event detail action:', ev?.detail?.action );
			debugLog( 'log', '[Gate] Pending checkout:', window?.newspackReaderActivation?.getPendingCheckout() );

			// When a new reader is registered, which may or may not happen inside an overlay.
			if ( ev?.detail?.action && 'reader_registered' === ev.detail.action && ! window?.newspackReaderActivation?.getPendingCheckout() ) {
				debugLog( 'log', '[Gate] reader_registered action detected' );
				reload = true;
			}

			// When closing an overlay, check if the last activity was a checkout, registration, or login.
			if ( ev?.detail?.overlays && ev.detail.removed ) {
				const activities = window?.newspackReaderActivation?.getActivities();
				debugLog( 'log', '[Gate] Overlay removed, activities:', activities );
				const lastActivity = activities?.[ activities.length - 1 ] || {};
				const validActions = [ 'checkout_completed', 'reader_registered', 'reader_logged_in', 'newsletter_signup' ];
				if ( activities.length && validActions.includes( lastActivity.action ) ) {
					debugLog( 'log', '[Gate] Valid action detected:', lastActivity.action );
					reload = true;
					// Add a CSS class to the body so we can keep the overlay content gate hidden while the page refreshes.
					document.body.classList.add( 'newspack-content-gate__gate-passed' );
				} else {
					reload = false;
					handleDismissed();
				}
			}

			// Check for reader_logged_in action specifically for non-overlay contexts
			if ( ev?.detail?.action && 'reader_logged_in' === ev.detail.action ) {
				debugLog( 'log', '[Gate] reader_logged_in action detected' );
				reload = true;
			}

			// If there are no overlays and a new reader, login, or checkout is detected,
			// reload the window, but allow other JS – which might have
			// triggered another overlay – to be executed (setTimeout hack).
			setTimeout( () => {
				const overlays = ras.overlays.get();
				const activities = window?.newspackReaderActivation?.getActivities();
				debugLog( 'log', '[Gate] Checking reload conditions - overlays:', overlays, 'reload:', reload );
				debugLog( 'log', '[Gate] Current activities:', activities );
				if ( ! overlays.length && reload ) {
					debugLog( 'log', '[Gate] Reloading page!' );
					window.location.reload();
				}
			}, 50 );
		};

		ras.on( 'overlay', refreshPage ); // When an overlay is closed.
		ras.on( 'activity', refreshPage ); // When a newly registered reader is detected.
	} );
}

/**
 * Adds 'gate_post_id' hidden input to every form inside the gate.
 *
 * @param {HTMLElement} gate The gate element.
 */
function addFormInputs( gate ) {
	const forms = [
		...document.querySelectorAll( '.newspack-reader-auth form' ), // Auth modal.
		...gate.querySelectorAll( '.newspack-registration form' ), // Registration block.
		...gate.querySelectorAll( '.wp-block-newspack-blocks-checkout-button form' ), // Checkout button block.
		...gate.querySelectorAll( '.wp-block-newspack-blocks-donate form' ), // Donate block.
	];
	forms.forEach( form => {
		if ( ! form.querySelector( 'input[name="gate_post_id"]' ) ) {
			const input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = 'gate_post_id';
			input.value = newspack_content_gate.metadata?.gate_post_id || '1';
			form.appendChild( input );
			form.addEventListener( 'submit', evt => handleFormSubmission( evt, gate ) );
		}
	} );
}

/**
 * Check if a DOM element is visible.
 */
function isVisible( el ) {
	if ( ! el ) {
		return false;
	}

	return el.offsetWidth > 0 && el.offsetHeight > 0;
}

/**
 * Get the full event payload for GA4.
 *
 * @param {Array}       payload The event payload.
 * @param {HTMLElement} gate    The gate element.
 *
 * @return {Array} The full event payload
 */
function getGateEventPayload( payload, gate ) {
	if ( gate ) {
		gateInfo.gate_has_donation_block = isVisible( gate.querySelector( '.wp-block-newspack-blocks-donate' ) ) ? 'yes' : 'no';
		gateInfo.gate_has_registration_block = isVisible( gate.querySelector( '.newspack-registration' ) ) ? 'yes' : 'no';
		gateInfo.gate_has_checkout_button = isVisible( gate.querySelector( '.wp-block-newspack-blocks-checkout-button' ) ) ? 'yes' : 'no';
		gateInfo.gate_has_registration_link = isVisible( gate.querySelector( 'a[href="#register_modal"]' ) ) ? 'yes' : 'no';
		gateInfo.gate_has_signin_link = isVisible( gate.querySelector( 'a[href="#signin_modal"]' ) ) ? 'yes' : 'no';
	}

	return getEventPayload( { ...payload, ...gateInfo } );
}

/**
 * Handle when the gate is seen.
 *
 * @param {HTMLElement} gate            The gate element.
 * @param {boolean}     shouldRecordHit Whether to record a hit in RAS for this seen event. Defaults to false.
 */
function handleSeen( gate, shouldRecordHit = false ) {
	if ( shouldRecordHit ) {
		// paywall_hits - Number of times reader has reached a paywall.
		window.newspackRAS = window.newspackRAS || [];
		window.newspackRAS.push( function ( ras ) {
			const currentHits = ras.store.get( 'paywall_hits' ) || 0;
			ras.store.set( 'paywall_hits', currentHits + 1 );
		} );
	}

	if ( 'function' !== typeof window.gtag ) {
		return;
	}

	// Add hidden form inputs.
	addFormInputs( gate );
	const payload = {
		action: 'seen',
	};
	sendEvent( getGateEventPayload( payload, gate ), EVENT_NAME );
}

/**
 * Handle when an overlay (auth modal, checkout modal, or post-checkout modal) is dismissed.
 */
function handleDismissed() {
	if ( 'function' !== typeof window.gtag ) {
		return;
	}
	sendEvent( getGateEventPayload( { action: 'dismissed' } ), EVENT_NAME );
}

/**
 * Handle when a registration attempt is made from the gate.
 *
 * @param {Event}       evt  The event object.
 * @param {HTMLElement} gate The gate element.
 */
function handleFormSubmission( evt, gate ) {
	if ( 'function' !== typeof window.gtag ) {
		return;
	}
	const payload = { action: 'form_submission' };
	const postedData = new FormData( evt.target );
	const data = {};
	for ( const pair of postedData.entries() ) {
		data[ pair[ 0 ] ] = pair[ 1 ];
	}

	// Parse form data to determine the type of action.
	if ( data[ 'reader-activation-auth-form' ] && data.action ) {
		payload.action_type = 'register' === data.action ? 'registration' : 'signin';
	}
	if ( data.newspack_reader_registration ) {
		payload.action_type = 'registration';
	}
	if ( data.newspack_donate ) {
		payload.action_type = 'donation';
		if ( data.donation_currency ) {
			payload.donation_currency = data.donation_currency;
		}
		if ( data.donation_frequency ) {
			payload.donation_frequency = data.donation_frequency;
			if ( data[ `donation_value_${ data.donation_frequency }` ] ) {
				payload.donation_amount = data[ `donation_value_${ data.donation_frequency }` ];
				if ( 'other' === payload.donation_amount && data[ `donation_value_${ data.donation_frequency }_other` ] ) {
					payload.donation_amount = data[ `donation_value_${ data.donation_frequency }_other` ];
				}
			}
		}
	}
	if ( data.newspack_checkout ) {
		payload.action_type = 'checkout_button';

		// Checkout data attached to Checkout Button form.
		const checkoutData = evt.target.getAttribute( 'data-checkout' ) ? JSON.parse( evt.target.getAttribute( 'data-checkout' ) ) : null;
		if ( checkoutData ) {
			Object.assign( payload, checkoutData );
		}
	}

	sendEvent( getGateEventPayload( payload, gate ), EVENT_NAME );
}

/**
 * Initializes the overlay gate.
 *
 * @param {HTMLElement} gate The gate element.
 */
function initOverlay( gate ) {
	let entry = document.querySelector( '.entry-content' );
	if ( ! entry ) {
		entry = document.querySelector( '#content' ) || document.querySelector( 'main' );
	}
	gate.style.removeProperty( 'display' );
	let seen = false;
	const handleScroll = () => {
		const delta = ( entry?.getBoundingClientRect().top || 0 ) - window.innerHeight / 2;
		let visible = false;
		if ( delta < 0 ) {
			visible = true;
			if ( ! seen ) {
				handleSeen( gate );
			}
			seen = true;
		}
		gate.setAttribute( 'data-visible', visible );
	};
	document.addEventListener( 'scroll', handleScroll );
	handleScroll();
}

/**
 * Handle any floating elements within gate excerpt.
 * Floating elements live outside of the normal DOM flow,
 * so we need to handle various cases when they appear in the excerpt.
 */
function handleFloatingElements() {
	const excerpt = document.querySelector( '.newspack-content-gate__restricted-post-excerpt' );
	if ( ! excerpt ) {
		// No excerpt, nothing to do.
		return;
	}
	const floatingElements = excerpt.querySelectorAll( '.alignleft, .alignright' );
	if ( ! floatingElements.length ) {
		// No floating elements, nothing to do.
		return;
	}
	const { bottom: excerptBottom } = excerpt.getBoundingClientRect();
	floatingElements.forEach( el => {
		const { y: top, bottom } = el.getBoundingClientRect();
		// If the floating element ends within the visible area of the excerpt, do nothing.
		if ( bottom <= excerptBottom ) {
			return;
		}
		// If the floating element begins outside of the visible area of the excerpt, hide it.
		if ( top > excerptBottom ) {
			el.style.display = 'none';
		} else {
			el.style.maxHeight = `${ excerptBottom - top }px`;
			el.style.overflow = 'hidden';
			// If element display is not block, flex, or grid, set it to block to respect overflow.
			if ( ! [ 'block', 'flex', 'grid' ].includes( window.getComputedStyle( el ).display ) ) {
				el.style.display = 'block';
			}
		}
	} );
}

domReady( function () {
	const gate = document.querySelector( '.newspack-content-gate__gate' );
	if ( ! gate ) {
		return;
	}

	initReloadHandler();
	if ( gate.classList.contains( 'newspack-content-gate__overlay-gate' ) ) {
		initOverlay( gate );
	} else {
		window.addEventListener( 'resize', handleFloatingElements );
		handleFloatingElements();
		let seen = false;
		// Seen event for inline gate.
		const detectSeen = () => {
			const delta = ( gate?.getBoundingClientRect().top || 0 ) - window.innerHeight / 2;
			if ( delta < 0 ) {
				handleSeen( gate, ! seen );
				if ( 'function' === typeof window.gtag ) {
					document.removeEventListener( 'scroll', detectSeen );
				}
				seen = true;
			}
		};
		document.addEventListener( 'scroll', detectSeen );
		detectSeen();
	}
} );
