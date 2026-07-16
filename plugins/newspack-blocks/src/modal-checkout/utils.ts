/**
 * An iframe element augmented with the modal checkout's readiness bookkeeping.
 */
export interface CheckoutIframe extends HTMLIFrameElement {
	_ready?: boolean;
	_readyTimer?: ReturnType< typeof setTimeout >;
	_observer?: MutationObserver;
	_locationObserver?: ReturnType< typeof setInterval >;
}

/**
 * The checkout container element inside the iframe document, augmented with the
 * lifecycle flags dispatched from the checkout script.
 */
export interface CheckoutContainer extends HTMLElement {
	checkoutReady?: boolean;
	checkoutCancel?: boolean;
	checkoutComplete?: boolean;
	checkoutPlaceOrderStart?: boolean;
	checkoutPlaceOrderProcessing?: boolean;
	checkoutPlaceOrderError?: boolean;
	checkoutPlaceOrderCriticalError?: boolean;
}

/**
 * Checkout data assembled from a form and/or a `data-checkout` attribute.
 */
export type CheckoutData = Record< string, string >;

/**
 * A checkout lifecycle callback. Invoked either directly (no event) or as a DOM
 * event listener (with the dispatched event).
 */
export type CheckoutEventCallback = ( event?: Event ) => void;

/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param callback A function to execute after the DOM is ready.
 */
