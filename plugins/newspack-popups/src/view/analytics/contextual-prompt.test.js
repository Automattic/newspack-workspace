/**
 * The condition stamped on the card reaches both events, and is an empty
 * string when the card carries none (control override off).
 */
jest.mock( '../utils/analytics', () => ( { sendEvent: jest.fn() } ) );

import { sendEvent } from '../utils/analytics';
import { handleContextualPromptAnalytics } from './contextual-prompt';

// jsdom doesn't implement innerText (it needs real layout); shim it with
// textContent so promptTextOf() and the click handler's button_text read
// don't blow up on `undefined.trim()` in this test environment.
if ( ! Object.getOwnPropertyDescriptor( window.HTMLElement.prototype, 'innerText' )?.get ) {
	Object.defineProperty( window.HTMLElement.prototype, 'innerText', {
		configurable: true,
		get() {
			return this.textContent;
		},
		set( value ) {
			this.textContent = value;
		},
	} );
}

const card = condition =>
	`<div class="newspack-contextual-prompt" data-newspack-cp-post-id="12" data-newspack-cp-cta="button" data-newspack-cp-placement="mid"${
		condition ? ` data-newspack-cp-condition="${ condition }"` : ''
	}><p>Ask.</p><div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link" href="https://example.com/donate/">Give</a></div></div></div>`;

describe( 'contextual prompt condition', () => {
	beforeEach( () => {
		sendEvent.mockClear();
		global.IntersectionObserver = undefined;
	} );

	it( 'sends the condition on click', () => {
		document.body.innerHTML = card( 'generic_control' );
		handleContextualPromptAnalytics();
		document.querySelector( 'a' ).dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		expect( sendEvent ).toHaveBeenCalledWith(
			expect.objectContaining( { action: 'clicked', contextual_prompt_condition: 'generic_control' } ),
			'np_contextual_prompt_interaction'
		);
	} );

	it( 'sends an empty condition when none is stamped', () => {
		document.body.innerHTML = card( '' );
		handleContextualPromptAnalytics();
		document.querySelector( 'a' ).dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		expect( sendEvent.mock.calls[ 0 ][ 0 ].contextual_prompt_condition ).toBe( '' );
	} );
} );
