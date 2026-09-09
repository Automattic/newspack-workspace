/**
 * The gate's `seen` event stamps a capability flag per surface-bearing block.
 * `gate_has_newsletter_block` is what lets Insights on the hub count a gate
 * built from the Newsletter Subscription Form block as a registration- and
 * newsletter-intent surface; before it existed such a gate looked like no
 * surface at all.
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
		expect( payload.gate_has_registration_link ).toBe( 'no' );
	} );

	it( 'reports no newsletter block on a registration-block gate', () => {
		const payload = seenPayloadFor( '<div class="newspack-registration"><form></form></div>' );

		expect( payload.gate_has_newsletter_block ).toBe( 'no' );
		expect( payload.gate_has_registration_block ).toBe( 'yes' );
	} );
} );
