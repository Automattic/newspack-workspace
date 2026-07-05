/* globals newspack_my_account */

/**
 * Initialize functions for the Payment Methods page.
 */
import { domReady } from '../utils';

domReady( function () {
	const { nonce, rest_url, should_rate_limit } = newspack_my_account || {};

	// Rate limit the add payment method form.
	const addPaymentForm = document.getElementById( 'add_payment_method' );
	if ( addPaymentForm && Boolean( should_rate_limit ) ) {
		const errorContainer = document.querySelector( '.woocommerce-notices-wrapper' );
		const submitButton = addPaymentForm.querySelector( 'input[type="submit"], button[type="submit"]' );
		const rateLimit = function ( e ) {
			if ( addPaymentForm.hasAttribute( 'data-check-rate-limit' ) ) {
				errorContainer.textContent = '';
				submitButton.setAttribute( 'disabled', '' );
				e.preventDefault();
				const xhr = new XMLHttpRequest();
				xhr.onreadystatechange = function () {
					// Return if the request is completed.
					if ( xhr.readyState !== 4 ) {
						return;
					}

					// Call onSuccess with parsed JSON if the request is successful.
					if ( xhr.status >= 200 && xhr.status < 300 ) {
						submitButton.removeAttribute( 'disabled' );
						const data = JSON.parse( xhr.responseText );
						if ( data?.success ) {
							addPaymentForm.removeAttribute( 'data-check-rate-limit' );
							addPaymentForm.requestSubmit( submitButton );
							addPaymentForm.setAttribute( 'data-check-rate-limit', '1' );
						}
						if ( data?.error ) {
							const error = document.createElement( 'div' );
							const errorUl = document.createElement( 'ul' );
							const errorLi = document.createElement( 'li' );
							errorUl.classList.add( 'woocommerce-error' );
							errorLi.textContent = data.error;
							error.appendChild( errorUl );
							errorUl.appendChild( errorLi );
							errorContainer.appendChild( error );
							errorContainer.scrollIntoView( { behavior: 'smooth' } );
						}
					}
				};

				xhr.open( 'GET', rest_url + 'newspack/v1/check-rate' );
				xhr.setRequestHeader( 'X-WP-Nonce', nonce );
				xhr.send();
			}
		};
		addPaymentForm.setAttribute( 'data-check-rate-limit', '1' );
		addPaymentForm.addEventListener( 'submit', rateLimit, true );
		submitButton.addEventListener( 'click', rateLimit, true );
	}
} );
