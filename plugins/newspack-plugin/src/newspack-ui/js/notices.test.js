import notices from './notices';

describe( 'newspack-ui notices', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		document.body.innerHTML = `
			<div class="newspack-popup-container" hidden>
				<div class="newspack-registration newspack-ui"></div>
			</div>
		`;
	} );

	afterEach( () => {
		jest.runOnlyPendingTimers();
		jest.useRealTimers();
	} );

	it( 'hosts snackbars and live regions outside other .newspack-ui elements', () => {
		notices.createNotice( 'Link copied' );

		const host = document.getElementById( 'newspack-ui__notices' );
		expect( host.parentElement ).toBe( document.body );
		expect( host.querySelector( '.newspack-ui__snackbar__content' ).textContent ).toBe( 'Link copied' );
		expect( host.querySelector( '#newspack-ui__sr-live-polite' ) ).not.toBeNull();
		expect( document.querySelector( '.newspack-registration .newspack-ui__snackbar' ) ).toBeNull();
	} );

	it( 'reuses the host printed with server-side notices', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			'<div id="newspack-ui__notices" class="newspack-ui"><div class="newspack-ui__snackbar"></div></div>'
		);

		notices.createNotice( 'Saved' );

		expect( document.querySelectorAll( '#newspack-ui__notices' ) ).toHaveLength( 1 );
		expect( document.querySelectorAll( '.newspack-ui__snackbar' ) ).toHaveLength( 1 );
	} );

	it( 'stays open at least 8 seconds and pauses while hovered or focused', () => {
		notices.createNotice( 'Unsubscribed from The Weekly.' );
		const item = document.querySelector( '.newspack-ui__snackbar__item' );

		jest.advanceTimersByTime( 7000 );
		expect( item.classList.contains( 'active' ) ).toBe( true );

		item.dispatchEvent( new Event( 'mouseenter' ) );
		jest.advanceTimersByTime( 20000 );
		expect( item.classList.contains( 'active' ) ).toBe( true );

		item.dispatchEvent( new Event( 'mouseleave' ) );
		jest.advanceTimersByTime( 999 );
		expect( item.classList.contains( 'active' ) ).toBe( true );
		jest.advanceTimersByTime( 1 );
		expect( item.classList.contains( 'active' ) ).toBe( false );
	} );

	it( 'keeps the timer paused while focus stays inside the snackbar', () => {
		notices.createNotice( 'Saved' );
		const item = document.querySelector( '.newspack-ui__snackbar__item' );
		item.insertAdjacentHTML( 'beforeend', '<button>Undo</button><button>Close</button>' );
		const [ undo, close ] = item.querySelectorAll( 'button' );

		undo.dispatchEvent( new FocusEvent( 'focusin', { bubbles: true } ) );
		undo.dispatchEvent( new FocusEvent( 'focusout', { bubbles: true, relatedTarget: close } ) );
		jest.advanceTimersByTime( 20000 );
		expect( item.classList.contains( 'active' ) ).toBe( true );

		close.dispatchEvent( new FocusEvent( 'focusout', { bubbles: true, relatedTarget: document.body } ) );
		jest.advanceTimersByTime( 8000 );
		expect( item.classList.contains( 'active' ) ).toBe( false );
	} );
} );
