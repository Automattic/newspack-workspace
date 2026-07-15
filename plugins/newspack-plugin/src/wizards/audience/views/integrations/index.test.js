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
} );
