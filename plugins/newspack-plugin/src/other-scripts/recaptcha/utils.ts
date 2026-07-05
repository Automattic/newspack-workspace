/* globals jQuery, grecaptcha, newspack_recaptcha_data */

// The minimum continuous amount of time an element must be in the viewport before being considered visible.
const MINIMUM_VISIBLE_TIME = 250;

// The minimum percentage of an element that must be in the viewport before being considered visible.
const MINIMUM_VISIBLE_PERCENTAGE = 0.1;

/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param callback A function to execute after the DOM is ready.
 */
export function domReady( callback: () => void ): void {
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

/**
 * Create an IntersectionObserver to execute function `handleEvent` when an element becomes visible.
 *
 * @param handleEvent
 * @return Observer instance.
 */
export function getIntersectionObserver( handleEvent: () => void ): IntersectionObserver {
	let timer: ReturnType< typeof setTimeout > | false | undefined;
	const observer = new IntersectionObserver(
		entries => {
			entries.forEach( observerEntry => {
				if ( observerEntry.isIntersecting ) {
					if ( ! timer ) {
						timer = setTimeout( () => {
							handleEvent();
							observer.unobserve( observerEntry.target );
						}, MINIMUM_VISIBLE_TIME || 0 );
					}
				} else if ( timer ) {
					clearTimeout( timer );
					timer = false;
				}
			} );
		},
		{
			threshold: MINIMUM_VISIBLE_PERCENTAGE,
		}
	);

	return observer;
}

/**
 * Destroy hidden reCAPTCHA v3 token fields to avoid unnecessary reCAPTCHA checks.
 */
export function destroyV3Field( forms: HTMLFormElement[] = [] ) {
	const formsToHandle = forms.length ? forms : [ ...document.querySelectorAll< HTMLFormElement >( 'form[data-newspack-recaptcha]' ) ];

	formsToHandle.forEach( form => {
		removeHiddenV3Field( form );
	} );
}

/**
 * Append a hidden reCAPTCHA v3 token field to the given form.
 *
 * @param form The form element.
 */
export function addHiddenV3Field( form: HTMLFormElement ) {
	const field = form.querySelector< HTMLInputElement >( 'input[name="g-recaptcha-response"]' );
	if ( ! field ) {
		const newField = document.createElement( 'input' );
		newField.type = 'hidden';
		newField.name = 'g-recaptcha-response';
		form.appendChild( newField );

		const action = form.getAttribute( 'data-newspack-recaptcha' ) || 'submit';
		refreshV3Token( newField, action );
		setInterval( () => refreshV3Token( newField, action ), 30000 ); // Refresh token every 30 seconds.

		// Refresh reCAPTCHAs on Woo checkout update and error.
		if ( jQuery ) {
			jQuery( document ).on( 'updated_checkout', () => refreshV3Token( newField, action ) );
			jQuery( document.body ).on( 'checkout_error', () => refreshV3Token( newField, action ) );
		}
	}
}

/**
 * Refresh the reCAPTCHA v3 token for the given form and action.
 *
 * @param field  The hidden input field storing the token for a form.
 * @param action The action name to pass to reCAPTCHA.
 *
 * @return A promise that resolves when the token is refreshed.
 */
function refreshV3Token( field: HTMLInputElement, action = 'submit' ) {
	if ( field ) {
		const siteKey = newspack_recaptcha_data?.site_key;

		// Get a token to pass to the server. See https://developers.google.com/recaptcha/docs/v3 for API reference.
		return grecaptcha!.execute( siteKey, { action } ).then( token => {
			field.value = token;
		} );
	}
}

/**
 * Remove the hidden reCAPTCHA v3 token field from the given form.
 *
 * @param form The form element.
 */
function removeHiddenV3Field( form: HTMLFormElement ) {
	const field = form.querySelector( 'input[name="g-recaptcha-response"]' );
	if ( field ) {
		field.parentElement!.removeChild( field );
	}
}

/**
 * Refresh the reCAPTCHA v2 widget attached to the given element.
 *
 * @param el Element with the reCAPTCHA widget to refresh.
 */
export function refreshV2Widget( el: HTMLElement ) {
	// `|| ''` only converts a null attribute; parseInt( '' ) is NaN, matching parseInt( null ).
	const widgetId = parseInt( el.getAttribute( 'data-recaptcha-widget-id' ) || '' );
	if ( ! isNaN( widgetId ) ) {
		grecaptcha!.reset( widgetId );
	}
}

/**
 * Append a generic error message above the given form.
 *
 * @param form    The form element.
 * @param message The error message to display.
 */
export function addErrorMessage( form: HTMLFormElement, message: string ) {
	const errorText = document.createElement( 'p' );
	errorText.textContent = message;
	const container = document.createElement( 'div' );
	container.classList.add( 'newspack-recaptcha-error' );
	container.appendChild( errorText );
	// Newsletters block errors render below the form.
	if ( form.parentElement!.classList.contains( 'newspack-newsletters-subscribe' ) ) {
		form.append( container );
	} else {
		container.classList.add( 'newspack-ui__notice', 'newspack-ui__notice--error' );
		form.insertBefore( container, form.firstChild );
	}
}

/**
 * Remove generic error messages from form if present.
 *
 * @param form The form element.
 */
export function removeErrorMessages( form: HTMLFormElement ) {
	const errors = form.querySelectorAll( '.newspack-recaptcha-error' );
	for ( const error of errors ) {
		error.parentElement!.removeChild( error );
	}
}
