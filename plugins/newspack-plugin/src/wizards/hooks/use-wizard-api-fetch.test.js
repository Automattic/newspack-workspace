// @jest-environment jsdom

/**
 * External dependencies
 */
import { render, screen, act } from '@testing-library/react';

// Mock-prefixed names so Jest's hoisted jest.mock can close over them.
const mockUpdateWizardSettings = jest.fn();
const mockWizardApiFetch = jest.fn();
let mockWizardData = {};

jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( {
		wizardApiFetch: mockWizardApiFetch,
		updateWizardSettings: mockUpdateWizardSettings,
	} ),
	useSelect: () => mockWizardData,
} ) );

// The real store module calls createReduxStore() at import time; here we only
// need the namespace constant, so stub the module to avoid registering a store.
jest.mock( '../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

/**
 * Internal dependencies
 */
import { useWizardApiFetch } from './use-wizard-api-fetch';

// Capture the hook's latest return value so tests can drive it and read state.
let hook;
function HookProbe() {
	hook = useWizardApiFetch( 'test-slug' );
	return hook.errorMessage ? <div data-testid="error">{ hook.errorMessage }</div> : null;
}

// Writes into the store's `error` path — the sync that produced the NPPM-2733 loop.
function errorStoreWrites() {
	return mockUpdateWizardSettings.mock.calls.filter( ( [ arg ] ) => Array.isArray( arg?.path ) && arg.path.includes( 'error' ) );
}

describe( 'useWizardApiFetch', () => {
	beforeEach( () => {
		mockUpdateWizardSettings.mockClear();
		mockWizardApiFetch.mockReset();
		mockWizardData = {};
		hook = undefined;
	} );

	it( 'does not write an error into the shared wizard store on mount (NPPM-2733)', () => {
		render( <HookProbe /> );
		expect( errorStoreWrites() ).toEqual( [] );
	} );

	it( 'keeps a failed fetch error in local state, never in the shared store (NPPM-2733)', async () => {
		// A failed fetch used to be written into the @wordpress/data store, whose
		// clone-on-write reducer raced with a store->local sync into a flickering
		// render loop. The error must surface locally and never touch the store.
		mockWizardApiFetch.mockRejectedValue( {
			message: 'Boom',
			code: 'boom_error',
			data: { status: 500 },
		} );

		render( <HookProbe /> );

		await act( async () => {
			await hook.wizardApiFetch( { path: '/test' } ).catch( () => {} );
		} );

		// The error surfaces in local state (rendered via errorMessage)...
		expect( screen.getByTestId( 'error' ).textContent ).toBe( 'Boom' );
		// ...and was never synced into the shared store.
		expect( errorStoreWrites() ).toEqual( [] );
	} );
} );
