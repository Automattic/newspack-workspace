/**
 * External dependencies
 */
import { act, render, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import AudienceIntegrations from './index';

const mockAddNotice = jest.fn();
const captured = {};
const loadingStates = [];

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( { addNotice: mockAddNotice } ),
} ) );
jest.mock( '../../../../../packages/components/src', () => ( {
	Wizard: ( { sections } ) => {
		const Section = sections[ 0 ].render;
		return <Section { ...sections[ 0 ].props } />;
	},
	withWizard: Component => Component,
} ) );
jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );
jest.mock( './settings-section', () => ( {
	SettingsSection: props => {
		captured.props = props;
		loadingStates.push( props.loading );
		return null;
	},
} ) );
jest.mock( './configure-view', () => ( { ConfigureView: () => null } ) );
jest.mock( './logs-view', () => ( { LogsView: () => null } ) );

const SETTINGS_MAP = {
	esp: { id: 'esp', name: 'Newsletter ESP', enabled: false, settings: [] },
};

// `onToggleEnabled` doesn't return the underlying apiFetch promise, so `act()`
// can't await it directly. Flush pending microtasks (the apiFetch resolution
// and its .then/.catch/.finally chain) before act() exits, so the resulting
// state updates stay inside act's tracked scope instead of firing after it.
const flushPromises = () => new Promise( resolve => setTimeout( resolve, 0 ) );

describe( 'AudienceIntegrations notices', () => {
	beforeEach( async () => {
		mockAddNotice.mockClear();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( SETTINGS_MAP );
		render( <AudienceIntegrations /> );
		await waitFor( () => expect( captured.props.loading ).toBe( false ) );
	} );

	it( 'announces a success snackbar when an integration is enabled', async () => {
		await act( async () => {
			captured.props.onToggleEnabled( 'esp', true );
			await flushPromises();
		} );
		await waitFor( () =>
			expect( mockAddNotice ).toHaveBeenCalledWith( {
				id: 'integration-enabled-esp',
				type: 'success',
				message: 'Newsletter ESP enabled.',
			} )
		);
	} );

	it( 'announces a success snackbar when an integration is disabled', async () => {
		await act( async () => {
			captured.props.onToggleEnabled( 'esp', false );
			await flushPromises();
		} );
		await waitFor( () =>
			expect( mockAddNotice ).toHaveBeenCalledWith( {
				id: 'integration-enabled-esp',
				type: 'success',
				message: 'Newsletter ESP disabled.',
			} )
		);
	} );

	it( 'announces an error snackbar when the toggle request fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			captured.props.onToggleEnabled( 'esp', true );
			await flushPromises();
		} );
		await waitFor( () =>
			expect( mockAddNotice ).toHaveBeenCalledWith( {
				id: 'integration-enabled-esp',
				type: 'error',
				message: 'Something went wrong. Please try again.',
			} )
		);
	} );

	it( 'announces an error snackbar when the save request fails', async () => {
		act( () => {
			captured.props.onFieldChange( 'esp', 'mailchimp_audience_id', 'abc123' );
		} );
		await waitFor( () => expect( captured.props.pendingChanges.esp ).toEqual( { mailchimp_audience_id: 'abc123' } ) );

		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			captured.props.onSave( 'esp' );
			await flushPromises();
		} );
		await waitFor( () =>
			expect( mockAddNotice ).toHaveBeenCalledWith( {
				id: 'integration-saved-esp',
				type: 'error',
				message: 'Something went wrong. Please try again.',
			} )
		);
	} );

	it( 'announces the enabled snackbar after the modal save-and-enable succeeds', async () => {
		await act( () => captured.props.onSetupAndEnable( 'esp', { mailchimp_audience_id: 'abc123' } ) );
		await waitFor( () =>
			expect( mockAddNotice ).toHaveBeenCalledWith( {
				id: 'integration-enabled-esp',
				type: 'success',
				message: 'Newsletter ESP enabled.',
			} )
		);
	} );

	it( 'stays silent and rejects when save-and-enable fails at the enable step', async () => {
		apiFetch.mockResolvedValueOnce( SETTINGS_MAP ).mockRejectedValueOnce( new Error( 'nope' ) );
		await act( async () => {
			await expect( captured.props.onSetupAndEnable( 'esp', { mailchimp_audience_id: 'abc123' } ) ).rejects.toThrow( 'nope' );
		} );
		expect( mockAddNotice ).not.toHaveBeenCalled();
	} );

	it( 'keeps the activating state for a minimum window even when activation is instant', async () => {
		jest.useFakeTimers();
		try {
			act( () => {
				captured.props.onActivatePlugin( [ 'newspack-newsletters' ] );
			} );
			expect( captured.props.activating[ 'newspack-newsletters' ] ).toBe( true );
			// Let the (instantly resolved) activation request settle; the minimum
			// window has not elapsed, so the busy state must persist.
			await act( async () => {
				await Promise.resolve();
				await Promise.resolve();
				jest.advanceTimersByTime( 1000 );
				await Promise.resolve();
			} );
			expect( captured.props.activating[ 'newspack-newsletters' ] ).toBe( true );
			// Cross the minimum window; the busy state clears.
			await act( async () => {
				jest.advanceTimersByTime( 1100 );
				await Promise.resolve();
				await Promise.resolve();
				await Promise.resolve();
			} );
			expect( captured.props.activating[ 'newspack-newsletters' ] ).toBeUndefined();
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'keeps the card grid mounted and stays busy until the post-activation refetch lands', async () => {
		jest.useFakeTimers();
		try {
			// Hold the post-activation refetch (the plain GET call) open so we can
			// inspect state while it's in flight; the activation POST resolves
			// immediately.
			let resolveRefetch;
			const refetchPromise = new Promise( resolve => {
				resolveRefetch = resolve;
			} );
			apiFetch.mockImplementation( ( { method } = {} ) => ( method === 'POST' ? Promise.resolve( {} ) : refetchPromise ) );
			loadingStates.length = 0;

			act( () => {
				captured.props.onActivatePlugin( [ 'newspack-newsletters' ] );
			} );
			expect( captured.props.activating[ 'newspack-newsletters' ] ).toBe( true );

			// Resolve the activation request and cross the minimum busy window so
			// the refetch kicks off, but leave it unresolved.
			await act( async () => {
				await Promise.resolve();
				await Promise.resolve();
				jest.advanceTimersByTime( 2100 );
				await Promise.resolve();
				await Promise.resolve();
				await Promise.resolve();
			} );

			// The refetch is in flight: the grid must stay mounted (loading never
			// flips true) and the card must keep its own busy state rather than
			// flashing back to a stale "Activate".
			expect( captured.props.loading ).toBe( false );
			expect( captured.props.activating[ 'newspack-newsletters' ] ).toBe( true );
			expect( loadingStates ).not.toContain( true );

			await act( async () => {
				resolveRefetch( SETTINGS_MAP );
				await Promise.resolve();
				await Promise.resolve();
				await Promise.resolve();
			} );

			expect( captured.props.activating[ 'newspack-newsletters' ] ).toBeUndefined();
			expect( loadingStates ).not.toContain( true );
		} finally {
			jest.useRealTimers();
		}
	} );
} );

