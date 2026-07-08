/**
 * Tab-level tests for AppTab (Tab 10, NPPD-1882): the connect → select → render
 * state machine, driven by the /app/config response.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import AppTab from './AppTab';
import { fetchAppConfig, saveAppProperty, type AppConfig } from '../api/app';
import type { DateRange } from '../state/useDateRange';

jest.mock( '../api/app' );

const mockFetch = fetchAppConfig as jest.Mock;
const mockSave = saveAppProperty as jest.Mock;

const range = { start: '2026-05-01', end: '2026-05-31', preset: 'last-30' } as unknown as DateRange;

const baseConfig = ( overrides: Partial< AppConfig > = {} ): AppConfig => ( {
	is_app_publisher: true,
	connected: true,
	selected_property: null,
	selected_is_visible: false,
	properties: [],
	properties_error: null,
	settings_url: 'https://example.test/wp-admin/admin.php?page=newspack-settings',
	...overrides,
} );

const renderTab = () => render( <AppTab range={ range } previousRange={ null } /> );

describe( 'AppTab', () => {
	afterEach( () => {
		mockFetch.mockReset();
		mockSave.mockReset();
	} );

	it( 'shows the Connections CTA (not Site Kit) when not connected', async () => {
		mockFetch.mockResolvedValue( baseConfig( { connected: false } ) );
		renderTab();

		expect( await screen.findByText( /Connect Google in Newspack/i ) ).toBeInTheDocument();
		const link = screen.getByRole( 'link', { name: /Go to Connections/i } );
		expect( link ).toHaveAttribute( 'href', 'https://example.test/wp-admin/admin.php?page=newspack-settings' );
		expect( screen.queryByText( /Site Kit/i ) ).not.toBeInTheDocument();
	} );

	it( 'shows the property picker when connected with no property chosen', async () => {
		mockFetch.mockResolvedValue(
			baseConfig( {
				properties: [ { account_id: '1', account_name: 'YubaNet', property_id: '533212292', property_name: 'yubanetapp' } ],
			} )
		);
		renderTab();

		expect( await screen.findByText( /Choose the Google Analytics property/i ) ).toBeInTheDocument();
		// Option label combines account → property (id).
		expect( screen.getByText( /YubaNet → yubanetapp \(533212292\)/ ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: /Save property/i } ) ).toBeInTheDocument();
	} );

	it( 'surfaces an enumeration error in the picker', async () => {
		mockFetch.mockResolvedValue( baseConfig( { properties_error: 'permission denied' } ) );
		renderTab();

		expect( await screen.findByText( /permission denied/i ) ).toBeInTheDocument();
	} );

	it( 'persists the chosen property on Save', async () => {
		mockFetch.mockResolvedValue(
			baseConfig( {
				properties: [ { account_id: '1', account_name: 'YubaNet', property_id: '533212292', property_name: 'yubanetapp' } ],
			} )
		);
		mockSave.mockResolvedValue( baseConfig( { selected_property: '533212292', selected_is_visible: true } ) );
		renderTab();

		fireEvent.change( await screen.findByRole( 'combobox' ), { target: { value: '533212292' } } );
		fireEvent.click( screen.getByRole( 'button', { name: /Save property/i } ) );

		await waitFor( () => expect( mockSave ).toHaveBeenCalledWith( '533212292' ) );
		// After saving, the ready state renders.
		expect( await screen.findByText( /will appear here/i ) ).toBeInTheDocument();
	} );

	it( 'renders the ready state when a visible property is selected', async () => {
		mockFetch.mockResolvedValue( baseConfig( { selected_property: '533212292', selected_is_visible: true } ) );
		renderTab();

		expect( await screen.findByText( /App analytics for property 533212292/i ) ).toBeInTheDocument();
	} );

	it( 'falls back to the picker when the saved property is no longer visible', async () => {
		mockFetch.mockResolvedValue(
			baseConfig( {
				selected_property: '999',
				selected_is_visible: false,
				properties: [ { account_id: '1', account_name: 'YubaNet', property_id: '533212292', property_name: 'yubanetapp' } ],
			} )
		);
		renderTab();

		expect( await screen.findByText( /Choose the Google Analytics property/i ) ).toBeInTheDocument();
	} );

	it( 'shows an error state when the config fetch fails', async () => {
		mockFetch.mockRejectedValue( new Error( 'boom' ) );
		renderTab();

		expect( await screen.findByText( /boom/i ) ).toBeInTheDocument();
	} );
} );
