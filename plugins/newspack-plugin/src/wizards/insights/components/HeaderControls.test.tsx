/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, within } from '@testing-library/react';

/**
 * Internal dependencies
 */
import HeaderControls from './HeaderControls';
import usePendingControls from '../state/usePendingControls';
import type { DateRange } from '../state/useDateRange';

const DEFAULT_RANGE: DateRange = { preset: 'last-30', start: '2026-05-01', end: '2026-05-30' };

const Harness = ( { comparison = false }: { comparison?: boolean } ) => {
	const controls = usePendingControls( { defaultRange: DEFAULT_RANGE, defaultComparison: comparison } );
	return <HeaderControls controls={ controls } />;
};

const renderHarness = ( comparison = false ) => {
	window.history.replaceState( {}, '', '/wp-admin/admin.php?page=newspack-insights' );
	return render( <Harness comparison={ comparison } /> );
};

// The preset SelectControl is the only <select> (role combobox) in the row.
const selectPreset = ( value: string ) => fireEvent.change( screen.getByRole( 'combobox' ), { target: { value } } );
const compareCheckbox = () => screen.getByRole( 'checkbox', { name: /Compare to previous period/ } ) as HTMLInputElement;

describe( 'HeaderControls', () => {
	it( 'hides Apply/Cancel until a control changes', () => {
		renderHarness();
		expect( screen.queryByRole( 'button', { name: 'Apply' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Cancel' } ) ).not.toBeInTheDocument();
	} );

	it( 'reveals Apply/Cancel when a preset changes', () => {
		renderHarness();
		selectPreset( 'last-7' );
		expect( screen.getByRole( 'button', { name: 'Apply' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Cancel' } ) ).toBeInTheDocument();
	} );

	it( 'Cancel restores the control and hides the buttons', () => {
		renderHarness();
		selectPreset( 'last-7' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( screen.queryByRole( 'button', { name: 'Apply' } ) ).not.toBeInTheDocument();
		expect( ( screen.getByRole( 'combobox' ) as HTMLSelectElement ).value ).toBe( 'last-30' );
	} );

	it( 'preset→preset Apply commits without a modal', () => {
		renderHarness();
		selectPreset( 'last-7' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Apply' } ) );
		expect( screen.queryByText( /may take a while to load/ ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Apply' } ) ).not.toBeInTheDocument(); // committed → clean
	} );

	it( 'applying a custom range opens the modal', () => {
		renderHarness();
		selectPreset( 'custom' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Apply' } ) );
		expect( screen.getByText( /may take a while to load/ ) ).toBeInTheDocument();
	} );

	it( 'toggling compare + Apply opens the modal; Continue commits', () => {
		renderHarness();
		fireEvent.click( compareCheckbox() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Apply' } ) );
		expect( screen.getByText( /may take a while to load/ ) ).toBeInTheDocument();
		fireEvent.click( within( screen.getByRole( 'dialog' ) ).getByRole( 'button', { name: 'Continue' } ) );
		expect( screen.queryByText( /may take a while to load/ ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Apply' } ) ).not.toBeInTheDocument(); // committed → clean
	} );

	it( 'modal Cancel discards the change', () => {
		renderHarness();
		fireEvent.click( compareCheckbox() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Apply' } ) );
		// Global Cancel and modal Cancel both exist — target the one inside the dialog.
		fireEvent.click( within( screen.getByRole( 'dialog' ) ).getByRole( 'button', { name: 'Cancel' } ) );
		expect( screen.queryByText( /may take a while to load/ ) ).not.toBeInTheDocument();
		expect( compareCheckbox().checked ).toBe( false );
	} );
} );
