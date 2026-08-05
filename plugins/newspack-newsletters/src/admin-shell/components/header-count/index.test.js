import { render } from '@testing-library/react';

import HeaderCount from './index';

describe( 'HeaderCount', () => {
	beforeEach( () => {
		document.body.innerHTML =
			'<div id="newspack-wizards-admin-header"><nav><h1 class="newspack-breadcrumbs__current">All Newsletters</h1></nav></div>';
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	const getHeading = () => document.querySelector( 'h1.newspack-breadcrumbs__current' );

	it( 'portals the parenthesised count into the breadcrumbs heading', () => {
		render( <HeaderCount count={ 112 } /> );
		expect( getHeading().textContent ).toBe( 'All Newsletters (112)' );
	} );

	it( 'updates in place when the count changes', () => {
		const { rerender } = render( <HeaderCount count={ 112 } /> );
		rerender( <HeaderCount count={ 3 } /> );
		expect( getHeading().textContent ).toBe( 'All Newsletters (3)' );
	} );

	it( 'renders nothing without a numeric count', () => {
		render( <HeaderCount count={ null } /> );
		expect( getHeading().querySelector( '.newspack-newsletters-header-count' ).textContent ).toBe( '' );
	} );

	it( 'renders nothing when the count is zero', () => {
		render( <HeaderCount count={ 0 } /> );
		expect( getHeading().querySelector( '.newspack-newsletters-header-count' ).textContent ).toBe( '' );
	} );

	it( 'removes its container on unmount', () => {
		const { unmount } = render( <HeaderCount count={ 112 } /> );
		unmount();
		expect( getHeading().querySelector( '.newspack-newsletters-header-count' ) ).toBeNull();
	} );

	it( 'renders nothing when the admin header is absent (standalone mode)', () => {
		document.body.innerHTML = '<div id="root"></div>';
		expect( () => render( <HeaderCount count={ 112 } /> ) ).not.toThrow();
		expect( document.querySelector( '.newspack-newsletters-header-count' ) ).toBeNull();
	} );
} );
