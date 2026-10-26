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
		const stack = document.querySelector( '.newspack-ui__snackbar__stack' );

		jest.advanceTimersByTime( 7000 );
		expect( item.classList.contains( 'active' ) ).toBe( true );

		stack.dispatchEvent( new Event( 'pointerenter' ) );
		jest.advanceTimersByTime( 20000 );
		expect( item.classList.contains( 'active' ) ).toBe( true );

		stack.dispatchEvent( new Event( 'pointerleave' ) );
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

	it( 'stacks notices that hide on their own and lists notices with a close button below them', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			`<div id="newspack-ui__notices" class="newspack-ui"><div class="newspack-ui__snackbar">
				<div class="newspack-ui__snackbar__item" data-autohide="false"><div class="newspack-ui__snackbar__content">Your card expires soon.</div></div>
			</div></div>`
		);
		const pinnedItem = document.querySelector( '.newspack-ui__snackbar__item' );
		notices.openNotice( pinnedItem );
		notices.createNotice( 'Password updated.' );

		const snackbar = document.querySelector( '.newspack-ui__snackbar' );
		const [ stack, pinned ] = snackbar.children;
		expect( stack.classList.contains( 'newspack-ui__snackbar__stack' ) ).toBe( true );
		expect( pinned.classList.contains( 'newspack-ui__snackbar__pinned' ) ).toBe( true );
		expect( pinned.contains( pinnedItem ) ).toBe( true );
		expect( stack.textContent ).toContain( 'Password updated.' );

		jest.advanceTimersByTime( 60000 );
		expect( pinnedItem.classList.contains( 'active' ) ).toBe( true );
	} );

	it( 'puts the newest notice in front with two peeking behind', () => {
		[ 'One', 'Two', 'Three', 'Four' ].forEach( message => notices.createNotice( message ) );

		const position = message =>
			[ ...document.querySelectorAll( '.newspack-ui__snackbar__item' ) ].find( item => item.textContent === message ).dataset.stackPosition;
		expect( position( 'Four' ) ).toBe( 'front' );
		expect( position( 'Three' ) ).toBe( 'behind' );
		expect( position( 'Two' ) ).toBe( 'behind' );
		expect( position( 'One' ) ).toBe( 'hidden' );
		expect( document.querySelector( '.newspack-ui__snackbar__stack' ).style.getPropertyValue( '--newspack-ui-snackbar-peeks' ) ).toBe( '2' );
	} );

	it( 'moves the next notice to the front when the front one closes', () => {
		notices.createNotice( 'Older' );
		notices.createNotice( 'Newer' );
		const [ older, newer ] = document.querySelectorAll( '.newspack-ui__snackbar__item' );

		notices.closeNotice( newer );

		expect( newer.classList.contains( 'is-leaving' ) ).toBe( true );
		expect( older.dataset.stackPosition ).toBe( 'front' );
	} );

	it( 'pauses every notice in the stack while it is expanded', () => {
		notices.createNotice( 'First' );
		jest.advanceTimersByTime( 4000 );
		notices.createNotice( 'Second' );
		const stack = document.querySelector( '.newspack-ui__snackbar__stack' );
		const items = [ ...stack.children ];

		stack.dispatchEvent( new Event( 'pointerenter' ) );
		expect( stack.dataset.expanded ).toBe( 'true' );
		jest.advanceTimersByTime( 30000 );
		expect( items.every( item => item.classList.contains( 'active' ) ) ).toBe( true );

		stack.dispatchEvent( new Event( 'pointerleave' ) );
		expect( stack.dataset.expanded ).toBe( 'false' );
		jest.advanceTimersByTime( 4000 );
		expect( items[ 0 ].classList.contains( 'active' ) ).toBe( false );
		expect( items[ 1 ].classList.contains( 'active' ) ).toBe( true );
	} );

	it( 'expands on tap and collapses on a tap outside for touch screens', () => {
		notices.createNotice( 'Saved' );
		const stack = document.querySelector( '.newspack-ui__snackbar__stack' );
		const touch = ( type, target ) => {
			const event = new Event( type, { bubbles: true } );
			event.pointerType = 'touch';
			target.dispatchEvent( event );
		};

		touch( 'pointerenter', stack );
		expect( stack.dataset.expanded ).not.toBe( 'true' );
		touch( 'pointerup', stack );
		expect( stack.dataset.expanded ).toBe( 'true' );
		touch( 'pointerdown', document.body );
		expect( stack.dataset.expanded ).toBe( 'false' );
	} );
} );
