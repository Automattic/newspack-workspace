import './password-toggle';

describe( 'newspack-ui password toggle', () => {
	beforeEach( () => {
		document.body.innerHTML = `
			<span class="newspack-ui__password-field">
				<input type="password" value="secret" />
				<button type="button" class="newspack-ui__password-toggle" aria-label="Show password" data-label-show="Show password" data-label-hide="Hide password"></button>
			</span>
		`;
	} );

	it( 'shows and hides the password and keeps the toggle labelled', () => {
		const input = document.querySelector( 'input' );
		const toggle = document.querySelector( 'button' );

		toggle.click();
		expect( input.type ).toBe( 'text' );
		expect( toggle.getAttribute( 'aria-label' ) ).toBe( 'Hide password' );

		toggle.click();
		expect( input.type ).toBe( 'password' );
		expect( toggle.getAttribute( 'aria-label' ) ).toBe( 'Show password' );
	} );
} );
