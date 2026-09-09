/**
 * A gate built from the Newsletter Subscription Form block is a registration
 * and newsletter surface: the `seen` event stamps `gate_has_newsletter_block`
 * so Insights on the hub can count it, and the block's form carries the
 * gate's id so the registration it produces can be attributed to the gate.
 *
 * gate.js is imported for its side effects, so the mocks below have to be in
 * place before the import is evaluated.
 */

const mockSendEvent = jest.fn();

jest.mock( './preview-links', () => ( { propagateGatePreviewParams: jest.fn() } ) );
jest.mock( '../reader-activation/analytics', () => ( {
	getEventPayload: payload => payload,
	sendEvent: ( ...args ) => mockSendEvent( ...args ),
} ) );
jest.mock( '../reader-activation/utils', () => ( { debugLog: jest.fn() } ) );
jest.mock( '../shared/js/cta-attribution', () => ( { persistCtaAttribution: jest.fn() } ) );
jest.mock( './gate.scss', () => ( {} ), { virtual: true } );

/**
 * Render an inline gate and let gate.js fire its `seen` event.
 *
 * jsdom lays nothing out, so every element reports a 0×0 box and gate.js's
 * isVisible() would call every block hidden. Give elements a box so the flags
 * reflect what is in the gate rather than jsdom's lack of layout.
 *
 * @param {string} innerHtml Gate contents.
 * @return {Object} The payload gate.js sent for the `seen` event.
 */
function seenPayloadFor( innerHtml ) {
	jest.resetModules();
	mockSendEvent.mockReset();
	global.newspack_content_gate = { metadata: { gate_post_id: 123 } };
	window.newspackRAS = [];
	window.gtag = jest.fn();
	Object.defineProperty( document, 'readyState', { value: 'interactive', configurable: true } );
	Object.defineProperty( HTMLElement.prototype, 'offsetWidth', { configurable: true, get: () => 100 } );
	Object.defineProperty( HTMLElement.prototype, 'offsetHeight', { configurable: true, get: () => 100 } );
	document.body.innerHTML = `<div class="newspack-content-gate__gate">${ innerHtml }</div>`;

	require( './gate' );

	const seen = mockSendEvent.mock.calls.find( ( [ payload ] ) => payload?.action === 'seen' );
	expect( seen ).toBeDefined();
	return seen[ 0 ];
}

describe( 'gate.js seen-event capability flags', () => {
	it( 'flags a gate built from the Newsletter Subscription Form block', () => {
		const payload = seenPayloadFor( '<div class="wp-block-newspack-newsletters-subscribe newspack-newsletters-subscribe"><form></form></div>' );

		expect( payload.gate_has_newsletter_block ).toBe( 'yes' );
		expect( payload.gate_has_registration_block ).toBe( 'no' );
	} );

	it( 'stamps the gate id onto the newsletter form and labels its submission', () => {
		seenPayloadFor(
			'<div class="newspack-newsletters-subscribe"><form><input type="email" name="npe" value="reader@example.test" /></form></div>'
		);

		const form = document.querySelector( '.newspack-newsletters-subscribe form' );
		expect( form.querySelector( 'input[name="gate_post_id"]' ).value ).toBe( '123' );

		mockSendEvent.mockReset();
		form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
		const submission = mockSendEvent.mock.calls.find( ( [ payload ] ) => payload?.action === 'form_submission' );
		expect( submission ).toBeDefined();
		expect( submission[ 0 ].action_type ).toBe( 'newsletter_signup' );
	} );

	it( 'reports no newsletter block on a registration-block gate', () => {
		const payload = seenPayloadFor( '<div class="newspack-registration"><form></form></div>' );

		expect( payload.gate_has_newsletter_block ).toBe( 'no' );
		expect( payload.gate_has_registration_block ).toBe( 'yes' );
	} );
} );
