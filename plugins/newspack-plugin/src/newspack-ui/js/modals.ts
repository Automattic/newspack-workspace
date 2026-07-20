/**
 * Common functions for Newspack UI modals throughout My Account.
 */

import { domReady } from '../../utils';

/**
 * A modal container element, with the RAS overlay ID stashed on it as an expando
 * so the overlay can be removed when the modal closes.
 */
interface ModalContainerElement extends HTMLElement {
	_overlayId?: string | null;
}

/**
 * Payload of a `[data-fetch]` button inside a modal.
 */
type ModalFetchPayload = {
	url?: string;
	nonce?: string;
	method?: string;
	body?: Record< string, unknown >;
	next?: string;
};

window.newspackRAS = window.newspackRAS || [];

/**
 * Handle overlays for a modal based on its state.
 *
 * @param modal The modal element.
 * @param state The current state ('open' or 'closed').
 */
function handleModalOverlay( modal: ModalContainerElement, state: string | undefined ) {
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
 * @param modal The modal element.
 */
function setupModalObserver( modal: ModalContainerElement ) {
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
				if ( newState === 'closed' ) {
					modal.dispatchEvent( new CustomEvent( 'closeModal' ) );
				}
			}
		} );
	} );

	observer.observe( modal, {
		attributes: true,
		attributeFilter: [ 'data-state' ],
	} );
}

domReady( function () {
	const modals = [ ...document.querySelectorAll< ModalContainerElement >( '.newspack-ui__modal-container' ) ];

	modals.forEach( modal => {
		// Assumed present in every modal container; fetch-button handlers below rely on it.
		const content = modal.querySelector( '.newspack-ui__modal__content' ) as Element;
		const closeButtons = [ ...modal.querySelectorAll( '.newspack-ui__modal__close' ) ];

		// Set up mutation observer for automatic overlay management
		setupModalObserver( modal );

		closeButtons.forEach( closeButton => {
			closeButton.addEventListener( 'click', e => {
				e.preventDefault();
				modal.setAttribute( 'data-state', 'closed' );
			} );
		} );

		// Form-submit modals: show a spinner on the submit button while the form
		// navigates away. No need to remove the class — page reloads. Covers both
		// shapes: form IS the modal content, or form lives inside a content section.
		const modalForm = modal.querySelector( 'form.newspack-ui__modal__content, .newspack-ui__modal__content form' );
		if ( modalForm ) {
			modalForm.addEventListener( 'submit', () => {
				const submitButton = modalForm.querySelector( 'button[type="submit"]' );
				if ( submitButton ) {
					submitButton.classList.add( 'newspack-ui__button--loading' );
					submitButton.setAttribute( 'disabled', 'true' );
				}
			} );
		}

		const fetchButtons = [ ...modal.querySelectorAll( '[data-fetch]' ) ];
		fetchButtons.forEach( fetchButton => {
			fetchButton.addEventListener( 'click', e => {
				// `String()` keeps JSON.parse's behavior identical for a (practically
				// impossible) missing attribute: JSON.parse coerces null to 'null' anyway.
				const fetchData: ModalFetchPayload = JSON.parse( String( fetchButton.getAttribute( 'data-fetch' ) ) );
				if ( fetchData.url && fetchData.nonce ) {
					const errors = content.querySelector( '.newspack-ui__notice--error' );
					if ( errors ) {
						errors.parentElement!.removeChild( errors );
					}
					e.preventDefault();
					fetchButton.setAttribute( 'disabled', 'true' );
					fetchButton.classList.add( 'newspack-ui__button--loading' );
					fetch( fetchData.url, {
						method: fetchData.method,
						body: JSON.stringify( fetchData.body || {} ),
						headers: {
							'X-WP-Nonce': fetchData.nonce,
						},
					} )
						.then( async response => {
							const json: { error?: string; message?: string } = await response.json();
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
						.catch( ( error: Error ) => {
							const errorsDiv = document.createElement( 'div' );
							// `String()` reproduces the coercion the textContent setter applies
							// to a non-string value, e.g. 'Error: <message>'.
							errorsDiv.textContent = String( error || 'An error occurred.' );
							errorsDiv.classList.add( 'newspack-ui__notice', 'newspack-ui__notice--error' );
							content.insertBefore( errorsDiv, content.firstChild );
						} )
						.finally( () => {
							fetchButton.removeAttribute( 'disabled' );
							fetchButton.classList.remove( 'newspack-ui__button--loading' );
						} );
				}
			} );
		} );
	} );
} );
