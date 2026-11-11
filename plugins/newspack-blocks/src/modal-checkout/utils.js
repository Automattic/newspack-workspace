/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param {Function} callback A function to execute after the DOM is ready.
 * @return {void}
 */
export function domReady( callback ) {
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
 * Run a callback once every deferred script on the page has executed.
 *
 * Deferred scripts run after parsing ends and before DOMContentLoaded, and
 * this bundle is async, so it can execute in between — domReady() only waits
 * for parsing. readyState is `interactive` on both sides of DOMContentLoaded,
 * so in that state navigation timing settles whether the event already fired.
 *
 * @param {Function} callback The function to run.
 * @return {void}
 */
export function afterDeferredScripts( callback ) {
	const { readyState } = document;
	if ( readyState === 'complete' || ( readyState === 'interactive' && hasDomContentLoadedFired() ) ) {
		return void callback();
	}
	document.addEventListener( 'DOMContentLoaded', callback, { once: true } );
}

/**
 * Whether DOMContentLoaded has fired, according to navigation timing.
 *
 * Without a navigation entry there is nothing to consult, and waiting would
 * risk an event that never comes: report it as fired, which degrades to
 * running immediately.
 *
 * @return {boolean} Whether the event has fired.
 */
function hasDomContentLoadedFired() {
	const [ navigation ] = window.performance?.getEntriesByType?.( 'navigation' ) || [];
	return ! navigation || navigation.domContentLoadedEventStart > 0;
}

// The focus rules of newspack-ui's modals (newspack-plugin's focus.js), which this
// plugin can't depend on.
const FOCUSABLE = 'a[href], button, iframe, input, select, textarea, [tabindex]';
const FIELDS = 'input:not([type="button"], [type="submit"], [type="reset"], [type="image"], [readonly]), select, textarea:not([readonly])';

/**
 * The elements Tab can reach inside an element, in order.
 *
 * @param {HTMLElement} root The element to look in.
 *
 * @return {HTMLElement[]} The reachable elements.
 */
export function getTabbableElements( root ) {
	const view = root.ownerDocument.defaultView;
	return [ ...root.querySelectorAll( FOCUSABLE ) ].filter(
		element =>
			! element.disabled &&
			'hidden' !== element.type &&
			element.tabIndex >= 0 &&
			! element.closest( '[inert], [aria-hidden="true"]' ) &&
			element.getClientRects().length > 0 &&
			'hidden' !== view.getComputedStyle( element ).visibility
	);
}

/**
 * The first field Tab can reach inside an element.
 *
 * @param {HTMLElement} root The element to look in.
 *
 * @return {HTMLElement|null} The field.
 */
export function getFirstField( root ) {
	return getTabbableElements( root ).find( element => element.matches( FIELDS ) ) || null;
}

/**
 * Where focus lands in a checkout screen: its first field, else its first action.
 *
 * @param {HTMLElement} root The element to look in.
 *
 * @return {HTMLElement|null} The element to focus.
 */
export function getInitialFocus( root ) {
	return getFirstField( root ) || getTabbableElements( root )[ 0 ] || null;
}

// Payment providers put card fields on the page too. Only their overlays, fixed over it,
// are challenges. Matches the frames newspack-ui's modals step down for.
const PAYMENT_FRAMES = 'iframe[src*="js.stripe.com"], iframe[src*="hooks.stripe.com"], iframe[src*="paypal.com"]';

/**
 * Whether a payment challenge, like 3D Secure, is showing over a page.
 *
 * @param {Document} doc The page's document.
 *
 * @return {boolean} Whether one is showing.
 */
export function isPaymentChallengeShown( doc ) {
	const view = doc.defaultView;
	const isInFixedLayer = element => {
		for ( let node = element; node && node !== doc.body; node = node.parentElement ) {
			if ( 'fixed' === view.getComputedStyle( node ).position ) {
				return true;
			}
		}
		return false;
	};
	return [ ...doc.querySelectorAll( PAYMENT_FRAMES ) ].some(
		frame => frame.getClientRects().length > 0 && 'hidden' !== view.getComputedStyle( frame ).visibility && isInFixedLayer( frame )
	);
}

const PAYMENT_SECTION = '.woocommerce-checkout-payment';

/**
 * Note the payment control that has focus, so it can be found again once a checkout
 * refresh has replaced the payment section.
 *
 * @param {Document} doc The page's document.
 *
 * @return {{id: string, name: string}|null} The control's id, and its group's name if it is a radio. Null when focus is elsewhere.
 */
export function getPaymentFocus( doc ) {
	const { activeElement } = doc;
	if ( ! activeElement?.closest?.( PAYMENT_SECTION ) ) {
		return null;
	}
	return {
		id: activeElement.id || '',
		name: 'radio' === activeElement.type ? activeElement.name : '',
	};
}

/**
 * Return focus to the payment control noted before a checkout refresh, if the refresh
 * dropped it to the body. A radio goes to the checked one in its group; otherwise the
 * control with the same id, then the checked payment method.
 *
 * @param {Document} doc          The page's document.
 * @param {Object}   focused      What getPaymentFocus() noted.
 * @param {string}   focused.id   The control's id.
 * @param {string}   focused.name The radio group's name, if the control is a radio.
 *
 * @return {HTMLElement|null} The control that took focus.
 */
export function restorePaymentFocus( doc, { id, name } ) {
	const { activeElement } = doc;
	if ( activeElement && activeElement !== doc.body ) {
		return null;
	}
	const candidates = [
		name && [ ...doc.querySelectorAll( `${ PAYMENT_SECTION } input[type="radio"]:checked` ) ].find( input => input.name === name ),
		id && doc.getElementById( id ),
		doc.querySelector( 'input[name="payment_method"]:checked' ),
	];
	for ( const candidate of candidates ) {
		if ( candidate ) {
			candidate.focus( { preventScroll: true } );
			if ( doc.activeElement === candidate ) {
				return candidate;
			}
		}
	}
	return null;
}

/**
 * Put the order summary between the payment methods and the place order button,
 * or above the payment box when the order needs no payment.
 *
 * A checkout update replaces `#payment` whole, taking a summary placed inside it
 * along. A detached summary goes back with the update's review table, because
 * WooCommerce only refreshes the table while it is in the document. A summary
 * already in place, or one that holds `#payment` itself (classic themes other
 * than Newspack's), is not moved, so focus inside it survives the update.
 *
 * @param {Document}     doc           The checkout document.
 * @param {Element|null} summary       The summary placed last time, if any.
 * @param {string}       [reviewTable] The update's review table fragment, if any.
 *
 * @return {Element|null} The summary, or null when the checkout has none.
 */
export function placeOrderSummary( doc, summary, reviewTable ) {
	const attached = doc.querySelector( '.order-review-wrapper' );
	const current = attached || summary;
	if ( ! current ) {
		return null;
	}
	if ( ! attached && reviewTable ) {
		const table = current.querySelector( '.woocommerce-checkout-review-order-table' );
		if ( table ) {
			table.outerHTML = reviewTable;
		}
	}
	const paymentMethods = doc.querySelector( '.payment_methods' );
	if ( paymentMethods ) {
		if ( paymentMethods.nextElementSibling !== current && ! current.contains( paymentMethods ) ) {
			paymentMethods.after( current );
		}
		return current;
	}
	const payment = doc.querySelector( '#payment' );
	if ( payment && payment.previousElementSibling !== current && ! current.contains( payment ) ) {
		payment.before( current );
	}
	return current;
}

/**
 * Create a hidden input field.
 *
 * @param {string} name  The name of the input field.
 * @param {string} value The value of the input field. Optional.
 *
 * @return {HTMLInputElement} The hidden input element.
 */
export function createHiddenInput( name, value = null ) {
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
 * @param {HTMLIFrameElement} iframe  The iframe element.
 * @param {Function}          cb      The callback to execute when the iframe is ready.
 * @param {Function}          onReset The callback to execute when the iframe is reset.
 *
 * @return {void}
 */
export function iframeReady( iframe, cb, onReset ) {
	iframe._ready = false;
	if ( iframe._readyTimer ) {
		clearTimeout( iframe._readyTimer );
	}
	let fired = false;
	let lastLocation = '';

	function ready() {
		if ( ! fired ) {
			fired = true;
			clearTimeout( iframe._readyTimer );
			cb.call( this );
		}
	}

	function readyState() {
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
 * @param {Object}   container The container element inside the iframe document.
 * @param {Function} callback  The callback to execute when the checkout is ready.
 */
export function onCheckoutReady( container, callback ) {
	if ( container.checkoutReady ) {
		callback();
	} else {
		container.addEventListener( 'checkout-ready', callback );
	}
}

/**
 * Run a callback when the checkout place order starts.
 *
 * @param {Object}   container The container element inside the iframe document.
 * @param {Function} callback  The callback to execute when the checkout place order starts.
 */
export function onCheckoutPlaceOrderStart( container, callback ) {
	if ( container.checkoutPlaceOrderStart ) {
		callback();
	} else {
		container.addEventListener( 'checkout-place-order-start', callback );
	}
}

/**
 * Run a callback when the checkout place order is processing.
 *
 * @param {Object}   container The container element inside the iframe document.
 * @param {Function} callback  The callback to execute when the checkout place order is processing.
 */
export function onCheckoutPlaceOrderProcessing( container, callback ) {
	if ( container.checkoutPlaceOrderProcessing ) {
		callback();
	} else {
		container.addEventListener( 'checkout-place-order-processing', callback );
	}
}

/**
 * Run a callback when the order is placed and the gateway shows its own step in place.
 *
 * @param {Object}   container The container element inside the iframe document.
 * @param {Function} callback  The callback to execute when the gateway takes over.
 */
export function onCheckoutPlaceOrderGatewayAction( container, callback ) {
	container.addEventListener( 'checkout-place-order-gateway-action', callback );
}

/**
 * Run a callback when the checkout place order fails.
 *
 * @param {Object}   container The container element inside the iframe document.
 * @param {Function} callback  The callback to execute when the checkout place order fails.
 */
export function onCheckoutPlaceOrderError( container, callback ) {
	if ( container.checkoutPlaceOrderError ) {
		callback();
	} else {
		container.addEventListener( 'checkout-place-order-error', callback );
	}
}

/**
 * Run a callback when the checkout place order fails in an unrecoverable state.
 *
 * @param {Object}   container The container element inside the iframe document.
 * @param {Function} callback  The callback to execute when the checkout place order fails in an unrecoverable state.
 */
export function onCheckoutPlaceOrderCriticalError( container, callback ) {
	if ( container.checkoutPlaceOrderCriticalError ) {
		callback();
	} else {
		container.addEventListener( 'checkout-place-order-critical-error', callback );
	}
}

/**
 * Run a callback when the checkout is complete.
 *
 * @param {Object}   container The container element inside the iframe document.
 * @param {Function} callback  The callback to execute when the checkout is complete.
 */
export function onCheckoutComplete( container, callback ) {
	if ( container.checkoutComplete ) {
		callback();
	} else {
		container.addEventListener( 'checkout-complete', callback );
	}
}

/**
 * Trigger a form submit.
 *
 * @param {HTMLFormElement} form The form element.
 *
 * @return {void}
 */
export function triggerFormSubmit( form ) {
	// form.submit does not trigger submit event listener, so we use requestSubmit.
	form.requestSubmit( form.querySelector( 'button[type="submit"]' ) );
}

/**
 * Get checkout data from an element or its ID.
 *
 * @param {HTMLElement|string} element HTML element or its ID to get checkout data from.
 *
 * @return {Object} Checkout data.
 */
export function getCheckoutData( element ) {
	const container = typeof element === 'string' ? document.getElementById( element ) : element;
	if ( ! container ) {
		console.warn( 'No container found for checkout data' ); // eslint-disable-line no-console
		return {};
	}

	let data = {};
	// If the element is a form, get the form data.
	if ( container.tagName === 'FORM' ) {
		const formData = new FormData( container );
		data = Object.fromEntries( formData );
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
	const url = new URL( container.ownerDocument.defaultView.location.href );
	if ( url.searchParams.get( 'action_type' ) ) {
		data.action_type = url.searchParams.get( 'action_type' );
	}

	return data;
}

/**
 * Get formatted amount for price summary display.
 *
 * @param {number} amount   The amount to format.
 * @param {string} currency The currency to format the amount in.
 *
 * @return {string} The formatted amount.
 */
export function getFormattedAmount( amount, currency = 'USD' ) {
	return parseFloat( amount ).toLocaleString( document.documentElement.lang, {
		style: 'currency',
		currency,
		currencyDisplay: 'narrowSymbol',
	} );
}

/**
 * Whether a place-order redirect keeps the shopper on the checkout page.
 *
 * Gateways that need the shopper for another step (a 3D Secure challenge, a
 * voucher, a wallet QR code) answer a successful place-order request with a
 * fragment on the current page, and their own script then shows that step in
 * place. Any other redirect leaves the checkout.
 *
 * @param {string} redirect   The `redirect` value from the place-order response.
 * @param {string} currentUrl The checkout page URL.
 *
 * @return {boolean} Whether the redirect only changes the fragment.
 */
export function isSameDocumentRedirect( redirect, currentUrl ) {
	if ( ! redirect || typeof redirect !== 'string' ) {
		return false;
	}
	let target, current;
	try {
		target = new URL( redirect, currentUrl );
		current = new URL( currentUrl );
	} catch ( error ) {
		return false;
	}
	return target.hash.length > 1 && target.origin === current.origin && target.pathname === current.pathname && target.search === current.search;
}

const FRAME_PROCESSING_SCREEN_ATTRIBUTE = 'data-newspack-processing-screen';

/**
 * Show the processing screen inside the checkout document.
 *
 * Stripe.js draws its in-place steps (e.g. a 3D Secure challenge) in a layer
 * above everything else in the checkout document, so a processing screen
 * mounted here stays behind them without covering them. Reuses the screen
 * already in the document, if any, and replaces its message.
 *
 * @param {Document} doc       The checkout document.
 * @param {string}   className The spinner class name.
 * @param {Node}     content   The processing message node, moved into the screen.
 *
 * @return {HTMLElement|null} The screen, or null without a document.
 */
export function mountFrameProcessingScreen( doc, className, content ) {
	if ( ! doc?.body ) {
		return null;
	}
	let screen = doc.querySelector( `[${ FRAME_PROCESSING_SCREEN_ATTRIBUTE }]` );
	if ( ! screen ) {
		screen = doc.createElement( 'div' );
		screen.className = className;
		screen.setAttribute( FRAME_PROCESSING_SCREEN_ATTRIBUTE, '' );
		screen.appendChild( doc.createElement( 'span' ) );
		doc.body.appendChild( screen );
	}
	if ( content ) {
		[ ...screen.children ].filter( child => child.tagName !== 'SPAN' ).forEach( child => child.remove() );
		screen.appendChild( content );
	}
	return screen;
}

/**
 * Whether the checkout document is showing the processing screen.
 *
 * @param {Document} doc The checkout document.
 *
 * @return {boolean} Whether the screen is mounted.
 */
export function hasFrameProcessingScreen( doc ) {
	return !! doc?.querySelector( `[${ FRAME_PROCESSING_SCREEN_ATTRIBUTE }]` );
}

/**
 * Remove the processing screen from the checkout document.
 *
 * @param {Document} doc The checkout document.
 */
export function removeFrameProcessingScreen( doc ) {
	doc?.querySelector( `[${ FRAME_PROCESSING_SCREEN_ATTRIBUTE }]` )?.remove();
}

/**
 * Keep clicks on a disabled close button from reaching any close handler.
 *
 * Other scripts close the modal from their own listeners on the button (the
 * newspack-ui modal handler sets the modal's state directly), so the click is
 * stopped on its way down, before any of them run.
 *
 * @param {HTMLElement} root     The modal.
 * @param {string}      selector The close button selector.
 */
export function preventDisabledCloseClicks( root, selector ) {
	root.addEventListener(
		'click',
		ev => {
			if ( ev.target.closest?.( selector )?.getAttribute( 'aria-disabled' ) === 'true' ) {
				ev.preventDefault();
				ev.stopPropagation();
			}
		},
		true
	);
}

/**
 * Disable or re-enable the modal close buttons.
 *
 * Uses `aria-disabled` rather than `disabled` so the button stays in the focus
 * order the modal's focus trap relies on.
 *
 * @param {NodeList|Array} buttons  The close buttons.
 * @param {boolean}        disabled Whether to disable them.
 */
export function setCloseButtonsDisabled( buttons, disabled ) {
	buttons.forEach( button => {
		if ( disabled ) {
			button.setAttribute( 'aria-disabled', 'true' );
		} else {
			button.removeAttribute( 'aria-disabled' );
		}
	} );
}

/**
 * Run a callback once a modal that was just closed has finished transitioning out.
 * It runs straight away when nothing in the modal is transitioning. Looping
 * animations, such as a spinner's, are not waited for.
 *
 * @param {HTMLElement} el       The modal container, already closed.
 * @param {Function}    callback The callback.
 * @return {Function} Runs the callback now, if it hasn't run yet.
 */
export function afterModalExit( el, callback ) {
	let done = false;
	let timer = null;
	const finish = () => {
		if ( ! done ) {
			done = true;
			clearTimeout( timer );
			callback();
		}
	};
	const getEndTime = animation => animation.effect?.getComputedTiming().endTime;
	const animations = ( typeof el.getAnimations === 'function' ? el.getAnimations( { subtree: true } ) : [] ).filter( animation =>
		Number.isFinite( getEndTime( animation ) )
	);
	if ( ! animations.length ) {
		finish();
		return finish;
	}
	timer = setTimeout( finish, Math.max( ...animations.map( getEndTime ) ) + 100 );
	Promise.allSettled( animations.map( animation => animation.finished ) ).then( finish );
	return finish;
}
