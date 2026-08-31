import { render, screen } from '@testing-library/react';

import UserRow, { avatarPropsFromAuthor } from './';

describe( 'UserRow', () => {
	it( 'renders the avatar with its srcSet when given one', () => {
		render( <UserRow avatarUrl="https://example.test/a.png" avatarSrcSet="https://example.test/a-2x.png 2x" label="Alice" /> );

		const img = screen.getByRole( 'presentation', { hidden: true } );
		expect( img ).toHaveAttribute( 'src', 'https://example.test/a.png' );
		expect( img ).toHaveAttribute( 'srcset', 'https://example.test/a-2x.png 2x' );
		expect( screen.getByText( 'Alice' ) ).toBeInTheDocument();
	} );

	// Avatars can be switched off site-wide, and locks then arrive without one.
	it( 'falls back to the icon when there is no avatar', () => {
		const { container } = render( <UserRow label="Alice is currently editing" /> );

		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( container.querySelector( '.newspack-newsletters-user-row__icon' ) ).toBeTruthy();
		expect( screen.getByText( 'Alice is currently editing' ) ).toBeInTheDocument();
	} );

	it( 'appends a caller class to its own', () => {
		const { container } = render( <UserRow label="Alice" className="host-class" /> );

		expect( container.querySelector( '.newspack-newsletters-user-row.host-class' ) ).toBeTruthy();
	} );
} );

describe( 'avatarPropsFromAuthor', () => {
	// The row renders at 16px, so 24 is the 1x source and 48 the retina one.
	it( 'prefers the 24px source and offers 48px at 2x', () => {
		expect( avatarPropsFromAuthor( { avatar_urls: { 24: 'a-24.png', 48: 'a-48.png', 96: 'a-96.png' } } ) ).toEqual( {
			avatarUrl: 'a-24.png',
			avatarSrcSet: 'a-48.png 2x',
		} );
	} );

	it( 'falls back to the 48px source when 24 is missing', () => {
		expect( avatarPropsFromAuthor( { avatar_urls: { 48: 'a-48.png' } } ) ).toEqual( {
			avatarUrl: 'a-48.png',
			avatarSrcSet: 'a-48.png 2x',
		} );
	} );

	it( 'returns nothing to render when the author has no avatars', () => {
		expect( avatarPropsFromAuthor( undefined ) ).toEqual( { avatarUrl: undefined, avatarSrcSet: undefined } );
	} );
} );
