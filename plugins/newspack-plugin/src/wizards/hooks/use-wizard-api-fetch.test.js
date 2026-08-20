// @jest-environment jsdom

/**
 * External dependencies
 */
import { render, screen, act, waitFor } from '@testing-library/react';

// Mock-prefixed names so Jest's hoisted jest.mock can close over them.
// The dispatcher stands in for the store: it writes to `mockWizardData` at the
// path it is given, which is what makes the cache observable to a later mount.
const mockUpdateWizardSettings = jest.fn( ( { path, value } ) => {
	if ( ! Array.isArray( path ) ) {
		return;
	}
	let node = mockWizardData;
	path.slice( 0, -1 ).forEach( key => {
		node[ key ] = node[ key ] ?? {};
		node = node[ key ];
	} );
	node[ path[ path.length - 1 ] ] = value;
} );
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

// The toggle hook takes one spinner from the component barrel; importing the
// real barrel boots @wordpress/components stores the mocked data module can't serve.
jest.mock( '../../../packages/components/src', () => ( {
	Waiting: () => null,
} ) );

/**
 * Internal dependencies
 */
import { useWizardApiFetch } from './use-wizard-api-fetch';
import useWizardApiFetchToggle from './use-wizard-api-fetch-toggle';

// Capture the hook's latest return value so tests can drive it and read state.
let hook;
function HookProbe() {
	hook = useWizardApiFetch( 'test-slug' );
	return hook.errorMessage ? <div data-testid="error">{ hook.errorMessage }</div> : null;
}

const TOGGLE_PATH = '/newspack/v1/wizard/test-toggle';

let toggle;
function ToggleProbe() {
	toggle = useWizardApiFetchToggle( {
		path: TOGGLE_PATH,
		apiNamespace: 'test-toggle',
		data: { label: 'initial' },
		description: 'Toggle description',
	} );
	return <div data-testid="label">{ toggle.apiData.label }</div>;
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
		toggle = undefined;
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

	it( 'mirrors a POST response into the GET cache, so a remount is served the saved payload', async () => {
		// A POST is never cached under its own method, so without
		// `updateCacheMethods: [ 'GET' ]` the next mount is served — and a later
		// save writes back — the stale first-load snapshot.
		mockWizardApiFetch.mockImplementation( ( { method } ) => Promise.resolve( { label: method === 'POST' ? 'saved' : 'stored' } ) );

		const first = render( <ToggleProbe /> );
		await waitFor( () => expect( screen.getByTestId( 'label' ).textContent ).toBe( 'stored' ) );

		await act( async () => {
			await toggle.apiFetchToggle( { label: 'saved' }, true );
		} );
		expect( screen.getByTestId( 'label' ).textContent ).toBe( 'saved' );

		first.unmount();
		mockWizardApiFetch.mockClear();

		render( <ToggleProbe /> );
		await waitFor( () => expect( screen.getByTestId( 'label' ).textContent ).toBe( 'saved' ) );
		expect( mockWizardApiFetch ).not.toHaveBeenCalled();
	} );
} );
