/**
 * Campaigns Settings tab: the header Save is disabled until an edit makes the
 * form dirty.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@testing-library/jest-dom';
import apiFetch from '@wordpress/api-fetch';
import Settings from './index';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// Render content and header actions without the wizard Page shell.
jest.mock( '../../../../../../packages/components/src/with-wizard-screen', () => ( {
	__esModule: true,
	default: Component => props => (
		<>
			{ props.headerActions }
			<Component { ...props } />
		</>
	),
} ) );

const SETTINGS = {
	general_settings: [
		{ key: 'active', description: 'General Settings', help: 'Section help.', value: null },
		{ key: 'newspack_popups_foo', description: 'Foo', type: 'string', value: 'bar' },
	],
};

const renderTab = () => render( <Settings />, { wrapper: MemoryRouter } );

describe( 'Campaigns Settings tab', () => {
	beforeEach( () => jest.clearAllMocks() );

	it( 'disables Save until an edit makes the form dirty', async () => {
		apiFetch.mockResolvedValue( SETTINGS );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'textbox', { name: 'Foo' } ) ).toBeInTheDocument() );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();

		fireEvent.change( screen.getByRole( 'textbox', { name: 'Foo' } ), { target: { value: 'baz' } } );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();

		// Saving surfaces a success snackbar (Snackbar also mirrors the text in an aria-live region).
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () => expect( screen.getAllByText( 'Settings saved.' ).length ).toBeGreaterThan( 0 ) );
	} );
} );
