import notices from './notices';

describe( 'newspack-ui notices', () => {
	beforeEach( () => {
		document.body.innerHTML = `
			<div class="newspack-popup-container" hidden>
				<div class="newspack-registration newspack-ui"></div>
			</div>
		`;
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
} );
