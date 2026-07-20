/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param  callback A function to execute after the DOM is ready.
 * @return {void}
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
 * Format time in MM:SS format.
 *
 * @param time Time in seconds.
 */
export function formatTime( time: number ) {
	const minutes = Math.floor( time / 60 );
	const seconds = time % 60;
	return `${ minutes }:${ seconds < 10 ? '0' : '' }${ seconds }`;
}

/**
 * Converts FormData into an object.
 *
 * @param formData       The form data to convert.
 * @param includedFields Form fields to include.
 *
 * @return The converted form data.
 */
export function convertFormDataToObject( formData: FormData, includedFields: string[] = [] ) {
	return Array.from( formData.entries() ).reduce( ( acc: Record< string, FormDataEntryValue | FormDataEntryValue[] >, [ key, val ] ) => {
		if ( ! includedFields.includes( key ) ) {
			return acc;
		}
		if ( key.indexOf( '[]' ) > -1 ) {
			key = key.replace( '[]', '' );
			acc[ key ] = acc[ key ] || [];
			// Multi-value keys are only ever seeded with an array (line above).
			( acc[ key ] as FormDataEntryValue[] ).push( val );
		} else {
			acc[ key ] = val;
		}
		return acc;
	}, {} );
}

/**
 * The callback populating a reader activity's data payload.
 */
type ActivityDataCallback = ( element: Element ) => Record< string, unknown >;

/**
 * Register a reader activity dispatch on an element event.
 *
 * @param element The element to register the activity on. Can either be the element node or a string for the element selector.
 * @param action  The action to dispatch an activity for.
 * @param cb      The callback to populate the activity data.
 * @param event   The element event to listen for. Defaults to `submit` for form elements and `click` for other elements.
 */
export function registerElementActivity( element: string | Element | Element[] | null, action: string, cb?: ActivityDataCallback, event?: string ) {
	window.newspackRAS = window.newspackRAS || [];
	if ( typeof element === 'string' ) {
		element = [ ...document.querySelectorAll( element ) ];
	}

	if ( element && ! Array.isArray( element ) ) {
		element = [ element ];
	}

	if ( ! element?.length ) {
		return;
	}

	// If no callback is provided, use a noop.
	if ( ! cb ) {
		cb = () => ( {} );
	}
	const activityDataCallback = cb;

	element.forEach( el => {
		el.addEventListener( event || ( el.tagName === 'FORM' ? 'submit' : 'click' ), ev => {
			// Wait for the event to be processed.
			setTimeout( () => {
				// If the event was not prevented, dispatch the activity.
				// Form submissions will not consider the default prevented because they
				// are commonly ajaxified.
				if ( el.tagName === 'FORM' || ! ev.defaultPrevented ) {
					window.newspackRAS.push( [ action, activityDataCallback( el ) ] );
				}
			} );
		} );
	} );
}

/**
 * Register an activity dispatch on checkout submission.
 *
 * @param action The action to dispatch an activity for.
 * @param cb     The callback to populate the activity data.
 */
export function registerCheckoutActivity( action: string, cb?: ActivityDataCallback ) {
	// Woo Block checkout is react, so we need to wait for the form to be rendered.
	wp?.hooks?.addAction( 'experimental__woocommerce_blocks-checkout-render-checkout-form', 'newspack/my-account/activity', () => {
		registerElementActivity( '.wc-block-components-checkout-place-order-button', action, cb );
	} );
	// Shortcode checkout.
	registerElementActivity( 'form[name="checkout"]', action, cb );
}
