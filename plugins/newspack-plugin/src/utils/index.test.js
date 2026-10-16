/**
 * A loading button has to stay focusable, so its busy state is announced, while
 * still refusing a second submit.
 */

import { isButtonLoading, setButtonLoading } from './index';

function renderForm() {
	document.body.innerHTML = `
		<form>
			<input type="email" name="npe">
			<button type="submit">Continue</button>
		</form>
	`;
	const form = document.querySelector( 'form' );
	const onSubmit = jest.fn( event => event.preventDefault() );
	form.addEventListener( 'submit', onSubmit );
	return { button: form.querySelector( 'button' ), onSubmit };
}

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'setButtonLoading', () => {
	it( 'keeps the button focusable while it loads', () => {
		const { button } = renderForm();
		button.focus();

		setButtonLoading( button, true );

		expect( button.disabled ).toBe( false );
		expect( document.activeElement ).toBe( button );
		expect( button.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( isButtonLoading( button ) ).toBe( true );
	} );

	it( 'ignores a press while loading, and submits again once cleared', () => {
		const { button, onSubmit } = renderForm();

		setButtonLoading( button, true );
		button.click();
		expect( onSubmit ).not.toHaveBeenCalled();

		setButtonLoading( button, false );
		button.click();
		expect( onSubmit ).toHaveBeenCalledTimes( 1 );
		expect( button.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( button.hasAttribute( 'aria-disabled' ) ).toBe( false );
	} );
} );
