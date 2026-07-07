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
		// Drive the real failure path: a rejected fetch runs `catchCallback`, which
		// sets the error in local state. It must surface (via `errorMessage`) and
		// never be written into the shared store.
		mockWizardApiFetch.mockRejectedValue( {
			message: 'Boom',
			code: 'boom_error',
			data: { status: 500 },
		} );

		render( <HookProbe /> );

		// The request path matches the slug so the promise the hook returns is the
		// rejecting one we swallow here; otherwise the rejection would be unhandled.
		await act( async () => {
			await hook.wizardApiFetch( { path: 'test-slug' } ).catch( () => {} );
		} );

		expect( screen.getByTestId( 'error' ).textContent ).toBe( 'Boom' );
		expect( errorStoreWrites() ).toEqual( [] );
	} );

	it( 'clears a stale error when the slug changes (NPPM-2733)', async () => {
		// The loop-free slug-reset effect must clear a prior slug's error so it
		// can't leak into a new slug. Added in response to earlier review.
		mockWizardApiFetch.mockRejectedValue( {
			message: 'Boom',
			code: 'boom_error',
			data: { status: 500 },
		} );

		function SlugProbe( { slug } ) {
			hook = useWizardApiFetch( slug );
			return hook.errorMessage ? <div data-testid="error">{ hook.errorMessage }</div> : null;
		}

		const { rerender } = render( <SlugProbe slug="slug-a" /> );

		// Path matches the slug so the returned promise is the rejecting one.
		await act( async () => {
			await hook.wizardApiFetch( { path: 'slug-a' } ).catch( () => {} );
		} );
		expect( screen.getByTestId( 'error' ).textContent ).toBe( 'Boom' );

		// Changing the slug must clear the stale error.
		rerender( <SlugProbe slug="slug-b" /> );
		expect( screen.queryByTestId( 'error' ) ).toBeNull();
	} );
} );
