// @jest-environment jsdom

/**
 * NPPM-2733 — the per-gate actions in ContentGateSettings (enable/disable, delete)
 * must surface a failed save as their own error notice (addNotice). Before the
 * fix these handlers had no onError, so a failed action went silent once the
 * store error bridge was removed. Mocks mirror the CI-proven modal test pattern;
 * Card is stubbed to render its action items as buttons so they can be clicked.
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
const mockAddNotice = jest.fn();
const mockResetNotices = jest.fn();
const mockResetError = jest.fn();

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
	return { CardBody: ( { children } ) => React.createElement( 'div', null, children ) };
} );

// Card renders its action items (nested arrays) as clickable buttons so the
// test can trigger the enable/disable action.
jest.mock( '../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		Badge: ( { text } ) => React.createElement( 'span', null, text ),
		Grid: ( { children } ) => React.createElement( 'div', null, children ),
		Card: ( { children, __experimentalCoreProps } ) =>
			React.createElement(
				'div',
				null,
				( __experimentalCoreProps?.actions || [] )
					.flat()
					.map( a => React.createElement( 'button', { key: a.label, onClick: a.action }, a.label ) ),
				children
			),
		Router: { useHistory: () => ( { push: () => {} } ) },
		useConfirmDialog: () => ( { confirmDialog: null, requestConfirm: cb => cb() } ),
	};
} );

jest.mock( '../../../../../packages/components/src/wizard/store/utils', () => ( {
	useWizardData: () => ( { gates: [ mockGate ] } ),
} ) );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

jest.mock( './edit/content-rule-control', () => ( { __esModule: true, default: () => null } ) );

jest.mock( './utils', () => ( {
	getEditGateLayoutUrl: () => '#',
	getGateStatus: () => 'Active',
	getGateStatusBadgeLevel: () => 'success',
} ) );

describe( 'ContentGateSettings per-gate actions', () => {
	beforeEach( () => {
		mockAddNotice.mockReset();
		// Read at module load; must exist before the component is required.
		window.newspackAudienceContentGates = { available_access_rules: {} };
	} );

	it( 'surfaces a failed status change as an error notice (NPPM-2733)', async () => {
		const ContentGateSettings = require( './content-gate-settings' ).default;
		render( <ContentGateSettings gate={ mockGate } updateGatesData={ () => {} } /> );

		// gate.status === 'publish' -> the toggle action is labelled "Set to inactive".
		fireEvent.click( screen.getByRole( 'button', { name: 'Set to inactive' } ) );

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
		fireEvent.click( screen.getByRole( 'button', { name: 'Delete' } ) );

		await waitFor( () => {
			expect( mockAddNotice ).toHaveBeenCalledWith(
				expect.objectContaining( {
					type: 'error',
					id: 'content-gate-delete-error',
				} )
			);
		} );
	} );
} );
