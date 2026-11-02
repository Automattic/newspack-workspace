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

	it( 'collapses an expanded stack once its last notice closes', () => {
		notices.createNotice( 'Saved' );
		const stack = document.querySelector( '.newspack-ui__snackbar__stack' );
		stack.dispatchEvent( new Event( 'pointerenter' ) );

		notices.closeNotice( stack.querySelector( '.newspack-ui__snackbar__item' ) );

		expect( stack.dataset.expanded ).toBe( 'false' );
	} );

	it( 'resumes the timers when a focused notice closes and takes focus with it', () => {
		notices.createNotice( 'Older' );
		notices.createNotice( 'Newer' );
		const stack = document.querySelector( '.newspack-ui__snackbar__stack' );
		const [ older, newer ] = stack.children;
		newer.insertAdjacentHTML( 'beforeend', '<button>Undo</button>' );
		newer.querySelector( 'button' ).dispatchEvent( new FocusEvent( 'focusin', { bubbles: true } ) );
		jest.advanceTimersByTime( 20000 );

		notices.closeNotice( newer );
		jest.advanceTimersByTime( 0 );

		expect( newer.isConnected ).toBe( false );
		expect( stack.dataset.expanded ).toBe( 'false' );
		jest.advanceTimersByTime( 8000 );
		expect( older.classList.contains( 'active' ) ).toBe( false );
	} );

	it( 'keeps a notice reopened before its removal fires', () => {
		notices.createNotice( 'Unsubscribed.' );
		const item = document.querySelector( '.newspack-ui__snackbar__item' );

		notices.closeNotice( item );
		notices.openNotice( item, false );
		jest.advanceTimersByTime( 0 );

		expect( item.isConnected ).toBe( true );
		expect( item.dataset.stackPosition ).toBe( 'front' );
	} );

	it( 'shows errors in full below the stack, with a localized close button', () => {
		window.newspackUIData = { closeLabel: 'Fermer', closeIcon: '<svg aria-hidden="true"><path d="M0 0"/></svg>' };
		notices.createNotice( "Couldn't copy the invite link.", 'error' );
		notices.createNotice( 'Invite sent.' );
		const error = [ ...document.querySelectorAll( '.newspack-ui__snackbar__item' ) ].find( item => 'error' === item.dataset.type );
		const close = error.querySelector( '.newspack-ui__snackbar__close' );

		expect( error.closest( '.newspack-ui__snackbar__pinned' ) ).not.toBeNull();
		expect( close.getAttribute( 'aria-label' ) ).toBe( 'Fermer' );
		expect( close.querySelector( 'svg' ) ).not.toBeNull();

		close.click();
		expect( error.classList.contains( 'active' ) ).toBe( false );
		delete window.newspackUIData;
	} );

	it( 'keeps one copy of an error raised again', () => {
		notices.createNotice( 'Could not distribute.', 'error' );
		notices.createNotice( 'Could not distribute.', 'error' );

		expect( document.querySelectorAll( '.newspack-ui__snackbar__item[data-type="error"]' ) ).toHaveLength( 1 );
	} );

	it( 'moves focus to the next notice when the focused one closes', () => {
		notices.createNotice( 'First error.', 'error' );
		notices.createNotice( 'Second error.', 'error' );
		const [ first, second ] = document.querySelectorAll( '.newspack-ui__snackbar__close' );

		first.focus();
		first.click();

		expect( document.activeElement ).toBe( second );
	} );

	it( 'keeps an error until the reader closes it', () => {
		const error = notices.createNotice( 'Could not save.', 'error' );

		jest.advanceTimersByTime( 60000 );
		expect( error.classList.contains( 'active' ) ).toBe( true );
	} );

	it( 'keeps a server-printed error marked to hide, with a close button, until the reader closes it', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			`<div id="newspack-ui__notices" class="newspack-ui"><div class="newspack-ui__snackbar">
				<div class="newspack-ui__snackbar__item" data-type="error" data-autohide="true"><div class="newspack-ui__snackbar__content">Could not save.</div></div>
			</div></div>`
		);
		const error = document.querySelector( '.newspack-ui__snackbar__item' );
		notices.openNotice( error );

		jest.advanceTimersByTime( 60000 );
		expect( error.closest( '.newspack-ui__snackbar__pinned' ) ).not.toBeNull();
		expect( error.classList.contains( 'active' ) ).toBe( true );

		error.querySelector( '.newspack-ui__snackbar__close' ).click();
		expect( error.classList.contains( 'active' ) ).toBe( false );
	} );

	it( 'leaves focus alone when a notice is closed with the mouse', () => {
		notices.createNotice( 'First error.', 'error' );
		notices.createNotice( 'Second error.', 'error' );
		const [ first ] = document.querySelectorAll( '.newspack-ui__snackbar__close' );
		const matches = Element.prototype.matches;
		const spy = jest.spyOn( Element.prototype, 'matches' ).mockImplementation( function ( selector ) {
			return ':focus-visible' === selector ? false : matches.call( this, selector );
		} );

		first.focus();
		first.click();

		expect( document.activeElement ).toBe( first );
		spy.mockRestore();
	} );

	it( 'shows a server-printed error once when the same error is raised again', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			`<div id="newspack-ui__notices" class="newspack-ui"><div class="newspack-ui__snackbar">
				<div class="newspack-ui__snackbar__item" data-type="error" data-autohide="false"><div class="newspack-ui__snackbar__content">Payment failed.</div></div>
			</div></div>`
		);
		const persistent = document.querySelector( '.newspack-ui__snackbar__item' );
		notices.openNotice( persistent );

		expect( notices.createNotice( ' Payment failed.\n', 'error' ) ).toBe( persistent );
		expect( document.querySelectorAll( '.newspack-ui__snackbar__item[data-type="error"]' ) ).toHaveLength( 1 );

		jest.advanceTimersByTime( 9000 );
		expect( persistent.classList.contains( 'active' ) ).toBe( true );
	} );

	it( 'collapses a closing pinned notice from its measured height', () => {
		const error = notices.createNotice( 'Could not save.', 'error' );

		notices.closeNotice( error, false );
		expect( error.style.height ).toBe( '0px' );

		notices.openNotice( error, false );
		expect( error.style.height ).toBe( '' );
	} );
} );
