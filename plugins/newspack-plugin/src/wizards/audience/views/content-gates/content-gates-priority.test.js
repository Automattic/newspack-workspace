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
const mockPlainGates = [
	{ id: 1, title: 'Gate A', status: 'active', priority: 0 },
	{ id: 2, title: 'Gate B', status: 'active', priority: 1 },
];
let mockGates = mockPlainGates;
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

jest.mock( '@wordpress/ui', () => {
	const React = require( 'react' );
	return { Stack: ( { children } ) => React.createElement( 'div', null, children ) };
} );

// Button/Modal passthroughs; CardSortableList exposes its drag callback as a
// clickable button so the test can reorder (0 -> 1) and enable Save, and
// renders each item's warning badge tooltip so the priority warnings are visible.
jest.mock( '../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		Button: ( { children, onClick, disabled } ) => React.createElement( 'button', { onClick, disabled }, children ),
		Modal: ( { children } ) => React.createElement( 'div', { role: 'dialog' }, children ),
		CardSortableList: ( { items, onDragCallback } ) =>
			React.createElement(
				'div',
				null,
				React.createElement( 'button', { 'data-testid': 'drag', onClick: () => onDragCallback( 0, 1 ) }, 'drag' ),
				items.map( item => item.secondaryBadge && React.createElement( 'p', { key: item.id }, item.secondaryBadge.tooltip ) )
			),
	};
} );

jest.mock( '../../../../../packages/components/src/wizard/store/utils', () => ( {
	useWizardData: () => ( { gates: mockGates } ),
} ) );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

// Avoid pulling in the real gate-status helpers; the modal only needs strings.
// The priority warnings are the real ones, since the case below tests them.
jest.mock( './utils', () => ( {
	getGateStatus: () => 'Active',
	getGateStatusBadgeIntent: () => 'stable',
	getPriorityWarnings: jest.requireActual( './utils' ).getPriorityWarnings,
	getPriorityWarningLabel: jest.requireActual( './utils' ).getPriorityWarningLabel,
} ) );

describe( 'Content Gates Priority modal', () => {
	beforeEach( () => {
		mockGates = mockPlainGates;
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

	it( 'warns about a gate ranked above a paid gate, and follows the unsaved order (NPPD-2289)', () => {
		const allPosts = [ { slug: 'post_types', value: [ 'post' ] } ];
		mockGates = [
			{
				id: 1,
				title: 'Registration wall',
				status: 'publish',
				priority: 0,
				content_rules: allPosts,
				registration: { active: true },
				custom_access: { active: false, access_rules: [] },
			},
			{
				id: 2,
				title: 'Paid wall',
				status: 'publish',
				priority: 1,
				content_rules: allPosts,
				registration: { active: true },
				custom_access: { active: true, access_rules: [ [ { slug: 'subscription', value: [ '10' ] } ] ] },
			},
		];
		const ContentGatesPriority = require( './content-gates-priority' ).default;
		render( <ContentGatesPriority showModal={ true } closeModal={ () => {} } updateGatesData={ () => {} } /> );

		expect( screen.getByText( /also restricted by paid access rules/ ) ).toBeTruthy();

		// Drag the registration wall below the paid wall; the warning goes with the old order.
		fireEvent.click( screen.getByTestId( 'drag' ) );

		expect( screen.queryByText( /also restricted by paid access rules/ ) ).toBeNull();
	} );

	it( 'warns from the latest gates each time it opens, not the ones it first saw', () => {
		const allPosts = [ { slug: 'post_types', value: [ 'post' ] } ];
		const paidWall = {
			id: 2,
			title: 'Paid wall',
			status: 'publish',
			priority: 1,
			content_rules: allPosts,
			registration: { active: true },
			custom_access: { active: true, access_rules: [ [ { slug: 'subscription', value: [ '10' ] } ] ] },
		};
		const registrationWall = {
			id: 1,
			title: 'Registration wall',
			status: 'draft',
			priority: 0,
			content_rules: allPosts,
			registration: { active: true },
			custom_access: { active: false, access_rules: [] },
		};
		mockGates = [ registrationWall, paidWall ];
		const ContentGatesPriority = require( './content-gates-priority' ).default;
		const { rerender } = render( <ContentGatesPriority showModal={ false } closeModal={ () => {} } updateGatesData={ () => {} } /> );

		// Activated from its card while the modal was closed.
		mockGates = [ { ...registrationWall, status: 'publish' }, paidWall ];
		rerender( <ContentGatesPriority showModal={ true } closeModal={ () => {} } updateGatesData={ () => {} } /> );

		expect( screen.getByText( /also restricted by paid access rules/ ) ).toBeTruthy();
	} );
} );
