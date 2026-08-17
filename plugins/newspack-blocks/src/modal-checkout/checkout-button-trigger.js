/**
 * Resolve the form submitted by a modal checkout `checkout_button` URL trigger.
 */

/**
 * Parse a form's `data-checkout` attribute without throwing.
 * Picker forms do not carry `data-checkout`.
 *
 * @param {HTMLElement|null} form The form element.
 *
 * @return {Object|null} The parsed checkout data, or null.
 */
export function readCheckoutData( form ) {
	const raw = form && form.dataset ? form.dataset.checkout : null;
	if ( ! raw ) {
		return null;
	}
	try {
		return JSON.parse( raw );
	} catch ( e ) {
		return null;
	}
}

/**
 * Find a checkout button form matching the requested product.
 *
 * Variation requests are never served by a button locked to a different
 * variation.
 *
 * @param {Document|HTMLElement} root        The DOM root to search.
 * @param {string}               productId   The requested product ID.
 * @param {string|null}          variationId Optional. The requested variation ID.
 *
 * @return {HTMLFormElement|null} The matching form, or null.
 */
export function findCheckoutButtonForm( root, productId, variationId = null ) {
	const buttons = root.querySelectorAll( '.wp-block-newspack-blocks-checkout-button' );
	const hasVariation = variationId !== null && variationId !== undefined && String( variationId ) !== '';
	let match = null;
	buttons.forEach( button => {
		if ( match ) {
			return;
		}
		const form = button.querySelector( 'form' );
		const data = readCheckoutData( form );
		if ( ! data ) {
			return;
		}
		if ( String( data.product_id ) !== String( productId ) ) {
			return;
		}
		if ( hasVariation && String( data.variation_id ) !== String( variationId ) ) {
			return;
		}
		match = form;
	} );
	return match;
}

/**
 * Find the button whose context a picker submission should inherit.
 *
 * Prefers a button that is not locked to a single variation: it stands for the
 * whole product, so its context — including any attached coupon — applies to
 * whichever variation the reader picks. A locked button was configured for one
 * specific variation and is only used when nothing better exists.
 *
 * @param {Document|HTMLElement} root      The DOM root to search.
 * @param {string}               productId The requested product ID.
 *
 * @return {HTMLFormElement|null} The donor form, or null.
 */
function findContextDonorForm( root, productId ) {
	const buttons = root.querySelectorAll( '.wp-block-newspack-blocks-checkout-button' );
	let fallback = null;
	let unlocked = null;
	buttons.forEach( button => {
		if ( unlocked ) {
			return;
		}
		const form = button.querySelector( 'form' );
		const data = readCheckoutData( form );
		if ( ! data || String( data.product_id ) !== String( productId ) ) {
			return;
		}
		if ( ! data.variation_id ) {
			unlocked = form;
			return;
		}
		fallback = fallback || form;
	} );
	return unlocked || fallback;
}

/**
 * Select the requested variation in a product picker.
 * Picker forms use the selected radio value instead of `data-checkout`.
 *
 * Side effect: when a matching radio is found it is checked (mutating the DOM)
 * before the form is returned, so the form submits the requested variation.
 *
 * @param {Document|HTMLElement} root                              The DOM root to search.
 * @param {string}               productId                         The parent product ID of the picker.
 * @param {string}               variationId                       The requested variation ID.
 * @param {Object}               options                           Options.
 * @param {string}               options.variationModalClassPrefix Class of the picker container.
 * @param {string}               options.iframeName                The checkout iframe name (form target).
 *
 * @return {HTMLFormElement|null} The picker form, or null.
 */
export function selectPickerForm( root, productId, variationId, options = {} ) {
	const { variationModalClassPrefix, iframeName } = options;
	const modals = root.querySelectorAll( `.${ variationModalClassPrefix }` );
	const modal = [ ...modals ].find( el => String( el.dataset.productId ) === String( productId ) );
	if ( ! modal ) {
		return null;
	}
	const forms = modal.querySelectorAll( 'form' );
	const form = iframeName ? [ ...forms ].find( el => el.getAttribute( 'target' ) === iframeName ) : forms[ 0 ];
	if ( ! form ) {
		return null;
	}
	const radios = form.querySelectorAll( 'input[type="radio"][name="product_id"]' );
	const radio = [ ...radios ].find( input => String( input.value ) === String( variationId ) );
	if ( ! radio ) {
		return null;
	}
	radio.checked = true;
	return form;
}

/**
 * Hidden fields copied from a source checkout button to a picker submission.
 *
 * The picker form is rendered once per variable product in the footer and is
 * submitted instead of the button's own form, so anything the block attached to
 * that button — after-success behavior, attribution, the auto-applied coupon —
 * is lost unless it is listed here. Shared with modal.js so the click path and
 * the URL-trigger path carry the same context.
 *
 * @type {string[]}
 */
