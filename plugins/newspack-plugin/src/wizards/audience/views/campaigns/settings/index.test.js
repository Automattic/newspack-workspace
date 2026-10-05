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

// Stand in for the page autocomplete: surface the seeded selection and let a test
// drive onChange as picking or clearing a page, without the real suggestions fetch.
jest.mock( '../../../../../../packages/components/src/page-control', () => ( {
	__esModule: true,
	default: ( { selected, onChange } ) => (
		<div>
			<span data-testid="donor-landing-selected">{ selected ? selected.label : 'none' }</span>
			<button type="button" onClick={ () => onChange( '842' ) }>
				Choose another page
			</button>
			<button type="button" onClick={ () => onChange( '' ) }>
				Clear page
			</button>
		</div>
	),
} ) );

const SETTINGS = {
	general_settings: [
		{ key: 'active', description: 'General Settings', help: 'Section help.', value: null },
		{ key: 'newspack_popups_foo', description: 'Foo', type: 'string', value: 'bar' },
	],
};

// A setting carrying `control: 'page'` and a saved `selected` page, as the Campaigns
// settings endpoint returns for the donor landing page.
const DONOR_SETTINGS = {
	donor_settings: [
		{ key: 'active', description: 'Donor Settings', help: 'Section help.', value: null },
		{
			section: 'donor_settings',
			key: 'newspack_popups_donor_landing_page',
			type: 'string',
			control: 'page',
			value: '700',
			description: 'Donor landing page',
			help: 'Donor landing page help.',
			selected: { label: 'Support our journalism', value: 700 },
		},
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

	it( 'renders the donor landing page through the page control, seeded with the saved page', async () => {
		apiFetch.mockResolvedValue( DONOR_SETTINGS );
		renderTab();

		await waitFor( () => expect( screen.getByTestId( 'donor-landing-selected' ) ).toHaveTextContent( 'Support our journalism' ) );
		// A page control, not the raw text field this setting regressed to.
		expect( screen.queryByRole( 'textbox', { name: 'Donor landing page' } ) ).not.toBeInTheDocument();
	} );

	it.each( [
		[ 'Choose another page', '842' ],
		[ 'Clear page', '' ],
	] )( 'posts the page id when the donor landing page is changed via "%s"', async ( action, expected ) => {
		apiFetch.mockResolvedValue( DONOR_SETTINGS );
		renderTab();
		await waitFor( () => expect( screen.getByTestId( 'donor-landing-selected' ) ).toBeInTheDocument() );

		// Save stays inert until the control reports a different page.
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
		fireEvent.click( screen.getByRole( 'button', { name: action } ) );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();

		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () =>
			expect( apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					method: 'POST',
					data: { section: 'donor_settings', settings: { newspack_popups_donor_landing_page: expected } },
				} )
			)
		);
	} );
} );
