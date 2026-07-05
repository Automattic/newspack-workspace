/**
 * Resolve the form submitted by a modal checkout `checkout_button` URL trigger.
 */

/**
 * Options controlling how a `checkout_button` URL trigger resolves its form.
 */
type CheckoutButtonFormOptions = {
	variationModalClassPrefix?: string;
	iframeName?: string;
	allowProductOnlyFallback?: boolean;
};

/**
 * Parse a form's `data-checkout` attribute without throwing.
 * Picker forms do not carry `data-checkout`.
 *
 * @param form The form element.
 *
 * @return The parsed checkout data, or null.
 */
export function readCheckoutData( form: HTMLElement | null ): Record< string, unknown > | null {
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
 * @param root        The DOM root to search.
 * @param productId   The requested product ID.
 * @param variationId Optional. The requested variation ID.
 *
 * @return The matching form, or null.
 */
export function findCheckoutButtonForm(
	root: Document | HTMLElement,
	productId: string,
	variationId: string | null | undefined = null
): HTMLFormElement | null {
	const buttons = root.querySelectorAll( '.wp-block-newspack-blocks-checkout-button' );
	const hasVariation = variationId !== null && variationId !== undefined && String( variationId ) !== '';
	let match: HTMLFormElement | null = null;
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
 * @param root                              The DOM root to search.
 * @param productId                         The parent product ID of the picker.
 * @param variationId                       The requested variation ID.
 * @param options                           Options.
 * @param options.variationModalClassPrefix Class of the picker container.
 * @param options.iframeName                The checkout iframe name (form target).
 *
 * @return The picker form, or null.
 */
export function selectPickerForm(
	root: Document | HTMLElement,
	productId: string,
	variationId: string | null | undefined,
	options: CheckoutButtonFormOptions = {}
): HTMLFormElement | null {
	const { variationModalClassPrefix, iframeName } = options;
	const modals = root.querySelectorAll< HTMLElement >( `.${ variationModalClassPrefix }` );
	const modal = [ ...modals ].find( el => String( el.dataset.productId ) === String( productId ) );
	if ( ! modal ) {
		return null;
	}
	const forms = modal.querySelectorAll( 'form' );
	const form = iframeName ? [ ...forms ].find( el => el.getAttribute( 'target' ) === iframeName ) : forms[ 0 ];
	if ( ! form ) {
		return null;
	}
	const radios = form.querySelectorAll< HTMLInputElement >( 'input[type="radio"][name="product_id"]' );
	const radio = [ ...radios ].find( input => String( input.value ) === String( variationId ) );
	if ( ! radio ) {
		return null;
	}
	radio.checked = true;
	return form;
}

/**
 * Hidden fields copied from a source checkout button to a picker submission.
 */
export const PICKER_CONTEXT_FIELDS: string[] = [
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
 * @param sourceForm Checkout button form to read from.
 * @param targetForm Picker form to copy into.
 * @param fields     Field names to copy.
 */
export function copyContextFields(
	sourceForm: HTMLFormElement | null,
	targetForm: HTMLFormElement | null,
	fields: string[] = PICKER_CONTEXT_FIELDS
): void {
	if ( ! sourceForm || ! targetForm ) {
		return;
	}
	const doc = targetForm.ownerDocument;
	const sourceData = new FormData( sourceForm );
	fields.forEach( name => {
		if ( targetForm.querySelector( `input[name="${ name }"]` ) ) {
			return;
		}
		const values = sourceData.getAll( name ).filter( value => typeof value === 'string' && value ) as string[];
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
 * @param root        The DOM root to search.
 * @param productId   The requested product ID.
 * @param variationId Optional. The requested variation ID.
 * @param options     Options (see selectPickerForm) plus
 *                    `allowProductOnlyFallback` (default false).
 *
 * @return The form to submit, or null.
 */
export function resolveCheckoutButtonForm(
	root: Document | HTMLElement,
	productId: string,
	variationId: string | null | undefined,
	options: CheckoutButtonFormOptions = {}
): HTMLFormElement | null {
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
