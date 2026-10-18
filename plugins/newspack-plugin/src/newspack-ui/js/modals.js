/**
 * Common functions for Newspack UI modals throughout My Account.
 */

import { domReady, isButtonLoading, setButtonLoading } from '../../utils';
import { getFocusableElements, getInitialFocus, isVisible } from './focus';

window.newspackRAS = window.newspackRAS || [];

// Submit buttons this script marked busy. A form that submits but keeps the page,
// like one posting into the modal checkout iframe, has nothing else to release them.
const handedOffButtons = new WeakSet();

// Challenges third parties render at the end of the body, like reCAPTCHA or a card's
// 3D Secure check. A modal dialog would leave them under the top layer and inert, so
// the dialog steps down while one shows.
const EXTERNAL_LAYERS = [
	'iframe[src*="/recaptcha/api2/bframe"]',
	'iframe[src*="/recaptcha/enterprise/bframe"]',
	'iframe[src*="js.stripe.com"]',
	'iframe[src*="hooks.stripe.com"]',
].join( ', ' );

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
	const heading = modal.querySelector( '.newspack-ui__modal__header h2' );
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
 * Step every open dialog down to non-modal while a third-party layer shows, and back
 * up once it's gone.
 */
function syncExternalLayers() {
	layersFrame = null;
	const layerShown = [ ...document.querySelectorAll( EXTERNAL_LAYERS ) ].some(
		frame => ! frame.closest( '.newspack-ui__modal-container' ) && isVisible( frame )
	);
	openDialogs.forEach( modal => {
		if ( layerShown && ! modal._steppedDown ) {
			const active = modal.ownerDocument.activeElement;
			modal._focusBeforeStepDown = modal.contains( active ) ? active : null;
			modal._steppedDown = true;
			modal.close();
			modal.show();
			// show() focuses the first control, Close, one keypress from closing the modal mid-challenge.
			active?.focus?.( { preventScroll: true } );
		} else if ( ! layerShown && modal._steppedDown ) {
			modal._steppedDown = false;
			modal.close();
			modal.showModal();
			const target = modal._focusBeforeStepDown;
			modal._focusBeforeStepDown = null;
			( target && target.isConnected && isVisible( target ) ? target : getInitialFocus( modal ) || modal ).focus();
		}
	} );
}

/**
 * Watch for third-party layers only while a dialog is open.
 */
function watchExternalLayers() {
	if ( openDialogs.length && ! layersObserver ) {
		layersObserver = new MutationObserver( () => {
			if ( ! layersFrame ) {
				layersFrame = window.requestAnimationFrame( syncExternalLayers );
			}
		} );
		layersObserver.observe( document.body, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'style', 'class' ] } );
	} else if ( ! openDialogs.length && layersObserver ) {
		layersObserver.disconnect();
		layersObserver = null;
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
	moveNotices();
	watchExternalLayers();
	syncExternalLayers();
	if ( ! modal._steppedDown ) {
		( getInitialFocus( modal ) || modal ).focus();
	}
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
 * Set up a dialog's keyboard handling and label.
 *
 * @param {HTMLDialogElement} modal The modal element.
 */
function setupDialog( modal ) {
	labelDialog( modal );

	// Escape goes through data-state, so every close path runs the same teardown.
	// A modal with a request in flight stays open.
	modal.addEventListener( 'cancel', event => {
		event.preventDefault();
		if ( ! modal.querySelector( '[aria-busy="true"]' ) ) {
			modal.setAttribute( 'data-state', 'closed' );
		}
	} );

	// The browser can still close a dialog itself, such as on a repeated Escape.
	modal.addEventListener( 'close', () => {
		if ( ! modal.open && 'open' === modal.dataset.state ) {
			modal.setAttribute( 'data-state', 'closed' );
		}
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
		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];
		if ( event.shiftKey && ( active === first || active === modal ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && active === last ) {
			event.preventDefault();
			first.focus();
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
 * @return {boolean} Whether a dialog closed.
 */
function applyModalState( modal, oldState ) {
	const newState = modal.dataset.state;
	let dialogClosed = false;
	if ( isDialog( modal ) ) {
		if ( 'open' === newState ) {
			openDialog( modal );
		} else if ( modal.open || openDialogs.includes( modal ) ) {
			closeDialog( modal );
			dialogClosed = true;
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
	return dialogClosed;
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
	let dialogClosed = false;
	changes.forEach( ( oldState, modal ) => {
		const closed = applyModalState( modal, oldState );
		// Closing a dialog returns focus to its opener, which would pull it out of a
		// modal that isn't a dialog, like modal checkout, opened in the same task.
		if ( ! isDialog( modal ) && 'open' === modal.dataset.state && dialogClosed && ! modal.contains( modal.ownerDocument.activeElement ) ) {
			getFocusableElements( modal )[ 0 ]?.focus();
		}
		dialogClosed = dialogClosed || closed;
	} );
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
		const modalForm = modal.querySelector( 'form.newspack-ui__modal__content, .newspack-ui__modal__content form' );
		if ( modalForm ) {
			modalForm.addEventListener( 'submit', event => {
				const submitButton = modalForm.querySelector( 'button[type="submit"]' );
				setTimeout( () => {
					if ( submitButton && ! event.defaultPrevented ) {
						setButtonLoading( submitButton, true );
						handedOffButtons.add( submitButton );
					}
				} );
			} );
		}

		const fetchButtons = [ ...modal.querySelectorAll( '[data-fetch]' ) ];
		fetchButtons.forEach( fetchButton => {
			fetchButton.addEventListener( 'click', e => {
				if ( isButtonLoading( fetchButton ) ) {
					e.preventDefault();
					return;
				}
				const fetchData = JSON.parse( fetchButton.getAttribute( 'data-fetch' ) );
				if ( fetchData.url && fetchData.nonce ) {
					const errors = content.querySelector( '.newspack-ui__notice--error' );
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
							if ( fetchData.next ) {
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
							errorsDiv.setAttribute( 'role', 'alert' );
							content.insertBefore( errorsDiv, content.firstChild );
						} )
						.finally( () => {
							setButtonLoading( fetchButton, false );
						} );
				}
			} );
		} );
	} );
} );
