// @jest-environment jsdom

/**
 * NPPM-2733 — the Premium Newsletters "Advanced settings" modal must
 * surface a failed save as its own error notice (addNotice), rather than
 * relying on the removed store error bridge to reach the sibling list.
 * Mocks mirror the CI-proven settings-modal.test.js pattern: passthrough
 * component stubs + a controllable useWizardApiFetch that drives onError.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

// Mock-prefixed names so Jest's hoisted jest.mock can close over them.
const mockAddNotice = jest.fn();
const mockResetNotices = jest.fn();
const mockUpdateWizardSettings = jest.fn();
const mockResetError = jest.fn();

// Control the fetch boundary: the save invokes onError (with the parsed
// error the hook would deliver) then onFinally, and returns nothing — the
// modal never reads the return value, so there is no unhandled rejection.
jest.mock( '../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: ( _opts, callbacks ) => {
			callbacks.onError( { message: 'Save failed &amp; rejected' } );
			callbacks.onFinally();
		},
		isFetching: false,
		resetError: ( ...args ) => mockResetError( ...args ),
	} ),
} ) );

// The modal's only @wordpress/data hooks. useSelect is stubbed defensively
// in case any node in the render tree reaches for it.
jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( {
		addNotice: ( ...args ) => mockAddNotice( ...args ),
		resetNotices: ( ...args ) => mockResetNotices( ...args ),
		updateWizardSettings: ( ...args ) => mockUpdateWizardSettings( ...args ),
	} ),
	useSelect: () => ( {} ),
} ) );

// Stub @wordpress/components — the modal imports ToggleControl and the
// experimental HStack/VStack. Real-module load needs broader setup in
// jsdom, so provide minimal passthroughs (same approach as settings-modal).
jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	const Passthrough = ( { children } ) => React.createElement( 'div', null, children );
	const ToggleControl = ( { label, checked, onChange } ) =>
		React.createElement( 'input', {
			type: 'checkbox',
			'aria-label': label,
			checked: !! checked,
			onChange: e => onChange( e.target.checked ),
		} );
	return {
		ToggleControl,
		__experimentalHStack: Passthrough,
		__experimentalVStack: Passthrough,
	};
} );

// The barrel the modal reads Button/Modal from. Passthroughs so the test
// exercises the modal's own save/error logic, not component internals.
jest.mock( '../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		Button: ( { children, onClick, disabled } ) => React.createElement( 'button', { onClick, disabled }, children ),
		Modal: ( { children } ) => React.createElement( 'div', { role: 'dialog' }, children ),
	};
} );

// `config` undefined -> the modal's config state differs from the stored
// config, so Save renders enabled without a toggle interaction.
jest.mock( '../../../../../packages/components/src/wizard/store/utils', () => ( {
	useWizardData: () => ( {} ),
} ) );

// Stub the store module to the namespace constant only.
jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

describe( 'Premium newsletters AdvancedSettings modal', () => {
	beforeEach( () => {
		mockAddNotice.mockReset();
		mockResetNotices.mockReset();
		mockUpdateWizardSettings.mockReset();
		mockResetError.mockReset();
	} );

	it( 'surfaces a failed save as an error notice (NPPM-2733)', async () => {
		// Before the fix the modal only called setError() on its own hook
		// instance, which the sibling list read through the shared store.
		// With the store error bridge removed, the modal must surface its
		// own error via addNotice.
		const AdvancedSettings = require( './advanced-settings' ).default;
		render( <AdvancedSettings showModal={ true } closeModal={ () => {} } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => {
			expect( mockAddNotice ).toHaveBeenCalledWith(
				expect.objectContaining( {
					type: 'error',
					// decodeEntities( 'Save failed &amp; rejected' ) === 'Save failed & rejected'
					message: 'Save failed & rejected',
				} )
			);
		} );
	} );
} );
