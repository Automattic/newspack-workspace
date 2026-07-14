/**
 * Tests for ReaderLifecycleSection (Section 1).
 *
 * Covers populated / empty / error render paths and the lastUpdated chrome slot.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ReaderLifecycleSection from './ReaderLifecycleSection';
import { makeConversionWindow } from './fixtures';

describe( 'ReaderLifecycleSection', () => {
	it( 'renders the heading', () => {
		render( <ReaderLifecycleSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'The Reader Lifecycle' } ) ).toBeInTheDocument();
	} );

	it( 'renders the funnel when state is populated', () => {
		render( <ReaderLifecycleSection current={ makeConversionWindow( { lifecycleState: 'populated' } ) } /> );
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( /Unable to load/ ) ).not.toBeInTheDocument();
	} );

	it( 'renders the empty treatment when state is empty', () => {
		render( <ReaderLifecycleSection current={ makeConversionWindow( { lifecycleState: 'empty' } ) } /> );
		expect( screen.getByText( /No funnel data yet/ ) ).toBeInTheDocument();
	} );

	it( 'renders the error treatment when state is error', () => {
		render( <ReaderLifecycleSection current={ makeConversionWindow( { lifecycleState: 'error' } ) } /> );
		expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
		expect( screen.getByText( /Unable to load this section/ ) ).toBeInTheDocument();
	} );

	it( 'renders the lastUpdated slot when provided', () => {
		render( <ReaderLifecycleSection current={ makeConversionWindow() } lastUpdated={ <span>last-updated-sentinel</span> } /> );
		expect( screen.getByText( 'last-updated-sentinel' ) ).toBeInTheDocument();
	} );

	it( 'renders without a lastUpdated slot', () => {
		expect( () => render( <ReaderLifecycleSection current={ makeConversionWindow() } /> ) ).not.toThrow();
	} );
} );
