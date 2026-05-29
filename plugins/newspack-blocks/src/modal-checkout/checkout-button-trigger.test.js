/**
 * Tests for the checkout-button URL trigger resolution helpers.
 */

import { readCheckoutData, findCheckoutButtonForm, selectPickerForm, resolveCheckoutButtonForm, copyContextFields } from './checkout-button-trigger';

const VARIATION_MODAL_CLASS_PREFIX = 'newspack-blocks__modal-variation';
const IFRAME_NAME = 'newspack_modal_checkout_iframe';

const PICKER_OPTIONS = {
	variationModalClassPrefix: VARIATION_MODAL_CLASS_PREFIX,
	iframeName: IFRAME_NAME,
};

/**
 * Build a checkout button block markup string.
 *
 * @param {Object|null} checkoutData Object to JSON-encode into data-checkout, or null to omit it.
 * @param {string}      label        Button label.
 * @return {string} HTML.
 */
const checkoutButton = ( checkoutData, label = 'Buy' ) => {
	const attr = checkoutData ? ` data-checkout='${ JSON.stringify( checkoutData ) }'` : '';
	return `<div class="wp-block-newspack-blocks-checkout-button"><form${ attr }><button type="submit">${ label }</button></form></div>`;
};

/**
 * Build a variation picker modal, mirroring Subscriptions_Tiers::render_form output:
 * a single form with `target` set, radio inputs named product_id, and no data-checkout.
 *
 * @param {string}   productId Parent product id for the picker container.
 * @param {string[]} radioIds  Radio values (variation/child ids).
 * @return {string} HTML.
 */
const variationPicker = ( productId, radioIds ) => {
	const radios = radioIds.map( id => `<input type="radio" name="product_id" value="${ id }">` ).join( '' );
	return `<div class="${ VARIATION_MODAL_CLASS_PREFIX }" data-product-id="${ productId }"><form target="${ IFRAME_NAME }">${ radios }<button type="submit">Purchase</button></form></div>`;
};

const render = html => {
	document.body.innerHTML = html;
	return document.body;
};

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'readCheckoutData', () => {
	it( 'parses a valid data-checkout attribute', () => {
		const root = render( checkoutButton( { product_id: '1406', variation_id: '1408' } ) );
		const form = root.querySelector( 'form' );
		expect( readCheckoutData( form ) ).toEqual( { product_id: '1406', variation_id: '1408' } );
	} );

	it( 'returns null without throwing when the attribute is missing', () => {
		const root = render( variationPicker( '1434', [ '158' ] ) );
		const form = root.querySelector( 'form' );
		expect( () => readCheckoutData( form ) ).not.toThrow();
		expect( readCheckoutData( form ) ).toBeNull();
	} );

	it( 'returns null without throwing on malformed JSON', () => {
		const root = render( '<div class="wp-block-newspack-blocks-checkout-button"><form data-checkout="not json">x</form></div>' );
		const form = root.querySelector( 'form' );
		expect( () => readCheckoutData( form ) ).not.toThrow();
		expect( readCheckoutData( form ) ).toBeNull();
	} );

	it( 'returns null for a null form', () => {
		expect( readCheckoutData( null ) ).toBeNull();
	} );
} );

describe( 'findCheckoutButtonForm', () => {
	it( 'requires both product_id and variation_id to match when a variation is requested', () => {
		const root = render( checkoutButton( { product_id: '1406', variation_id: '1408', is_variable: true } ) );
		const form = root.querySelector( 'form' );
		expect( findCheckoutButtonForm( root, '1406', '1408' ) ).toBe( form );
	} );

	it( 'does NOT match a locked button for a different requested variation', () => {
		const root = render( checkoutButton( { product_id: '1406', variation_id: '1408', is_variable: true } ) );
		// Request 1407 while only the 1408-locked button exists.
		expect( findCheckoutButtonForm( root, '1406', '1407' ) ).toBeNull();
	} );

	it( 'matches by product_id only when no variation is requested', () => {
		const root = render( checkoutButton( { product_id: '1406', variation_id: '1408', is_variable: true } ) );
		const form = root.querySelector( 'form' );
		expect( findCheckoutButtonForm( root, '1406', null ) ).toBe( form );
	} );

	it( 'does not match a grouped button (no variation_id) for a variation request', () => {
		const root = render( checkoutButton( { product_id: '1434' }, 'Buy grouped' ) );
		expect( findCheckoutButtonForm( root, '1434', '158' ) ).toBeNull();
	} );

	it( 'skips forms with missing or invalid data-checkout without throwing', () => {
		const root = render( variationPicker( '1406', [ '1408' ] ) + checkoutButton( { product_id: '1406', variation_id: '1408' } ) );
		expect( () => findCheckoutButtonForm( root, '1406', '1408' ) ).not.toThrow();
		expect( findCheckoutButtonForm( root, '1406', '1408' ) ).not.toBeNull();
	} );
} );