describe( 'AudienceIntegrations pending changes', () => {
	beforeEach( async () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( SETTINGS_MAP );
		render( <AudienceIntegrations /> );
		await waitFor( () => expect( captured.props.loading ).toBe( false ) );
	} );

	// Pins Finding 1: onDiscardChanges must clear the real pendingChanges state
	// in the parent, not just be a callback that gets invoked. A no-op
	// handleDiscardChanges would fail this assertion even though it would pass
	// a test that only checks the callback was called.
	it( "clears an integration's pending changes when onDiscardChanges is called", async () => {
		act( () => {
			captured.props.onFieldChange( 'esp', 'mailchimp_audience_id', 'abc123' );
		} );
		await waitFor( () => expect( captured.props.pendingChanges.esp ).toEqual( { mailchimp_audience_id: 'abc123' } ) );

		act( () => {
			captured.props.onDiscardChanges( 'esp' );
		} );
		await waitFor( () => expect( captured.props.pendingChanges.esp ).toBeUndefined() );
	} );

	it( 'is a no-op when there is nothing pending for the integration', async () => {
		const pendingChangesBefore = captured.props.pendingChanges;
		act( () => {
			captured.props.onDiscardChanges( 'esp' );
		} );
		expect( captured.props.pendingChanges ).toBe( pendingChangesBefore );
	} );

	// Pins a data-safety property: on a failed save, the server never received
	// the edit, so pendingChanges is the user's only copy of it. A future
	// refactor must not start clearing it on failure.
	it( 'preserves pendingChanges when the save request fails', async () => {
		act( () => {
			captured.props.onFieldChange( 'esp', 'mailchimp_audience_id', 'abc123' );
		} );
		await waitFor( () => expect( captured.props.pendingChanges.esp ).toEqual( { mailchimp_audience_id: 'abc123' } ) );

		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			captured.props.onSave( 'esp' );
			await flushPromises();
		} );
		expect( captured.props.pendingChanges.esp ).toEqual( { mailchimp_audience_id: 'abc123' } );
	} );
} );
