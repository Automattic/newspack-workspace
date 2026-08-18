import { render, within } from '@testing-library/react';
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

		expect( speak ).toHaveBeenCalledWith( 'Fetching layouts…', 'polite' );
	} );

	// Two live regions carrying the same text means some screen readers
	// say it twice, so `speak()` is the only announcement.
	it( 'leaves the announcement to speak rather than a second live region', () => {
		const { container } = render( <LoadingState label="Fetching ads…" /> );

		expect( within( container ).queryByRole( 'status' ) ).toBeNull();
		expect( speak ).toHaveBeenCalledTimes( 1 );
	} );
} );