describe( 'selectPickerForm', () => {
	it( 'checks the radio matching the variation and returns the picker form', () => {
		const root = render( variationPicker( '1406', [ '1407', '1408', '1409' ] ) );
		const form = selectPickerForm( root, '1406', '1407', PICKER_OPTIONS );
		expect( form ).toBe( root.querySelector( `.${ VARIATION_MODAL_CLASS_PREFIX } form` ) );
		expect( root.querySelector( 'input[value="1407"]' ).checked ).toBe( true );
		expect( root.querySelector( 'input[value="1408"]' ).checked ).toBe( false );
	} );

	it( 'returns null when no picker exists for the product', () => {
		const root = render( variationPicker( '1434', [ '158' ] ) );
		expect( selectPickerForm( root, '1406', '1407', PICKER_OPTIONS ) ).toBeNull();
	} );

	it( 'returns null when no radio matches the variation', () => {
		const root = render( variationPicker( '1406', [ '1407', '1409' ] ) );
		expect( selectPickerForm( root, '1406', '1408', PICKER_OPTIONS ) ).toBeNull();
	} );

	it( 'only selects radio inputs inside the checkout iframe form', () => {
		const root = render(
			`<div class="${ VARIATION_MODAL_CLASS_PREFIX }" data-product-id="1406">` +
				'<form target="other_iframe"><input type="radio" name="product_id" value="1407"></form>' +
				`<form target="${ IFRAME_NAME }">` +
				'<input type="hidden" name="product_id" value="1407">' +
				'<input type="radio" name="product_id" value="1408">' +
				'</form>' +
				'</div>'
		);
		const checkoutForm = root.querySelector( `form[target="${ IFRAME_NAME }"]` );

		expect( selectPickerForm( root, '1406', '1407', PICKER_OPTIONS ) ).toBeNull();
		expect( selectPickerForm( root, '1406', '1408', PICKER_OPTIONS ) ).toBe( checkoutForm );
		expect( checkoutForm.querySelector( 'input[type="radio"][value="1408"]' ).checked ).toBe( true );
	} );
} );

