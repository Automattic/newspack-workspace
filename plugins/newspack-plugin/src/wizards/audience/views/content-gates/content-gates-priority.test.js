// @jest-environment jsdom

/**
 * NPPM-2733 — the Content Gates "Gate priority" modal must surface a failed
 * save as its own error notice (addNotice) AND roll back the optimistic
 * reorder (updateGatesData(oldGates)), rather than relying on the removed
 * store error bridge. Mocks mirror the CI-proven settings-modal.test.js
 * pattern; CardSortableList is stubbed to expose its drag callback so Save
 * can be enabled without a real drag interaction.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

// mock-prefixed so Jest's hoisted jest.mock factories may close over them.
const mockGates = [
	{ id: 1, title: 'Gate A', status: 'active', priority: 0 },
	{ id: 2, title: 'Gate B', status: 'active', priority: 1 },
];
const mockAddNotice = jest.fn();
const mockResetNotices = jest.fn();
const mockResetError = jest.fn();

// Control the fetch boundary: the save invokes onError then onFinally, and
// returns nothing — the modal never reads the return value.
jest.mock( '../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: ( _opts, callbacks ) => {
			callbacks.onError( { message: 'Priority save failed &amp; rejected' } );
			callbacks.onFinally();
		},
		isFetching: false,
		resetError: ( ...args ) => mockResetError( ...args ),
	} ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( {
		addNotice: ( ...args ) => mockAddNotice( ...args ),
		resetNotices: ( ...args ) => mockResetNotices( ...args ),
	} ),
	useSelect: () => ( {} ),
} ) );

jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	const Passthrough = ( { children } ) => React.createElement( 'div', null, children );
	return {
		__experimentalHStack: Passthrough,
		__experimentalVStack: Passthrough,
	};
} );

// Button/Modal passthroughs; CardSortableList exposes its drag callback as a
// clickable button so the test can reorder (0 -> 1) and enable Save.
jest.mock( '../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		Button: ( { children, onClick, disabled } ) => React.createElement( 'button', { onClick, disabled }, children ),
		Modal: ( { children } ) => React.createElement( 'div', { role: 'dialog' }, children ),
		CardSortableList: ( { onDragCallback } ) =>
			React.createElement( 'button', { 'data-testid': 'drag', onClick: () => onDragCallback( 0, 1 ) }, 'drag' ),
	};
} );

jest.mock( '../../../../../packages/components/src/wizard/store/utils', () => ( {
	useWizardData: () => ( { gates: mockGates } ),
} ) );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

// Avoid pulling in the real gate-status helpers; the modal only needs strings.
jest.mock( './utils', () => ( {
	getGateStatus: () => 'Active',
	getGateStatusBadgeLevel: () => 'success',
} ) );

describe( 'Content Gates Priority modal', () => {
	beforeEach( () => {
		mockAddNotice.mockReset();
		mockResetNotices.mockReset();
		mockResetError.mockReset();
	} );

	it( 'surfaces a failed priority save as an error notice and rolls back the reorder (NPPM-2733)', async () => {
		const ContentGatesPriority = require( './content-gates-priority' ).default;
		const updateGatesData = jest.fn();
		render( <ContentGatesPriority showModal={ true } closeModal={ () => {} } updateGatesData={ updateGatesData } /> );

		// Reorder via CardSortableList's drag callback so the config differs from
		// the stored order and Save enables.
		fireEvent.click( screen.getByTestId( 'drag' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => {
			expect( mockAddNotice ).toHaveBeenCalledWith(
				expect.objectContaining( {
					type: 'error',
					// decodeEntities( 'Priority save failed &amp; rejected' )
					message: 'Priority save failed & rejected',
				} )
			);
		} );

		// Rollback: the optimistic reorder is reverted to the original gate order.
		expect( updateGatesData ).toHaveBeenCalledWith( mockGates );
	} );
} );
