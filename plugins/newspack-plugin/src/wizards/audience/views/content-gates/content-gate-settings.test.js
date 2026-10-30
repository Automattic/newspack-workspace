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

// The fetch boundary: any save invokes onError with a parsed error.
jest.mock( '../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: ( _opts, callbacks ) => {
			// Mirror the hook: onError only fires when the handler provides one.
			if ( callbacks.onError ) {
				callbacks.onError( { message: 'Status change failed &amp; rejected' } );
			}
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
	return {
		DropdownMenu: ( { children, label } ) =>
			React.createElement( 'div', null, React.createElement( 'button', { 'aria-label': label } ), children( { onClose: mockCloseMenu } ) ),
		MenuGroup: ( { children } ) => React.createElement( 'div', null, children ),
		MenuItem: ( { children, onClick, 'aria-label': ariaLabel } ) =>
			React.createElement( 'button', { onClick, 'aria-label': ariaLabel }, children ),
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
	beforeEach( () => {
		mockAddNotice.mockReset();
		mockCloseMenu.mockReset();
		mockConfig = {};
		// Read at module load; must exist before the component is required.
		window.newspackAudienceContentGates = { available_access_rules: {} };
	} );

	it( 'surfaces a failed status change as an error notice (NPPM-2733)', async () => {
		const ContentGateSettings = require( './content-gate-settings' ).default;
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
		const ContentGateSettings = require( './content-gate-settings' ).default;
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
		const ContentGateSettings = require( './content-gate-settings' ).default;
		render( <ContentGateSettings gate={ mockGate } updateGatesData={ () => {} } /> );

		expect( screen.getByRole( 'button', { name: 'Gate actions: Gate X' } ) ).toBeInTheDocument();
		[ 'Edit', 'Set to Inactive', 'Duplicate', 'Delete' ].forEach( label => {
			expect( screen.getByRole( 'button', { name: `${ label }: Gate X` } ) ).toBeInTheDocument();
		} );
	} );

	it( 'closes the menu when an action runs', () => {
		const ContentGateSettings = require( './content-gate-settings' ).default;
		render( <ContentGateSettings gate={ mockGate } updateGatesData={ () => {} } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Duplicate: Gate X' } ) );

		expect( mockCloseMenu ).toHaveBeenCalled();
	} );

	it( 'names the site auto-signup setting on a premium newsletter card', () => {
		mockConfig = { auto_signup: true };
		const ContentGateSettings = require( './content-gate-settings' ).default;
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
