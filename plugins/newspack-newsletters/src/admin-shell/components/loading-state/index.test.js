import { render, screen, within } from '@testing-library/react';
import { speak } from '@wordpress/a11y';

import LoadingState from './index';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

describe( 'LoadingState', () => {
	beforeEach( () => {
		speak.mockClear();
	} );

	it( 'labels the spinner so the first paint says what it is fetching', () => {
		const { container } = render( <LoadingState label="Fetching newsletters…" /> );

		expect( within( container ).getByText( 'Fetching newsletters…' ) ).toBeInTheDocument();
		expect( container.querySelector( '.components-spinner' ) ).toBeInTheDocument();
	} );

	it( 'announces itself politely', () => {
		render( <LoadingState label="Fetching layouts…" /> );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Fetching layouts…' );
	} );

	// The region mounts with its text already in place, which screen
	// readers don't reliably announce, so the label is spoken directly.
	it( 'speaks the label rather than relying on the live region alone', () => {
		render( <LoadingState label="Fetching ads…" /> );

		expect( speak ).toHaveBeenCalledWith( 'Fetching ads…', 'polite' );
	} );
} );
