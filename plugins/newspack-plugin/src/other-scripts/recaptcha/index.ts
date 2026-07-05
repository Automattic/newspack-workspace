/* globals jQuery, grecaptcha, newspack_recaptcha_data */

import { addErrorMessage, addHiddenV3Field, destroyV3Field, domReady, getIntersectionObserver, refreshV2Widget, removeErrorMessages } from './utils';
import './style.scss';

window.newspack_grecaptcha = window.newspack_grecaptcha || {
	destroy: destroyV3Field,
	render,
	version: newspack_recaptcha_data.version,
};

const isV2 = 'v2' === newspack_recaptcha_data.version.substring( 0, 2 );
const isV3 = 'v3' === newspack_recaptcha_data.version;
const siteKey = newspack_recaptcha_data.site_key;
const isInvisible = 'v2_invisible' === newspack_recaptcha_data.version;

/**
 * Render reCAPTCHA v2 widget on the given form.
 *
 * @param form      The form element.
 * @param onSuccess Callback to handle success. Optional.
 * @param onError   Callback to handle errors. Optional.
 */
function renderV2Widget( form: HTMLFormElement, onSuccess: ( () => void ) | null = null, onError: ( ( message: string ) => void ) | null = null ) {
	form.removeAttribute( 'data-recaptcha-validated' );
	// Common render options for reCAPTCHA v2 widget. See https://developers.google.com/recaptcha/docs/invisible#render_param for supported params.
	const options = {
		sitekey: siteKey,
		size: isInvisible ? 'invisible' : 'normal',
		isolated: true,
	};

	// Callback when reCAPTCHA passes validation or skip flag is present.
	const successCallback = ( token: string ) => {
		onSuccess?.();
		// Ensure the token gets submitted with the form submission.
		let hiddenField = form.querySelector< HTMLInputElement >( '[name="g-recaptcha-response"]' );
		if ( ! hiddenField ) {
			hiddenField = document.createElement( 'input' );
			hiddenField.type = 'hidden';
			hiddenField.name = 'g-recaptcha-response';
			form.appendChild( hiddenField );
		}
		hiddenField.value = token;
		form.setAttribute( 'data-recaptcha-validated', '1' );

		// If the form has a #place_order button, click it.
		const placeOrder = form.querySelector< HTMLElement >( '#place_order' );
		if ( placeOrder ) {
			placeOrder.click();
		} else {
			form.requestSubmit( form.querySelector< HTMLElement >( 'input[type="submit"], button[type="submit"]' ) );
		}

		refreshV2Widget( form );
	};
	// Callback when reCAPTCHA rendering fails or expires.
	const errorCallback = () => {
		form.removeAttribute( 'data-recaptcha-validated' );
		// `|| ''` only converts a null attribute; parseInt( '' ) is NaN, matching parseInt( null ).
		const retryCount = parseInt( form.getAttribute( 'data-recaptcha-retry-count' ) || '' ) || 0;
		if ( retryCount < 3 ) {
			refreshV2Widget( form );
			grecaptcha!.execute( form.getAttribute( 'data-recaptcha-widget-id' ) );
			form.setAttribute( 'data-recaptcha-retry-count', String( retryCount + 1 ) );
		} else {
			const message = wp.i18n.__( 'There was an error connecting with reCAPTCHA. Please reload the page and try again.', 'newspack-plugin' );
			if ( onError ) {
				onError( message );
			} else {
				addErrorMessage( form, message );
			}
		}
	};

	// Attach widget to form events.
	const attachListeners = () => {
		form.removeAttribute( 'data-submit-button-click' );
		// IntersectionObserver.observe() takes a single argument; the runtime ignores extras.
		getIntersectionObserver( () => renderV2Widget( form, onSuccess, onError ) ).observe( form );

		const handleSubmit = ( e: Event ) => {
			if ( ! form.hasAttribute( 'data-recaptcha-validated' ) && ! form.hasAttribute( 'data-skip-recaptcha' ) ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				// Empty error messages if present.
				removeErrorMessages( form );

				grecaptcha!.execute( widgetId );
			} else {
				form.removeAttribute( 'data-recaptcha-validated' );
			}
		};
		form.addEventListener( 'submit', handleSubmit, true );

		const placeOrderClone = form.querySelector( '#place_order_clone' );
		if ( placeOrderClone ) {
			placeOrderClone.addEventListener(
				'click',
				e => {
					e.preventDefault();
					e.stopImmediatePropagation();
					handleSubmit( e );
				},
				true
			);
		}
	};
	// Refresh reCAPTCHA widgets on Woo checkout update and error.
	if ( jQuery ) {
		jQuery( document ).on( 'updated_checkout', () => renderV2Widget( form, onSuccess, onError ) );
		jQuery( document.body ).on( 'checkout_error', () => renderV2Widget( form, onSuccess, onError ) );
	}
	// Refresh widget if it already exists.
	if ( form.hasAttribute( 'data-recaptcha-widget-id' ) ) {
		refreshV2Widget( form );
		return;
	}
	const container = document.createElement( 'div' );
	container.classList.add( 'grecaptcha-container' );
	document.body.append( container );
	const widgetId = grecaptcha!.render( container, {
		...options,
		callback: successCallback,
		'error-callback': errorCallback,
		'expired-callback': errorCallback,
	} );
	form.setAttribute( 'data-recaptcha-widget-id', String( widgetId ) );
	attachListeners();
}

/**
 * Render reCAPTCHA elements.
 *
 * @param forms     Array of form elements to render reCAPTCHA on.
 * @param onSuccess Callback to handle success. Optional.
 * @param onError   Callback to handle errors. Optional.
 */
function render( forms: HTMLFormElement[] = [], onSuccess: ( () => void ) | null = null, onError: ( ( message: string ) => void ) | null = null ) {
	// In case some other file calls this function before the reCAPTCHA API is ready.
	if ( ! grecaptcha ) {
		// domReady defers until the API script has loaded, so grecaptcha is defined by then.
		return domReady( () => grecaptcha!.ready( () => render( forms, onSuccess, onError ) ) );
	}

	const formsToHandle = forms.length
		? forms
		: [ ...document.querySelectorAll< HTMLFormElement >( 'form[data-newspack-recaptcha],form#add_payment_method,form.checkout' ) ];

	formsToHandle.forEach( form => {
		const renderForm = () => {
			if ( isV2 ) {
				renderV2Widget( form, onSuccess, onError );
			}
			if ( isV3 ) {
				addHiddenV3Field( form );
			}
		};
		// IntersectionObserver.observe() takes a single argument; the runtime ignores extras.
		getIntersectionObserver( renderForm ).observe( form );
	} );
}

/**
 * Invoke only after reCAPTCHA API is ready.
 */
domReady( function () {
	grecaptcha!.ready( render );
} );
