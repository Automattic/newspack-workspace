/**
 * The one implementation behind every newspack-ui modal: scripts open and close a
 * modal by setting its data-state, and this runs the dialog behind it.
 */

import { domReady, isButtonLoading, setButtonLoading } from '../../utils';
import { getFocusableElements, getInitialFocus, isProgrammaticFocus, isVisible } from './focus';
import { getNoticeIcon } from './icons';
import { resetSteps, showStep } from './modal-steps';
import { observeScrollEdges } from './scroll-edges';

window.newspackRAS = window.newspackRAS || [];

// Submit buttons this script marked busy. A form that submits but keeps the page,
// like one posting into the modal checkout iframe, has nothing else to release them.
const handedOffButtons = new WeakSet();

// Third parties render their challenges at the end of the body. A modal dialog would
// leave them under the top layer and inert, so the dialog steps down while one shows.
// reCAPTCHA's challenge frame is only ever a challenge.
const CHALLENGE_FRAMES = 'iframe[src*="/recaptcha/api2/bframe"], iframe[src*="/recaptcha/enterprise/bframe"]';

// Payment providers also put card fields and buttons on the page. Only their overlays,
// fixed over the page, are challenges.
const PAYMENT_FRAMES = 'iframe[src*="js.stripe.com"], iframe[src*="hooks.stripe.com"], iframe[src*="paypal.com"]';

// Dialogs open on the page, oldest first.
const openDialogs = [];

let layersObserver = null;
let layersFrame = null;

/**
 * Whether a modal is built on a dialog element this browser can open as a modal.
 *
 * @param {HTMLElement} modal The modal element.
 * @return {boolean} Whether it's a dialog.
 */
function isDialog( modal ) {
	return 'DIALOG' === modal.tagName && typeof modal.showModal === 'function';
}

/**
 * Label a dialog with its heading, unless its markup already labels it.
 *
 * @param {HTMLElement} modal The modal element.
 */
function labelDialog( modal ) {
	if ( modal.hasAttribute( 'aria-labelledby' ) || modal.hasAttribute( 'aria-label' ) ) {
		return;
	}
	const header = modal.querySelector( '.newspack-ui__modal__header' );
	const heading = header?.hidden
		? modal.querySelector( '.newspack-ui__modal__content :is(h1, h2, h3, h4, h5, h6)' ) || header.querySelector( 'h2' )
		: header?.querySelector( 'h2' );
	if ( ! heading ) {
		return;
	}
	if ( ! heading.id ) {
		heading.id = `${ modal.id || 'newspack-ui-modal-' + Math.random().toString( 36 ).slice( 2 ) }-title`;
	}
	modal.setAttribute( 'aria-labelledby', heading.id );
}

/**
 * Keep snackbars and screen reader announcements inside the top open dialog,
 * where a modal dialog doesn't make them inert.
 */
function moveNotices() {
	const notices = document.getElementById( 'newspack-ui__notices' );
	const host = openDialogs[ openDialogs.length - 1 ] || document.body;
	if ( notices && notices.parentElement !== host ) {
		host.appendChild( notices );
	}
}

/**
 * Whether an element or one of its ancestors is fixed over the page.
 *
 * @param {HTMLElement} element The element.
 * @return {boolean} Whether it's in a fixed layer.
 */
