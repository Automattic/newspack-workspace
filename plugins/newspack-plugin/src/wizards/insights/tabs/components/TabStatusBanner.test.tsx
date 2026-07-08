/**
 * Tests for TabStatusBanner (NEWS-2603): the two-tier top-of-tab status
 * banner driven by the envelope's `data_status` field. Verifies that
 * `complete` and `undefined` render nothing, `warming` renders the soft/info
 * notice inside a polite live region, and `incomplete` renders the warning
 * notice inside an assertive live region.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TabStatusBanner from './TabStatusBanner';

describe( 'TabStatusBanner', () => {
	it( 'renders nothing when status is "complete"', () => {
		const { container } = render( <TabStatusBanner status="complete" /> );

		expect( container.firstChild ).toBeNull();
	} );

	it( 'renders nothing when status is undefined', () => {
		const { container } = render( <TabStatusBanner /> );

		expect( container.firstChild ).toBeNull();
	} );

	it( 'renders the soft/info notice with a polite live region when status is "warming"', () => {
		render( <TabStatusBanner status="warming" /> );

		expect( screen.getByText( 'Some metrics are still being calculated and will appear shortly.' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();

		// Info/soft variant: no warning class applied.
		const notice = document.querySelector( '.newspack-notice' );
		expect( notice ).not.toHaveClass( 'newspack-notice__is-warning' );
		expect( notice ).not.toHaveClass( 'newspack-notice__is-error' );
	} );

	it( 'renders the warning notice with an assertive live region when status is "incomplete"', () => {
		render( <TabStatusBanner status="incomplete" /> );

		expect( screen.getByText( "The last data fetch didn't finish, so some figures may be missing or out of date." ) ).toBeInTheDocument();
		expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();

		const notice = document.querySelector( '.newspack-notice' );
		expect( notice ).toHaveClass( 'newspack-notice__is-warning' );
	} );
} );