export const PICKER_CONTEXT_FIELDS = [
	'after_success_behavior',
	'after_success_url',
	'after_success_button_label',
	'after_success_token',
	'gate_post_id',
	'newspack_popup_id',
	'prompt_title',
	'coupon',
];

/**
 * Stamp context fields onto a picker form from the button that opened it.
 *
 * Authoritative, unlike copyContextFields(): the picker is rendered once per
 * parent product and shared by every button targeting it, and nothing clears it
 * when the modal closes, so each open must overwrite whatever the previous one
 * left behind. An absent value removes the field rather than preserving a stale
 * one — for `coupon` that is the difference between the reader's discount and
 * a discount attached to a different button.
 *
 * @param {HTMLFormElement|null} targetForm Picker form to stamp.
 * @param {Object|null}          data       Checkout data read from the clicked button's form.
 * @param {string[]}             fields     Field names to stamp.
 *
 * @return {void}
 */
export function applyContextFields( targetForm, data, fields = PICKER_CONTEXT_FIELDS ) {
	if ( ! targetForm || ! data ) {
		return;
	}
	const doc = targetForm.ownerDocument;
	fields.forEach( name => {
		// Drop whatever a previous open left behind before writing this one's value.
		targetForm.querySelectorAll( `input[name="${ name }"]` ).forEach( input => input.remove() );
		const raw = data[ name ];
		const value = raw === undefined || raw === null ? '' : String( raw );
		if ( ! value ) {
			return;
		}
		const input = doc.createElement( 'input' );
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		targetForm.prepend( input );
	} );
}

/**
 * Copy context fields. Target values are preserved, empty source values are
 * skipped, and null forms are ignored.
 *
 * Used by the URL-trigger path, which runs once on load before any click. The
 * click path uses applyContextFields() instead and overwrites anything left
 * here, so preserving existing values cannot strand a stale coupon.
 *
 * @param {HTMLFormElement|null} sourceForm Checkout button form to read from.
 * @param {HTMLFormElement|null} targetForm Picker form to copy into.
 * @param {string[]}             fields     Field names to copy.
 *
 * @return {void}
 */
export function copyContextFields( sourceForm, targetForm, fields = PICKER_CONTEXT_FIELDS ) {
	if ( ! sourceForm || ! targetForm ) {
		return;
	}
	const doc = targetForm.ownerDocument;
	const sourceData = new FormData( sourceForm );
	fields.forEach( name => {
		if ( targetForm.querySelector( `input[name="${ name }"]` ) ) {
			return;
		}
		const values = sourceData.getAll( name ).filter( value => typeof value === 'string' && value );
		if ( ! values.length ) {
			return;
		}
		const input = doc.createElement( 'input' );
		input.type = 'hidden';
		input.name = name;
		input.value = values[ values.length - 1 ];
		targetForm.prepend( input );
	} );
}

/**
 * Resolve which form a `checkout_button` URL trigger should submit.
 *
 * Strict order: exact button, picker, then explicit product-only fallback.
 * Returning null prevents silent substitution.
 *
 * @param {Document|HTMLElement} root        The DOM root to search.
 * @param {string}               productId   The requested product ID.
 * @param {string|null}          variationId Optional. The requested variation ID.
 * @param {Object}               options     Options (see selectPickerForm) plus
 *                                           `allowProductOnlyFallback` (default false).
 *
 * @return {HTMLFormElement|null} The form to submit, or null.
 */
export function resolveCheckoutButtonForm( root, productId, variationId, options = {} ) {
	const { allowProductOnlyFallback = false } = options;
	const hasVariation = variationId !== null && variationId !== undefined && String( variationId ) !== '';

	if ( ! hasVariation ) {
		// No variation requested. If several buttons on the page share this
		// parent product, the first in DOM order is used (along with its
		// context); the URL gives no signal to prefer one over another.
		return findCheckoutButtonForm( root, productId, null );
	}

	const exact = findCheckoutButtonForm( root, productId, variationId );
	if ( exact ) {
		return exact;
	}

	const picker = selectPickerForm( root, productId, variationId, options );
	if ( picker ) {
		// The picker is only reached because no button matches the requested
		// variation, so the context has to come from some other button for this
		// product. Since PICKER_CONTEXT_FIELDS now carries `coupon`, that choice
		// decides a discount: a button locked to a variation was configured for
		// that variation, so its coupon should not follow the reader to a
		// different one. Prefer an unlocked button, which stands for the whole
		// product, and fall back to DOM order only when there isn't one.
		copyContextFields( findContextDonorForm( root, productId ), picker );
		return picker;
	}

	if ( allowProductOnlyFallback ) {
		return findCheckoutButtonForm( root, productId, null );
	}

	return null;
}
