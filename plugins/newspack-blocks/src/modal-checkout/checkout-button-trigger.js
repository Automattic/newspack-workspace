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
 * @type {string[]}
 */
export const PICKER_CONTEXT_FIELDS = [
	'after_success_behavior',
	'after_success_url',
	'after_success_button_label',
	'gate_post_id',
	'newspack_popup_id',
	'prompt_title',
];

/**
 * Copy context fields. Target values are preserved, empty source values are
 * skipped, and null forms are ignored.
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
		// The source button may be locked to another variation. Use it only
		// for block context, then submit the picker. The picker is only reached
		// because no button matches the requested variation, so when several
		// buttons share this parent product there is no single correct one to
		// prefer: the first in DOM order supplies the context.
		copyContextFields( findCheckoutButtonForm( root, productId, null ), picker );
		return picker;
	}

	if ( allowProductOnlyFallback ) {
		return findCheckoutButtonForm( root, productId, null );
	}

	return null;
}
