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
const mockRemoveNotice = jest.fn();
const captured = {};
const loadingStates = [];

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( { addNotice: mockAddNotice, removeNotice: mockRemoveNotice } ),
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
		mockRemoveNotice.mockClear();
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

	it( 'announces a success snackbar when the save succeeds', async () => {
		await act( async () => {
			captured.props.onSave( 'esp', { mailchimp_audience_id: 'abc123' } );
			await flushPromises();
		} );
		await waitFor( () =>
			expect( mockAddNotice ).toHaveBeenCalledWith( {
				id: 'integration-saved-esp',
				type: 'success',
				message: 'Settings saved.',
			} )
		);
	} );

	// Success and error share a notice id, and the wizard keys snackbars by id, so
	// each save has to clear the previous attempt's notice before adding its own.
	it( 'clears the previous save snackbar before each save', async () => {
		await act( async () => {
			captured.props.onSave( 'esp', { mailchimp_audience_id: 'abc123' } );
			await flushPromises();
		} );
		expect( mockRemoveNotice ).toHaveBeenCalledWith( 'integration-saved-esp' );
		expect( mockRemoveNotice.mock.invocationCallOrder[ 0 ] ).toBeLessThan( mockAddNotice.mock.invocationCallOrder[ 0 ] );
	} );

	it( 'announces an error snackbar when the save request fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			captured.props.onSave( 'esp', { mailchimp_audience_id: 'abc123' } ).catch( () => {} );
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

	// On a settings-ok/enable-fail partial, keep the pre-save integration so the
	// modal keeps its fields for a retry instead of collapsing to a bare error.
	it( 'does not swap integration state when only the enable step fails', async () => {
		const savedData = {
			esp: { id: 'esp', name: 'Newsletter ESP', enabled: false, settings: [ { key: 'mailchimp_audience_id', value: 'abc123' } ] },
		};
		apiFetch.mockReset();
		apiFetch.mockResolvedValueOnce( savedData ).mockRejectedValueOnce( new Error( 'nope' ) );
		await act( async () => {
			await expect( captured.props.onSetupAndEnable( 'esp', { mailchimp_audience_id: 'abc123' } ) ).rejects.toThrow( 'nope' );
		} );
		expect( captured.props.integrations.esp.settings ).toEqual( [] );
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

describe( 'AudienceIntegrations retry buffer', () => {
	beforeEach( async () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( SETTINGS_MAP );
		render( <AudienceIntegrations /> );
		await waitFor( () => expect( captured.props.loading ).toBe( false ) );
	} );

	it( 'clears the retry buffer when the save succeeds', async () => {
		await act( async () => {
			captured.props.onSave( 'esp', { mailchimp_audience_id: 'abc123' } );
			await flushPromises();
		} );
		expect( captured.props.inFlightChanges.esp ).toBeUndefined();
	} );

	// The server never received a failed edit, so the buffer is the user's only
	// copy. A future change must not start clearing it on failure.
	it( 'retains the retry buffer when the save fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			captured.props.onSave( 'esp', { mailchimp_audience_id: 'abc123' } ).catch( () => {} );
			await flushPromises();
		} );
		expect( captured.props.inFlightChanges.esp ).toEqual( { mailchimp_audience_id: 'abc123' } );
	} );

	// Setup-and-enable saves only the modal's fields, so it must not discard an
	// unrelated failed ConfigureView edit still in the retry buffer.
	it( 'preserves a prior failed-save retry buffer across a setup-and-enable', async () => {
		apiFetch.mockReset();
		apiFetch.mockRejectedValueOnce( new Error( 'save failed' ) );
		await act( async () => {
			captured.props.onSave( 'esp', { mailchimp_audience_id: 'from-configure' } ).catch( () => {} );
			await flushPromises();
		} );
		expect( captured.props.inFlightChanges.esp ).toEqual( { mailchimp_audience_id: 'from-configure' } );

		apiFetch.mockResolvedValue( SETTINGS_MAP );
		await act( async () => {
			await captured.props.onSetupAndEnable( 'esp', { other_field: 'x' } );
		} );
		expect( captured.props.inFlightChanges.esp ).toEqual( { mailchimp_audience_id: 'from-configure' } );
	} );

	// A key saved via setup-and-enable is dropped from the buffer; others stay.
	it( 'drops only the overlapping keys from the retry buffer on setup-and-enable', async () => {
		apiFetch.mockReset();
		apiFetch.mockRejectedValueOnce( new Error( 'save failed' ) );
		await act( async () => {
			captured.props.onSave( 'esp', { mailchimp_audience_id: 'A', other_field: 'keep' } ).catch( () => {} );
			await flushPromises();
		} );
		expect( captured.props.inFlightChanges.esp ).toEqual( { mailchimp_audience_id: 'A', other_field: 'keep' } );

		apiFetch.mockResolvedValue( SETTINGS_MAP );
		await act( async () => {
			await captured.props.onSetupAndEnable( 'esp', { mailchimp_audience_id: 'B' } );
		} );
		expect( captured.props.inFlightChanges.esp ).toEqual( { other_field: 'keep' } );
	} );

	it( 'clears the retry buffer when a ConfigureView discards its changes', async () => {
		apiFetch.mockReset();
		apiFetch.mockRejectedValueOnce( new Error( 'save failed' ) );
		await act( async () => {
			captured.props.onSave( 'esp', { mailchimp_audience_id: 'A' } ).catch( () => {} );
			await flushPromises();
		} );
		expect( captured.props.inFlightChanges.esp ).toEqual( { mailchimp_audience_id: 'A' } );
		act( () => {
			captured.props.onDiscardChanges( 'esp' );
		} );
		expect( captured.props.inFlightChanges.esp ).toBeUndefined();
	} );

	it( 'marks the integration saving while the request is in flight', async () => {
		let resolveSave;
		apiFetch.mockImplementation(
			() =>
				new Promise( resolve => {
					resolveSave = resolve;
				} )
		);
		act( () => {
			captured.props.onSave( 'esp', { mailchimp_audience_id: 'abc123' } );
		} );
		await waitFor( () => expect( captured.props.saving.esp ).toBe( true ) );
		await act( async () => {
			resolveSave( SETTINGS_MAP );
			await flushPromises();
		} );
		expect( captured.props.saving.esp ).toBe( false );
	} );
} );
