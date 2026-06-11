/**
 * Tests for the shared InfoCallout (NPPD-1618): heading + body rendering, and
 * the dismissible / localStorage-persisted behavior used by the Gates callout.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import InfoCallout from './InfoCallout';

const STORAGE_KEY = 'newspack-insights-callout:test-key';

describe( 'InfoCallout', () => {
	beforeEach( () => {
		window.localStorage.clear();
	} );

	it( 'renders the heading and body content', () => {
		render(
			<InfoCallout heading="About this thing">
				<p>Body paragraph.</p>
			</InfoCallout>
		);
		expect( screen.getByText( 'About this thing' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Body paragraph.' ) ).toBeInTheDocument();
	} );

	it( 'shows no dismiss button when not dismissible', () => {
		render(
			<InfoCallout heading="Heading">
				<p>Body.</p>
			</InfoCallout>
		);
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );

	it( 'dismisses and persists the dismissal to localStorage', () => {
		render(
			<InfoCallout heading="Heading" dismissible storageKey="test-key">
				<p>Body.</p>
			</InfoCallout>
		);
		expect( screen.getByText( 'Heading' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button' ) );

		expect( screen.queryByText( 'Heading' ) ).not.toBeInTheDocument();
		expect( window.localStorage.getItem( STORAGE_KEY ) ).toBe( '1' );
	} );

	it( 'starts dismissed when the storageKey was previously dismissed', () => {
		window.localStorage.setItem( STORAGE_KEY, '1' );
		const { container } = render(
			<InfoCallout heading="Heading" dismissible storageKey="test-key">
				<p>Body.</p>
			</InfoCallout>
		);
		expect( container ).toBeEmptyDOMElement();
	} );
} );
