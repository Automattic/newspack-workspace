/**
 * Common functions for Newspack UI modals throughout My Account.
 */

import { domReady } from '../../utils';

window.newspackRAS = window.newspackRAS || [];

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
				const fetchData = JSON.parse( fetchButton.getAttribute( 'data-fetch' ) );
				if ( fetchData.url && fetchData.nonce ) {
					const errors = content.querySelector( '.newspack-ui__notice--error' );
					if ( errors ) {
						errors.parentElement.removeChild( errors );
					}
					e.preventDefault();
					fetchButton.setAttribute( 'disabled', true );
					fetchButton.classList.add( 'newspack-ui__button--loading' );
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