function isInFixedLayer( element ) {
	for ( let node = element; node && node !== document.body; node = node.parentElement ) {
		if ( 'fixed' === window.getComputedStyle( node ).position ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a third-party challenge is showing outside the modals.
 *
 * @return {boolean} Whether one is showing.
 */
function isChallengeShown() {
	const isOutsideAndVisible = frame => ! frame.closest( '.newspack-ui__modal-container' ) && isVisible( frame );
	return (
		[ ...document.querySelectorAll( CHALLENGE_FRAMES ) ].some( isOutsideAndVisible ) ||
		[ ...document.querySelectorAll( PAYMENT_FRAMES ) ].some( frame => isOutsideAndVisible( frame ) && isInFixedLayer( frame ) )
	);
}

/**
 * Re-check third-party layers on the next frame.
 */
function scheduleLayersSync() {
	if ( ! layersFrame ) {
		layersFrame = window.requestAnimationFrame( syncExternalLayers );
	}
}

/**
 * Step every open dialog down to non-modal while a third-party layer shows, and back
 * up once it's gone.
 */
function syncExternalLayers() {
	layersFrame = null;
	const layerShown = isChallengeShown();
	openDialogs.forEach( modal => {
		if ( layerShown && ! modal._steppedDown ) {
			const active = modal.ownerDocument.activeElement;
			const focusInDialog = modal.contains( active );
			modal._focusBeforeStepDown = focusInDialog ? active : null;
			modal._steppedDown = true;
			modal.close();
			modal.show();
			// show() focuses Close, one keypress from closing the modal mid-challenge. Focus already in the challenge stays there.
			const target =
				focusInDialog || ! active || active === modal.ownerDocument.body ? modal._focusBeforeStepDown || getInitialFocus( modal ) : active;
			target?.focus?.( { preventScroll: true } );
		} else if ( ! layerShown && modal._steppedDown ) {
			modal._steppedDown = false;
			modal.close();
			modal.showModal();
			const target = modal._focusBeforeStepDown;
			modal._focusBeforeStepDown = null;
			( target && target.isConnected && isVisible( target ) ? target : getInitialFocus( modal ) ).focus();
		}
	} );
}

/**
 * Watch for third-party layers only while a dialog is open.
 */
function watchExternalLayers() {
	if ( openDialogs.length && ! layersObserver ) {
		layersObserver = new MutationObserver( scheduleLayersSync );
		layersObserver.observe( document.body, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'style', 'class' ] } );
		// reCAPTCHA hides its challenge with a delayed visibility transition, after the last style change.
		document.addEventListener( 'transitionend', scheduleLayersSync );
	} else if ( ! openDialogs.length && layersObserver ) {
		layersObserver.disconnect();
		layersObserver = null;
		document.removeEventListener( 'transitionend', scheduleLayersSync );
	}
}

/**
 * Open a dialog as a modal and move focus into it.
 *
 * @param {HTMLDialogElement} modal The modal element.
 */
function openDialog( modal ) {
	if ( modal.open ) {
		return;
	}
	// A modal opened from one that is closing returns focus to where the first was opened from.
	const active = modal.ownerDocument.activeElement;
	const fromModal = active?.closest?.( '.newspack-ui__modal-container' );
	modal._opener = fromModal && fromModal !== modal && 'open' !== fromModal.dataset.state && fromModal._opener ? fromModal._opener : active;

	modal.showModal();
	openDialogs.push( modal );
	followKeyboard( modal );
	moveNotices();
	getInitialFocus( modal ).focus();
	watchExternalLayers();
	syncExternalLayers();
}

/**
 * Keep a modal inside the visible viewport while the on-screen keyboard is open.
 * iOS overlays the keyboard instead of resizing the page, which would hide the
 * bottom sheet's actions behind it.
 *
 * @param {HTMLDialogElement} modal The modal element.
 */
function followKeyboard( modal ) {
	const viewport = window.visualViewport;
	if ( ! viewport || modal._unfollowKeyboard ) {
		return;
	}
	const update = () => {
		const offset = Math.max( 0, Math.round( window.innerHeight - viewport.height - viewport.offsetTop ) );
		if ( offset ) {
			modal.style.setProperty( '--newspack-ui-keyboard-offset', `${ offset }px` );
		} else {
			modal.style.removeProperty( '--newspack-ui-keyboard-offset' );
		}
	};
	viewport.addEventListener( 'resize', update );
	viewport.addEventListener( 'scroll', update );
	modal._unfollowKeyboard = () => {
		viewport.removeEventListener( 'resize', update );
		viewport.removeEventListener( 'scroll', update );
		modal.style.removeProperty( '--newspack-ui-keyboard-offset' );
		modal._unfollowKeyboard = null;
	};
	update();
}

/**
 * Close a dialog and return focus to where it was opened from.
 *
 * @param {HTMLDialogElement} modal The modal element.
 */
function closeDialog( modal ) {
	const index = openDialogs.indexOf( modal );
	if ( index > -1 ) {
		openDialogs.splice( index, 1 );
	}
	modal._steppedDown = false;
	modal._focusBeforeStepDown = null;
	modal._unfollowKeyboard?.();
	if ( modal.open ) {
		modal.close();
	}
	moveNotices();
	watchExternalLayers();

	const opener = modal._opener;
	modal._opener = null;
	const active = modal.ownerDocument.activeElement;
	if (
		opener?.isConnected &&
		typeof opener.focus === 'function' &&
		( ! active || active === modal.ownerDocument.body || modal.contains( active ) )
	) {
		opener.focus();
	}
}

/**
 * Focus an element. A frame showing a page from this site, like modal checkout,
 * gets focus on the first or last element Tab reaches inside it.
 *
 * @param {HTMLElement} element The element.
 * @param {boolean}     last    Whether to focus the last element inside a frame.
 */
function focusInto( element, last = false ) {
	const frameBody = 'IFRAME' === element.tagName ? element.contentDocument?.body : null;
	const inside = frameBody ? getFocusableElements( frameBody ) : [];
	( inside.length ? inside[ last ? inside.length - 1 : 0 ] : element ).focus();
}

/**
 * Set up a dialog's keyboard handling and label.
 *
 * @param {HTMLDialogElement} modal The modal element.
 */
function setupDialog( modal ) {
	labelDialog( modal );

	// Escape goes through data-state, so every close path runs the same teardown.
	// A modal with a request in flight stays open.
	modal.addEventListener( 'cancel', event => {
		// A file input inside the modal fires its own cancel when its picker is dismissed.
		if ( event.target !== modal ) {
			return;
		}
		event.preventDefault();
		if ( ! modal.querySelector( '[aria-busy="true"]' ) ) {
			modal.setAttribute( 'data-state', 'closed' );
		}
	} );

	// The browser can still close a dialog itself, such as on a repeated Escape. A
	// modal with a request in flight comes straight back.
	modal.addEventListener( 'close', () => {
		if ( modal.open || 'open' !== modal.dataset.state ) {
			return;
		}
		if ( modal.querySelector( '[aria-busy="true"]' ) ) {
			modal.showModal();
			getInitialFocus( modal ).focus();
			return;
		}
		modal.setAttribute( 'data-state', 'closed' );
	} );

	// A modal dialog makes the page inert, but Tab would still leave for the browser's own controls.
	modal.addEventListener( 'keydown', event => {
		if ( 'Tab' !== event.key || event.defaultPrevented || modal._steppedDown ) {
			return;
		}
		const focusable = getFocusableElements( modal );
		const active = modal.ownerDocument.activeElement;
		if ( ! focusable.length ) {
			event.preventDefault();
			return;
		}
		// Measured by position, so focus on the heading or the dialog itself wraps too.
		const elements = [ ...modal.querySelectorAll( '*' ) ];
		const position = elements.indexOf( active );
		// From the title the modal opened on, Tab goes on to the content rather than back to Close.
		const next =
			! event.shiftKey &&
			isProgrammaticFocus( active ) &&
			focusable.find( element => elements.indexOf( element ) > position && ! element.closest( '.newspack-ui__modal__header' ) );
		if ( next ) {
			event.preventDefault();
			focusInto( next );
		} else if ( event.shiftKey && ! focusable.some( element => elements.indexOf( element ) < position ) ) {
			event.preventDefault();
			focusInto( focusable[ focusable.length - 1 ], true );
		} else if ( ! event.shiftKey && ! focusable.some( element => elements.indexOf( element ) > position ) ) {
			event.preventDefault();
			focusInto( focusable[ 0 ] );
		}
	} );
}

/**
 * Handle overlays for a modal based on its state.
 *
 * @param {HTMLElement} modal The modal element.
 * @param {string}      state The current state ('open' or 'closed').
 */
function handleModalOverlay( modal, state ) {
	window.newspackRAS.push( ras => {
		if ( state === 'open' ) {
			// Remove any existing overlays first (in case of state toggle)
			if ( modal._overlayId ) {
				ras.overlays.remove( modal._overlayId );
			}
			modal._overlayId = ras.overlays.add();
		} else if ( state === 'closed' ) {
			if ( modal._overlayId ) {
				ras.overlays.remove( modal._overlayId );
				modal._overlayId = null;
			}
		}
	} );
}

/**
 * Apply a modal's new state.
 *
 * @param {HTMLElement} modal    The modal element.
 * @param {string}      oldState The previous state.
 */
function applyModalState( modal, oldState ) {
	const newState = modal.dataset.state;
	if ( newState === 'open' && oldState !== 'open' ) {
		resetSteps( modal );
	}
	if ( isDialog( modal ) ) {
		if ( 'open' === newState ) {
			openDialog( modal );
		} else if ( modal.open || openDialogs.includes( modal ) ) {
			closeDialog( modal );
		}
	}
	handleModalOverlay( modal, newState );
	if ( newState === 'open' && oldState !== 'open' ) {
		modal.querySelectorAll( 'button[type="submit"]' ).forEach( button => {
			if ( handedOffButtons.has( button ) ) {
				handedOffButtons.delete( button );
				setButtonLoading( button, false );
			}
		} );
	}
	if ( newState === 'closed' ) {
		modal.dispatchEvent( new CustomEvent( 'closeModal' ) );
	}
}

// One observer for every modal, so a modal that closes and one that opens in the
// same task are handled in the order they changed.
const modalObserver = new MutationObserver( mutations => {
	const changes = new Map();
	mutations.forEach( mutation => {
		if ( mutation.type !== 'attributes' || mutation.attributeName !== 'data-state' ) {
			return;
		}
		const oldState = changes.has( mutation.target ) ? changes.get( mutation.target ) : mutation.oldValue;
		changes.delete( mutation.target );
		changes.set( mutation.target, oldState );
	} );
	changes.forEach( ( oldState, modal ) => applyModalState( modal, oldState ) );
} );

/**
 * Set up a modal's state handling.
 *
 * @param {HTMLElement} modal The modal element.
 */
function setupModalObserver( modal ) {
	if ( isDialog( modal ) ) {
		setupDialog( modal );
	}
	if ( modal.dataset.state === 'open' ) {
		applyModalState( modal, null );
	}
	modalObserver.observe( modal, {
		attributes: true,
		attributeFilter: [ 'data-state' ],
		attributeOldValue: true,
	} );
}

// A page restored from the back/forward cache comes back with the busy buttons its
// form left behind, and its modal may still be open.
window.addEventListener( 'pageshow', event => {
	if ( ! event.persisted ) {
		return;
	}
	document.querySelectorAll( '.newspack-ui__modal-container button[type="submit"]' ).forEach( button => {
		if ( handedOffButtons.has( button ) ) {
			handedOffButtons.delete( button );
			setButtonLoading( button, false );
		}
	} );
} );

/**
 * Open select2 dropdowns, like WooCommerce's country and state fields, inside the
 * dialog that holds them. Rendered at the end of the body, they would be inert.
 */
function keepSelect2InDialogs() {
	const $ = window.jQuery;
	if ( ! $ ) {
		return;
	}
	$( document ).on( 'select2:opening', event => {
		const dialog = event.target.closest?.( 'dialog.newspack-ui__modal-container' );
		const dropdown = $( event.target ).data( 'select2' )?.dropdown;
		if ( dialog && dropdown?.$dropdownParent ) {
			dropdown.$dropdownParent = $( dialog );
		}
	} );
}

domReady( function () {
	keepSelect2InDialogs();

	const modals = [ ...document.querySelectorAll( '.newspack-ui__modal-container' ) ];

	modals.forEach( modal => {
		const content = modal.querySelector( '.newspack-ui__modal__content' );
		const closeButtons = [ ...modal.querySelectorAll( '.newspack-ui__modal__close' ) ];

		// Set up mutation observer for automatic overlay management
		setupModalObserver( modal );

		modal.querySelectorAll( '.newspack-ui__modal, .newspack-ui__modal__steps' ).forEach( scroller => observeScrollEdges( scroller, 'y' ) );

		closeButtons.forEach( closeButton => {
			closeButton.addEventListener( 'click', e => {
				e.preventDefault();
				modal.setAttribute( 'data-state', 'closed' );
			} );
		} );

		// Form-submit modals: show the loading state on the submit button while the
		// form submits. A form that intercepts its own submit owns its button, so this
		// only marks submits that go ahead, once every listener has had its say. A form
		// that keeps the page, like one posting into an iframe, is released when its
		// modal opens again. Covers both shapes: form IS the modal content, or form
		// lives inside a content section.
		modal.querySelectorAll( 'form.newspack-ui__modal__content, .newspack-ui__modal__content form' ).forEach( modalForm => {
			modalForm.addEventListener( 'submit', event => {
				const submitButton = modalForm.querySelector( 'button[type="submit"]' );
				setTimeout( () => {
					if ( submitButton && ! event.defaultPrevented ) {
						setButtonLoading( submitButton, true );
						handedOffButtons.add( submitButton );
					}
				} );
			} );
		} );

		const fetchButtons = [ ...modal.querySelectorAll( '[data-fetch]' ) ];
		fetchButtons.forEach( fetchButton => {
			fetchButton.addEventListener( 'click', e => {
				if ( isButtonLoading( fetchButton ) ) {
					e.preventDefault();
					return;
				}
				const fetchData = JSON.parse( fetchButton.getAttribute( 'data-fetch' ) );
				if ( fetchData.url && fetchData.nonce ) {
					const fetchContent = fetchButton.closest( '.newspack-ui__modal__content' ) || content;
					const errors = fetchContent.querySelector( '.newspack-ui__notice--error' );
					if ( errors ) {
						errors.parentElement.removeChild( errors );
					}
					e.preventDefault();
					setButtonLoading( fetchButton, true );
					fetch( fetchData.url, {
						method: fetchData.method,
						body: JSON.stringify( fetchData.body || {} ),
						headers: {
							'X-WP-Nonce': fetchData.nonce,
						},
					} )
						.then( async response => {
							const json = await response.json();
							if ( ! response.ok || json.error ) {
								throw new Error( json.message || json.error || 'An error occurred. Please try again.' );
							}
							return json;
						} )
						.then( () => {
							if ( fetchData.next && ! showStep( modal, fetchData.next ) ) {
								const nextModal = document.getElementById( `newspack-my-account__${ fetchData.next }` );
								if ( nextModal ) {
									modal.setAttribute( 'data-state', 'closed' );
									nextModal.setAttribute( 'data-state', 'open' );
								}
							}
						} )
						.catch( error => {
							const errorsDiv = document.createElement( 'div' );
							errorsDiv.textContent = error || 'An error occurred.';
							errorsDiv.classList.add( 'newspack-ui__notice', 'newspack-ui__notice--error' );
							const errorIcon = getNoticeIcon( 'error' );
							if ( errorIcon ) {
								errorsDiv.prepend( errorIcon );
							}
							errorsDiv.setAttribute( 'role', 'alert' );
							fetchContent.insertBefore( errorsDiv, fetchContent.firstChild );
						} )
						.finally( () => {
							setButtonLoading( fetchButton, false );
						} );
				}
			} );
		} );
	} );
} );
