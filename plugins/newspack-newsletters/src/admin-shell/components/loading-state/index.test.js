import { render, screen } from '@testing-library/react';

import LoadingState from './index';

describe( 'LoadingState', () => {
	it( 'labels the spinner so the first paint says what it is fetching', () => {
		const { container } = render( <LoadingState label="Fetching newsletters…" /> );

		expect( screen.getByText( 'Fetching newsletters…' ) ).toBeInTheDocument();
		expect( container.querySelector( '.components-spinner' ) ).toBeInTheDocument();
	} );

	it( 'announces itself politely', () => {
		render( <LoadingState label="Fetching layouts…" /> );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Fetching layouts…' );
	} );
} );