export function domReady( callback: () => void ): void {
	if ( typeof document === 'undefined' || typeof callback !== 'function' ) {
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
 * Create a hidden input field.
 *
 * @param name  The name of the input field.
 * @param value The value of the input field. Optional.
 *
 * @return The hidden input element.
 */
export function createHiddenInput( name: string, value: string | null = null ): HTMLInputElement {
	const input = document.createElement( 'input' );
	input.type = 'hidden';
	input.name = name;

	// Set the value if provided.
	if ( value ) {
		input.value = value;
	}

	return input;
}

/**
 * Run a callback when an iframe is ready.
 *
 * @param iframe  The iframe element.
 * @param cb      The callback to execute when the iframe is ready.
 * @param onReset The callback to execute when the iframe is reset.
 */
export function iframeReady( iframe: CheckoutIframe, cb: () => void, onReset?: () => void ): void {
	iframe._ready = false;
	if ( iframe._readyTimer ) {
		clearTimeout( iframe._readyTimer );
	}
	let fired = false;
	let lastLocation = '';

	function ready( this: Document ) {
		if ( ! fired ) {
			fired = true;
			clearTimeout( iframe._readyTimer );
			cb.call( this );
		}
	}

	function readyState( this: Document ) {
		if ( this.readyState === 'complete' ) {
			ready.call( this );
		}
	}

	function cleanup() {
		iframe._ready = false;
		if ( onReset ) {
			onReset();
		}
		if ( iframe._readyTimer ) {
			clearTimeout( iframe._readyTimer );
		}
		const doc = iframe.contentDocument || iframe.contentWindow?.document;
		if ( doc ) {
			doc.removeEventListener( 'DOMContentLoaded', ready );
			doc.removeEventListener( 'readystatechange', readyState );
		}
	}

	function checkLoaded() {
		if ( iframe._ready ) {
			cleanup();
			return;
		}
		const doc = iframe.contentDocument || iframe.contentWindow?.document;
		if ( doc && doc.URL.indexOf( 'about:' ) !== 0 ) {
			if ( doc?.readyState === 'complete' ) {
				ready.call( doc );
			} else {
				doc.addEventListener( 'DOMContentLoaded', ready );
				doc.addEventListener( 'readystatechange', readyState );
			}
		} else {
			iframe._readyTimer = setTimeout( checkLoaded, 10 );
		}
	}

	function handleLocationChange() {
		const doc = iframe.contentDocument || iframe.contentWindow?.document;
		if ( doc && doc.URL !== lastLocation ) {
			lastLocation = doc.URL;
			fired = false;
			cleanup();
			checkLoaded();
		}
	}

	// Set up MutationObserver to watch for src changes
	if ( ! iframe._observer ) {
		iframe._observer = new MutationObserver( mutations => {
			mutations.forEach( mutation => {
				if ( mutation.type === 'attributes' && mutation.attributeName === 'src' ) {
					fired = false;
					cleanup();
					checkLoaded();
				}
			} );
		} );
		iframe._observer.observe( iframe, { attributes: true } );
	}

	// Set up location change detection
	if ( ! iframe._locationObserver ) {
		iframe._locationObserver = setInterval( handleLocationChange, 50 );
	}

	checkLoaded();
}

/**
 * Run a callback when the checkout is ready.
 *
 * @param container The container element inside the iframe document.
 * @param callback  The callback to execute when the checkout is ready.
 */
export function onCheckoutReady( container: CheckoutContainer, callback: CheckoutEventCallback ) {
	if ( container.checkoutReady ) {
		callback();
	} else {
		container.addEventListener( 'checkout-ready', callback );
	}
}

/**
 * Run a callback when the checkout is canceled.
 *
 * @param container The container element inside the iframe document.
 * @param callback  The callback to execute when the checkout is canceled.
 */
export function onCheckoutCancel( container: CheckoutContainer, callback: CheckoutEventCallback ) {
	if ( container.checkoutCancel ) {
		callback();
	} else {
		container.addEventListener( 'checkout-cancel', callback );
	}
}

/**
 * Run a callback when the checkout place order starts.
 *
 * @param container The container element inside the iframe document.
 * @param callback  The callback to execute when the checkout place order starts.
 */
export function onCheckoutPlaceOrderStart( container: CheckoutContainer, callback: CheckoutEventCallback ) {
	if ( container.checkoutPlaceOrderStart ) {
		callback();
	} else {
		container.addEventListener( 'checkout-place-order-start', callback );
	}
}

/**
 * Run a callback when the checkout place order is processing.
 *
 * @param container The container element inside the iframe document.
 * @param callback  The callback to execute when the checkout place order is processing.
 */
export function onCheckoutPlaceOrderProcessing( container: CheckoutContainer, callback: CheckoutEventCallback ) {
	if ( container.checkoutPlaceOrderProcessing ) {
		callback();
	} else {
		container.addEventListener( 'checkout-place-order-processing', callback );
	}
}

/**
 * Run a callback when the checkout place order fails.
 *
 * @param container The container element inside the iframe document.
 * @param callback  The callback to execute when the checkout place order fails.
 */
export function onCheckoutPlaceOrderError( container: CheckoutContainer, callback: CheckoutEventCallback ) {
	if ( container.checkoutPlaceOrderError ) {
		callback();
	} else {
		container.addEventListener( 'checkout-place-order-error', callback );
	}
}

/**
 * Run a callback when the checkout place order fails in an unrecoverable state.
 *
 * @param container The container element inside the iframe document.
 * @param callback  The callback to execute when the checkout place order fails in an unrecoverable state.
 */
export function onCheckoutPlaceOrderCriticalError( container: CheckoutContainer, callback: CheckoutEventCallback ) {
	if ( container.checkoutPlaceOrderCriticalError ) {
		callback();
	} else {
		container.addEventListener( 'checkout-place-order-critical-error', callback );
	}
}

/**
 * Run a callback when the checkout is complete.
 *
 * @param container The container element inside the iframe document.
 * @param callback  The callback to execute when the checkout is complete.
 */
export function onCheckoutComplete( container: CheckoutContainer, callback: CheckoutEventCallback ) {
	if ( container.checkoutComplete ) {
		callback();
	} else {
		container.addEventListener( 'checkout-complete', callback );
	}
}

/**
 * Trigger a form submit.
 *
 * @param form The form element.
 */
export function triggerFormSubmit( form: HTMLFormElement ): void {
	// form.submit does not trigger submit event listener, so we use requestSubmit.
	form.requestSubmit( form.querySelector< HTMLElement >( 'button[type="submit"]' ) );
}

/**
 * Get checkout data from an element or its ID.
 *
 * @param element HTML element or its ID to get checkout data from.
 *
 * @return Checkout data.
 */
export function getCheckoutData( element: HTMLElement | string | null | undefined ): CheckoutData {
	const container = typeof element === 'string' ? document.getElementById( element ) : element;
	if ( ! container ) {
		console.warn( 'No container found for checkout data' ); // eslint-disable-line no-console
		return {};
	}

	let data: CheckoutData = {};
	// If the element is a form, get the form data.
	if ( container.tagName === 'FORM' ) {
		const formData = new FormData( container as HTMLFormElement );
		data = Object.fromEntries( formData ) as CheckoutData;
	}

	const json = container.dataset.checkout;
	if ( ! json ) {
		console.warn( 'No checkout data found' ); // eslint-disable-line no-console
		return data;
	}

	try {
		data = {
			...data,
			...JSON.parse( json ),
		};
	} catch ( error ) {
		console.warn( 'Error parsing checkout data' ); // eslint-disable-line no-console
	}

	// Overwrite the action type with the value from the URL.
	const url = new URL( container.ownerDocument.defaultView!.location.href );
	if ( url.searchParams.get( 'action_type' ) ) {
		data.action_type = url.searchParams.get( 'action_type' )!;
	}

	return data;
}

/**
 * Get formatted amount for price summary display.
 *
 * @param amount   The amount to format.
 * @param currency The currency to format the amount in.
 *
 * @return The formatted amount.
 */
export function getFormattedAmount( amount: string, currency: string = 'USD' ): string {
	return parseFloat( amount ).toLocaleString( document.documentElement.lang, {
		style: 'currency',
		currency,
		currencyDisplay: 'narrowSymbol',
	} );
}
