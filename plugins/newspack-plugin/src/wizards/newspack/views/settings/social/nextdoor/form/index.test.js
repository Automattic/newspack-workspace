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

const SETTINGS = { client_id: '', publication_url: '', allowed_roles: [] };

const CLAIMED_SETTINGS = { ...SETTINGS, publication_url: 'https://example.com', allowed_roles: [ 'administrator' ] };

const mockUpdateSettings = jest.fn();
const mockClaimPage = jest.fn();

// A factory rather than a render, so `rerender` can hand the form the fresh
// `settings` object the parent rebuilds on every apiData change.
const form = ( { status = {}, settings = {} } = {} ) => (
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

const renderForm = props => render( form( props ) );

const renderClaimedForm = () => renderForm( { status: CLAIMED, settings: CLAIMED_SETTINGS } );

beforeEach( () => {
	mockNotify.mockReset();
	mockReload.mockReset();
	mockUpdateSettings.mockReset().mockResolvedValue( undefined );
	mockClaimPage.mockReset().mockResolvedValue( { success: true } );
} );

describe( 'NextdoorForm', () => {
	it( 'holds the roles inert until the page is claimed', () => {
		renderForm( { status: { ...STATUS, has_credentials: true, has_tokens: true, token_valid: true } } );

		expect( screen.getByRole( 'checkbox', { name: 'Editor' } ) ).toBeDisabled();
		expect( screen.getByText( /Available once the page is claimed\./ ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Claim Page' } ) ).toBeEnabled();
	} );

	it( 'keeps Save disabled until the roles or the publication URL change', () => {
		renderClaimedForm();

		// The primary stays focusable while disabled, so the state is on aria-disabled.
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toHaveAttribute( 'aria-disabled', 'true' );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Editor' } ) );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).not.toHaveAttribute( 'aria-disabled' );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Editor' } ) );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toHaveAttribute( 'aria-disabled', 'true' );

		fireEvent.change( screen.getByLabelText( 'Publication URL' ), { target: { value: 'https://news.example.com' } } );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).not.toHaveAttribute( 'aria-disabled' );
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

	it( 'saves the roles before claiming the page', async () => {
		renderClaimedForm();

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Editor' } ) );
		fireEvent.change( screen.getByLabelText( 'Publication URL' ), { target: { value: 'https://news.example.com' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( mockClaimPage ).toHaveBeenCalledWith( 'https://news.example.com' ) );
		expect( mockUpdateSettings ).toHaveBeenCalledWith( { allowed_roles: [ 'administrator', 'editor' ] } );
		expect( mockUpdateSettings.mock.invocationCallOrder[ 0 ] ).toBeLessThan( mockClaimPage.mock.invocationCallOrder[ 0 ] );
	} );

	it( 'reloads the page once the claim succeeds', async () => {
		renderClaimedForm();

		fireEvent.change( screen.getByLabelText( 'Publication URL' ), { target: { value: 'https://news.example.com' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( mockReload ).toHaveBeenCalled() );
	} );

	it( 'keeps a typed publication URL when a save hands back new settings', async () => {
		const { rerender } = renderClaimedForm();

		fireEvent.change( screen.getByLabelText( 'Publication URL' ), { target: { value: 'https://news.example.com' } } );
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Editor' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( mockReload ).toHaveBeenCalled() );

		rerender( form( { status: CLAIMED, settings: { ...CLAIMED_SETTINGS, allowed_roles: [ 'administrator', 'editor' ] } } ) );

		expect( screen.getByLabelText( 'Publication URL' ) ).toHaveValue( 'https://news.example.com' );
	} );

	it( 'leaves the draft and the form usable when the claim fails', async () => {
		mockClaimPage.mockRejectedValue( new Error( 'Failed to claim page.' ) );
		renderClaimedForm();

		fireEvent.change( screen.getByLabelText( 'Publication URL' ), { target: { value: 'https://news.example.com' } } );
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Editor' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( mockClaimPage ).toHaveBeenCalledWith( 'https://news.example.com' ) );
		expect( mockUpdateSettings ).toHaveBeenCalledWith( { allowed_roles: [ 'administrator', 'editor' ] } );

		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Save' } ) ).not.toHaveAttribute( 'aria-disabled' ) );
		expect( screen.getByLabelText( 'Publication URL' ) ).toHaveValue( 'https://news.example.com' );
		expect( mockReload ).not.toHaveBeenCalled();
	} );

	it( 'refuses an empty publication URL and says so once the field is left', () => {
		renderClaimedForm();

		fireEvent.change( screen.getByLabelText( 'Publication URL' ), { target: { value: '' } } );
		fireEvent.blur( screen.getByLabelText( 'Publication URL' ) );

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.getByText( 'Enter the URL of your publication.' ) ).toBeInTheDocument();
	} );

	it( 'picks up a publication URL that changed on the server', () => {
		const { rerender } = renderClaimedForm();

		rerender( form( { status: CLAIMED, settings: { ...CLAIMED_SETTINGS, publication_url: 'https://moved.example.com' } } ) );

		expect( screen.getByLabelText( 'Publication URL' ) ).toHaveValue( 'https://moved.example.com' );
	} );
} );
