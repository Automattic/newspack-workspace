/**
 * Helpers for resolving which form a modal checkout `checkout_button` URL
 * trigger should submit (NPPM-2872).
 *
 * These are pure DOM utilities: they read the page and, for the picker case,
 * check the matching radio. They never submit a form and never depend on the
 * modal bootstrap globals, so they can be unit tested in isolation. The
 * side-effecting submit stays in the modal module.
 */

/**
 * Safely parse a form's `data-checkout` attribute.
 *
 * Picker forms rendered by the subscription tiers UI do not carry a
 * `data-checkout` attribute, so this must never throw on a missing or
 * malformed value.
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
 * When `variationId` is provided, BOTH `product_id` and `variation_id` must
 * match, so an exact request is never served by a button locked to a different
 * variation. When `variationId` is omitted, the form is matched by
 * `product_id` only.
 *
 * @param {Document|HTMLElement} root        The DOM root to search.
 * @param {string}               productId   The requested product ID.
 * @param {string|null}          variationId Optional. The requested variation ID.
 *
 * @return {HTMLFormElement|null} The matching form, or null.
 */
export function findCheckoutButtonForm( root, productId, variationId = null ) {
	const buttons = root.querySelectorAll( '.wp-block-newspack-blocks-checkout-button' );
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
		if ( variationId && String( data.variation_id ) !== String( variationId ) ) {
			return;
		}
		match = form;
	} );
	return match;
}

/**
 * Find the variation picker for a product, check the radio matching the
 * requested variation, and return the picker form to submit.
 *
 * The picker form carries no per-variation `data-checkout`; the selected radio
 * (`input[name="product_id"]`) drives which variation is purchased.
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
	const radios = modal.querySelectorAll( 'input[name="product_id"]' );
	const radio = [ ...radios ].find( input => String( input.value ) === String( variationId ) );
	if ( ! radio ) {
		return null;
	}
	radio.checked = true;
	return radio.closest( 'form' ) || modal.querySelector( `form[target="${ iframeName }"]` );
}

/**
 * Contextual hidden fields a checkout button passes to the modal checkout.
 * These control post-checkout behavior, content gating, and popup attribution,
 * so they must travel with a picker form driven by a URL trigger, mirroring
 * what a manual click copies into the picker.
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
 * Copy contextual hidden fields from a source checkout button form into a
 * target picker form. Existing target fields are not overwritten, and missing
 * or empty source fields are skipped. Never throws on null forms.
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
	fields.forEach( name => {
		if ( targetForm.querySelector( `input[name="${ name }"]` ) ) {
			return;
		}
		const source = sourceForm.querySelector( `input[name="${ name }"]` );
		if ( ! source || ! source.value ) {
			return;
		}
		const input = doc.createElement( 'input' );
		input.type = 'hidden';
		input.name = name;
		input.value = source.value;
		targetForm.prepend( input );
	} );
}

/**
 * Resolve which form a `checkout_button` URL trigger should submit.
 *
 * Order of preference:
 *   1. An exact checkout button form (both product_id and variation_id).
 *   2. The variation picker, selecting the requested variation.
 *   3. A product-only checkout button, only when explicitly enabled.
 *
 * When no specific variation is requested, the form is matched by product_id.
 * Returns null when nothing satisfies the request, so the caller can avoid
 * submitting a different product than the URL asked for.
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
	const hasVariation = !! variationId && String( variationId ) !== String( productId );

	if ( ! hasVariation ) {
		return findCheckoutButtonForm( root, productId, null );
	}

	const exact = findCheckoutButtonForm( root, productId, variationId );
	if ( exact ) {
		return exact;
	}

	const picker = selectPickerForm( root, productId, variationId, options );
	if ( picker ) {
		// Carry the source button's block context (after-success behavior,
		// gating, popup attribution) into the picker, matching what a manual
		// click copies. The source button may be locked to a different
		// variation; it is used only as a context source and never submitted.
		copyContextFields( findCheckoutButtonForm( root, productId, null ), picker );
		return picker;
	}

	if ( allowProductOnlyFallback ) {
		return findCheckoutButtonForm( root, productId, null );
	}

	return null;
}
