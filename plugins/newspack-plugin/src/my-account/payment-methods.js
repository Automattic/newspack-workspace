/* globals newspack_my_account */

/**
 * Initialize functions for the Payment Methods page.
 */
import { domReady, isButtonLoading, setButtonLoading } from '../utils';

domReady( function () {
	const { labels, nonce, rest_url, should_rate_limit } = newspack_my_account || {};

	// Rate limit the add payment method form.
	const addPaymentForm = document.getElementById( 'add_payment_method' );
	if ( addPaymentForm && Boolean( should_rate_limit ) ) {
		const errorContainer = document.querySelector( '.woocommerce-notices-wrapper' );
		const submitButton = addPaymentForm.querySelector( 'input[type="submit"], button[type="submit"]' );
		const showError = message => {
			const error = document.createElement( 'div' );
			const errorUl = document.createElement( 'ul' );
			const errorLi = document.createElement( 'li' );
			errorUl.classList.add( 'woocommerce-error' );
			errorLi.textContent = message;
			error.appendChild( errorUl );
			errorUl.appendChild( errorLi );
			errorContainer.appendChild( error );
			errorContainer.scrollIntoView( { behavior: 'smooth' } );
		};
		const rateLimit = function ( e ) {
			if ( addPaymentForm.hasAttribute( 'data-check-rate-limit' ) ) {
				e.preventDefault();
				if ( isButtonLoading( submitButton ) ) {
					return;
				}
				errorContainer.textContent = '';
				setButtonLoading( submitButton, true );
				const xhr = new XMLHttpRequest();
				xhr.onreadystatechange = function () {
					// Return if the request is completed.
					if ( xhr.readyState !== 4 ) {
						return;
					}
					setButtonLoading( submitButton, false );

					if ( xhr.status < 200 || xhr.status >= 300 ) {
						showError( labels?.generic_error );
						return;
					}
					let data;
					try {
						data = JSON.parse( xhr.responseText );
					} catch {
						data = null;
					}
					if ( data?.success ) {
						addPaymentForm.removeAttribute( 'data-check-rate-limit' );
						addPaymentForm.requestSubmit( submitButton );
						addPaymentForm.setAttribute( 'data-check-rate-limit', '1' );
					} else {
						showError( data?.error || labels?.generic_error );
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
