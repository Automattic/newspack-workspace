// @jest-environment jsdom

/**
 * NPPM-2733 — the per-gate actions in ContentGateSettings (enable/disable, delete)
 * must surface a failed save as their own error notice (addNotice). Before the
 * fix these handlers had no onError, so a failed action went silent once the
 * store error bridge was removed. Mocks mirror the CI-proven modal test pattern;
 * the menu is stubbed to always render its items so they can be clicked.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

// mock-prefixed so Jest's hoisted jest.mock factories may close over them.
const mockGate = {
	id: 7,
	title: 'Gate X',
	status: 'publish',
	content_rules: [],
	registration: {},
	custom_access: {},
};
// The wizard's loaded settings; a test sets what it needs.
let mockConfig = {};
const mockAddNotice = jest.fn();
const mockResetNotices = jest.fn();
const mockResetError = jest.fn();
const mockCloseMenu = jest.fn();
let mockStoreGates = [ mockGate ];
let mockIsFetching = false;
// The fetch boundary's outcome; by default any save fails with a parsed error.
let mockFetch;

jest.mock( '../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: ( ...args ) => mockFetch( ...args ),
		isFetching: mockIsFetching,
		resetError: ( ...args ) => mockResetError( ...args ),
	} ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( {
		addNotice: ( ...args ) => mockAddNotice( ...args ),
		resetNotices: ( ...args ) => mockResetNotices( ...args ),
	} ),
	useSelect: () => ( {} ),
	select: () => ( { getWizardAPIData: () => ( { gates: mockStoreGates } ) } ),
} ) );

jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	return {
		DropdownMenu: ( { children, label } ) =>
			React.createElement( 'div', null, React.createElement( 'button', { 'aria-label': label } ), children( { onClose: mockCloseMenu } ) ),
		MenuGroup: ( { children } ) => React.createElement( 'div', null, children ),
		MenuItem: ( { children, onClick, href, 'aria-label': ariaLabel } ) =>
			href
				? React.createElement( 'a', { href, 'aria-label': ariaLabel }, children )
				: React.createElement( 'button', { onClick, 'aria-label': ariaLabel }, children ),
	};
} );

jest.mock( '../../../../../packages/components/src', () => ( {
	Divider: () => null,
	Router: { useHistory: () => ( { push: () => {} } ) },
	TooltipBadge: () => null,
	useConfirmDialog: () => ( { confirmDialog: null, requestConfirm: cb => cb() } ),
} ) );

jest.mock( '../../../../../packages/components/src/wizard/store/utils', () => ( {
	useWizardData: () => ( { gates: [ mockGate ], config: mockConfig } ),
} ) );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

jest.mock( './edit/content-rule-control', () => ( { __esModule: true, default: () => null } ) );

jest.mock( './utils', () => ( {
	getAutoSignupOptions: jest.requireActual( './utils' ).getAutoSignupOptions,
	getEditGateLayoutUrl: () => '#',
	getGateStatus: () => 'Active',
	getGateStatusBadgeIntent: () => 'stable',
	getPriorityWarnings: () => ( {} ),
} ) );

describe( 'ContentGateSettings per-gate actions', () => {
	let ContentGateSettings;

	// The first load pulls in the whole card and can outlast the default per-test timeout.
	beforeAll( () => {
		// Read at module load; must exist before the component is required.
		window.newspackAudienceContentGates = { available_access_rules: {} };
		ContentGateSettings = require( './content-gate-settings' ).default;
	}, 60000 );

	beforeEach( () => {
		mockAddNotice.mockReset();
		mockCloseMenu.mockReset();
		mockStoreGates = [ mockGate ];
		mockIsFetching = false;
		mockFetch = ( _opts, callbacks ) => {
			// Mirror the hook: onError only fires when the handler provides one.
			if ( callbacks.onError ) {
				callbacks.onError( { message: 'Status change failed &amp; rejected' } );
			}
			callbacks.onFinally?.();
		};
		mockConfig = {};
	} );

	it( 'surfaces a failed status change as an error notice (NPPM-2733)', async () => {
		render( <ContentGateSettings gate={ mockGate } updateGatesData={ () => {} } /> );

		// gate.status === 'publish' -> the toggle action is labelled "Set to Inactive".
		fireEvent.click( screen.getByRole( 'button', { name: 'Set to Inactive: Gate X' } ) );

		await waitFor( () => {
			expect( mockAddNotice ).toHaveBeenCalledWith(
				expect.objectContaining( {
					type: 'error',
					id: 'content-gate-status-error',
					// decodeEntities( 'Status change failed &amp; rejected' )
					message: 'Status change failed & rejected',
				} )
			);
		} );
	} );

	it( 'surfaces a failed delete as an error notice (NPPM-2733)', async () => {
		render( <ContentGateSettings gate={ mockGate } updateGatesData={ () => {} } /> );

		// The Delete action routes through useConfirmDialog, mocked to fire its
		// callback immediately -> handleDelete -> failed DELETE -> onError.
		fireEvent.click( screen.getByRole( 'button', { name: 'Delete: Gate X' } ) );

		await waitFor( () => {
			expect( mockAddNotice ).toHaveBeenCalledWith(
				expect.objectContaining( {
					type: 'error',
					id: 'content-gate-delete-error',
				} )
			);
		} );
	} );

	it( 'names the actions menu and each action after the gate', () => {
		render( <ContentGateSettings gate={ mockGate } updateGatesData={ () => {} } /> );

		expect( screen.getByRole( 'button', { name: 'Gate actions: Gate X' } ) ).toBeInTheDocument();
		[ 'Edit', 'Set to Inactive', 'Duplicate', 'Delete' ].forEach( label => {
			expect( screen.getByRole( 'button', { name: `${ label }: Gate X` } ) ).toBeInTheDocument();
		} );
	} );

	it( 'closes the menu when an action runs', () => {
		render( <ContentGateSettings gate={ mockGate } updateGatesData={ () => {} } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Duplicate: Gate X' } ) );

		expect( mockCloseMenu ).toHaveBeenCalled();
	} );

	it( 'still undoes a status change after a filter has removed the card', () => {
		const other = { ...mockGate, id: 8, title: 'Gate Y' };
		const saves = [];
		mockStoreGates = [ mockGate, other ];
		mockFetch = ( { data }, callbacks ) => {
			saves.push( data.gate.status );
			mockStoreGates = mockStoreGates.map( g => ( g.id === data.gate.id ? data.gate : g ) );
			callbacks.onSuccess( data.gate );
			callbacks.onFinally();
		};
		const updateGatesData = jest.fn();
		const { rerender, unmount } = render( <ContentGateSettings gate={ mockGate } updateGatesData={ updateGatesData } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Set to Inactive: Gate X' } ) );
		const undo = mockAddNotice.mock.calls[ 0 ][ 0 ].actions[ 0 ].onClick;

		// The card's last render before it left the list still had the request in flight.
		mockIsFetching = true;
		rerender( <ContentGateSettings gate={ mockGate } updateGatesData={ updateGatesData } /> );
		unmount();
		// A change to another gate made since the toggle must survive the undo.
		mockStoreGates = mockStoreGates.map( g => ( g.id === other.id ? { ...g, status: 'draft' } : g ) );

		undo();

		expect( saves ).toEqual( [ 'draft', 'publish' ] );
		expect( updateGatesData ).toHaveBeenLastCalledWith( [
			expect.objectContaining( { id: 7, status: 'publish' } ),
			expect.objectContaining( { id: 8, status: 'draft' } ),
		] );

		// Undoing the undo goes back to the status it replaced.
		mockAddNotice.mock.calls.at( -1 )[ 0 ].actions[ 0 ].onClick();

		expect( saves ).toEqual( [ 'draft', 'publish', 'draft' ] );
	} );

	it( 'links the layout items to the layout editor', () => {
		render(
			<ContentGateSettings
				gate={ { ...mockGate, registration: { active: true, gate_layout_id: 12, metering: { enabled: false }, access_rules: [] } } }
				updateGatesData={ () => {} }
			/>
		);

		expect( screen.getByRole( 'link', { name: 'Edit Registered Access Layout: Gate X' } ) ).toHaveAttribute( 'href', '#' );
	} );

	it( 'names the site auto-signup setting on a premium newsletter card', () => {
		mockConfig = { auto_signup: true };
		render(
			<ContentGateSettings
				gate={ { ...mockGate, custom_access: { active: true, access_rules: [], metering: { enabled: false, count: 1, period: 'month' } } } }
				updateGatesData={ () => {} }
				isNewsletter
			/>
		);

		expect( screen.getByText( /Default \(on\)/ ) ).toBeInTheDocument();
	} );
} );
