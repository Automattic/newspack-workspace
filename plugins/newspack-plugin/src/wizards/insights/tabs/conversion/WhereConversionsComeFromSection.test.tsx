/**
 * Tests for WhereConversionsComeFromSection (Section 3).
 *
 * Covers populated / empty / error render paths for the three source-mix pies.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import WhereConversionsComeFromSection from './WhereConversionsComeFromSection';
import { makeConversionWindow } from './fixtures';

describe( 'WhereConversionsComeFromSection', () => {
	it( 'renders the heading and the three pie titles', () => {
		render( <WhereConversionsComeFromSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'Where Conversions Come From' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'New Registrations' ) ).toBeInTheDocument();
		expect( screen.getByText( 'New Subscribers' ) ).toBeInTheDocument();
		expect( screen.getByText( 'New Donors' ) ).toBeInTheDocument();
	} );

	it( 'renders the empty treatment when state is empty', () => {
		render( <WhereConversionsComeFromSection current={ makeConversionWindow( { sourceMixState: 'empty' } ) } /> );
		expect( screen.getByText( 'Source data will appear once registrations occur in this timeframe.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Source data will appear once donations occur in this timeframe.' ) ).toBeInTheDocument();
	} );

	it( 'renders the error treatment when state is error', () => {
		render( <WhereConversionsComeFromSection current={ makeConversionWindow( { sourceMixState: 'error' } ) } /> );
		expect( screen.getAllByRole( 'alert' ) ).toHaveLength( 3 );
		expect( screen.getAllByText( /Unable to load this section/ ) ).toHaveLength( 3 );
	} );

	it( 'renders the coming_soon treatment when state is coming_soon', () => {
		// Source mix is coming_soon in the default fixture override — set explicitly to be clear.
		const win = makeConversionWindow();
		win.source_mix_registrations = { ...win.source_mix_registrations, state: 'coming_soon' };
		render( <WhereConversionsComeFromSection current={ win } /> );
		expect( screen.getByText( /Coming soon/ ) ).toBeInTheDocument();
	} );
} );
