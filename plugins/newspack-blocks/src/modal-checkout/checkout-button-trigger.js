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
 * Container Modal_Checkout::render_url_triggered_block() wraps a synthesized
 * block in. Buttons inside it exist only to serve the URL trigger, so the
 * resolver considers them after every block the page itself carries.
 *
 * @type {string}
 */
export const SYNTHESIZED_CONTAINER_SELECTOR = '.newspack-blocks__url-triggered-checkout';

/**
 * Whether an element belongs to a synthesized (URL-triggered) footer block.
 *
 * @param {HTMLElement} element The element to test.
 *
 * @return {boolean} Whether the element is inside the synthesized container.
 */
const isSynthesized = element => Boolean( element.closest( SYNTHESIZED_CONTAINER_SELECTOR ) );

/**
 * Find a checkout button form matching the requested product.
 *
 * Variation requests are never served by a button locked to a different
 * variation.
 *
 * @param {Document|HTMLElement} root                The DOM root to search.
 * @param {string}               productId           The requested product ID.
 * @param {string|null}          variationId         Optional. The requested variation ID.
 * @param {Object}               options             Options.
 * @param {boolean|null}         options.synthesized Restrict the search: false for
 *                                                   page-authored buttons only, true
 *                                                   for synthesized ones only, null
 *                                                   (default) for both.
 *
 * @return {HTMLFormElement|null} The matching form, or null.
 */
export function findCheckoutButtonForm( root, productId, variationId = null, options = {} ) {
	const { synthesized = null } = options;
	const buttons = root.querySelectorAll( '.wp-block-newspack-blocks-checkout-button' );
	const hasVariation = variationId !== null && variationId !== undefined && String( variationId ) !== '';
	let match = null;
	buttons.forEach( button => {
		if ( match ) {
			return;
		}
		if ( synthesized !== null && isSynthesized( button ) !== synthesized ) {
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
 * @param {Document|HTMLElement} root                The DOM root to search.
 * @param {string}               productId           The requested product ID.
 * @param {Object}               options             Options.
 * @param {boolean|null}         options.synthesized See findCheckoutButtonForm().
 *
 * @return {HTMLFormElement|null} The donor form, or null.
 */
function findContextDonorForm( root, productId, options = {} ) {
	const { synthesized = null } = options;
	const buttons = root.querySelectorAll( '.wp-block-newspack-blocks-checkout-button' );
	let fallback = null;
	let unlocked = null;
	buttons.forEach( button => {
		if ( unlocked ) {
			return;
		}
		if ( synthesized !== null && isSynthesized( button ) !== synthesized ) {
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
 * Read utm params from a query string, mirroring the server-side prefix match
 * (Modal_Checkout::merge_request_utm_params()).
 *
 * @param {string} search The query string (e.g. window.location.search).
 *
 * @return {Object} Map of utm param name → value. Empty values are dropped.
 */
export function readUtmParams( search ) {
	const params = {};
	new URLSearchParams( search ).forEach( ( value, key ) => {
		if ( key.startsWith( 'utm' ) && value ) {
			params[ key ] = value;
		}
	} );
	return params;
}

/**
 * Append utm params to a checkout form as hidden fields.
 *
 * The modal checkout form GET-submits into its iframe, replacing the landing
 * URL's query string, and the URL-trigger path strips the params from the
 * address bar after it fires — so the form's own fields are the only carrier
 * the checkout request can rely on. A field already on the form wins.
 *
 * @param {HTMLFormElement|null} form   The form about to submit.
 * @param {Object}               params Map of utm param name → value.
 *
 * @return {void}
 */
export function appendUtmFields( form, params ) {
	if ( ! form || ! params ) {
		return;
	}
	const doc = form.ownerDocument;
	Object.keys( params ).forEach( name => {
		// The name comes from the landing URL, so it must never be interpolated
		// into a selector — a key carrying selector syntax would throw and abort
		// the submission. The form's own controls collection checks it safely.
		if ( ! params[ name ] || form.elements.namedItem( name ) ) {
			return;
		}
		const input = doc.createElement( 'input' );
		input.type = 'hidden';
		input.name = name;
		input.value = params[ name ];
		form.prepend( input );
	} );
}

/**
 * Link params that only reach the checkout as fields on the submitted form.
 *
 * A promotional URL carries these for the block the server synthesizes; when a
 * page-authored form wins the resolution instead, whichever of them that form
 * does not carry never reaches the checkout.
 *
 * @type {string[]}
 */
export const LINK_CONTEXT_PARAMS = [ 'coupon', 'after_success_behavior', 'after_success_url', 'after_success_button_label' ];

/**
 * Name the link params the resolved form has no field for.
 *
 * The trigger submits the form as-is, so a param without a matching field is
 * dropped — the caller warns instead of letting that happen silently.
 *
 * @param {HTMLFormElement|null} form   The form about to be submitted.
 * @param {string}               search The landing page query string.
 *
 * @return {string[]} Names of params the form will not carry.
 */
export function getDroppedLinkContext( form, search ) {
	const params = new URLSearchParams( search );
	return LINK_CONTEXT_PARAMS.filter( name => params.get( name ) && ! ( form && form.elements.namedItem( name ) ) );
}

/**
 * Resolve which form a `checkout_button` URL trigger should submit.
 *
 * Page-authored forms outrank the synthesized footer form at every step, so a
 * block an editor configured — with its coupon and after-checkout context —
 * always wins over the copy rendered to serve the trigger. Strict order: exact
 * page button, picker fed by a page button's context, exact synthesized button,
 * picker fed by the synthesized context, then the explicit product-only
 * fallback. Returning null prevents silent substitution.
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
		// parent product, the first page-authored one in DOM order is used
		// (along with its context); the synthesized form serves only when the
		// page carries none.
		return (
			findCheckoutButtonForm( root, productId, null, { synthesized: false } ) ||
			findCheckoutButtonForm( root, productId, null, { synthesized: true } )
		);
	}

	const exactPage = findCheckoutButtonForm( root, productId, variationId, { synthesized: false } );
	if ( exactPage ) {
		return exactPage;
	}

	// A page button for this product exists but none is locked to the requested
	// variation: let the picker serve it with that page button's context. This
	// deliberately outranks a synthesized exact match — the page block's coupon
	// and after-checkout settings are the editor's, and they keep applying.
	const pageDonor = findContextDonorForm( root, productId, { synthesized: false } );
	if ( pageDonor ) {
		const pagePicker = selectPickerForm( root, productId, variationId, options );
		if ( pagePicker ) {
			copyContextFields( pageDonor, pagePicker );
			return pagePicker;
		}
	}

	const exactSynthesized = findCheckoutButtonForm( root, productId, variationId, { synthesized: true } );
	if ( exactSynthesized ) {
		return exactSynthesized;
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
		return (
			findCheckoutButtonForm( root, productId, null, { synthesized: false } ) ||
			findCheckoutButtonForm( root, productId, null, { synthesized: true } )
		);
	}

	return null;
}
