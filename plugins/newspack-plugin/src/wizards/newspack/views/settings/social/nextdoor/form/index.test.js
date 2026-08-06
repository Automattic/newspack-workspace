/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { NextdoorForm } from './index';

const mockNotify = jest.fn();
jest.mock( '../../context', () => ( {
	useSocialCards: () => ( { notify: mockNotify } ),
} ) );

const mockReload = jest.fn();
// A successful claim reloads, which jsdom cannot do.
delete window.location;
window.location = { href: 'https://example.com/', search: '', reload: mockReload };

window.newspackSettings = {
	social: {
		nextdoor: {
			country_options: [ { label: 'United States', value: 'US' } ],
			default_country: 'US',
			site_url: 'https://example.com',
			available_roles: [
				{ label: 'Administrator', value: 'administrator' },
				{ label: 'Editor', value: 'editor' },
			],
			redirect_uri: 'https://example.com/callback',
		},
	},
};

const STATUS = {
	is_connected: false,
	has_credentials: false,
	has_centralized_credentials: false,
	has_tokens: false,
	has_page: false,
	token_valid: false,
};

const CLAIMED = { ...STATUS, is_connected: true, has_credentials: true, has_tokens: true, has_page: true, token_valid: true };

const SETTINGS = { client_id: '', client_secret: '', publication_url: '', allowed_roles: [] };

const mockUpdateSettings = jest.fn();
const mockClaimPage = jest.fn();

const renderForm = ( { status = {}, settings = {} } = {} ) =>
	render(
		<NextdoorForm
			settings={ { ...SETTINGS, ...settings } }
			status={ { ...STATUS, ...status } }
			error={ null }
			updateSettings={ mockUpdateSettings }
			startOAuthFlow={ jest.fn() }
			claimPage={ mockClaimPage }
			setError={ jest.fn() }
		/>
	);

const renderClaimedForm = () =>
	renderForm( { status: CLAIMED, settings: { publication_url: 'https://example.com', allowed_roles: [ 'administrator' ] } } );

beforeEach( () => {
	mockNotify.mockReset();
	mockReload.mockReset();
	mockUpdateSettings.mockReset().mockResolvedValue( undefined );
	mockClaimPage.mockReset().mockResolvedValue( { success: true } );
} );

describe( 'NextdoorForm', () => {
	it( 'holds the roles inert until the page is claimed', () => {
		renderForm( { status: { ...STATUS, has_credentials: true, has_tokens: true } } );

		expect( screen.getByRole( 'checkbox', { name: 'Editor' } ) ).toBeDisabled();
		expect( screen.getByText( /Available once the page is claimed\./ ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Claim Page' } ) ).toBeEnabled();
	} );

	it( 'keeps Save disabled until the roles or the publication URL change', () => {
		renderClaimedForm();

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Editor' } ) );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Editor' } ) );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Publication URL' ), { target: { value: 'https://news.example.com' } } );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();
	} );

	it( 'saves changed roles without reclaiming the page', async () => {
		renderClaimedForm();

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Editor' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( mockUpdateSettings ).toHaveBeenCalledWith( { allowed_roles: [ 'administrator', 'editor' ] } ) );
		expect( mockClaimPage ).not.toHaveBeenCalled();
		expect( mockNotify ).toHaveBeenCalledWith( 'Nextdoor settings updated.' );
	} );

	it( 'claims the page again when the publication URL changes', async () => {
		renderClaimedForm();

		fireEvent.change( screen.getByLabelText( 'Publication URL' ), { target: { value: 'https://news.example.com' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( mockClaimPage ).toHaveBeenCalledWith( 'https://news.example.com' ) );
		expect( mockUpdateSettings ).not.toHaveBeenCalled();
	} );
} );