describe( 'resolveCheckoutButtonForm', () => {
	it( 'returns the picker form for a non-locked variation rather than the locked checkout button', () => {
		const root = render(
			checkoutButton( { product_id: '1406', variation_id: '1408', is_variable: true }, 'Subscribe' ) +
				variationPicker( '1406', [ '1407', '1408', '1409' ] )
		);
		const pickerForm = root.querySelector( `.${ VARIATION_MODAL_CLASS_PREFIX } form` );
		const result = resolveCheckoutButtonForm( root, '1406', '1407', PICKER_OPTIONS );
		expect( result ).toBe( pickerForm );
		expect( root.querySelector( 'input[value="1407"]' ).checked ).toBe( true );
	} );

	it( 'returns the exact checkout button form when the requested variation is the locked one', () => {
		const root = render(
			checkoutButton( { product_id: '1406', variation_id: '1408', is_variable: true }, 'Subscribe' ) +
				variationPicker( '1406', [ '1407', '1408', '1409' ] )
		);
		const buttonForm = root.querySelector( '.wp-block-newspack-blocks-checkout-button form' );
		expect( resolveCheckoutButtonForm( root, '1406', '1408', PICKER_OPTIONS ) ).toBe( buttonForm );
	} );

	it( 'drives the grouped picker without throwing on its data-checkout-less form', () => {
		const root = render( checkoutButton( { product_id: '1434' }, 'Buy grouped' ) + variationPicker( '1434', [ '158' ] ) );
		const pickerForm = root.querySelector( `.${ VARIATION_MODAL_CLASS_PREFIX } form` );
		let result;
		expect( () => {
			result = resolveCheckoutButtonForm( root, '1434', '158', PICKER_OPTIONS );
		} ).not.toThrow();
		expect( result ).toBe( pickerForm );
		expect( root.querySelector( 'input[value="158"]' ).checked ).toBe( true );
	} );

	it( 'returns null for an invalid variation when product-only fallback is off (default)', () => {
		const root = render( checkoutButton( { product_id: '158' }, 'Checkout' ) );
		expect( resolveCheckoutButtonForm( root, '158', '160', PICKER_OPTIONS ) ).toBeNull();
	} );

	it( 'treats a variation_id equal to product_id as a strict variation request', () => {
		const root = render( checkoutButton( { product_id: '158' }, 'Checkout' ) );
		expect( resolveCheckoutButtonForm( root, '158', '158', PICKER_OPTIONS ) ).toBeNull();
	} );

	it( 'returns the product-only button for an invalid variation only when fallback is explicitly enabled', () => {
		const root = render( checkoutButton( { product_id: '158' }, 'Checkout' ) );
		const buttonForm = root.querySelector( 'form' );
		expect( resolveCheckoutButtonForm( root, '158', '160', { ...PICKER_OPTIONS, allowProductOnlyFallback: true } ) ).toBe( buttonForm );
	} );

	it( 'matches a checkout button by product_id when no variation is requested', () => {
		const root = render( checkoutButton( { product_id: '1406', variation_id: '1408', is_variable: true } ) );
		const buttonForm = root.querySelector( 'form' );
		expect( resolveCheckoutButtonForm( root, '1406', null, PICKER_OPTIONS ) ).toBe( buttonForm );
	} );

	it( 'returns null without throwing when nothing matches', () => {
		const root = render( checkoutButton( { product_id: '999' } ) );
		let result;
		expect( () => {
			result = resolveCheckoutButtonForm( root, '1406', '1407', PICKER_OPTIONS );
		} ).not.toThrow();
		expect( result ).toBeNull();
	} );

	it( 'copies block context from the source button into the picker form without submitting the locked button', () => {
		const button = `<div class="wp-block-newspack-blocks-checkout-button"><form data-checkout='${ JSON.stringify( {
			product_id: '1406',
			variation_id: '1408',
			is_variable: true,
		} ) }'><input type="hidden" name="after_success_button_label" value="Thanks!"><input type="hidden" name="after_success_url" value="/welcome/"><button type="submit">Subscribe</button></form></div>`;
		const root = render( button + variationPicker( '1406', [ '1407', '1408', '1409' ] ) );
		const pickerForm = root.querySelector( `.${ VARIATION_MODAL_CLASS_PREFIX } form` );
		const buttonForm = root.querySelector( '.wp-block-newspack-blocks-checkout-button form' );

		const result = resolveCheckoutButtonForm( root, '1406', '1407', PICKER_OPTIONS );

		expect( result ).toBe( pickerForm );
		expect( result ).not.toBe( buttonForm );
		expect( pickerForm.querySelector( 'input[name="after_success_button_label"]' ).value ).toBe( 'Thanks!' );
		expect( pickerForm.querySelector( 'input[name="after_success_url"]' ).value ).toBe( '/welcome/' );
	} );
} );

describe( 'copyContextFields', () => {
	it( 'copies present source fields, skips missing ones, and does not overwrite existing target fields', () => {
		const root = render(
			`<form id="src"><input type="hidden" name="after_success_url" value="/welcome/"><input type="hidden" name="prompt_title" value="Join"></form>` +
				`<form id="dst"><input type="hidden" name="prompt_title" value="Existing"></form>`
		);
		const source = root.querySelector( '#src' );
		const target = root.querySelector( '#dst' );

		copyContextFields( source, target );

		// Copied from source.
		expect( target.querySelector( 'input[name="after_success_url"]' ).value ).toBe( '/welcome/' );
		// Not overwritten.
		expect( target.querySelector( 'input[name="prompt_title"]' ).value ).toBe( 'Existing' );
		// Missing on source -> not added.
		expect( target.querySelector( 'input[name="gate_post_id"]' ) ).toBeNull();
	} );

	it( 'copies the last source value when a context field is duplicated', () => {
		const root = render(
			'<form id="src">' +
				'<input type="hidden" name="after_success_url" value="/first/">' +
				'<input type="hidden" name="after_success_url" value="/last/">' +
				'</form>' +
				'<form id="dst"></form>'
		);
		const source = root.querySelector( '#src' );
		const target = root.querySelector( '#dst' );

		copyContextFields( source, target );

		expect( target.querySelector( 'input[name="after_success_url"]' ).value ).toBe( '/last/' );
	} );

	it( 'does not throw when source or target is null', () => {
		const root = render( '<form id="dst"></form>' );
		const target = root.querySelector( '#dst' );
		expect( () => copyContextFields( null, target ) ).not.toThrow();
		expect( () => copyContextFields( target, null ) ).not.toThrow();
	} );
} );
