/**
 * The modal script marks a submit button busy only when its form actually submits,
 * and releases it when the modal opens again, since a form that keeps the page has
 * nothing else to release it. A form that handles its own submit owns its button.
 */

import { isButtonLoading } from '../../utils';

/**
 * Render a modal holding one form, and load the modal script.
 *
 * @param {boolean} interceptSubmit Whether the form handles its own submit.
 */
function renderModal( interceptSubmit ) {
	document.body.innerHTML = `
		<div id="newspack-my-account__example" class="newspack-ui__modal-container" data-state="open">
			<div class="newspack-ui__modal">
				<form class="newspack-ui__modal__content" action="https://example.test/checkout" target="checkout-frame">
					<button type="submit" class="newspack-ui__button"><span>Continue</span></button>
				</form>
			</div>
		</div>
	`;
	jest.isolateModules( () => {
		require( './modals' );
	} );
	const modal = document.getElementById( 'newspack-my-account__example' );
	const form = modal.querySelector( 'form' );
	if ( interceptSubmit ) {
		form.addEventListener( 'submit', event => event.preventDefault() );
	}
	return { modal, form, button: form.querySelector( 'button' ) };
}

const nextTask = () => new Promise( resolve => setTimeout( resolve, 0 ) );

afterEach( () => {
	jest.restoreAllMocks();
	document.body.innerHTML = '';
} );

describe( 'newspack-ui modal forms', () => {
	it( 'releases a submit button whose form handed off, when its modal opens again', async () => {
		const { modal, form, button } = renderModal( false );
		// jsdom reports the form navigation it can't perform; the page staying is the point.
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		form.requestSubmit( button );
		await nextTask();
		expect( isButtonLoading( button ) ).toBe( true );

		modal.setAttribute( 'data-state', 'closed' );
		await nextTask();
		expect( isButtonLoading( button ) ).toBe( true );

		modal.setAttribute( 'data-state', 'open' );
		await nextTask();
		expect( isButtonLoading( button ) ).toBe( false );
	} );

	it( 'leaves the button of a form that handles its own submit to that form', async () => {
		const { form, button } = renderModal( true );

		form.requestSubmit( button );
		await nextTask();

		expect( isButtonLoading( button ) ).toBe( false );
	} );
} );
