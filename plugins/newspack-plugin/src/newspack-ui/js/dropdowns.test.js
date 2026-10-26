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

	describe( 'positioning', () => {
		const style =
			'<style>.newspack-ui__dropdown__content { visibility: hidden; } .newspack-ui__dropdown.active .newspack-ui__dropdown__content { visibility: visible; }</style>';
		let fits;

		beforeEach( () => {
			document.body.innerHTML = style + markup;
			fits = false;
			const content = document.querySelector( '.newspack-ui__dropdown__content' );
			const inline = {
				removeProperty( name ) {
					delete this[ name ];
				},
				get length() {
					return Object.keys( this ).length;
				},
			};
			Object.defineProperty( content, 'style', { value: inline } );
			content.getBoundingClientRect = () => {
				const flipped = 'auto' === content.style.top;
				const bottom = fits || flipped ? window.innerHeight - 10 : window.innerHeight + 50;
				return { top: bottom - 100, bottom, left: 10, right: 110, width: 100, height: 100 };
			};
		} );

		const toggleOpen = () => document.querySelector( '.newspack-ui__dropdown__toggle' ).click();

		it( 'opens above its trigger every time there is no room below', () => {
			const content = document.querySelector( '.newspack-ui__dropdown__content' );
			for ( let i = 0; i < 3; i++ ) {
				toggleOpen();
				expect( content.classList.contains( 'newspack-ui__dropdown__content--above' ) ).toBe( true );
				expect( content.style.top ).toBe( 'auto' );
				toggleOpen();
			}
		} );

		it( 'drops an earlier flip once there is room below', () => {
			const content = document.querySelector( '.newspack-ui__dropdown__content' );
			toggleOpen();
			toggleOpen();
			fits = true;
			toggleOpen();

			expect( content.classList.contains( 'newspack-ui__dropdown__content--above' ) ).toBe( false );
			expect( content.style.top ).toBeUndefined();
			expect( content.style.transition ).toBeUndefined();
		} );

		it( 'keeps its position when reopened before the close has finished', () => {
			const content = document.querySelector( '.newspack-ui__dropdown__content' );
			toggleOpen();
			document.head.insertAdjacentHTML( 'beforeend', '<style id="mid-close">.newspack-ui__dropdown__content { visibility: visible; }</style>' );
			toggleOpen();
			fits = true;
			toggleOpen();

			expect( content.classList.contains( 'newspack-ui__dropdown__content--above' ) ).toBe( true );
			expect( content.style.top ).toBe( 'auto' );
			document.getElementById( 'mid-close' ).remove();
		} );
	} );
} );
