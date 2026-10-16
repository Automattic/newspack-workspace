/**
 * Common functions for Newspack UI modals throughout My Account.
 */

import { domReady, isButtonLoading, setButtonLoading } from '../../utils';

window.newspackRAS = window.newspackRAS || [];

// Submit buttons this script marked busy. A form that submits but keeps the page,
// like one posting into the modal checkout iframe, has nothing else to release them.
const handedOffButtons = new WeakSet();

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
 * Set up mutation observer for a modal to watch for state changes.
 *
 * @param {HTMLElement} modal The modal element.
 */
function setupModalObserver( modal ) {
	// Handle initial state
	const initialState = modal.dataset.state;
	if ( initialState === 'open' ) {
		handleModalOverlay( modal, 'open' );
	}

	// Watch for state changes
	const observer = new MutationObserver( mutations => {
		mutations.forEach( mutation => {
			if ( mutation.type === 'attributes' && mutation.attributeName === 'data-state' ) {
				const newState = modal.dataset.state;
				handleModalOverlay( modal, newState );
				if ( newState === 'open' && mutation.oldValue !== 'open' ) {
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
		} );
	} );

	observer.observe( modal, {
		attributes: true,
		attributeFilter: [ 'data-state' ],
		attributeOldValue: true,
	} );
}

domReady( function () {
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
