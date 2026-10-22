import { initDropdowns } from './dropdowns';

const markup = `
	<div class="newspack-ui__dropdown">
		<button class="newspack-ui__dropdown__toggle">Manage subscription</button>
		<div class="newspack-ui__dropdown__content">
			<ul><li><a href="#renew">Renew now</a></li><li><a href="#cancel">Cancel</a></li></ul>
		</div>
	</div>
`;

describe( 'newspack-ui dropdowns', () => {
	beforeEach( () => {
		document.body.innerHTML = markup;
	} );

	it( 'marks every toggle as collapsed on load', () => {
		initDropdowns();
		expect( document.querySelector( '.newspack-ui__dropdown__toggle' ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );

	it( 'reflects the open state in aria-expanded on the toggle', () => {
		const toggle = document.querySelector( '.newspack-ui__dropdown__toggle' );
		toggle.click();
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		toggle.click();
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );

	it( 'closes on Escape and returns focus to the toggle when focus was inside', () => {
		const toggle = document.querySelector( '.newspack-ui__dropdown__toggle' );
		toggle.click();
		const item = document.querySelector( '.newspack-ui__dropdown__content a' );
		item.focus();
		document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
		expect( document.querySelector( '.newspack-ui__dropdown' ).classList.contains( 'active' ) ).toBe( false );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( document.activeElement ).toBe( toggle );
	} );

	it( 'closes on an outside click without moving focus', () => {
		const toggle = document.querySelector( '.newspack-ui__dropdown__toggle' );
		toggle.click();
		toggle.focus();
		document.body.click();
		expect( document.querySelector( '.newspack-ui__dropdown' ).classList.contains( 'active' ) ).toBe( false );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( document.activeElement ).toBe( toggle );
	} );
} );
