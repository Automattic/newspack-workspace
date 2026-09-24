/**
 * External dependencies.
 */
import { render, screen, fireEvent, act } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import SavePanel from './index';

// SavePanel only reads Divider from the components barrel, but the
// barrel's index evaluates all ~50 components — seconds of module
// execution per suite that made these tests fragile under CPU load.
// Re-export just the real Divider; everything else the panel renders
// (@wordpress/components Modal & controls) stays fully real.
jest.mock( '../../../../../../../packages/components/src', () => ( {
	Divider: jest.requireActual( '../../../../../../../packages/components/src/divider' ).default,
} ) );

const baseProps = {
	initialStatus: 'draft',
	presaveChecksEnabled: true,
	summary: [
		{ label: 'Content rules', content: 'All content' },
		{ label: 'Paid access', content: 'N/A' },
	],
	isSaving: false,
	onCancel: jest.fn(),
	onConfirm: jest.fn(),
};

describe( 'Content Gate SavePanel', () => {
	afterEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders the confirmation prompt', () => {
		render( <SavePanel { ...baseProps } /> );
		expect( screen.getByText( 'Are you ready to save?' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Double-check your settings before saving.' ) ).toBeInTheDocument();
	} );

	it( 'renders the status options and summary rows', () => {
		render( <SavePanel { ...baseProps } /> );
		expect( screen.getByText( 'Status' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Active' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Inactive' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Content rules' ) ).toBeInTheDocument();
		expect( screen.getByText( 'All content' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Paid access' ) ).toBeInTheDocument();
	} );

	it( 'checks "Always show pre-save checks." by default', () => {
		render( <SavePanel { ...baseProps } /> );
		expect( screen.getByLabelText( 'Always show pre-save checks.' ) ).toBeChecked();
	} );

	it( 'confirms with the initial status when saved unchanged', () => {
		render( <SavePanel { ...baseProps } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( baseProps.onConfirm ).toHaveBeenCalledWith( {
			status: 'draft',
			presaveChecksEnabled: true,
		} );
	} );

	it( 'confirms with the chosen status', () => {
		render( <SavePanel { ...baseProps } /> );
		fireEvent.click( screen.getByLabelText( 'Active' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( baseProps.onConfirm ).toHaveBeenCalledWith( {
			status: 'publish',
			presaveChecksEnabled: true,
		} );
	} );

	it( 'reports an unchecked preference on confirm', () => {
		render( <SavePanel { ...baseProps } /> );
		fireEvent.click( screen.getByLabelText( 'Always show pre-save checks.' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( baseProps.onConfirm ).toHaveBeenCalledWith( {
			status: 'draft',
			presaveChecksEnabled: false,
		} );
	} );

	it( 'calls onCancel from the Cancel button after the slide-out', () => {
		// The Cancel handler waits out the 200ms slide-out (SLIDE_OUT_MS in
		// index.tsx) on a real setTimeout before invoking onCancel. Drive the
		// timer with fake timers instead of polling waitFor against the wall
		// clock, so the test stays deterministic when CPU load delays timers.
		jest.useFakeTimers();
		try {
			render( <SavePanel { ...baseProps } /> );
			fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
			expect( baseProps.onCancel ).not.toHaveBeenCalled();
			act( () => {
				jest.advanceTimersByTime( 200 );
			} );
			expect( baseProps.onCancel ).toHaveBeenCalled();
		} finally {
			jest.useRealTimers();
		}
	} );
} );
