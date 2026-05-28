/* globals jQuery */

/**
 * Initialize functions for the Payment Information page.
 */

import { domReady } from '../../utils';

/**
 * Handle form submission with loading state.
 *
 * @param {Event} e - Form submit event
 */
function handleFormSubmission( e ) {
	const modal = e.target.closest( '[id*="newspack-my-account__"]' );
	if ( modal ) {
		const submitButton = e.target.querySelector( 'button[type="submit"]' );
		if ( submitButton && ! submitButton.disabled ) {
			submitButton.disabled = true;
			submitButton.classList.add( 'newspack-ui--loading' );
		}
	}
}

/**
 * Setup modal handlers for buttons
 *
 * @param {string} selector      - CSS selector for buttons
 * @param {string} modalId       - Base modal ID (dynamic suffix appended when available)
 * @param {string} dataAttribute - Data attribute to use for dynamic IDs (e.g., 'data-address-type')
 */
function setupModalHandlers( selector, modalId, dataAttribute = null ) {
	document.querySelectorAll( selector ).forEach( button => {
		button.addEventListener( 'click', e => {
			e.preventDefault();

			// Handle dynamic modal IDs when data attributes are provided.
			const type = dataAttribute ? button.getAttribute( dataAttribute ) : '';
			const targetModalId = modalId + ( type ? `-${ type }` : '' );

			// Open modal and handle common behavior.
			const modal = document.getElementById( targetModalId );
			if ( modal ) {
				modal.setAttribute( 'data-state', 'open' );
				button.closest( 'div' ).classList.remove( 'newspack-ui--loading' );
				const dropdown = button.closest( '.newspack-ui__dropdown' );
				if ( dropdown ) {
					dropdown.classList.remove( 'active' );
				}
				jQuery( document.body ).trigger( 'refresh' );
			}
		} );
	} );
}

domReady( function () {
	// Add payment method modal.
	setupModalHandlers( '.newspack-my-account__add-payment-method', 'newspack-my-account__add-payment-method' );

	// Delete payment method modals.
	setupModalHandlers( '.newspack-my-account__delete-payment-method', 'newspack-my-account__delete-payment-method', 'data-payment-method' );

	// Edit address modals.
	setupModalHandlers( '.newspack-my-account__edit-address', 'newspack-my-account__edit-address', 'data-address-type' );

	// Delete address modals.
	setupModalHandlers( '.newspack-my-account__delete-address', 'newspack-my-account__delete-address', 'data-address-type' );

	// Prevent multiple form submissions and show loading state for all modals.
	document.addEventListener( 'submit', handleFormSubmission );

	// Force display first payment method.
	const firstPaymentMethod = document.querySelector( '.woocommerce-PaymentMethod:first-child' );
	if ( firstPaymentMethod ) {
		firstPaymentMethod.classList.add( 'active' );
	}
} );
