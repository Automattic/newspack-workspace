import { closeOverlay } from './prompts';

const render = style => {
	document.body.innerHTML = `<div class="newspack-lightbox" style="${ style }"><button class="newspack-lightbox__close"></button></div>`;
	const lightbox = document.querySelector( '.newspack-lightbox' );
	const button = lightbox.querySelector( 'button' );
	button.addEventListener( 'click', closeOverlay );
	return { lightbox, button };
};

describe( 'closeOverlay', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
		document.body.innerHTML = '';
	} );

	it( 'hides the overlay at once and removes it when its exit transition ends', () => {
		const { lightbox, button } = render( 'transition-property: opacity, visibility; transition-duration: 0s; transition-delay: 400ms' );
		button.click();
		expect( lightbox.classList.contains( 'hidden' ) ).toBe( true );
		expect( lightbox.style.display ).toBe( '' );
		jest.advanceTimersByTime( 399 );
		expect( lightbox.style.display ).toBe( '' );
		jest.advanceTimersByTime( 1 );
		expect( lightbox.style.display ).toBe( 'none' );
	} );

	it( 'removes the overlay on the next tick when nothing transitions', () => {
		const { lightbox, button } = render( '' );
		button.click();
		jest.advanceTimersByTime( 0 );
		expect( lightbox.style.display ).toBe( 'none' );
	} );
} );
