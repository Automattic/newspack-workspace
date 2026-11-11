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

	it( 'adds the hidden class before removing a non-transitioning overlay on the next tick', () => {
		const { lightbox, button } = render( '' );
		button.click();
		expect( lightbox.classList.contains( 'hidden' ) ).toBe( true );
		expect( lightbox.style.display ).toBe( '' );
		jest.advanceTimersByTime( 0 );
		expect( lightbox.style.display ).toBe( 'none' );
	} );
} );

describe( 'closing while the overlay leaves', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
		document.body.innerHTML = '';
		jest.resetModules();
	} );

	it( 'schedules one removal and removes the overlay from reader activation once', () => {
		const remove = jest.fn();
		window.newspackReaderActivation = { overlays: { remove } };
		const { lightbox, button } = render( 'transition-property: opacity; transition-duration: 0s; transition-delay: 400ms' );
		lightbox.overlayId = 'overlay-1';
		const spy = jest.spyOn( global, 'setTimeout' );
		button.click();
		button.click();
		expect( spy ).toHaveBeenCalledTimes( 1 );
		expect( remove ).toHaveBeenCalledTimes( 1 );
		jest.advanceTimersByTime( 400 );
		expect( lightbox.style.display ).toBe( 'none' );
		spy.mockRestore();
		delete window.newspackReaderActivation;
	} );

	it( 'sends the dismissed event once whatever the listener order', () => {
		const sendEvent = jest.fn();
		jest.doMock( '../utils', () => ( {
			getEventPayload: ( action, id ) => ( { action, id } ),
			getRawId: () => 1,
			sendEvent,
		} ) );
		const { manageDismissals } = require( '../analytics/dismissed' );
		document.body.innerHTML =
			'<div id="prompt_1" class="newspack-popup-container"><div class="newspack-lightbox"><button class="newspack-lightbox__close"></button></div></div>';
		const button = document.querySelector( 'button' );
		button.addEventListener( 'click', closeOverlay );
		manageDismissals( [ document.querySelector( '.newspack-popup-container' ) ] );
		button.click();
		button.click();
		expect( sendEvent ).toHaveBeenCalledTimes( 1 );
	} );
} );
